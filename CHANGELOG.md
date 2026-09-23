# Changelog

All notable changes to this plugin are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/).

## [1.2.2] - 2026-09-23

### Changed
- **License changed from GPL-2.0+ to Proprietary / All Rights Reserved.**
  The GPL header was inherited from before this plugin's rename and had
  never been deliberately chosen -- reconsidered and switched, matching
  the main platform's own licensing. Note: this plugin is built entirely
  on WordPress core's own hooks/APIs, which are themselves GPLv2 --
  WordPress ecosystem convention treats plugins built this way as
  derivative works expected to be GPL-compatible, so a fully proprietary
  license here is a deliberate, accepted departure from that norm, not
  an oversight.

## [1.2.1] - 2026-09-23

### Fixed
- Admin menu icon replaced (was an abstract stack-of-lines shape that
  WordPress's forced monochrome rendering flattened into a generic-
  looking placeholder glyph). Now a shield-with-checkmark, matching the
  main dashboard's own "Security" icon and this plugin's trust framing.
- Settings screen width was hard-capped at 960px, leaving a lot of dead
  space on wide monitors. Now `min(1400px, 94vw)`.

### Added
- **Spike**: the settings screen's accent color now shifts to harmonize
  with a site's chosen WordPress Admin Color Scheme (Users -> Profile),
  but only for non-default schemes -- sites on the default "Fresh"
  scheme (the large majority) keep the plugin's own distinctive
  indigo/violet look unchanged, so the brand identity isn't diluted for
  the common case. Curated accent values, not lifted directly from WP
  core -- visually unverified against a live install for any scheme
  other than the default. A few hardcoded glow-shadow colors (button
  hover, code-chip hover border) were not migrated to the new accent
  variables in this pass.

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
  - Untested against a live RankMath install -- reviewed against
    RankMath's documented meta key conventions, not verified end-to-end.

## [1.0.0] - 2026-09-23

### Changed
- Renamed from **VtechSEO Agent** to **AI SEO Connector** (class prefixes,
  option keys, REST namespace, admin UI, repo). Legacy `/wp-json/vtseo/v1`
  REST alias and an options-migration step kept in place so already-
  connected sites don't lose their token/settings.
- Redesigned the admin settings screen: added a plain-language trust
  section, split routine blocked-auth log noise from real activity, and
  gave the screen a modern glassmorphism/gradient visual treatment.

### Added
- Auto-updates via GitHub Releases (vendored Plugin Update Checker
  library) -- installed sites now get native WordPress update notices
  instead of manual reinstalls.
- `.github/workflows/release.yml` -- tagging a version automatically
  builds and publishes the plugin zip as a GitHub Release.
