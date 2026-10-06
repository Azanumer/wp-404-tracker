<?php
/**
 * Plugin Name: 404 Tracker & Redirects
 * Plugin URI: https://github.com/Azanumer/wp-404-tracker
 * Description: Logs every 404 hit (URL, referrer, hit count, first/last seen) and lets you create 301 redirects in one click from Tools → 404 Tracker.
 * Version: 1.0.0
 * Author: Azan Umer
 * License: GPLv2 or later
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('WP404T_VERSION', '1.0.0');

function wp404t_table() {
	global $wpdb;
	return $wpdb->prefix . 'not_found_log';
}

/**
 * Activation: create the log table + the redirects option.
 */
register_activation_hook(__FILE__, 'wp404t_activate');
function wp404t_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		'CREATE TABLE ' . wp404t_table() . " (\n" .
		"  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n" .
		"  url varchar(255) NOT NULL,\n" .
		"  referrer varchar(255) NOT NULL DEFAULT '',\n" .
		"  hits bigint(20) unsigned NOT NULL DEFAULT 1,\n" .
		"  first_hit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
		"  last_hit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
		"  PRIMARY KEY (id),\n" .
		"  UNIQUE KEY url (url(191))\n" .
		') ' . $wpdb->get_charset_collate() . ';'
	);
	add_option('wp404t_redirects', array());
}

/**
 * Serve any configured 301 redirect before WordPress decides it is a 404.
 * Matches on the request path (trailing-slash agnostic).
 */
add_action('template_redirect', 'wp404t_maybe_redirect', 1);
function wp404t_maybe_redirect() {
	$redirects = get_option('wp404t_redirects', array());
	if (!is_array($redirects) || empty($redirects)) {
		return;
	}
	$path = untrailingslashit(strtok((string) $_SERVER['REQUEST_URI'], '?') ?: '/');
	if ($path === '') {
		$path = '/';
	}
	foreach ($redirects as $from => $to) {
		$from = untrailingslashit((string) $from);
		if ($from === '') {
			$from = '/';
		}
		if ($from === $path) {
			wp_redirect(esc_url_raw($to), 301);
			exit;
		}
	}
}

/**
 * Log the 404: one row per URL, hit counter + latest referrer.
 * Filter 'wp404t_exclude_patterns' with an array of PCRE patterns to skip URLs
 * (e.g. array('#^/wp-content/cache/#')).
 */
add_action('template_redirect', 'wp404t_log_404', 99);
function wp404t_log_404() {
	if (!is_404() || is_admin()) {
		return;
	}
	$url = substr((string) $_SERVER['REQUEST_URI'], 0, 255);
	$url = '/' . ltrim($url, '/');

	$excludes = apply_filters('wp404t_exclude_patterns', array());
	foreach ((array) $excludes as $pattern) {
		if (@preg_match($pattern, $url)) {
			return;
		}
	}

	global $wpdb;
	$table    = wp404t_table();
	$referrer = isset($_SERVER['HTTP_REFERER']) ? substr(wp_unslash((string) $_SERVER['HTTP_REFERER']), 0, 255) : '';
	$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE url = %s", $url));

	if ($existing) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET hits = hits + 1, last_hit = NOW(), referrer = %s WHERE id = %d",
				$referrer,
				$existing
			)
		);
	} else {
		$now = current_time('mysql');
		$wpdb->insert(
			$table,
			array(
				'url'       => $url,
				'referrer'  => $referrer,
				'hits'     => 1,
				'first_hit' => $now,
				'last_hit'  => $now,
			),
			array('%s', '%s', '%d', '%s', '%s')
		);
	}
}

/* ------------------------------------------------------------------ */
/* Admin page: Tools → 404 Tracker                                      */
/* ------------------------------------------------------------------ */

add_action('admin_menu', 'wp404t_admin_menu');
function wp404t_admin_menu() {
	add_management_page('404 Tracker', '404 Tracker', 'manage_options', 'wp-404-tracker', 'wp404t_admin_page');
}

