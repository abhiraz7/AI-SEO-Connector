<?php
/**
 * Plugin Name: AI SEO Connector
 * Plugin URI:  https://github.com/abhiraz7/AI-SEO-Connector
 * Description: Lets your SEO platform read on-page SEO data and apply approved fixes (meta tags, image alt text, content) on this site. Scoped to content/SEO/media only -- no page-builder control, no plugin management, no raw PHP execution.
 * Version:     1.5.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      AI SEO Connector
 * Author URI:  https://github.com/abhiraz7/AI-SEO-Connector
 * License:     Proprietary -- All Rights Reserved. See LICENSE.
 * Text Domain: ai-seo-connector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AISEOC_VERSION',    '1.5.0' );
define( 'AISEOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISEOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AISEOC_SLUG',       'ai-seo-connector' );

/* ── Autoload modules ─────────────────────────────────────── */
require_once AISEOC_PLUGIN_DIR . 'includes/class-auth.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-router.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-mcp.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-logger.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-cache.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-status.php';
require_once AISEOC_PLUGIN_DIR . 'includes/class-doctor.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-content.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-seo.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-media.php';
require_once AISEOC_PLUGIN_DIR . 'mcp-handlers/handler-site.php';
require_once AISEOC_PLUGIN_DIR . 'admin/class-admin.php';

/**
 * ── Option migration from earlier releases ──
 *
 * Sites connected under an earlier release keep their token and settings
 * under the old option names (the vtseo_* keys mapped below). Because the
 * plugin folder was renamed, WordPress treats this as a brand-new plugin
 * install and the activation hook fires fresh. add_option() is a no-op only
 * if the option already exists, and the new aiseoc_* names never have on
 * those sites -- so without this step add_option() would create a NEW random
 * token and silently orphan the one the client already pasted into their
 * platform, breaking the connection.
 *
 * This copies each old value to its new name, but only if the new option is
 * still empty/unset and an old value actually exists. On a genuinely fresh
 * install it is a harmless no-op and add_option() below proceeds as normal.
 *
 * Runs on register_activation_hook (the normal path) AND plugins_loaded
 * (belt-and-braces: some flows -- a reinstall via a hosting panel, or an
 * already-active plugin file swapped without a clean deactivate/reactivate
 * cycle -- are not guaranteed to fire the activation hook first).
 */
function aiseoc_migrate_legacy_options(): void {
    $map = require AISEOC_PLUGIN_DIR . 'includes/legacy-options.php';

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
    // of silently getting a fresh token that breaks its connection. See the
    // aiseoc_migrate_legacy_options() docblock above.
    aiseoc_migrate_legacy_options();

    // add_option() is a no-op if the option already exists, so this can safely
    // re-run on every plugin update/reactivation without ever regenerating an
    // existing site's token or resetting its enabled groups -- see README.md,
    // "Does updating the plugin break my connection?".
    $token = bin2hex( random_bytes( 32 ) );
    add_option( 'aiseoc_api_token', $token );
    add_option( 'aiseoc_enabled',   '1' );
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
    $GLOBALS['aiseoc_update_checker'] = $aiseocUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/abhiraz7/AI-SEO-Connector/',
        __FILE__,
        'ai-seo-connector'
    );
    $aiseocUpdateChecker->setBranch( 'main' );
    $aiseocUpdateChecker->getVcsApi()->enableReleaseAssets();
}
