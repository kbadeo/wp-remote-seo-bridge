# WP Remote SEO Bridge

**Version:** 1.9.3  
**Author:** Karl Badeo  
**License:** MIT  
**Requires PHP:** 7.4+  
**Namespace:** `wseb/v1`  
**Plugin URI:** https://github.com/kbadeo/wp-remote-seo-bridge

---

## Overview

WP Remote SEO Bridge is a secure WordPress REST API plugin that enables bulk SEO meta and schema injection from external scripts. It is used to push per-page schema markup and SEO meta titles/descriptions to WordPress sites remotely without logging into WP Admin.

It integrates with:
- **Header and Footer Scripts by Anand Kumar** for schema injection (per-page and sitewide)
- **Rank Math** and **Yoast SEO** for SEO meta title, description, and focus keyword writes
- **LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache** for cache purging after writes

---

## Authentication

Every request must include the API token in the request header:

```
X-WSEB-Token: YOUR_TOKEN_HERE
```

The back-compat alias `X-SEO-Bridge-Token` is also accepted for older scripts.

The token is auto-generated on plugin activation and is visible in **WP Admin > Settings > Remote SEO Bridge**.

Token behaviour:
- Tokens never expire by default (expiry hours = 0)
- An optional expiry timer can be set in the admin panel (hours from token creation)
- Tokens can be regenerated at any time from the admin panel

---

## Admin Panel

Located at **WP Admin > Settings > Remote SEO Bridge**, split across two tabs.

**Settings tab:**
- Current API token (click to select all)
- Correct request header format
- Detected SEO plugin (Rank Math, Yoast, or none)
- Base REST URL for all endpoints
- Token regeneration checkbox
- Token expiry timer (hours, 0 = never)
- Webhook URL for write notifications
- Term Schema ACF field name setting
- llms.txt (AI Visibility) one-click Enable/Disable
- Taxonomy Term Schema (ACF) create/sync button and front-end output snippet
- Per-endpoint enable/disable toggles
- Audit log (last 50 entries)

**SEO Exporter tab:** exports SEO metadata to CSV — see below.

---

## SEO Exporter (admin tab)

A CSV export tool merged in from the standalone SEO Data Exporter plugin, on its own tab so it doesn't need a separate plugin install. Unlike the rest of the bridge (Yoast/Rank Math only), the exporter also reads **All in One SEO**, plus a raw-postmeta fallback (`_seo_title`/`_seo_description`/`_canonical`) when no supported plugin is detected — since it's read-only, covering more plugins here doesn't carry the same risk as the write endpoints.

Select post types and/or taxonomies (all checked by default), optionally include the sitewide schema block as its own row, and click **Export to CSV**. A live "Ready to export N rows" counter updates as you check/uncheck things, and the file downloads as a UTF-8 CSV (with BOM, so Excel opens it correctly) — no REST call needed, this is a plain wp-admin download.

**Exported columns:** `ID`, `Type` (`post`/`term`/`sitewide`), `Post Type / Taxonomy`, `Title`, `URL`, `Status`, `SEO Title`, `SEO Description`, `Canonical URL`, `Robots Noindex`, `Robots Nofollow`, `OG Title`, `OG Description`, `OG Image`, `Twitter Title`, `Twitter Description`, `Twitter Image`, `Focus Keyword`, `Cornerstone` (Yoast only), `Schema`, `SEO Plugin`.

The `Schema` column reuses the bridge's own schema helpers (`wseb_read_post_schema()` / `wseb_read_term_schema()`), so it reflects exactly what `/schema` would read back for that post or term. The sitewide schema, if included, gets its own row (`Type: sitewide`, `Title: Sitewide Schema`) rather than being attached to any single post/term, since it applies site-wide.

---

## Endpoints

All endpoints are under: `https://yoursite.com/wp-json/wseb/v1/`

### GET /ping

Confirms the bridge is reachable and the token is valid. Use this at the start of any push script to verify connectivity before making writes.

**Response:**
```json
{
  "success": true,
  "version": "1.9.3",
  "site": "https://yoursite.com",
  "seo_plugin": "rankmath",
  "php": "8.1.0"
}
```

---

### GET /health

