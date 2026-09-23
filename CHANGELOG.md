# Changelog

All notable changes to this plugin are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/).

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
