<?php
/**
 * Plugin Name:  WP 301 Redirects Pro — TranslatePress Integration
 * Plugin URI:   https://github.com/hellomahfuzpro/wp301-tp-integration
 * Description:  Applies WP 301 Redirects Pro rules (exact + regex) to all TranslatePress translated language URLs.
 * Version:      2.1.0
 * Author:       Mahfuz
 * Author URI:   https://github.com/hellomahfuzpro
 * License:      GPL-2.0+
 * Requires WP:  5.0
 * Requires PHP: 7.4
 *
 * Tested with:  WP 301 Redirects Pro 5.x, TranslatePress Multilingual 3.2.6+
 */

defined('ABSPATH') || exit;

/* ────────────────────────────────────────────────────────────────
 *  Helper: get TranslatePress language configuration
 * ──────────────────────────────────────────────────────────────── */

function wptp_get_languages(): array {
	static $cache = null;
	if ($cache !== null) {
		return $cache;
	}

	if (!class_exists('TRP_Translate_Press')) {
		return $cache = wptp_empty_lang();
	}

	$trp      = TRP_Translate_Press::get_trp_instance();
	$settings = $trp->get_component('settings');
	$all      = $settings->get_settings();

	if (empty($all)) {
		return $cache = wptp_empty_lang();
	}

	$default    = $all['default-language'] ?? '';
	$published  = $all['publish-languages'] ?? [];
	$url_slugs  = $all['url-slugs'] ?? [];
	$add_subdir = $all['add-subdirectory-to-default-language'] ?? 'no';

	$slug_to_locale = [];
	$locale_to_slug = [];
	$active_slugs   = [];

	foreach ($published as $locale) {
		if ($locale === $default && $add_subdir !== 'yes') {
			continue;
		}
		$slug = $url_slugs[$locale] ?? strtok($locale, '_');
		$slug_to_locale[$slug]   = $locale;
		$locale_to_slug[$locale] = $slug;
		$active_slugs[]          = $slug;
	}

	return $cache = [
		'default'        => $default,
		'slugs'          => $active_slugs,
		'slug_to_locale' => $slug_to_locale,
		'locale_to_slug' => $locale_to_slug,
	];
}

function wptp_empty_lang(): array {
	return ['default' => '', 'slugs' => [], 'slug_to_locale' => [], 'locale_to_slug' => []];
}

/* ────────────────────────────────────────────────────────────────
 *  Helper: build final redirect destination with language prefix
 * ──────────────────────────────────────────────────────────────── */

function wptp_build_destination(string $dest, string $locale, array $lang): string {
	if (preg_match('#^https?://#i', $dest)) {
		return $dest;
	}
	$slug = $lang['locale_to_slug'][$locale] ?? strtok($locale, '_');
	return '/' . $slug . '/' . ltrim($dest, '/');
}

/* ────────────────────────────────────────────────────────────────
 *  Main redirect handler
 * ──────────────────────────────────────────────────────────────── */