Returns environment information including SEO plugin, HFS plugin variant, WooCommerce status, WordPress version, PHP version, and database version.

**Response:**
```json
{
  "success": true,
  "seo_plugin": "rankmath",
  "hfs_plugin": "shfs",
  "woocommerce": "not active",
  "wp_version": "6.5.0",
  "php_version": "8.1.0",
  "db_version": "8.0.0",
  "plugin_version": "1.9.3"
}
```

---

### GET /scan

Lists all published posts, pages, products, and custom post types with their post ID, URL, post type, post title, SEO title, SEO description, and detected SEO plugin.

Use this to build a URL-to-post-ID map before pushing schemas. There is no /lookup endpoint. /scan is the correct way to resolve post IDs.

**Query parameters:**
- `post_type` — filter by post type slug (comma-separated), default `any`
- `per_page` — results per page, max 500, default 200
- `page` — page number for pagination, default 1

**Response:**
```json
{
  "success": true,
  "total": 34,
  "page": 1,
  "per_page": 200,
  "total_pages": 1,
  "posts": [
    {
      "id": 12,
      "url": "https://yoursite.com/about-us/",
      "post_type": "page",
      "post_title": "About Us",
      "seo_title": "About Us | Site Name",
      "seo_desc": "Description here",
      "seo_plugin": "rankmath"
    }
  ]
}
```

---

### GET /scan-terms

Lists all terms in all public taxonomies (or a specified taxonomy) with term ID, taxonomy, name, slug, URL, post count, SEO title, SEO description, and detected SEO plugin.

**Query parameters:**
- `taxonomy` — filter by taxonomy slug (comma-separated), default `any`

---

### POST /meta

Writes SEO title, description, and/or focus keyword to one or more posts, pages, or products. Accepts a single object or an array of objects. Supports Rank Math and Yoast SEO.

**Payload (single or batch array):**
```json
[
  { "post_id": 12, "title": "Title One", "description": "Desc one.", "focus_keyword": "keyword" },
  { "post_id": 15, "title": "Title Two", "description": "Desc two." }
]
```

All three fields are optional — only include what you want to update.

---

### POST /meta-terms

Writes SEO title, description, and/or focus keyword to taxonomy terms.

**Payload:**
```json
[
  {
    "term_id": 5,
    "taxonomy": "product_cat",
    "title": "Category SEO Title",
    "description": "Category meta description.",
    "focus_keyword": "category keyword"
  }
]
```

---

### GET /llms-settings

Reads the llms.txt (AI visibility) configuration for whichever SEO plugin is detected.

**Yoast SEO response:**
```json
{
  "success": true,
  "seo_plugin": "yoast",
  "enabled": true,
  "selection_mode": "auto",
  "pages": {
    "about_us_page": null,
    "contact_page": null,
    "terms_page": null,
    "privacy_policy_page": null,
    "shop_page": null,
    "other_included_pages": []
  }
}
```

**Rank Math response:**
```json
{
  "success": true,
  "seo_plugin": "rankmath",
  "enabled": true,
  "post_types": ["post", "page", "product"],
  "taxonomies": ["category", "product_cat"],
  "limit": 50,
  "extra_content": ""
}
```

If neither plugin is active: `{ "seo_plugin": "none", "enabled": false }`.

---

### POST /llms-settings

Updates the llms.txt configuration for whichever SEO plugin is detected. All fields are optional — only send what you want to change. Fields specific to the other plugin are ignored (e.g. sending `post_types` on a Yoast site does nothing).

Writes directly via `update_option()`, which both plugins already watch (`update_option_wpseo` for Yoast, module state for Rank Math) to regenerate or remove the actual `/llms.txt` file — no need to trigger that separately.

**Common:**
- `enabled` — boolean. Turns the llms.txt feature on/off (Yoast: `enable_llms_txt` in the `wpseo` option; Rank Math: adds/removes the `llms-txt` module in `rank_math_modules`).

**Yoast-only:**
- `selection_mode` — `"auto"` or `"manual"`
- `pages` — object with `about_us_page`, `contact_page`, `terms_page`, `privacy_policy_page`, `shop_page` (post IDs), and `other_included_pages` (array of post IDs)

