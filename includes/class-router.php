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

        add_filter( 'rest_post_dispatch', [ 'AISEOC_MCP', 'fix_allow_header' ], 20, 3 );

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
        // Name, group and description of every tool, built from the registry
        // (group) and the MCP definitions (description) so they can't drift.
        $registry = self::registry();
        $tools    = [];
        foreach ( AISEOC_MCP::tool_definitions() as $def ) {
            if ( ! isset( $registry[ $def['name'] ] ) ) continue;
            $tools[] = [
                'name'        => $def['name'],
                'group'       => $registry[ $def['name'] ][2],
                'description' => $def['description'],
            ];
        }
        return new WP_REST_Response( [ 'tools' => $tools ] );
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
        $handlers = self::registry();

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

    /**
     * The one list of tools: name => [ class, method, group ]. The MCP tool
     * list, /capabilities and group gating all read from here, so adding a
     * tool means one line here plus its definition in AISEOC_MCP.
     */
    public static function registry(): array {
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
}
