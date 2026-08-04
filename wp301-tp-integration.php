<?php
/**
 * Plugin Name:  WP 301 Redirects Pro — TranslatePress Integration
 * Plugin URI:   https://github.com/hellomahfuzpro/wp301-tp-integration
 * Description:  Applies WP 301 Redirects Pro rules (exact + regex) to all TranslatePress translated language URLs.
 * Version:      2.0.0
 * Author:       Mahfuz
 * Author URI:   https://github.com/hellomahfuzpro
 * License:      GPL-2.0+
 * Requires WP:  5.0
 * Requires PHP: 7.4
 *
 * Tested with:  WP 301 Redirects Pro (latest), TranslatePress Multilingual 3.2.6+
 */

defined('ABSPATH') || exit;

/**
 * Get active TranslatePress language configuration.
 *
 * Reads trp_settings and builds slug↔locale maps respecting
 * custom URL slugs and the "add subdirectory to default language" setting.
 *
 * @return array{
 *   default: string,
 *   slugs: string[],
 *   slug_to_locale: array<string,string>,
 *   locale_to_slug: array<string,string>,
 * }
 */
function wptp_get_languages(): array {
	static $cache = null;
	if ($cache !== null) {
		return $cache;
	}

	if (!class_exists('TRP_Translate_Press')) {
		return $cache = [
			'default'         => '',
			'slugs'           => [],
			'slug_to_locale'  => [],
			'locale_to_slug'  => [],
		];
	}

	$trp      = TRP_Translate_Press::get_trp_instance();
	$settings = $trp->get_component('settings');
	$all      = $settings->get_settings();

	if (empty($all)) {
		return $cache = [
			'default'         => '',
			'slugs'           => [],
			'slug_to_locale'  => [],
			'locale_to_slug'  => [],
		];
	}

	$default    = $all['default-language'] ?? '';
	$published  = $all['publish-languages'] ?? [];
	$url_slugs  = $all['url-slugs'] ?? [];
	$add_subdir = $all['add-subdirectory-to-default-language'] ?? 'no';

	$slug_to_locale = [];
	$locale_to_slug = [];
	$active_slugs   = [];

	foreach ($published as $locale) {
		// Respect "add subdirectory to default language" setting
		if ($locale === $default && $add_subdir !== 'yes') {
			continue;
		}

		// Use custom URL slug if defined, otherwise fall back to 2-letter prefix
		$slug = $url_slugs[$locale] ?? strtok($locale, '_');

		$slug_to_locale[$slug] = $locale;
		$locale_to_slug[$locale] = $slug;
		$active_slugs[] = $slug;
	}

	return $cache = [
		'default'        => $default,
		'slugs'          => $active_slugs,
		'slug_to_locale' => $slug_to_locale,
		'locale_to_slug' => $locale_to_slug,
	];
}

/**
 * Build the final destination URL for a matched redirect rule.
 *
 * If the destination is already an absolute URL, return it as-is.
 * Otherwise prepend the language slug so the redirect stays within
 * the same translated subdirectory.
 *
 * @param string $dest      Raw destination from the redirect rule.
 * @param string $locale    Full locale code (e.g. fr_FR).
 * @param array  $languages Output of wptp_get_languages().
 * @return string
 */
function wptp_build_destination(string $dest, string $locale, array $languages): string {
	// Absolute external URL — pass through unchanged
	if (preg_match('#^https?://#i', $dest)) {
		return $dest;
	}

	// Get the correct URL slug for this locale
	$slug = $languages['locale_to_slug'][$locale] ?? strtok($locale, '_');

	return '/' . $slug . '/' . ltrim($dest, '/');
}

/**
 * Apply WP 301 Redirects Pro rules to translated URLs.
 *
 * Hooks into template_redirect at priority 1 (before the built-in
 * 301 plugin runs at default priority) so translated URLs are
 * intercepted first.
 *
 * Flow:
 *  1. Parse the language slug from the URL.
 *  2. Extract the remaining path (without the language prefix).
 *  3. Try exact-match rules against the remaining path.
 *  4. If no exact match, try regex rules.
 *  5. On match, rebuild the destination with the language prefix and redirect.
 */
function wptp_handle_redirect(): void {
	if (is_admin()) {
		return;
	}

	$languages = wptp_get_languages();
	if (empty($languages['slugs'])) {
		return;
	}

	// ── Parse request URI ──────────────────────────────────────
	$uri = rtrim(urldecode(strtok($_SERVER['REQUEST_URI'] ?? '', '?')), '/');

	if (empty($uri)) {
		return;
	}

	// Build regex to match any active language slug as the first segment
	$quoted = array_map('preg_quote', $languages['slugs']);
	$lang_pattern = implode('|', $quoted);

	// Captures: group 1 = language slug, group 2 = remaining path
	if (!preg_match("#^/({$lang_pattern})(?:/(.*))?$#ui", $uri, $m)) {
		return;
	}

	$lang_slug = $m[1];
	$path      = !empty($m[2]) ? '/' . trim($m[2], '/') : '';

	// No sub-path to redirect — nothing to do
	if ($path === '' || $path === '/') {
		return;
	}

	// Resolve the full locale from the URL slug
	$locale = $languages['slug_to_locale'][$lang_slug] ?? null;
	if (!$locale) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'wf301_redirect_rules';

	// ── Step 1: Exact-match redirects ──────────────────────────
	$variants = array_unique([
		$path,
		$path . '/',
		rtrim($path, '/'),
	]);

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
		$final = wptp_build_destination(trim($rule->url_to), $locale, $languages);
		$code  = in_array((int) $rule->type, [301, 302, 307], true) ? (int) $rule->type : 301;
		wp_redirect($final, $code);
		exit;
	}

	// ── Step 2: Regex redirects ────────────────────────────────
	$rules = $wpdb->get_results(
		"SELECT * FROM {$table}
		 WHERE status = 'enabled' AND regex = 'enabled'
		 ORDER BY position ASC"
	);

	if (!$rules) {
		return;
	}

	foreach ($rules as $rule) {
		$pattern = stripslashes(trim($rule->url_from));

		// Normalise: ensure pattern starts with /
		if ($pattern !== '' && $pattern[0] !== '/') {
			$pattern = '/' . $pattern;
		}

		// Suppress warnings from malformed user regex
		$matched = @preg_match('~' . $pattern . '~iu', $path, $matches);
		if ($matched !== 1) {
			continue;
		}

		$dest = trim($rule->url_to);

		// Replace named captures: [name]
		foreach ($matches as $key => $value) {
			if (is_string($key)) {
				$dest = str_replace("[$key]", $value, $dest);
			}
		}

		// Replace numbered captures: $1…$9 and [1]…[9]
		for ($i = 1; $i <= 9; $i++) {
			if (isset($matches[$i])) {
				$dest = str_replace(["\${$i}", "[{$i}]"], $matches[$i], $dest);
			}
		}

		$final = wptp_build_destination($dest, $locale, $languages);
		$code  = in_array((int) $rule->type, [301, 302, 307], true) ? (int) $rule->type : 301;
		wp_redirect($final, $code);
		exit;
	}
}

// ── Bootstrap ──────────────────────────────────────────────────
add_action('template_redirect', 'wptp_handle_redirect', 1);