**Rank Math-only:**
- `post_types` — array of post type slugs to include
- `taxonomies` — array of taxonomy slugs to include
- `limit` — max links per post type/taxonomy
- `extra_content` — free text/links appended to the file manually

**Important — Rank Math has no "auto" mode.** Unlike Yoast, which populates a sensible file with zero extra config, Rank Math's `add_post_types_data()`/`add_taxonomies_data()` silently skip any section left unconfigured — enabling the module alone produces a file with only the site title, an optional sitemap link, and `extra_content`, with no actual page listing. To avoid that trap, sending `{"enabled": true}` with no explicit `post_types`/`taxonomies` (and none already configured) auto-seeds sensible defaults — `post`, `page`, and `product`/`product_cat` if WooCommerce is active. Pass your own `post_types`/`taxonomies` in the same call to skip the defaults and use exactly what you specify.

**Payload (Rank Math example):**
```json
{
  "enabled": true,
  "post_types": ["post", "page", "product"],
  "taxonomies": ["product_cat"],
  "limit": 100,
  "extra_content": "Company overview: https://yoursite.com/about"
}
```

**Payload (Yoast example):**
```json
{
  "enabled": true,
  "selection_mode": "manual",
  "pages": { "about_us_page": 12, "contact_page": 15 }
}
```

Response is the same shape as `GET /llms-settings`, reflecting the state after the write.

---

### GET /llms-txt

Reads the raw `llms.txt` file currently on disk at the site root (if any), independent of any SEO plugin.

**Response:**
```json
{
  "success": true,
  "exists": true,
  "writable": true,
  "content": "# Site Name\n\n> ...\n"
}
```

---

### POST /llms-txt

Pushes fully custom `llms.txt` content, written directly to the site root via `WP_Filesystem` — this is the option to reach for when neither Yoast's page-selection nor Rank Math's "Additional Content" field (see `POST /llms-settings` above) is expressive enough and you want full control over the file.

This bypasses both plugins' generators rather than fighting them: standard WordPress `.htaccess`/nginx rewrite rules always serve a real file directly from disk before a request ever reaches WordPress's own routing, so a physical `llms.txt` here wins regardless of which SEO plugin is active. Yoast's own generator is explicitly designed to cooperate with this — it hashes the content it writes, and backs off without overwriting if the file on disk no longer matches that hash.

After a successful write, this endpoint also calls the same code as `POST /llms-settings` with `{"enabled": false}` to turn off the detected plugin's own llms.txt toggle, so there's no stale "managed by us" state left in its admin UI or cron.

A backup of the previous file content is saved before every write (last 5 in `wseb_llms_txt_backup` in wp_options).

**Requires filesystem write access to the site root** — same requirement Yoast's own llms.txt feature has. If unavailable (e.g. certain managed-hosting or FTP-only setups), the write fails with a 500 and a clear error rather than silently doing nothing.

**Payload:**
```json
{
  "content": "# Site Name\n\n> A short description of the site.\n\n## Pages\n\n- [About](https://yoursite.com/about/): About page\n"
}
```

---

### POST /schema

Injects a JSON-LD schema block into a post/page, **or** a taxonomy term (category, product category, tag, etc). Which target is used depends on the payload shape.

**Posts/pages (via HFS)**

Writes to both `shfs_all_post_meta` (synth_header_script) and `_inpost_head_script` so the schema is visible in the HFS admin metabox and outputs correctly on the front end.

A backup of the previous schema is saved before every write (last 5 per post in `wseb_schema_backup_{post_id}` in wp_options).

**Payload:**
```json
{
  "post_id": 12,
  "schema": "<script type=\"application/ld+json\">\n{ ... }\n</script>"
}
```

**Taxonomy terms / product categories (via ACF field)**

The Anand Kumar HFS plugin does not attach to term/category archive pages, so term schema is written instead to an ACF field on the term — `header_script` by default. Configurable in **WP Admin > Settings > Remote SEO Bridge** ("Term Schema ACF Field") if your ACF field is named differently.

If ACF is active when the bridge plugin is activated, the field group is created automatically with a location rule for every public taxonomy (categories, product categories, tags, etc). If ACF is installed later, or new taxonomies are added afterwards, click "Create / Sync ACF Field" on the settings page to (re)create it — safe to run any time, it upserts by field group key rather than duplicating. Falls back to plain term meta if ACF isn't active at all.

