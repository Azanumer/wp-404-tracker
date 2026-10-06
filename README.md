# 404 Tracker & Redirects

A tiny WordPress plugin that logs every 404 hit — URL, referrer, hit count, first and last seen — and lets you create 301 redirects in one click. Broken backlinks and mistyped URLs quietly bleed SEO juice; this shows you exactly which dead URLs matter.

## Features

- **404 log** — one row per dead URL with hit counter, referrer host, first/last seen timestamps, sortable by hits (worst offenders first)
- **One-click 301 redirects** — create a redirect straight from a log row; paths matched trailing-slash-agnostic
- **Redirect manager** — list and remove all active 301s from the same screen
- **Clear log / delete entries** — keep the table small on busy sites
- **Filter hook** — `wp404t_exclude_patterns`: pass PCRE patterns to skip URLs from logging (e.g. cache-warmer noise)

## Install

1. Copy `wp-404-tracker.php` and `uninstall.php` into `wp-content/plugins/wp-404-tracker/`
2. Activate in **Plugins** — the database table is created automatically
3. Open **Tools → 404 Tracker**

## Usage

Visit a non-existent URL on your site, then check the log. Click **Redirect** next to a row, enter the destination URL, and the 301 is live immediately — no .htaccess edits, no extra plugin suite.

```php
// functions.php — skip logging for noisy bot paths
add_filter('wp404t_exclude_patterns', function ($patterns) {
	$patterns[] = '#^/wp-content/cache/#';
	$patterns[] = '#\.map$#';
	return $patterns;
});
```

Redirects are stored in the `wp404t_redirects` option and served at `template_redirect` priority 1 (before WordPress renders the 404). Uninstalling drops the table and the option.

Requires WordPress 5.0+ and PHP 7.4+. GPLv2 or later (WordPress plugin convention).
