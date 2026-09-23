# AI SEO Connector

A WordPress plugin that lets the VtechSEO platform read on-page SEO data
from your site and apply approved fixes -- meta tags, image alt text,
and content -- without ever needing your WordPress login.

This is the plugin the VtechSEO dashboard's "Connect WordPress" popup asks
you to install. It replaces the earlier `claude-wp-mcp` developer plugin
for that purpose: this one is deliberately narrower in scope, built only
for what an SEO fix actually needs.

## How the connection works

1. **Install and activate this plugin** on your WordPress site (upload the
   zip via Plugins → Add New → Upload Plugin, or your host's usual method).
2. On activation, the plugin generates a random 64-character API token and
   stores it in your site's own database (`wp_options` table, key
   `aiseoc_api_token`). This token never leaves your server until you copy
   it into the VtechSEO dashboard yourself.
3. Open **AI SEO Connector** in your WordPress admin sidebar. Copy the
   **API base URL** and **Bearer token** shown there into VtechSEO's
   WordPress connection screen.
4. From then on, VtechSEO talks to your site by sending that token in an
   `Authorization: Bearer <token>` header to
   `https://yoursite.com/wp-json/aiseoc/v1/tool` (or the fuller
   `/wp-json/aiseoc/v1/mcp` endpoint for MCP-compatible clients). Every
   request is checked against that token before anything runs.
   (Sites connected before this plugin's rename from "VtechSEO Agent" to
   "AI SEO Connector" can keep working unchanged for now -- the old
   `/wp-json/vtseo/v1/...` path still responds too, as a temporary alias.
   New connections should use the `aiseoc/v1` path above.)

**Nothing is shared except this one token.** Your WordPress admin
password, email, and every other credential stay entirely private to you.

## You are always in control

- **Revoke access instantly**: click "Regenerate" next to the token in the
  plugin's settings screen. The old token stops working immediately --
  no confirmation needed from anyone else, no waiting.
- **Disable the whole plugin**: uncheck "Enabled" and save. Every request,
  even with a valid token, gets rejected until you turn it back on.
- **Turn off specific capabilities**: the four tool groups (Content, SEO,
  Media, Site info) can each be disabled independently. Only need SEO
  fixes applied, nothing else? Turn off Content and Media.
- **Uninstall entirely**: deactivate and delete the plugin like any other.
  All of its data (token, settings, activity log) is stored as normal
  WordPress options and is removed the same way any other plugin's data
  would be.
- **See everything it's done**: the "Recent activity" log on the settings
  screen records every tool call made through this connection, with a
  timestamp, so nothing happens invisibly.

## What this plugin can and cannot do

Unlike a general-purpose WordPress developer/automation plugin, this one
is scoped to exactly four categories, and nothing outside them:

| Group | What it allows | What it explicitly does NOT include |
|---|---|---|
| **Content** | Create, update, or delete posts/pages; schedule publishing; set featured images; manage taxonomy terms | No page-builder (Elementor/Divi) access, no theme file editing |
| **SEO** | Read/write Yoast SEO meta fields (title, description, focus keyword, canonical, OpenGraph, robots); run an SEO audit; ping search engines' sitemaps | No support (yet) for RankMath, AIOSEO, or SEOPress meta fields -- these are detected and reported, not written to |
| **Media** | Upload/list/delete media library items; set or fix alt text -- either by attachment ID, or by the image's public URL for images (like a theme logo) that have no attachment ID visible in page HTML | No bulk media operations beyond what's listed |
| **Site info** | Read-only: site name/URL/WordPress version/active theme/detected SEO plugin/content counts; list installed plugins (name, version, active/inactive) | **Cannot** install, activate, or deactivate any plugin; cannot switch themes; cannot read or write arbitrary `wp_options` (a fixed block-list always protects auth keys, this plugin's own token, and other sensitive options even from the one narrow read tool that exists) |

**There is no PHP execution tool, no arbitrary database query tool, no
user-management tool, and no site-wide search-and-replace tool in this
plugin at all** -- those exist only in the separate, internal
`claude-wp-mcp` developer plugin used for one-off engagements, and are
never shipped to a client site by this plugin.

## Why alt text has two different fix tools

WordPress images fall into two categories, and only one of them carries
an internal ID that page HTML reveals:

- **Editor-inserted images** (anything added through the block or classic
  editor) get a `wp-image-{ID}` CSS class stamped onto their `<img>` tag.
  VtechSEO can read that ID straight out of the page and fix these with
  `update_media_meta`.
- **Theme-level images** (a logo, header/footer image set via the
  Customizer) carry no such class -- they're not "attachments" in the
  same sense, so there's no ID for a page scan to find. For these,
  `update_media_alt_by_url` resolves the image's public URL to its real
  attachment record using WordPress's own built-in
  `attachment_url_to_postid()` function, then fixes the alt text the
  normal way. If an image is hotlinked from elsewhere (not actually in
  this site's media library), this tool fails with a clear error instead
  of silently doing nothing.

## Does updating the plugin break my connection?

No. The API token is stored via `add_option()`, which WordPress
guarantees is a no-op if the option already exists. Installing a newer
version of this plugin never regenerates your token, never resets which
tool groups you've enabled, and never touches your activity log -- only
clicking "Regenerate" in the settings screen changes the token.

## Technical notes

- Every write goes through WordPress's own core functions
  (`wp_update_post`, `update_post_meta`, `wp_insert_attachment`, etc.) --
  never raw SQL, never `eval()`, never dynamic code execution of any
  kind. There is nothing in this plugin that a WordPress security scanner
  would flag as obfuscated or suspicious; everything is plain, readable
  PHP calling standard WordPress APIs.
- Authentication supports two methods: this plugin's own Bearer token, or
  a standard WordPress Application Password (Users → Profile) -- your
  choice.
- Failed authentication attempts are rate-limited (20 per 15 minutes per
  IP) to slow down brute-force attempts against the token.
- Every response is a clear success or a clear, specific error -- a
  fix that didn't apply never looks like one that did.