Because every theme wires up its `<head>` output differently, the settings page also shows a ready-to-paste `wp_head` snippet (with the configured field name baked in) to output the pushed schema on term archive pages — there is no automatic front-end injection for terms.

A backup of the previous schema is saved before every write (last 5 per term in `wseb_term_schema_backup_{taxonomy}_{term_id}` in wp_options).

**IMPORTANT:** Use /scan-terms first to get the term ID for each taxonomy URL.

**Payload:**
```json
{
  "term_id": 5,
  "taxonomy": "product_cat",
  "schema": "<script type=\"application/ld+json\">\n{ ... }\n</script>"
}
```

**IMPORTANT:** Use /scan first to get the post ID for each URL. There is no /lookup endpoint.

---

### POST /sitewide-schema

Writes a sitewide JSON-LD schema block to the global header field of the Anand Kumar HFS plugin. This schema outputs on every page of the site.

Auto-detects the correct wp_options key across all known HFS variants: `jamify_hfs_insert_header`, `shfs_insert_header`, `hfs_insert_header`. If none found, defaults to `shfs_insert_header`.

All existing `<script type="application/ld+json">` blocks are stripped from the global header before writing. Non-schema content is preserved.

A backup of the previous sitewide schema is saved before every write (last 5 in `wseb_sitewide_schema_backup` in wp_options).

**Payload:**
```json
{
  "schema": "<script type=\"application/ld+json\">\n{ ... }\n</script>"
}
```

---

### POST /purge-cache

Purges the site cache. Supports LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache. If `post_id` is provided, purges only that post. If omitted, purges the full site.

**Payload (optional):**
```json
{ "post_id": 12 }
```

---

## Schema Write Behaviour

### Per-page (/schema)

1. Reads existing value from `shfs_all_post_meta` and `_inpost_head_script`
2. Backs up to `wseb_schema_backup_{post_id}` (last 5 snapshots)
3. Strips all ld+json blocks from existing value
4. Writes new schema block to both `shfs_all_post_meta` and `_inpost_head_script`
5. WordPress handles serialization automatically — never call serialize() manually
6. Purges cache for that post

### Sitewide (/sitewide-schema)

1. Detects correct HFS wp_options key via `wseb_detect_hfs_option_key()`
2. Backs up to `wseb_sitewide_schema_backup` (last 5 snapshots)
3. Strips all ld+json blocks from global header value
4. Writes new schema block alongside any preserved non-schema content
5. Purges full site cache

---

## Push Script Pattern

```python
# 1. Ping to confirm bridge is reachable
GET /ping

# 2. Push sitewide schema
POST /sitewide-schema  {"schema": "..."}

# 3. Scan to build URL -> post_id map
GET /scan?per_page=500

# 4. For each page push per-page schema
POST /schema  {"post_id": 12, "schema": "..."}
```

---

## Installing and project layout

The plugin is now several files, so install it as a folder (or a zip of the folder), not by copying
`wp-remote-seo-bridge.php` on its own. The plugin will not load without `includes/`.

```
wp-remote-seo-bridge/
  wp-remote-seo-bridge.php   plugin header, constants, loads the modules below
  includes/
    activation.php           activation callback and the audit-log table
    helpers.php              settings access, token auth, response helpers
    seo-meta.php             SEO plugin detection, post and term meta read/write
    llms.php                 llms.txt settings and the custom llms.txt push
    schema.php               JSON-LD storage for posts, terms and the sitewide header
    cache.php                cache purging after a push
    admin-page.php           Settings > Remote SEO Bridge (settings tab)
    exporter.php             the CSV exporter tab
    rest-routes.php          REST routes for the wseb/v1 namespace
  tests/auth-test.php        auth regression test (no WordPress needed)
```

Every route's `permission_callback` comes from `wseb_permission( 'endpoint_key' )` in
`includes/helpers.php`, which checks the endpoint toggle and the token. Add new routes with it.

### Running the tests

```
php tests/auth-test.php
```