function wp404t_admin_page() {
	if (!current_user_can('manage_options')) {
		return;
	}
	global $wpdb;
	$table = wp404t_table();

	// --- actions -----------------------------------------------------
	if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete' && check_admin_referer('wp404t_delete')) {
		$wpdb->delete($table, array('id' => absint($_GET['id'])), array('%d'));
		echo '<div class="notice notice-success is-dismissible"><p>Entry deleted.</p></div>';
	}
	if (isset($_GET['action']) && $_GET['action'] === 'clear' && check_admin_referer('wp404t_clear')) {
		$wpdb->query("TRUNCATE TABLE $table");
		echo '<div class="notice notice-success is-dismissible"><p>404 log cleared.</p></div>';
	}
	if (isset($_POST['wp404t_add_redirect']) && check_admin_referer('wp404t_redirect')) {
		$from = '/' . ltrim(trim(wp_unslash((string) $_POST['from'])), '/');
		$to   = trim(wp_unslash((string) $_POST['to']));
		if ($from && $to) {
			$redirects          = get_option('wp404t_redirects', array());
			$redirects[$from]   = esc_url_raw($to);
			update_option('wp404t_redirects', $redirects);
			echo '<div class="notice notice-success is-dismissible"><p>Redirect added: <code>' . esc_html($from) . '</code> → <code>' . esc_html($to) . '</code></p></div>';
		}
	}
	if (isset($_GET['action'], $_GET['rfrom']) && $_GET['action'] === 'del_redirect' && check_admin_referer('wp404t_redirect')) {
		$redirects = get_option('wp404t_redirects', array());
		unset($redirects[wp_unslash((string) $_GET['rfrom'])]);
		update_option('wp404t_redirects', $redirects);
		echo '<div class="notice notice-success is-dismissible"><p>Redirect removed.</p></div>';
	}

	// --- pagination --------------------------------------------------
	$per_page = 25;
	$page     = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
	$total    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
	$pages    = max(1, (int) ceil($total / $per_page));
	$rows     = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table ORDER BY hits DESC, last_hit DESC LIMIT %d OFFSET %d",
			$per_page,
			($page - 1) * $per_page
		)
	);

	$prefill = isset($_GET['prefill']) ? '/' . ltrim(wp_unslash((string) $_GET['prefill']), '/') : '';
	?>
	<div class="wrap">
		<h1>404 Tracker &amp; Redirects</h1>
		<p><?php echo esc_html(number_format($total)); ?> distinct dead URLs on record. Fix the ones with the most hits first — they are the broken backlinks.</p>

		<h2>Log</h2>
		<table class="widefat striped">
			<thead><tr><th>URL</th><th>Hits</th><th>First seen</th><th>Last seen</th><th>Referrer</th><th></th></tr></thead>
			<tbody>
			<?php if ($rows) : foreach ($rows as $row) : ?>
				<tr>
					<td><code><?php echo esc_html($row->url); ?></code></td>
					<td><strong><?php echo esc_html(number_format((int) $row->hits)); ?></strong></td>
					<td><?php echo esc_html($row->first_hit); ?></td>
					<td><?php echo esc_html($row->last_hit); ?></td>
					<td><?php echo $row->referrer ? '<a href="' . esc_url($row->referrer) . '" target="_blank" rel="noopener">' . esc_html(wp_parse_url($row->referrer, PHP_URL_HOST)) . '</a>' : '—'; ?></td>
					<td>
						<a href="<?php echo esc_url(add_query_arg(array('prefill' => ltrim($row->url, '/')), remove_query_arg(array('action', 'id', '_wpnonce')))); ?>">Redirect</a> |
						<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $row->id)), 'wp404t_delete')); ?>" onclick="return confirm('Delete this entry?')">Delete</a>
					</td>
				</tr>
			<?php endforeach; else : ?>
				<tr><td colspan="6">No 404s logged yet.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php if ($pages > 1) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			for ($i = 1; $i <= $pages; $i++) {
				if ($i === $page) {
					echo '<span class="tablenav-pages-navspan button disabled">' . $i . '</span> ';
				} else {
					echo '<a class="button" href="' . esc_url(add_query_arg('paged', $i)) . '">' . $i . '</a> ';
				}
			}
			?>
		</div></div>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'clear'), 'wp404t_clear')); ?>" onclick="return confirm('Clear the whole 404 log?')">Clear log</a></p>

		<h2>Add 301 redirect</h2>
		<form method="post">
			<?php wp_nonce_field('wp404t_redirect'); ?>
			<table class="form-table">
				<tr><th><label for="wp404t-from">From (path)</label></th>
					<td><input type="text" id="wp404t-from" name="from" class="regular-text" value="<?php echo esc_attr($prefill); ?>" placeholder="/old-page" required></td></tr>
				<tr><th><label for="wp404t-to">To (URL)</label></th>
					<td><input type="url" id="wp404t-to" name="to" class="regular-text" placeholder="https://example.com/new-page" required></td></tr>
			</table>
			<?php submit_button('Add redirect', 'primary', 'wp404t_add_redirect'); ?>
		</form>

		<h2>Active redirects (<?php echo count(get_option('wp404t_redirects', array())); ?>)</h2>
		<table class="widefat striped">
			<thead><tr><th>From</th><th>To</th><th></th></tr></thead>
			<tbody>
			<?php foreach ((array) get_option('wp404t_redirects', array()) as $from => $to) : ?>
				<tr>
					<td><code><?php echo esc_html($from); ?></code></td>
					<td><a href="<?php echo esc_url($to); ?>" target="_blank" rel="noopener"><?php echo esc_html($to); ?></a></td>
					<td><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'del_redirect', 'rfrom' => $from)), 'wp404t_redirect')); ?>">Remove</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
