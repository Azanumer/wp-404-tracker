<?php
// Drops the plugin's table and option on uninstall.
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'not_found_log');
delete_option('wp404t_redirects');
