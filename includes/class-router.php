<?php
/**
 * Registers every AI SEO Connector REST endpoint under /wp-json/aiseoc/v1/
 * (plus a compatibility alias for connections made by earlier releases --
 * see register_routes() below).
 */
/** A tool call refused because its group is switched off in settings. */
class AISEOC_Group_Disabled extends RuntimeException {}

class AISEOC_Router {

    const NS = 'aiseoc/v1';

    public static function init() {}

    /**
     * Enabled tool groups. Normally stored as a JSON string, but tolerate an
     * array too (e.g. written by an import tool or WP-CLI) instead of
     * letting json_decode() fatal on every API call.
     */
    public static function allowed_groups(): array {
        $raw = get_option( 'aiseoc_allowed_actions', '[]' );
        $val = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
        return is_array( $val ) ? array_values( array_filter( $val, 'is_string' ) ) : [];
    }

    /** The base URL the platform is given; also what the Doctor self-tests. */
    public static function api_base(): string {
        return get_bloginfo( 'url' ) . '/wp-json/' . self::NS;
    }

    public static function register_routes() {
        $perm = [ 'AISEOC_Auth', 'permission_callback' ];

        $routes = [
            '/ping' => [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'ping' ],
                'permission_callback' => $perm,
            ],
            '/capabilities' => [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'capabilities' ],
                'permission_callback' => $perm,
            ],
            '/tool' => [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'dispatch_tool' ],
                'permission_callback' => $perm,
            ],
            '/logs' => [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_logs' ],
                'permission_callback' => $perm,
            ],
            /* ── MCP Streamable HTTP (JSON-RPC 2.0) ───────────── */
            '/mcp' => [
                [
                    'methods'             => 'POST',
                    'callback'            => [ 'AISEOC_MCP', 'handle' ],
                    'permission_callback' => $perm,
                ],
                [
                    'methods'             => 'GET',
                    'callback'            => [ 'AISEOC_MCP', 'handle_get' ],
                    'permission_callback' => $perm,
                ],
            ],
        ];

        // Register every route above under BOTH namespaces: the primary
        // 'aiseoc/v1', plus a compatibility alias for connections made by
        // earlier releases. The platform still calls the alias today, so it
        // can only be removed after the platform is re-pointed at 'aiseoc/v1'.
        foreach ( [ self::NS, 'vtseo/v1' ] as $namespace ) {
            foreach ( $routes as $path => $args ) {
                register_rest_route( $namespace, $path, $args );
            }
        }
    }

    public static function ping(): WP_REST_Response {
        return new WP_REST_Response( [
            'status'  => 'ok',
            'plugin'  => 'AI SEO Connector',
            'version' => AISEOC_VERSION,
            'site'    => get_bloginfo( 'url' ),
            'time'    => current_time( 'c' ),
        ] );
    }

    public static function capabilities(): WP_REST_Response {
        return new WP_REST_Response( [
            'tools' => self::tool_manifest(),
        ] );
    }

    public static function get_logs(): WP_REST_Response {
        return new WP_REST_Response( AISEOC_Logger::get_recent() );
    }

    /** Central dispatcher — receives { tool, params } */
    public static function dispatch_tool( WP_REST_Request $request ): WP_REST_Response {
        $body    = $request->get_json_params();
        $body    = is_array( $body ) ? $body : [];
        $tool    = sanitize_key( $body['tool'] ?? '' );
        $params  = is_array( $body['params'] ?? null ) ? $body['params'] : [];
        $allowed = self::allowed_groups();

        AISEOC_Logger::log( 'info', "Tool called: {$tool}" );

        try {
            $result = self::call_tool( $tool, $params, $allowed );
            return new WP_REST_Response( [ 'success' => true, 'result' => $result ] );
        } catch ( Throwable $e ) {
            AISEOC_Logger::log( 'error', "Tool {$tool} error: " . $e->getMessage() );
            [ $status, $message ] = self::describe_error( $e );
            return new WP_REST_Response( [ 'success' => false, 'error' => $message ], $status );
        }
    }

    /**
     * Turn an exception into [ HTTP status, message safe to show the caller ].
     *
     * Problems the caller can act on -- bad input, a missing post, a
     * disabled tool group -- get their real message with a 400/403. Anything
     * else (a WordPress or PHP failure) gets a generic 500, because those
     * messages can contain file paths or table names; the real text is
     * still written to the activity log. Used by /tool and MCP tools/call
     * so both entry points answer the same way.
     */
    public static function describe_error( Throwable $e ): array {
        if ( $e instanceof AISEOC_Group_Disabled ) {
            return [ 403, $e->getMessage() ];
        }
        if ( $e instanceof InvalidArgumentException ) {
            return [ 400, $e->getMessage() ];
        }
        return [ 500, 'Tool execution failed. Check the activity log for details.' ];
    }

    /**
     * Shared tool executor — used by both the legacy /tool endpoint and the MCP handler.
     * Throws on unknown tool, disabled group, or handler error.
     */
    public static function call_tool( string $tool, array $params, array $allowed ) {
        $handlers = self::get_handlers();

        if ( ! isset( $handlers[ $tool ] ) ) {
            throw new InvalidArgumentException( "Unknown tool: {$tool}" );
        }

        [ $class, $method, $group ] = $handlers[ $tool ];

        if ( ! in_array( $group, $allowed, true ) ) {
            AISEOC_Logger::log( 'warn', "Blocked tool '{$tool}' — group '{$group}' not in allowed_actions." );
            throw new AISEOC_Group_Disabled( "Tool group '{$group}' is disabled. Enable it in AI SEO Connector settings." );
        }

        return call_user_func( [ $class, $method ], $params );
    }

    private static function get_handlers(): array {
        return [
            /* Content */
            'create_post'            => [ 'AISEOC_Content', 'create_post',        'content' ],
            'update_post'            => [ 'AISEOC_Content', 'update_post',        'content' ],
            'get_post'               => [ 'AISEOC_Content', 'get_post',           'content' ],
            'list_posts'             => [ 'AISEOC_Content', 'list_posts',         'content' ],
            'delete_post'            => [ 'AISEOC_Content', 'delete_post',        'content' ],
            'schedule_post'          => [ 'AISEOC_Content', 'schedule_post',      'content' ],
            'set_featured_image'     => [ 'AISEOC_Content', 'set_featured_image', 'content' ],
            'get_taxonomies'         => [ 'AISEOC_Content', 'get_taxonomies',     'content' ],
            'assign_terms'           => [ 'AISEOC_Content', 'assign_terms',       'content' ],
            /* SEO */
            'yoast_get_meta'         => [ 'AISEOC_SEO', 'get_meta',        'seo' ],
            'yoast_set_meta'         => [ 'AISEOC_SEO', 'set_meta',        'seo' ],
            'yoast_audit'            => [ 'AISEOC_SEO', 'audit_post',      'seo' ],
            'yoast_sitemap_ping'     => [ 'AISEOC_SEO', 'ping_sitemap',    'seo' ],
            /* Media */
            'upload_media'            => [ 'AISEOC_Media', 'upload',                'media' ],
            'list_media'              => [ 'AISEOC_Media', 'list_media',            'media' ],
            'get_media'               => [ 'AISEOC_Media', 'get_media',             'media' ],
            'delete_media'            => [ 'AISEOC_Media', 'delete_media',          'media' ],
            'update_media_meta'       => [ 'AISEOC_Media', 'update_meta',           'media' ],
            'update_media_alt_by_url' => [ 'AISEOC_Media', 'update_alt_by_url',     'media' ],
            /* Site (read-only diagnostics + narrow, allow-listed writes) */
            'get_site_info'          => [ 'AISEOC_Site', 'get_site_info', 'site' ],
            'list_plugins'           => [ 'AISEOC_Site', 'list_plugins',  'site' ],
            'get_options'            => [ 'AISEOC_Site', 'get_options',   'site' ],
            'flush_cache'            => [ 'AISEOC_Site', 'flush_cache',   'site' ],
        ];
    }

    private static function tool_manifest(): array {
        return [
            /* ── Content ── */
            [ 'name' => 'create_post',            'group' => 'content', 'description' => 'Create any post type (post, page, custom). Supports scheduling, excerpt, password, custom fields.' ],
            [ 'name' => 'update_post',            'group' => 'content', 'description' => 'Update any field of an existing post.' ],
            [ 'name' => 'get_post',               'group' => 'content', 'description' => 'Get full post data including meta and terms.' ],
            [ 'name' => 'list_posts',             'group' => 'content', 'description' => 'List posts with filters (type, status, author, date, search).' ],
            [ 'name' => 'delete_post',            'group' => 'content', 'description' => 'Trash or permanently delete a post.' ],
            [ 'name' => 'schedule_post',          'group' => 'content', 'description' => 'Schedule a post to publish at a specific datetime.' ],
            [ 'name' => 'set_featured_image',     'group' => 'content', 'description' => 'Set or remove the featured image on a post.' ],
            [ 'name' => 'get_taxonomies',         'group' => 'content', 'description' => 'List all taxonomies and their terms.' ],
            [ 'name' => 'assign_terms',           'group' => 'content', 'description' => 'Add/set/remove taxonomy terms on a post.' ],

            /* ── SEO ── */
            [ 'name' => 'yoast_get_meta',         'group' => 'seo', 'description' => 'Get all SEO meta for a post from whichever SEO plugin is active (Yoast SEO or RankMath).' ],
            [ 'name' => 'yoast_set_meta',         'group' => 'seo', 'description' => 'Set SEO meta (title, description, robots, og, canonical, schema) on whichever SEO plugin is active (Yoast SEO or RankMath).' ],
            [ 'name' => 'yoast_audit',            'group' => 'seo', 'description' => 'Run a readability/keyword audit and return recommendations.' ],
            [ 'name' => 'yoast_sitemap_ping',     'group' => 'seo', 'description' => 'Ping search engines with updated sitemap.' ],

            /* ── Media ── */
            [ 'name' => 'upload_media',            'group' => 'media', 'description' => 'Upload an image/file from URL or base64 to the media library.' ],
            [ 'name' => 'list_media',              'group' => 'media', 'description' => 'Search and list media library items.' ],
            [ 'name' => 'get_media',               'group' => 'media', 'description' => 'Get a single media item with all sizes and meta.' ],
            [ 'name' => 'delete_media',            'group' => 'media', 'description' => 'Delete a media item.' ],
            [ 'name' => 'update_media_meta',       'group' => 'media', 'description' => 'Update alt text, caption, title, description by media_id.' ],
            [ 'name' => 'update_media_alt_by_url', 'group' => 'media', 'description' => 'Update alt text for an attachment by its public URL -- resolves the URL to a media_id first via WordPress core, for images (e.g. logo, theme header images) where the caller only has the rendered <img src>, not the internal attachment ID.' ],

            /* ── Site ── */
            [ 'name' => 'get_site_info',          'group' => 'site', 'description' => 'Get WordPress site info: name, URL, version, active theme, post/user/media counts.' ],
            [ 'name' => 'list_plugins',           'group' => 'site', 'description' => 'List installed plugins (name, version, active status) -- read-only, no install/activate/deactivate.' ],
            [ 'name' => 'get_options',             'group' => 'site', 'description' => 'Read specific wp_options by key. Only a short list of site-setting keys can be read (e.g. show_on_front, page_on_front, blogname, permalink_structure); every other key is returned as [blocked].' ],
            [ 'name' => 'flush_cache',             'group' => 'site', 'description' => 'Clear the object cache and supported page caches, for one post (post_id) or the whole site. Returns which caches were cleared.' ],
        ];
    }
}