function wptp_handle_redirect(): void {
	if (is_admin() || wp_doing_cron() || wp_doing_ajax()) {
		return;
	}

	// Respect the 301 plugin's own "disable all redirections" flag
	if (class_exists('WF301_setup')) {
		$opts = WF301_setup::get_options();
		if (
			!empty($opts['disable_all_redirections']) ||
			(!empty($opts['disable_for_users']) && is_user_logged_in())
		) {
			return;
		}
	}

	$lang = wptp_get_languages();
	if (empty($lang['slugs'])) {
		return;
	}

	// ── Parse the URL ──────────────────────────────────────────
	$uri = urldecode($_SERVER['REQUEST_URI'] ?? '');
	// Strip query string
	$uri = strtok($uri, '?');
	$uri = rtrim($uri, '/');

	if (empty($uri)) {
		return;
	}

	// Does it start with a known language slug?
	$pattern = '#^/(' . implode('|', array_map('preg_quote', $lang['slugs'])) . ')(?:/(.*))?$#ui';
	if (!preg_match($pattern, $uri, $m)) {
		return;
	}

	$slug   = $m[1];
	$path   = !empty($m[2]) ? '/' . trim($m[2], '/') : '';
	$locale = $lang['slug_to_locale'][$slug] ?? null;

	if (!$locale || $path === '' || $path === '/') {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'wf301_redirect_rules';

	// ── Exact-match: try path, path/, and path without trailing slash ──
	$variants = array_values(array_unique([
		$path,
		$path . '/',
		rtrim($path, '/'),
	]));

	$placeholders = implode(',', array_fill(0, count($variants), '%s'));

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rule = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'enabled'
			   AND (regex = 'disabled' OR regex = '' OR regex IS NULL)
			   AND url_from IN ({$placeholders})
			 ORDER BY position ASC
			 LIMIT 1",
			...$variants
		)
	);
	// phpcs:enable

	if ($rule) {
		wptp_do_redirect(trim($rule->url_to), (int) $rule->type, $locale, $lang);
	}

	// ── Regex rules ────────────────────────────────────────────
	$rules = $wpdb->get_results(
		"SELECT * FROM {$table}
		 WHERE status = 'enabled' AND regex = 'enabled'
		 ORDER BY position ASC"
	);

	if (!$rules) {
		return;
	}

	// Prefer the 301 plugin's own pattern matching if available
	$use_builtin = class_exists('WF301_functions');

	foreach ($rules as $rule) {
		$pattern = stripslashes(trim($rule->url_from));
		if ($pattern !== '' && $pattern[0] !== '/') {
			$pattern = '/' . $pattern;
		}

		$matches = false;

		if ($use_builtin) {
			// Use WF301_functions::format_from_url + wild_compare for maximum
			// compatibility with the plugin's own pattern syntax (fnmatch-style
			// wildcards, {duplicate-slug}, etc.)
			$formatted_from = WF301_functions::format_from_url(ltrim($pattern, '/'));
			$case_insensitive = ($rule->case_insensitive ?? 'enabled') === 'enabled';

			if (WF301_functions::wild_compare($formatted_from, $path, $case_insensitive, $matches)) {
				$dest = trim($rule->url_to);
				if (is_array($matches) && !empty($matches)) {
					foreach ($matches as $k => $v) {
						if (is_string($k)) {
							$dest = str_replace("[$k]", trim($v, ' /'), $dest);
						}
					}
				}
				wptp_do_redirect($dest, (int) $rule->type, $locale, $lang);
			}
		} else {
			// Fallback: plain preg_match
			$matched = @preg_match('~' . $pattern . '~iu', $path, $matches);
			if ($matched !== 1) {
				continue;
			}
			$dest = trim($rule->url_to);
			foreach ($matches as $k => $v) {
				if (is_string($k)) {
					$dest = str_replace("[$k]", $v, $dest);
				}
			}
			for ($i = 1; $i <= 9; $i++) {
				if (isset($matches[$i])) {
					$dest = str_replace(["\${$i}", "[{$i}]"], $matches[$i], $dest);
				}
			}
			wptp_do_redirect($dest, (int) $rule->type, $locale, $lang);
		}
	}
}

/**
 * Send the redirect and exit.
 */
function wptp_do_redirect(string $dest, int $type, string $locale, array $lang): void {
	$final = wptp_build_destination($dest, $locale, $lang);
	$code  = in_array($type, [301, 302, 307, 308], true) ? $type : 301;

	// Mimic 301 plugin cache headers
	if (!headers_sent()) {
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
	}

	wp_redirect($final, $code);
	exit;
}

// ── Bootstrap ──────────────────────────────────────────────────
// Priority 0 on 'init' ensures we run BEFORE the 301 plugin,
// which hooks at 'init' priority 1 (when redirect_init is on)
// or 'template_redirect' priority 1 (when redirect_init is off).
add_action('init', 'wptp_handle_redirect', 0);
