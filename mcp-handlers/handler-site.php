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
     * The only wp_options keys get_options() will return. Everything else --
     * credentials, other plugins' settings, this plugin's own data -- comes
     * back as "[blocked]". An allow-list, not a block-list: a block-list has
     * to know every secret every installed plugin will ever store, this
     * doesn't. Add a key here only when a real feature needs to read it.
     */
    const READABLE_OPTION_KEYS = [
        'show_on_front',        // homepage: latest posts or a static page
        'page_on_front',        // which page is the static homepage
        'page_for_posts',       // which page shows the blog
        'blogname',
        'blogdescription',
        'permalink_structure',
        'timezone_string',
        'gmt_offset',
        'blog_public',          // "discourage search engines" setting
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

    /* ── Read specific wp_options (allow-list) ─────────────── */
    public static function get_options( array $p ): array {
        $keys   = (array) ( $p['keys'] ?? [] );
        $result = [];
        foreach ( $keys as $key ) {
            $clean = sanitize_key( $key );
            $result[ $clean ] = in_array( $clean, self::READABLE_OPTION_KEYS, true )
                ? get_option( $clean )
                : '[blocked]';
        }
        return $result;
    }

    /* ── Flush caches so a deployed fix shows up ────────────────
     * With post_id: that post's object + page cache. Without: everything
     * this plugin can reach. Returns which caches were actually cleared;
     * CDN/edge caches are outside WordPress and not included.
     */
    public static function flush_cache( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        $done    = $post_id ? AISEOC_Cache::purge_post( $post_id ) : AISEOC_Cache::purge_all();
        AISEOC_Logger::log( 'info', ( $post_id ? "Caches cleared for post #{$post_id}: " : 'All caches cleared: ' ) . implode( ', ', $done ) );
        return [ 'post_id' => $post_id ?: null, 'caches_purged' => $done ];
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
