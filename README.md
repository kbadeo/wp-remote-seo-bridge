# WP Remote SEO Bridge

A secure WordPress REST API bridge for remote SEO auditing, meta updates, and schema injection. Plug-and-play across any WordPress site.

## Features

- Auto-detects Yoast SEO or RankMath and writes to the correct fields
- Auto-detects Header and Footer Scripts plugin (Anand Kumar) for schema injection
- Auto-detects and purges LiteSpeed, WP Rocket, W3TC, and WP Super Cache
- Admin token by default, optional scoped read/write tokens
- Self-deactivation timer (set it and forget it on client sites)
- Full audit log with timestamp, endpoint, IP, scope, and status
- Backup before every write operation
- Schema validation catches invalid JSON and unknown properties before they reach the database
- Webhook on write for Make / Zapier / Slack integration
- Dry-run support on update and schema endpoints
- Per-endpoint enable/disable toggles

## Installation

1. Upload `wp-remote-seo-bridge.zip` via **Plugins > Add New > Upload Plugin**
2. Activate the plugin
3. Go to **Settings > Remote SEO Bridge**
4. Copy the auto-generated admin token
5. Use the token in your API calls

## Authentication

Pass the token via header or query parameter:

```
X-SEO-Bridge-Token: your-token-here
```

or

```
?token=your-token-here
```

## Endpoints

Base URL: `https://yoursite.com/wp-json/seo-bridge/v1`

| Endpoint | Method | Scope | Description |
|---|---|---|---|
| `/ping` | GET | read | Connectivity test, site info, detected plugins |
| `/health` | GET | read | WP/PHP versions, sitemap, robots.txt, cache plugins |
| `/scan` | GET | read | All posts and terms with Yoast AND RankMath meta |
| `/taxonomies` | GET | read | All taxonomies with term counts |
| `/meta-scan?post_id=X` | GET | read | Single post full meta decoded |
| `/lookup` | POST | read | Resolve slugs to post/term IDs |
| `/update` | POST | write | Write SEO meta, auto-routes Yoast or RankMath |
| `/schema` | POST | write | Inject schema into header script field |
| `/sitewide-schema` | POST | write | Write sitewide schema to global HFS head field |
| `/options` | GET+POST | admin | Read/write wp_options directly |
| `/purge-cache` | POST | write | Auto-detects and purges all known cache plugins |

## Usage Examples

**Ping:**
```bash
curl -s "https://yoursite.com/wp-json/seo-bridge/v1/ping?token=YOUR_TOKEN"
```

**Health check:**
```bash
curl -s "https://yoursite.com/wp-json/seo-bridge/v1/health?token=YOUR_TOKEN"
```

**Scan all pages:**
```bash
curl -s "https://yoursite.com/wp-json/seo-bridge/v1/scan?token=YOUR_TOKEN"
```

**Update SEO meta (dry run first):**
```bash
curl -s -X POST "https://yoursite.com/wp-json/seo-bridge/v1/update?token=YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  --data @update_payload.json
```

Payload format:
```json
{
  "dry_run": true,
  "updates": [
    {
      "type": "post",
      "id": 123,
      "title": "Your SEO Title | Site Name",
      "description": "Your meta description here."
    },
    {
      "type": "term",
      "id": 45,
      "taxonomy": "category",
      "title": "Category SEO Title | Site Name",
      "description": "Category meta description."
    }
  ]
}
```

**Inject schema:**
```bash
curl -s -X POST "https://yoursite.com/wp-json/seo-bridge/v1/schema?token=YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  --data @schema_payload.json
```

Payload format:
```json
{
  "dry_run": false,
  "page_schemas": [
    {
      "post_id": 123,
      "schema": "<script type=\"application/ld+json\">\n{ \"@context\": \"https://schema.org\", \"@type\": \"WebPage\" }\n</script>"
    }
  ],
  "term_schemas": [
    {
      "term_id": 45,
      "taxonomy": "category",
      "schema": "<script type=\"application/ld+json\">\n{ \"@context\": \"https://schema.org\", \"@type\": \"WebPage\" }\n</script>"
    }
  ]
}
```

**Purge cache:**
```bash
curl -s -X POST "https://yoursite.com/wp-json/seo-bridge/v1/purge-cache?token=YOUR_TOKEN"
```

## Scoped Tokens (Optional)

By default a single admin token grants full access. To restrict access:

1. Go to **Settings > Remote SEO Bridge**
2. Enable **Scoped Tokens**
3. Generate a **Read** token (ping, scan, health, meta-scan only)
4. Generate a **Write** token (update, schema, purge-cache)

## Self-Deactivation Timer

Set an expiry in hours under **Settings > Remote SEO Bridge**. The plugin deactivates itself via WP-Cron when the timer expires. Recommended for client sites — set to 24 hours while working, then it cleans up automatically.

## Security Notes

- Rotate the admin token after each session on client sites
- Use the self-deactivation timer on any site you do not manage day-to-day
- Enable scoped tokens when sharing read access with reporting tools
- The plugin logs all API calls — check the audit log if you suspect unauthorized access
- Deactivate and delete the plugin when not in use

## Supported Plugins

**SEO:**
- Yoast SEO
- RankMath

**Header Script Injection:**
- Header and Footer Scripts by Anand Kumar (`_inpost_head_script`)
- WPCode (`_wpcode_head_script`)
- Head, Footer and Post Injections (`_hfip_header`)

**Cache:**
- LiteSpeed Cache
- WP Rocket
- W3 Total Cache
- WP Super Cache

## License

GPL-2.0-or-later
