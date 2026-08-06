# WP Remote SEO Bridge

**Version:** 1.5.0  
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

Located at **WP Admin > Settings > Remote SEO Bridge**.

Shows:
- Current API token (click to select all)
- Correct request header format
- Detected SEO plugin (Rank Math, Yoast, or none)
- Base REST URL for all endpoints
- Token regeneration checkbox
- Token expiry timer (hours, 0 = never)
- Webhook URL for write notifications
- Per-endpoint enable/disable toggles
- Audit log (last 50 entries)

---

## Endpoints

All endpoints are under: `https://yoursite.com/wp-json/wseb/v1/`

### GET /ping

Confirms the bridge is reachable and the token is valid. Use this at the start of any push script to verify connectivity before making writes.

**Response:**
```json
{
  "success": true,
  "version": "1.5.0",
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
  "plugin_version": "1.5.0"
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

## Audit Log

Every API call is logged to `{prefix}_wseb_audit_log` with timestamp, endpoint, method, scope, status, IP, and note. Last 50 entries visible in the admin panel.

---

## Changelog

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
