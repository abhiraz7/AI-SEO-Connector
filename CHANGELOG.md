# Changelog

All notable changes to this plugin are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/).

## [1.4.0] - 2026-09-24

This release fixes a bug that could damage page content, closes several
places where the plugin returned more than it should, and makes the
README and the API's error messages match what the plugin actually does.
Two changes can affect anything that calls the API directly, so read
"Changes you might notice" below before updating a site you've connected
other tools to.

### Fixed
- **Changing a post title could strip HTML from the post body.** With the
  API token there is no logged-in user, so WordPress ran its HTML filter
  on everything it saved, and saving a post re-saves all of it. A
  title-only update therefore removed iframes, scripts and Custom HTML
  embeds from the content, and the call still said it worked. Content the
  caller doesn't send is now saved back exactly as it was. Content the
  caller does send is still sanitized as before.
- **Scheduling a draft published it immediately.** `schedule_post` (and
  `update_post` with a date) on a draft ignored the new date. Now it
  schedules the post.
- **A fix could sit behind a page cache.** SEO title and description
  changes are written as post meta, which doesn't make cache plugins
  purge the page. Writes now clear that post's cache for WP Rocket,
  LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache and
  SiteGround Optimizer, and say which caches were cleared. `flush_cache`
  clears the page cache too (optionally for one `post_id`). CDN caches
  such as Cloudflare are outside WordPress and are not touched.
- **RankMath: `noindex: "false"` turned noindex on.** noindex and
  nofollow are now read as real booleans and anything unclear is
  rejected without saving.
- **The SEO audit got non-English text wrong.** A 45-character Hindi
  title was counted as 125 characters and a 640-word page as 0 words. It
  now counts characters and words correctly in any script, and the score
  includes warnings (`score_percent` is new).
- **A valid token could be locked out.** The rate limit was checked
  before the token, so failed attempts from a shared proxy address
  blocked the real connection for 15 minutes. Valid credentials now
  always get through; only failures are counted, and "rate limit hit" is
  logged once per window instead of on every request.
- **Uninstalling left the API token behind.** Deleting the plugin now
  removes the token, settings, activity log, connection status and any
  Application Password it created. Updating or deactivating removes
  nothing.
- A setting stored in an unexpected format (for example written by an
  import tool) no longer crashes every API call.

### Changes you might notice
- **`get_options` only returns a short list of site settings**
  (`show_on_front`, `page_on_front`, `page_for_posts`, `blogname`,
  `blogdescription`, `permalink_structure`, `timezone_string`,
  `gmt_offset`, `blog_public`). Every other key comes back as
  `[blocked]`. Before, it only blocked a list of known secrets.
- **Content tools only work on public post types.** Orders, templates and
  other private types are reported as not found. `create_post` also
  refuses meta keys that hold secrets or order data (`_billing_*`,
  `_shipping_*`, `_wp_*`, anything containing `password`, `token` or
  `api_key`) and an author ID that doesn't exist.
- **MCP `resources/*` follow the tool group settings** and only expose
  published, non-password-protected posts of public types.
- **`yoast_sitemap_ping` is removed.** Google and Bing have retired their
  ping endpoints, so it could only report failures.
- **Error responses are more specific.** Bad input, a missing post and a
  disabled tool group now return their real message (400 or 403) from
  both `/tool` and MCP. Real failures are still a generic 500 with the
  detail in the activity log.
- **`GET /mcp` returns 405.** It used to hold a PHP worker open for up to
  five minutes. POST works as before.
- JSON-RPC notifications (requests with no `id`) no longer get a reply.
- Tool descriptions and the MCP instructions now say Yoast SEO or
  RankMath, and `yoast_set_meta` lists every field it accepts.
- The unused `log_level` setting is removed.

### Added
- `flush_cache` accepts an optional `post_id`. Writes report
  `caches_purged`.
- The release workflow checks that the tag matches the plugin version and
  has a changelog entry, and uses this entry as the release notes.