It stubs the few WordPress functions the plugin uses, loads the plugin, and checks every route's
`permission_callback` against seven request cases (no token, wrong token, right token, legacy header, no token configured,
endpoint disabled, expired token). It also checks that a copy of the plugin with a module missing stays inactive instead of crashing. It
exits 1 on any failure. **It covers the permission check only.** Route handlers, activation, the admin
page and the exporter are not exercised, and `add_action` is stubbed so no hook callbacks run. Update
the expected route count in the script when you add a route (it counts `register_rest_route` calls,
which is 13; some URLs have separate GET and POST registrations).

---

## Release checklist

The version appears in four places: the plugin header and `WSEB_VERSION` in `wp-remote-seo-bridge.php`,
`**Version:**` at the top of this file, and a new entry in the changelog below. Update all four, and
run `php tests/auth-test.php`.

---

## Audit Log

Every API call is logged to `{prefix}_wseb_audit_log` with timestamp, endpoint, method, scope, status, IP, and note. Last 50 entries visible in the admin panel.

---

## Changelog

### 1.9.3
- **Structure:** split the single 1,836-line plugin file into `includes/` modules (activation, helpers, seo-meta, llms, schema, cache, admin-page, exporter, rest-routes). No behaviour change: the same 51 functions exist and every route answers every auth case exactly as in 1.9.2. **Install the whole folder, not just the main file.** If `includes/` is missing the plugin stays inactive and shows an admin notice naming the missing files, instead of crashing the site.
- **Tests:** added `tests/auth-test.php`, which checks the toggle and token handling on all 13 routes without needing WordPress.

### 1.9.2
- **Hardening:** every REST route now gets its endpoint toggle and token check from one shared `permission_callback` (`wseb_permission()`), replacing 13 hand-written checks inside the handlers and the `__return_true` permission callbacks. A route added later can no longer be left open by forgetting the check. Responses are unchanged (401 for a bad token or a disabled endpoint).

### 1.9.1
- **Fix:** SEO plugin detection now prefers an ACTIVE plugin (version constant defined) over leftover options. Previously a leftover `rank-math-options-general` option made Yoast sites report `rankmath`, so `/meta` wrote `rank_math_*` keys that Yoast ignored (success:true, nothing changed live).

