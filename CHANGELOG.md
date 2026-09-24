# Changelog

All notable changes to this plugin are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/).

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