## [1.3.0] - 2026-09-24

### Added
- **Live connection status.** The settings screen and the WordPress admin
  menu now show the real state of the connection: green (connected),
  white (awaiting connection), yellow (idle) or red (paused). It is
  derived from the last authenticated request rather than from a setting,
  and refreshes on its own while the screen is open.
- **Doctor.** On-demand diagnostics covering PHP/WordPress versions,
  HTTPS, permalinks, token, SEO plugin, Application Passwords, memory,
  update status, and a real authenticated request to the site's own API
  that detects a stripped `Authorization` header, a blocking firewall or
  security plugin, and broken `/wp-json/` routing. Read-only; its
  self-test is not counted as the platform connecting.
- **Pause / Resume** as its own action next to the status.

### Changed
- The settings screen was rebuilt as a seamless, full-bleed, responsive
  layout with a new logo mark. The "Enabled" checkbox is gone: the state
  is shown by the status instead, and pausing is a separate action, so
  saving the tool groups can no longer change whether the connection is on.
- The Application Password panel is collapsed by default.
- Tool groups are toggle switches; the SEO group now names both Yoast and
  RankMath. Tool-group input is restricted to the four known groups.
- Every glow and shadow now follows the accent color, which follows a
  non-default WordPress Admin Color Scheme.
- Regenerating the token resets a connected state that was earned with the
  old token.

## [1.2.2] - 2026-09-23

### Changed
- **License changed from GPL-2.0+ to Proprietary / All Rights Reserved.**
  The GPL header had never been a deliberate choice and was reconsidered.
  Note: this plugin is built entirely on WordPress core's own hooks and
  APIs, which are themselves GPLv2 -- WordPress ecosystem convention
  treats plugins built this way as derivative works expected to be
  GPL-compatible, so a fully proprietary license here is a deliberate,
  accepted departure from that norm, not an oversight.

## [1.2.1] - 2026-09-23

### Fixed
- Admin menu icon replaced (it was an abstract shape that WordPress's
  forced monochrome rendering flattened into a generic-looking placeholder
  glyph). Now a shield-with-checkmark, matching this plugin's trust
  framing.
- Settings screen width was hard-capped at 960px, leaving a lot of dead
  space on wide monitors. Now `min(1400px, 94vw)`.

### Added
- The settings screen's accent color shifts to harmonize with a site's
  chosen WordPress Admin Color Scheme (Users -> Profile), but only for
  non-default schemes, so sites on the default "Fresh" scheme keep the
  plugin's own indigo/violet look. Curated accent values, visually
  unverified for any scheme other than the default.

## [1.2.0] - 2026-09-23

### Added
- **RankMath support** for the SEO tool group. `get_meta`/`set_meta` now
  detect whichever SEO plugin is active (Yoast or RankMath) and translate
  to the same friendly field names either way, so callers never need to
  know which one a site is running. `audit_post` works unchanged against
  either, since it already operated on the unified field set.
  - Known gap: RankMath's schema/snippet system has no 1:1 equivalent to
    Yoast's `schema_article_type`/`schema_page_type` -- those two fields
    are simply empty under RankMath rather than guessed at.

## [1.0.0] - 2026-09-23

### Changed
- Renamed to **AI SEO Connector** (class prefixes, option keys, REST
  namespace, admin UI, repo). A compatibility REST alias and an
  options-migration step were kept so already-connected sites don't lose
  their token/settings.
- Redesigned the admin settings screen: added a plain-language trust
  section, split routine blocked-auth log noise from real activity, and
  gave the screen a modern glassmorphism/gradient visual treatment.

### Added
- Auto-updates via GitHub Releases (vendored Plugin Update Checker
  library) -- installed sites now get native WordPress update notices
  instead of manual reinstalls.
- `.github/workflows/release.yml` -- tagging a version automatically
  builds and publishes the plugin zip as a GitHub Release.
