<?php
/**
 * Plugin Name: AI SEO Connector
 * Plugin URI:  https://github.com/abhiraz7/AI-SEO-Connector
 * Description: Lets the VtechSEO platform read on-page SEO data and apply approved fixes (meta tags, image alt text, content) on this site. Scoped to content/SEO/media only -- no page-builder control, no plugin management, no raw PHP execution.
 * Version:     1.2.2
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      AI SEO Connector
 * Author URI:  https://github.com/abhiraz7/AI-SEO-Connector
 * License:     Proprietary -- All Rights Reserved. See LICENSE.
 * Text Domain: ai-seo-connector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AISEOC_VERSION',    '1.2.2' );
define( 'AISEOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISEOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AISEOC_SLUG',       'ai-seo-connector' );

/* ── Autoload modules ─────────────────────────────────────── */
require_once AISEOC_PLUGIN_DIR . 'includes/class-auth.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-router.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-mcp.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-logger.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-content.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-seo.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-media.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-site.php';
require_once AISEOC_PLUGIN_DIR . 'admin/class-admin.php';

/**
 * ── Legacy option migration (rename: vtechseo-agent / VtechSEO Agent → AI SEO Connector) ──
 *
 * Two real, already-connected WordPress sites (examnotespdf.in and
 * vseo.vtraffic.io) have their token and settings stored under the OLD
 * option names (vtseo_api_token, vtseo_enabled, etc.) from before this
 * plugin was renamed from "VtechSEO Agent" to "AI SEO Connector".
 *
 * Because the plugin folder itself was renamed, WordPress treats this as a
 * brand-new plugin install, not an update -- the activation hook fires
 * fresh. add_option() is a no-op if the option already exists, but the
 * NEW aiseoc_* option names have never existed on those sites, so without
 * this migration step add_option() would happily create a brand new random
 * token under aiseoc_api_token, silently orphaning the token the client
 * already has pasted into the VtechSEO dashboard and breaking their
 * connection.
 *
 * This copies each old vtseo_* value over to its new aiseoc_* name --
 * but only if the new option is still empty/unset and an old value
 * actually exists. On a genuinely fresh install (no vtseo_* options at
 * all) this is a harmless no-op and add_option() below proceeds as normal.
 *
 * Run on both register_activation_hook (the normal path) AND plugins_loaded
 * (belt-and-braces: some WP admin flows -- e.g. a plugin reinstall via a
 * hosting panel, or an already-active plugin file being swapped out without
 * a clean deactivate/reactivate cycle -- are not guaranteed to fire the
 * activation hook before other code reads these options).
 */
function aiseoc_migrate_legacy_options(): void {
    $map = [
        'vtseo_api_token'      => 'aiseoc_api_token',
        'vtseo_enabled'        => 'aiseoc_enabled',
        'vtseo_log_level'      => 'aiseoc_log_level',
        'vtseo_allowed_actions'=> 'aiseoc_allowed_actions',
        'vtseo_app_username'   => 'aiseoc_app_username',
        // Activity log isn't part of auth/connection state, but carrying it
        // over avoids a client seeing their history vanish for no reason.
        'vtseo_activity_log'   => 'aiseoc_activity_log',
    ];

    foreach ( $map as $old_key => $new_key ) {
        // Use a strict false/'' check here rather than empty() -- 'aiseoc_enabled'
        // can legitimately hold the string '0' (user explicitly disabled the
        // plugin), which empty() would treat as "not set yet" and wrongly
        // re-migrate/overwrite with the old option's value on every plugins_loaded.
        $new_value = get_option( $new_key, false );
        if ( $new_value !== false && $new_value !== '' ) {
            continue; // Already migrated (or a fresh aiseoc_ value already set) -- don't clobber it.
        }
        $old_value = get_option( $old_key, false );
        if ( $old_value === false || $old_value === '' ) {
            continue; // Nothing to migrate from (genuinely fresh install).
        }
        update_option( $new_key, $old_value );
    }
}

register_activation_hook( __FILE__, function () {
    // Migrate BEFORE the add_option() calls below, so a site reinstalling
    // under the new plugin name keeps its existing token/settings instead
    // of silently getting a fresh token that breaks its VtechSEO dashboard
    // connection. See aiseoc_migrate_legacy_options() docblock above.
    aiseoc_migrate_legacy_options();

    // add_option() is a no-op if the option already exists, so this can safely
    // re-run on every plugin update/reactivation without ever regenerating an
    // existing site's token or resetting its enabled groups -- see README.md,
    // "Does updating the plugin break my connection?".
    $token = bin2hex( random_bytes( 32 ) );
    add_option( 'aiseoc_api_token', $token );
    add_option( 'aiseoc_enabled',   '1' );
    add_option( 'aiseoc_log_level', 'info' );
    add_option( 'aiseoc_allowed_actions', json_encode( [
        'content', 'seo', 'media', 'site',
    ] ) );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

/* ── Boot ─────────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    // Belt-and-braces re-run of the migration -- see docblock above.
    aiseoc_migrate_legacy_options();

    AISEOC_Auth::init();
    AISEOC_Router::init();
    AISEOC_Admin::init();
} );

/* ── REST namespace registration ─────────────────────────── */
add_action( 'rest_api_init', function () {
    AISEOC_Router::register_routes();
} );

/**
 * ── Auto-updates via GitHub Releases (Plugin Update Checker) ──
 *
 * This plugin now lives in its own public repo at
 * github.com/abhiraz7/AI-SEO-Connector -- tagging a release there (e.g. v1.1.0)
 * makes WordPress's native "update available" notice work the same way it
 * would for a wordpress.org-hosted plugin -- via the vendored
 * YahnisElsts/plugin-update-checker library. Guarded with file_exists() so
 * this never fatal-errors if the library hasn't been vendored yet (see
 * vendor/README.md).
 */
if ( file_exists( AISEOC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
    require AISEOC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    $aiseocUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/abhiraz7/AI-SEO-Connector/',
        __FILE__,
        'ai-seo-connector'
    );
    $aiseocUpdateChecker->setBranch( 'main' );
    $aiseocUpdateChecker->getVcsApi()->enableReleaseAssets();
}
