# WP 301 Redirects Pro — TranslatePress Integration

Applies every **WP 301 Redirects Pro** rule (exact + regex) to **all TranslatePress translated URLs** automatically.

## The Problem

WP 301 Redirects Pro only matches against the raw WordPress path — it doesn't know about TranslatePress language prefixes:

```
✅ /old-page  →  /new-page          (default language)
❌ /fr/old-page                       (no redirect!)
❌ /de/old-page                       (no redirect!)
```

## The Fix

This plugin intercepts requests **before** the 301 plugin, strips the language prefix, matches the rule against the remaining path, then rebuilds the destination **with** the correct language prefix:

```
/fr/old-page  →  301 →  /fr/new-page  ✅
/de/old-page  →  301 →  /de/new-page  ✅
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- [WP 301 Redirects Pro](https://wp301redirects.com/)
- [TranslatePress Multilingual](https://wordpress.org/plugins/translatepress-multilingual/) 3.2+

## Installation

### Option A: WordPress Plugin (recommended)

1. Download `wp301-tp-integration.php`
2. Upload to `/wp-content/plugins/wp301-tp-integration/`
3. Activate from **Plugins → Installed Plugins**

### Option B: Theme functions.php

Copy the functions into your child theme's `functions.php` (remove the plugin header).

### Option C: mu-plugin

Copy `wp301-tp-integration.php` into `/wp-content/mu-plugins/` for auto-loading.

## How It Works

```
Request:  /fr/some-old-page
           │   └── path to match against 301 rules
           └── language slug (resolved to fr_FR via trp_settings)

1. Strip /fr → /some-old-page
2. Match against wf301_redirect_rules table
3. Rule found: /some-old-page → /new-page
4. Rebuild: /fr/new-page
5. 301 redirect
```

### Features

- ✅ **Exact match** rules (with trailing-slash variants)
- ✅ **Regex rules** (named and numbered captures)
- ✅ **Custom URL slugs** — respects `trp_settings['url-slugs']` (e.g. `zh` for `zh_CN`)
- ✅ **Default language subdirectory** — respects `add-subdirectory-to-default-language`
- ✅ **Absolute URL destinations** — pass through unchanged
- ✅ **Priority 1** — runs before the built-in 301 plugin

## v2 Changelog (vs old snippet)

| Issue | Old Code | Fixed |
|-------|----------|-------|
| **Corrupted regex** | `#^/[a-z]{2,3}/\|\|\|\|#i\|\|\|\|` | `#^/({$lang_pattern})…$#ui` |
| **Custom URL slugs** | Only `strtok($l, '_')` | Reads `url-slugs` setting, falls back to 2-letter prefix |
| **Default lang prefix** | Always redirects | Respects `add-subdirectory-to-default-language` |
| **Slug → locale mapping** | Not done | Reverse-maps slug to locale for correct destination |
| **PHP 7.4+ typed returns** | None | Added return types, strict checks |

## License

GPL-2.0+
