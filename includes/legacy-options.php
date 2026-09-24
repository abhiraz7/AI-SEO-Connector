<?php
/**
 * Option names used by earlier releases, mapped to their current names.
 *
 * Single source of truth for two things: the one-time migration in
 * ai-seo-connector.php (copies old values to the new names so already
 * connected sites keep their token), and uninstall.php (removes the old
 * keys too, so nothing lingers after the plugin is deleted).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

return [
    'vtseo_api_token'       => 'aiseoc_api_token',
    'vtseo_enabled'         => 'aiseoc_enabled',
    'vtseo_log_level'       => 'aiseoc_log_level',
    'vtseo_allowed_actions' => 'aiseoc_allowed_actions',
    'vtseo_app_username'    => 'aiseoc_app_username',
    // Activity log isn't part of auth/connection state, but carrying it
    // over avoids a client seeing their history vanish for no reason.
    'vtseo_activity_log'    => 'aiseoc_activity_log',
];
