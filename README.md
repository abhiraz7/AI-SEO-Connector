# AI SEO Connector

[![Latest release](https://img.shields.io/github/v/release/abhiraz7/AI-SEO-Connector?label=latest%20release&sort=semver)](https://github.com/abhiraz7/AI-SEO-Connector/releases)
[![License: Proprietary](https://img.shields.io/badge/license-proprietary-red.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)

A WordPress plugin that lets your SEO platform read on-page SEO data from
your site and apply approved fixes -- meta tags, image alt text, and
content -- without ever needing your WordPress login.

It is deliberately narrow in scope: built only for what an SEO fix
actually needs, and nothing more.

## How the connection works

1. **Install and activate this plugin** on your WordPress site (upload the
   zip via Plugins → Add New → Upload Plugin, or your host's usual method).
2. On activation, the plugin generates a random 64-character API token and
   stores it in your site's own database (`wp_options` table, key
   `aiseoc_api_token`). This token never leaves your server until you copy
   it into your SEO platform yourself.
3. Open **AI SEO Connector** in your WordPress admin sidebar. Copy the
   **API base URL** and **Bearer token** shown there into your platform's
   WordPress connection screen.
4. From then on, the platform talks to your site by sending that token in
   an `Authorization: Bearer <token>` header to
   `https://yoursite.com/wp-json/aiseoc/v1/tool` (or the fuller
   `/wp-json/aiseoc/v1/mcp` endpoint for MCP-compatible clients). Every
   request is checked against that token before anything runs.
   (Sites connected under an earlier release keep working unchanged -- an
   older REST path still responds as a temporary compatibility alias. New
   connections should use the `aiseoc/v1` path above.)

**Your WordPress login is never shared.** The only credential the platform
receives is the token you choose to paste.

## Connection status

The settings screen shows the state of the connection at a glance, and
the same colored dot sits next to the plugin's name in the WordPress admin
menu, so you can see it from any screen. It reflects what actually
happened, not a setting: it is derived from the last time an authenticated
request reached your site.

| Color | State | Meaning |
|---|---|---|
| Green | **Connected** | An authenticated request reached this site within the last 7 days. |
| White | **Awaiting connection** | Nothing has connected with the current credentials yet (a new install, or right after regenerating the token). |
| Yellow | **Idle** | It connected before, but there has been no contact for over 7 days. Nothing is wrong; the credentials are still valid. |
| Red | **Paused** | You paused the connection here. Every request is rejected until you resume. |

## Doctor

Open the **Doctor** panel and it runs a set of diagnostics, each with a
plain-language result and, when something is wrong, how to fix it:

- PHP and WordPress versions, memory limit
- HTTPS, permalinks
- Token present, connection not paused
- **A real request to this site's own API**, made with your token exactly
  as your platform would -- it catches the common host problems that stop
  a connection from working, such as a server stripping the
  `Authorization` header, a firewall or security plugin blocking the REST
  API, or plain permalinks breaking `/wp-json/` URLs
- Yoast SEO / RankMath detected
- Application Passwords available
- Whether a newer plugin version is waiting

The Doctor only reads; it never changes anything on your site. Its
self-test is not counted as your platform connecting.

## You are always in control

- **Revoke access instantly**: click "Regenerate" next to the token in the
  plugin's settings screen. The old token stops working immediately --
  no confirmation needed from anyone else, no waiting.
- **Pause the connection**: click "Pause connection". Every request, even
  with a valid token, gets rejected until you resume.
- **Turn off specific capabilities**: the four tool groups (Content, SEO,
  Media, Site info) can each be switched off independently. Only need SEO
  fixes applied, nothing else? Turn off Content and Media.
- **Uninstall**: deactivate and delete the plugin like any other.
  Deleting it removes the API token, settings, activity log, connection
  status and any Application Password it created, so nothing is left
  behind. Deactivating on its own keeps them, so you can reactivate
  without re-pasting the token. (Updating the plugin never removes
  anything.)
- **See everything it's done**: the "Recent activity" log on the settings
  screen records every tool call made through this connection, with a
  timestamp, so nothing happens invisibly. Rejected connection attempts
  (routine scanner noise on any public site) are grouped separately.

## What this plugin can and cannot do

Unlike a general-purpose WordPress developer/automation plugin, this one
is scoped to exactly four categories, and nothing outside them:

| Group | What it allows | What it explicitly does NOT include |
|---|---|---|
| **Content** | Create, update, or delete posts/pages; schedule publishing; set featured images; manage taxonomy terms | No page-builder (Elementor/Divi) access, no theme file editing |
| **SEO** | Read/write SEO meta fields (title, description, focus keyword, canonical, OpenGraph, robots) for **Yoast SEO or RankMath**, whichever is active; run an SEO audit | No support (yet) for AIOSEO or SEOPress meta fields -- these are detected and reported, not written to |
| **Media** | Upload/list/delete media library items; set or fix alt text -- either by attachment ID, or by the image's public URL for images (like a theme logo) that have no attachment ID visible in page HTML | No bulk media operations beyond what's listed |
| **Site info** | Read site name/URL/WordPress version/active theme/detected SEO plugin/content counts; list installed plugins (name, version, active/inactive); read a short list of site settings (homepage setup, site name, permalinks); clear the object cache and supported page caches | **Cannot** install, activate, or deactivate any plugin; cannot switch themes; never writes options or users. Option reads are limited to a short fixed list of site settings; every other option (passwords, API keys, other plugins' settings) is refused |

**There is no PHP execution tool, no arbitrary database query tool, no
user-management tool, and no site-wide search-and-replace tool in this
plugin at all.**

## Why alt text has two different fix tools

WordPress images fall into two categories, and only one of them carries
an internal ID that page HTML reveals:

- **Editor-inserted images** (anything added through the block or classic
  editor) get a `wp-image-{ID}` CSS class stamped onto their `<img>` tag.
  The platform can read that ID straight out of the page and fix these
  with `update_media_meta`.
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
  choice. Token comparison is constant-time.
- Failed authentication attempts are rate-limited (20 per 15 minutes per
  IP) to slow down brute-force attempts against the token.
- A failed operation returns an error response instead of reporting
  success.
- The settings screen is full-bleed and responsive, and its accent color
  follows a non-default WordPress Admin Color Scheme (Users → Profile).

## Releasing an update

Tag a version on this repo (e.g. `git tag v1.1.0 && git push origin v1.1.0`)
and the `.github/workflows/release.yml` workflow builds a clean plugin zip
and attaches it to a GitHub Release automatically. Every site running this
plugin checks for new releases here (via the vendored
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library) and shows WordPress's normal "update available" notice -- no
manual redistribution needed. See "Does updating the plugin break my
connection?" above: updates only ever replace files, never the stored
connection/token.

## Feature checklist

**Connection and trust**
- [x] Bearer-token authentication (constant-time compare)
- [x] WordPress Application Password as an alternative sign-in
- [x] Per-IP rate limiting of failed attempts
- [x] Regenerate the token, invalidating the old one immediately
- [x] Pause and resume the connection
- [x] Per-group tool access: Content, SEO, Media, Site info
- [x] Activity log, with rejected connection attempts grouped separately

**Settings screen**
- [x] Seamless full-bleed layout that adapts to any screen size
- [x] Live connection status (green / white / yellow / red) in the header, the status panel and the admin menu
- [x] Doctor diagnostics, including a real API round-trip
- [x] Collapsible Application Password panel
- [x] Accent color follows a non-default WordPress Admin Color Scheme

**SEO tools**
- [x] Yoast SEO and RankMath meta read/write behind one interface
- [x] SEO audit
- [x] Image alt text by attachment ID or by public URL
- [ ] AIOSEO and SEOPress support

**Updates**
- [x] Update notices delivered through GitHub Releases
- [x] Connections and settings survive updates
