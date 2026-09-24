<?php
/**
 * Read-only site diagnostics ("what's running on this site") plus two
 * narrow, allow-listed writes/reads that on-page fixes actually need.
 * Deliberately does NOT include: activate/deactivate/install plugin,
 * theme switching, user management, site-wide search/replace, wp_options
 * writes, or PHP execution. See README.md.
 */
class AISEOC_Site {

    /**
     * wp_options keys that must never be read via the API, even though
     * get_options() is otherwise a read tool. Reading these could hand an
     * attacker who obtained the token everything needed to escalate
     * further (auth salts) or reveal this plugin's own credentials.
     */
    const BLOCKED_OPTION_KEYS = [
        'siteurl', 'home', 'admin_email',
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
        'wp_user_roles', 'default_role',
        'aiseoc_api_token', 'aiseoc_app_password', 'aiseoc_allowed_actions',
        // Option names used by earlier releases -- a site migrated from them
        // may still have these lingering in wp_options even after the
        // aiseoc_* values take over, so keep blocking them too rather than
        // assuming they're gone. Safe to drop once confirmed no live site
        // still carries them.
        'vtseo_api_token', 'vtseo_app_password', 'vtseo_allowed_actions',
    ];

    /* ── Site info ("what's running here") ────────────────── */
    public static function get_site_info( array $p ): array {
        $theme = wp_get_theme();
        return [
            'name'         => get_bloginfo( 'name' ),
            'tagline'      => get_bloginfo( 'description' ),
            'url'          => get_bloginfo( 'url' ),
            'wp_version'   => get_bloginfo( 'version' ),
            'language'     => get_bloginfo( 'language' ),
            'active_theme' => [
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'author'  => $theme->get( 'Author' ),
                'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
            ],
            'seo_plugin'   => self::detect_seo_plugin(),
            'posts_count'  => (int) wp_count_posts()->publish,
            'pages_count'  => (int) wp_count_posts( 'page' )->publish,
            'media_count'  => (int) wp_count_posts( 'attachment' )->inherit,
        ];
    }

    /* ── List installed plugins (read-only) ───────────────── */
    public static function list_plugins( array $p ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all    = get_plugins();
        $active = get_option( 'active_plugins', [] );
        $result = [];

        foreach ( $all as $path => $data ) {
            $result[] = [
                'slug'    => dirname( $path ),
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => in_array( $path, $active, true ),
            ];
        }

        if ( ! empty( $p['active_only'] ) ) {
            $result = array_values( array_filter( $result, fn( $r ) => $r['active'] ) );
        }

        return [ 'plugins' => $result ];
    }

    /* ── Read specific wp_options (narrow allow-list gate) ──── */
    public static function get_options( array $p ): array {
        $keys   = (array) ( $p['keys'] ?? [] );
        $result = [];
        foreach ( $keys as $key ) {
            $clean = sanitize_key( $key );
            if ( in_array( $clean, self::BLOCKED_OPTION_KEYS, true ) ) {
                $result[ $clean ] = '[blocked]';
                continue;
            }
            $result[ $clean ] = get_option( $clean );
        }
        return $result;
    }

    /* ── Flush cache (so a deployed fix is visible immediately) ── */
    public static function flush_cache( array $p ): array {
        wp_cache_flush();
        AISEOC_Logger::log( 'info', 'Object cache flushed.' );
        return [ 'object_cache' => true ];
    }

    /* ── Helpers ───────────────────────────────────────────── */

    private static function detect_seo_plugin(): ?string {
        if ( defined( 'WPSEO_VERSION' ) )                     return 'Yoast SEO ' . WPSEO_VERSION;
        if ( defined( 'RANK_MATH_VERSION' ) )                 return 'RankMath ' . RANK_MATH_VERSION;
        if ( defined( 'AIOSEO_VERSION' ) )                    return 'All in One SEO ' . AIOSEO_VERSION;
        if ( class_exists( 'SEOPress' ) )                     return 'SEOPress';
        return null;
    }
}
