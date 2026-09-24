<?php
/**
 * Runs when the plugin is DELETED from the Plugins screen (not on
 * deactivation, and not on an update -- WordPress replaces the files
 * without running this). Removes everything the plugin stored, so no API
 * token is left behind on a site that no longer has the plugin.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

require_once __DIR__ . '/includes/class-auth.php'; // APP_PASSWORD_NAMES

function aiseoc_uninstall_site(): void {
    global $wpdb;

    // Revoke the Application Password created for this connection.
    $username = get_option( 'aiseoc_app_username', '' );
    $user     = $username ? get_user_by( 'login', $username ) : false;
    if ( $user && class_exists( 'WP_Application_Passwords' ) ) {
        foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $app ) {
            if ( in_array( $app['name'], AISEOC_Auth::APP_PASSWORD_NAMES, true ) ) {
                WP_Application_Passwords::delete_application_password( $user->ID, $app['uuid'] );
            }
        }
    }

    // Current options, the legacy plaintext password option, and the
    // option names used by earlier releases.
    $legacy  = require __DIR__ . '/includes/legacy-options.php';
    $options = array_merge(
        array_values( $legacy ),
        array_keys( $legacy ),
        [ 'aiseoc_last_contact', 'aiseoc_app_password', 'vtseo_app_password', 'aiseoc_log_level', 'vtseo_log_level' ]
    );
    foreach ( array_unique( $options ) as $option ) {
        delete_option( $option );
    }

    // Rate-limit counters (transients), including their timeout rows.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_aiseoc\\_rl\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_aiseoc\\_rl\\_%'"
    );
}

if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
        switch_to_blog( $site_id );
        aiseoc_uninstall_site();
        restore_current_blog();
    }
} else {
    aiseoc_uninstall_site();
}
