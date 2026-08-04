# WP 301 Redirects Pro — TranslatePress Integration

Applies every **WP 301 Redirects Pro** rule (exact + regex) to **all TranslatePress translated language URLs** automatically.

## The Problem

WP 301 Redirects Pro matches against the raw request URI — which includes the TranslatePress language prefix:

```
Request:  /fr/old-page
Rule:     /old-page  →  /new-page
Match?    ❌  (/fr/old-page ≠ /old-page)
```

Result: **translated URLs don't get redirected**.

## The Fix

This plugin intercepts the request **before** the 301 plugin runs (hooked at `init` priority 0 vs the 301 plugin's priority 1), strips the language prefix, matches the rule against the remaining path, then rebuilds the destination **with** the correct language prefix:

```
/fr/old-page  →  strip /fr →  match /old-page →  rebuild /fr/new-page →  301  ✅
/de/old-page  →  strip /de →  match /old-page →  rebuild /de/new-page →  301  ✅
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- [WP 301 Redirects Pro](https://wp301redirects.com/) v5.x
- [TranslatePress Multilingual](https://wordpress.org/plugins/translatepress-multilingual/) 3.2+

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

### Why Priority 0 on `init`?

The 301 plugin can hook at either:
- **`init` priority 1** (when "Redirect Init" setting is ON)
- **`template_redirect` priority 1** (default)

Both run AFTER `init` priority 0, so this plugin always intercepts first.

## Installation

### Option A: WordPress Plugin (recommended)

1. Download `wp301-tp-integration.php`
2. Upload to `/wp-content/plugins/wp301-tp-integration/`
3. Activate from **Plugins → Installed Plugins**

### Option B: mu-plugin

Copy `wp301-tp-integration.php` into `/wp-content/mu-plugins/` for auto-loading.

## Features

- ✅ **Exact match** rules (with trailing-slash variants)
- ✅ **Regex rules** — uses the 301 plugin's own `WF301_functions::wild_compare()` for fnmatch-style patterns, `{duplicate-slug}`, etc.
- ✅ **Custom URL slugs** — respects `trp_settings['url-slugs']`
- ✅ **Default language subdirectory** — respects `add-subdirectory-to-default-language`
- ✅ **Absolute URL destinations** — pass through unchanged
- ✅ **Respects 301 plugin settings** — checks `disable_all_redirections` and `disable_for_users`
- ✅ **Cache headers** — matches 301 plugin behavior

## v2.1 Changelog

| Issue | v2.0 | v2.1 Fix |
|-------|------|----------|
| **Hook priority** | `template_redirect` priority 1 — race with 301 plugin | `init` priority 0 — always runs first |
| **Pattern matching** | Custom preg_match only | Uses `WF301_functions::wild_compare()` for fnmatch, case-insensitive, `{duplicate-slug}` support |
| **Settings awareness** | None | Respects `disable_all_redirections`, `disable_for_users` |
| **Redirect types** | 301/302/307 only | Adds 308 support |

## License

GPL-2.0+