### 1.9.0
- Merged in the standalone SEO Data Exporter plugin as a "SEO Exporter" tab on the admin settings page, so a separate plugin install is no longer needed
- Adds All in One SEO support and a raw-postmeta fallback for export purposes (read-only, broader than the write endpoints' Yoast/Rank Math scope) via `wseb_exporter_detect_plugin()`
- Added a `Schema` CSV column reusing the bridge's own `wseb_read_post_schema()`/`wseb_read_term_schema()`, plus an optional sitewide-schema row (`Type: sitewide`) via a new checkbox
- CSV download via `admin-post.php?action=wseb_export_download`, with a live row-count preview via `wp_ajax_wseb_export_counts` — a plain wp-admin download, no REST call
- Admin page restructured into tabs (`?tab=settings` / `?tab=exporter`); existing settings content unchanged, just moved under the Settings tab

### 1.8.1
- **Bugfix:** Rank Math's General Settings option name is `rank-math-options-general` (hyphenated CMB2 options-page key, per `RankMath\Settings::__construct()`), not `rank_math_general` as previously assumed — the wrong option name meant `llms_post_types`/`llms_taxonomies`/`llms_limit`/`llms_extra_content` writes silently went nowhere the plugin ever read from, leaving the Edit llms.txt screen showing nothing selected even after "enabling" via the bridge
- Fixed in `wseb_detect_seo_plugin()` and both `wseb_get_llms_settings()`/`wseb_set_llms_settings()` — this only affected llms.txt settings; the meta/schema endpoints use separate, independently-correct option/meta keys (`rank_math_title`, `rank_math_term_meta`, `rank_math_modules`) and were unaffected

### 1.8.0
- Added an "llms.txt (AI Visibility)" panel to the admin settings page with a one-click Enable/Disable button, running through the same `wseb_get_llms_settings()`/`wseb_set_llms_settings()` code as `POST /llms-settings` — no REST payload needed for the common case of just turning it on or off
- Shows current status (detected plugin, enabled/disabled, and Rank Math's post types/taxonomies/limit or Yoast's selection mode) directly in wp-admin

### 1.7.1
- `POST /llms-settings` now auto-seeds sensible `post_types`/`taxonomies` defaults for Rank Math on first enable (unless explicitly provided), since Rank Math — unlike Yoast — has no "auto" mode and silently skips any section left unconfigured, which previously meant enabling the module alone produced a near-empty file

### 1.7.0
- Added `GET /llms-txt` and `POST /llms-txt` to push a fully custom `llms.txt` file, written directly to the site root via `WP_Filesystem` — for when neither Yoast's page-selection nor Rank Math's "Additional Content" field is expressive enough
- A physical file at the site root wins over both plugins' generators at the webserver level (standard `.htaccess`/nginx rewrite rules serve a real file before WordPress routing even runs); the write also disables the detected plugin's own llms.txt toggle afterwards to avoid a stale "managed by us" state
- Backup before write for the raw file, mirroring existing backup behaviour (last 5 in `wseb_llms_txt_backup`)
- Added `wseb_write_llms_txt()`, `wseb_read_llms_txt()`, `wseb_backup_llms_txt()`, `wseb_init_filesystem()` helpers and the `llms_txt` endpoint toggle

### 1.6.0
- Added `GET /llms-settings` and `POST /llms-settings` to read/update the detected SEO plugin's llms.txt (AI visibility) configuration
- Yoast: toggles `enable_llms_txt` in the `wpseo` option, and page-selection settings in `wpseo_llmstxt` (`llms_txt_selection_mode`, `about_us_page`, `contact_page`, `terms_page`, `privacy_policy_page`, `shop_page`, `other_included_pages`)
- Rank Math: toggles the `llms-txt` module in `rank_math_modules`, and field settings in `rank-math-options-general` (`llms_post_types`, `llms_taxonomies`, `llms_limit`, `llms_extra_content`)
- Added `wseb_get_llms_settings()` / `wseb_set_llms_settings()` helpers and the `llms_settings` endpoint toggle

### 1.5.0
- `/schema` now accepts `term_id` + `taxonomy` to push schema to taxonomy terms (categories, product categories, tags, etc), writing to an ACF field (`header_script` by default) instead of the HFS plugin, which doesn't attach to term archive pages
- Added `wseb_write_term_schema()`, `wseb_read_term_schema()`, `wseb_backup_term_schema()` helpers
- Added "Term Schema ACF Field" setting in the admin panel to configure the ACF field name
- Backup before write for term schema, mirroring the existing post schema backup behaviour (last 5 per term)
- Added `wseb_sync_acf_term_schema_field()`: auto-creates/syncs the ACF field group (with a location rule per public taxonomy) on plugin activation if ACF is active, plus a "Create / Sync ACF Field" admin button for installing ACF later or after adding new taxonomies
- Added a copy-paste `wp_head` snippet on the settings page to output term schema in the front end, since theme structure varies per site and can't be injected automatically

### 1.4.0
- Fixed `wseb_write_post_schema` to write to both `shfs_all_post_meta` and `_inpost_head_script`
- Removed manual `serialize()` call — double-serialization was corrupting data
- Fixed `wseb_write_sitewide_schema` to detect all known HFS option key variants via new `wseb_detect_hfs_option_key()` helper
- Fixed `wseb_read_post_schema` to check both meta keys with fallback
- Re-added backup before every write (dropped in v1.3.0): `wseb_backup_post_schema()` and `wseb_backup_sitewide_schema()`
- Added this README

### 1.3.0
- Added `/scan-terms` and `/meta-terms` endpoints for taxonomy term SEO
- Added `wseb_get_term_seo()` and `wseb_set_term_seo()` helpers
- Added `/health` endpoint
- Renamed namespace from `seo-bridge/v1` to `wseb/v1`
- Back-compat alias: `X-SEO-Bridge-Token` still accepted
- Backup before write dropped in this version (restored in 1.4.0)

### 1.2.0
- Added `/sitewide-schema` endpoint
- Scoped tokens, self-deactivation timer, audit log, webhook, schema validation
- Backup before overwrite (last 5), per-endpoint toggles, dry-run, cache purge

### 1.1.0
- Added `/sitewide-schema` writing to global HFS head field
- Auto-detects HFS option key, preserves non-schema head scripts

### 1.0.0
- Initial build generalised from SB Peak Bridge
