<?php
/**
 * MCP Streamable HTTP transport — JSON-RPC 2.0
 *
 * POST /wp-json/aiseoc/v1/mcp  — JSON-RPC request (single or batch)
 *      If request has Accept: text/event-stream → each response
 *      object is streamed as an SSE "message" event before exit.
 *      (Also reachable under the compatibility alias namespace -- see
 *      class-router.php.)
 *
 * GET  /wp-json/aiseoc/v1/mcp  — SSE keep-alive channel
 *      Requires Accept: text/event-stream; streams ": ping"
 *      comments every 15 s for up to 5 minutes.
 *
 * Spec: MCP protocol version 2024-11-05, Streamable HTTP transport.
 */
class AISEOC_MCP {

    const PROTOCOL_VERSION = '2024-11-05';

    /* ════════════════════════════════════════════════════════
     *  WP REST entry points
     * ════════════════════════════════════════════════════════ */

    public static function handle( WP_REST_Request $request ) {
        $accept = $request->get_header( 'accept' ) ?? '';
        $stream = strpos( $accept, 'text/event-stream' ) !== false;

        $body = $request->get_json_params();

        if ( ! is_array( $body ) ) {
            $err = self::make_error( null, -32700, 'Parse error: body must be a JSON object or array.' );
            if ( $stream ) { self::sse_emit_and_exit( [ $err ] ); }
            return new WP_REST_Response( $err, 400 );
        }

        if ( isset( $body[0] ) && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
            $responses = [];
            foreach ( $body as $msg ) {
                if ( is_array( $msg ) ) {
                    $r = self::dispatch( $msg );
                    if ( $r !== null ) $responses[] = $r;
                }
            }
            if ( $stream ) { self::sse_emit_and_exit( $responses ); }
            if ( empty( $responses ) ) {
                return new WP_REST_Response( null, 202 );
            }
            return new WP_REST_Response( $responses );
        }

        $resp = self::dispatch( $body );
        if ( $stream ) { self::sse_emit_and_exit( $resp !== null ? [ $resp ] : [] ); }
        return $resp !== null
            ? new WP_REST_Response( $resp )
            : new WP_REST_Response( null, 202 );
    }

    public static function handle_get( WP_REST_Request $request ) {
        $accept = $request->get_header( 'accept' ) ?? '';
        if ( strpos( $accept, 'text/event-stream' ) === false ) {
            return new WP_REST_Response(
                [ 'error' => 'GET /mcp requires Accept: text/event-stream' ],
                400
            );
        }
        self::open_sse_stream(); // never returns
    }

    /* ════════════════════════════════════════════════════════
     *  SSE helpers
     * ════════════════════════════════════════════════════════ */

    private static function sse_emit_and_exit( array $responses ): void {
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        ob_implicit_flush( true );

        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache, no-store' );
        header( 'X-Accel-Buffering: no' );

        if ( empty( $responses ) ) {
            echo ": done\n\n";
        } else {
            foreach ( $responses as $msg ) {
                echo "event: message\n";
                echo 'data: ' . wp_json_encode( $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n";
                @ob_flush(); flush();
            }
        }
        exit;
    }

    private static function open_sse_stream(): void {
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        ob_implicit_flush( true );

        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache, no-store' );
        header( 'X-Accel-Buffering: no' );

        $start = time();
        while ( ! connection_aborted() && ( time() - $start ) < 300 ) {
            echo ": ping\n\n";
            @ob_flush(); flush();
            sleep( 15 );
        }

        AISEOC_Logger::log( 'info', 'MCP SSE channel closed' );
        exit;
    }

    /* ════════════════════════════════════════════════════════
     *  JSON-RPC 2.0 dispatcher
     * ════════════════════════════════════════════════════════ */

    private static function dispatch( array $msg ): ?array {
        if ( ( $msg['jsonrpc'] ?? '' ) !== '2.0' ) {
            return self::make_error( $msg['id'] ?? null, -32600, 'Invalid Request: jsonrpc must be "2.0".' );
        }

        $method          = $msg['method'] ?? '';
        $id              = $msg['id']     ?? null;
        $params          = is_array( $msg['params'] ?? null ) ? $msg['params'] : [];
        $is_notification = ! array_key_exists( 'id', $msg );

        switch ( $method ) {
            case 'initialize':
                return self::handle_initialize( $id, $params );
            case 'ping':
                return self::make_result( $id, new stdClass() );
            case 'tools/list':
                return self::handle_tools_list( $id );
            case 'tools/call':
                return self::handle_tools_call( $id, $params );
            case 'resources/list':
                return self::handle_resources_list( $id, $params );
            case 'resources/read':
                return self::handle_resource_read( $id, $params );
            case 'prompts/list':
                return self::handle_prompts_list( $id );
            case 'prompts/get':
                return self::handle_prompt_get( $id, $params );
            default:
                if ( $is_notification ) return null;
                return self::make_error( $id, -32601, "Method not found: {$method}" );
        }
    }

    /* ════════════════════════════════════════════════════════
     *  initialize
     * ════════════════════════════════════════════════════════ */

    private static function handle_initialize( $id, array $params ): array {
        $client = $params['clientInfo']['name'] ?? 'unknown';
        AISEOC_Logger::log( 'info', "MCP session initialized by: {$client}" );

        return self::make_result( $id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools'     => (object) [],
                'resources' => [ 'subscribe' => false, 'listChanged' => false ],
                'prompts'   => [ 'listChanged' => false ],
            ],
            'serverInfo'  => [
                'name'    => 'ai-seo-connector',
                'version' => AISEOC_VERSION,
            ],
            'instructions' => self::build_instructions(),
        ] );
    }

    /* ════════════════════════════════════════════════════════
     *  tools/list
     * ════════════════════════════════════════════════════════ */

    private static function handle_tools_list( $id ): array {
        $allowed = AISEOC_Router::allowed_groups();

        $group_map = [
            'create_post'             => 'content',
            'update_post'             => 'content',
            'get_post'                => 'content',
            'list_posts'              => 'content',
            'delete_post'             => 'content',
            'schedule_post'           => 'content',
            'set_featured_image'      => 'content',
            'get_taxonomies'          => 'content',
            'assign_terms'            => 'content',
            'yoast_get_meta'          => 'seo',
            'yoast_set_meta'          => 'seo',
            'yoast_audit'             => 'seo',
            'yoast_sitemap_ping'      => 'seo',
            'upload_media'            => 'media',
            'list_media'              => 'media',
            'get_media'               => 'media',
            'delete_media'            => 'media',
            'update_media_meta'       => 'media',
            'update_media_alt_by_url' => 'media',
            'get_site_info'           => 'site',
            'list_plugins'            => 'site',
            'get_options'             => 'site',
            'flush_cache'             => 'site',
        ];

        $tools = array_values( array_filter(
            self::tool_definitions(),
            fn( $t ) => in_array( $group_map[ $t['name'] ] ?? '', $allowed, true )
        ) );

        return self::make_result( $id, [ 'tools' => $tools ] );
    }

    /* ════════════════════════════════════════════════════════
     *  tools/call
     * ════════════════════════════════════════════════════════ */

    private static function handle_tools_call( $id, array $params ): array {
        $name      = sanitize_key( $params['name'] ?? '' );
        $arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];
        $allowed   = AISEOC_Router::allowed_groups();

        AISEOC_Logger::log( 'info', "MCP tools/call: {$name}" );

        try {
            $result = AISEOC_Router::call_tool( $name, $arguments, $allowed );
            return self::make_result( $id, [
                'content' => [ [
                    'type' => 'text',
                    'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                ] ],
                'isError' => false,
            ] );
        } catch ( Throwable $e ) {
            AISEOC_Logger::log( 'error', "MCP tools/call '{$name}': " . $e->getMessage() );
            return self::make_result( $id, [
                'content' => [ [ 'type' => 'text', 'text' => AISEOC_Router::describe_error( $e )[1] ] ],
                'isError' => true,
            ] );
        }
    }

    /* ════════════════════════════════════════════════════════
     *  resources/list + resources/read (browse posts/pages/media)
     * ════════════════════════════════════════════════════════ */

    /*
     * Resources follow the same rules as the tools: posts/pages need the
     * Content group, media needs the Media group, only public post types
     * are exposed, only published (not password-protected) posts can be
     * read, and meta goes through the same sensitive-key filter as get_post.
     */
    private static function handle_resources_list( $id, array $params ): array {
        $cursor   = $params['cursor'] ?? null;
        $paged    = $cursor ? max( 1, intval( base64_decode( $cursor ) ) ) : 1;
        $per_page = 15;
        $allowed  = AISEOC_Router::allowed_groups();

        $resources = [];
        $posts     = [];

        if ( in_array( 'content', $allowed, true ) ) {
            $posts = get_posts( [
                'post_type'      => AISEOC_Content::allowed_post_types(),
                'post_status'    => 'publish',
                'has_password'   => false,
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => false,
            ] );

            foreach ( $posts as $post ) {
                $snippet = wp_strip_all_tags( $post->post_excerpt ?: $post->post_content );
                $resources[] = [
                    'uri'         => "wp://{$post->post_type}/{$post->ID}",
                    'name'        => $post->post_title ?: "(#{$post->ID})",
                    'description' => wp_trim_words( $snippet, 20 ),
                    'mimeType'    => 'application/json',
                ];
            }
        }

        if ( in_array( 'media', $allowed, true ) ) {
            $media = get_posts( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 8,
                'paged'          => $paged,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            ] );

            foreach ( $media as $item ) {
                $mime = get_post_mime_type( $item->ID ) ?: 'application/octet-stream';
                $file = get_attached_file( $item->ID );
                $resources[] = [
                    'uri'      => "wp://media/{$item->ID}",
                    'name'     => $item->post_title ?: ( $file ? basename( $file ) : "media-{$item->ID}" ),
                    'mimeType' => $mime,
                ];
            }
        }

        $result = [ 'resources' => $resources ];
        if ( count( $posts ) === $per_page ) {
            $result['nextCursor'] = base64_encode( (string) ( $paged + 1 ) );
        }

        return self::make_result( $id, $result );
    }

    private static function handle_resource_read( $id, array $params ): array {
        $uri = (string) ( $params['uri'] ?? '' );

        if ( ! preg_match( '#^wp://([a-z0-9_-]+)/(\d+)$#', $uri, $m ) ) {
            return self::make_error( $id, -32602, 'Invalid resource URI. Expected wp://type/id' );
        }

        $type    = $m[1];
        $post_id = intval( $m[2] );
        $allowed = AISEOC_Router::allowed_groups();
        $post    = get_post( $post_id );

        if ( $type === 'media' ) {
            if ( ! in_array( 'media', $allowed, true ) ) {
                return self::make_error( $id, -32602, "Tool group 'media' is disabled. Enable it in AI SEO Connector settings." );
            }
            if ( ! $post || $post->post_type !== 'attachment' ) {
                return self::make_error( $id, -32602, "Media not found: {$post_id}" );
            }
            $data = wp_json_encode( [
                'id'       => $post_id,
                'title'    => $post->post_title,
                'url'      => wp_get_attachment_url( $post_id ),
                'alt'      => get_post_meta( $post_id, '_wp_attachment_image_alt', true ),
                'caption'  => $post->post_excerpt,
                'mime'     => get_post_mime_type( $post_id ),
                'metadata' => wp_get_attachment_metadata( $post_id ),
            ], JSON_UNESCAPED_SLASHES );
            return self::make_result( $id, [ 'contents' => [ [
                'uri'      => $uri,
                'mimeType' => 'application/json',
                'text'     => $data,
            ] ] ] );
        }

        if ( ! in_array( 'content', $allowed, true ) ) {
            return self::make_error( $id, -32602, "Tool group 'content' is disabled. Enable it in AI SEO Connector settings." );
        }

        // Anything that isn't a published, non-password-protected post of a
        // public type is reported as not found, without saying why.
        if ( ! $post
            || $post->post_type !== $type
            || ! in_array( $post->post_type, AISEOC_Content::allowed_post_types(), true )
            || $post->post_status !== 'publish'
            || $post->post_password !== '' ) {
            return self::make_error( $id, -32602, "Post not found: {$post_id}" );
        }

        $terms_out = [];
        foreach ( get_post_taxonomies( $post ) as $tax ) {
            $t = wp_get_post_terms( $post_id, $tax );
            if ( ! is_wp_error( $t ) ) {
                $terms_out[ $tax ] = wp_list_pluck( $t, 'name' );
            }
        }

        $data = wp_json_encode( [
            'id'       => $post_id,
            'title'    => $post->post_title,
            'content'  => $post->post_content,
            'excerpt'  => $post->post_excerpt,
            'status'   => $post->post_status,
            'type'     => $post->post_type,
            'author'   => get_the_author_meta( 'display_name', $post->post_author ),
            'date'     => $post->post_date,
            'modified' => $post->post_modified,
            'url'      => get_permalink( $post_id ),
            'slug'     => $post->post_name,
            'terms'    => $terms_out,
            'meta'     => AISEOC_Content::safe_post_meta( $post_id ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        return self::make_result( $id, [ 'contents' => [ [
            'uri'      => $uri,
            'mimeType' => 'application/json',
            'text'     => $data,
        ] ] ] );
    }

    /* ════════════════════════════════════════════════════════
     *  prompts/list + prompts/get — SEO/content workflows only
     * ════════════════════════════════════════════════════════ */

    private static function handle_prompts_list( $id ): array {
        return self::make_result( $id, [ 'prompts' => [
            [
                'name'        => 'seo_blog_post',
                'description' => 'Create a fully SEO-optimised blog post with SEO meta (Yoast or RankMath), featured image, and publish',
                'arguments'   => [
                    [ 'name' => 'topic',      'description' => 'Blog post topic',       'required' => true  ],
                    [ 'name' => 'keyword',    'description' => 'Primary focus keyword', 'required' => true  ],
                    [ 'name' => 'word_count', 'description' => 'Target word count',     'required' => false ],
                ],
            ],
            [
                'name'        => 'seo_audit',
                'description' => 'Audit SEO health — single post or site-wide top 10',
                'arguments'   => [
                    [ 'name' => 'post_id', 'description' => 'Post ID (omit for site-wide)', 'required' => false ],
                ],
            ],
            [
                'name'        => 'fix_missing_alt_text',
                'description' => 'Fix an image missing alt text, resolving by media_id or by its public URL',
                'arguments'   => [
                    [ 'name' => 'image_url', 'description' => 'The image\'s public URL as seen in page HTML', 'required' => true ],
                    [ 'name' => 'alt_text',  'description' => 'The alt text to set',                          'required' => true ],
                ],
            ],
        ] ] );
    }

    private static function handle_prompt_get( $id, array $params ): array {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        $site = get_bloginfo( 'name' );
        $url  = get_bloginfo( 'url' );

        switch ( $name ) {

            case 'seo_blog_post':
                $topic = $args['topic']      ?? 'your topic';
                $kw    = $args['keyword']    ?? 'focus keyword';
                $wc    = $args['word_count'] ?? '1200–1500';
                return self::make_result( $id, [
                    'description' => "Write an SEO blog post about \"{$topic}\" for {$site}",
                    'messages'    => [ [ 'role' => 'user', 'content' => [ 'type' => 'text', 'text' =>
"Create a fully SEO-optimised blog post for **{$site}** ({$url}) on the topic: **{$topic}**
Target keyword: `{$kw}` | Target length: {$wc} words

Steps:
1. `create_post` — status=draft, title optimised for `{$kw}` (≤60 chars), body {$wc} words using the keyword naturally
2. `yoast_set_meta` — seo_title ≤60 chars (keyword near start), meta_description ≤155 chars with a CTA, focus_keyword=\"{$kw}\"
3. Find a relevant image URL, `upload_media`, then `set_featured_image`
4. `yoast_audit` — fix any red/orange issues until score ≥ 70
5. `update_post` status=publish" ] ] ],
                ] );

            case 'seo_audit':
                $pid = isset( $args['post_id'] ) ? intval( $args['post_id'] ) : null;
                return self::make_result( $id, [
                    'description' => $pid ? "SEO audit for post #{$pid}" : "Site-wide SEO audit for {$site}",
                    'messages'    => [ [ 'role' => 'user', 'content' => [ 'type' => 'text', 'text' => $pid
                        ? "Audit SEO on post #{$pid} at {$site}:\n1. `get_post` — read content\n2. `yoast_get_meta` — read current meta\n3. `yoast_audit` — get score and issues\nReport: current score, missing meta, keyword gaps, content issues, and the exact fixes to apply."
                        : "Site-wide SEO audit for {$site} ({$url}):\n1. `get_site_info` — overall stats\n2. `list_posts` type=post status=publish per_page=10 — recent posts\n3. `yoast_audit` for each post\nReport: overall health score, the 3 posts needing most work, and specific action items per post."
                    ] ] ],
                ] );

            case 'fix_missing_alt_text':
                $img_url = $args['image_url'] ?? '';
                $alt     = $args['alt_text']  ?? '';
                return self::make_result( $id, [
                    'description' => "Fix missing alt text for {$img_url}",
                    'messages'    => [ [ 'role' => 'user', 'content' => [ 'type' => 'text', 'text' =>
"Set alt text on {$site} for the image at {$img_url} to: \"{$alt}\"

Steps:
1. Try `update_media_alt_by_url` with url=\"{$img_url}\" and alt=\"{$alt}\" first — it resolves the URL to the attachment internally.
2. If that fails (image not in this site's media library — hotlinked/CDN), report that the fix cannot be applied automatically and needs manual attention." ] ] ],
                ] );

            default:
                return self::make_error( $id, -32602, "Unknown prompt: {$name}" );
        }
    }

    /* ════════════════════════════════════════════════════════
     *  Helpers
     * ════════════════════════════════════════════════════════ */

    private static function make_result( $id, $result ): array {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ];
    }

    private static function make_error( $id, int $code, string $message ): array {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ];
    }

    private static function build_instructions(): string {
        $name  = get_bloginfo( 'name' );
        $url   = get_bloginfo( 'url' );
        $ver   = get_bloginfo( 'version' );
        $theme = wp_get_theme()->get( 'Name' );
        $parts = [ "Connected to WordPress \"{$name}\" at {$url} (WP {$ver}, theme: {$theme})." ];

        if ( defined( 'WPSEO_VERSION' ) )     $parts[] = 'Yoast SEO active (SEO meta is read and written through Yoast).';
        if ( defined( 'RANK_MATH_VERSION' ) ) $parts[] = 'RankMath active (SEO meta is read and written through RankMath).';

        $allowed = AISEOC_Router::allowed_groups();
        $parts[] = 'Enabled groups: ' . implode( ', ', $allowed ) . '.';
        $parts[] = 'Use resources/list to browse posts/pages/media. Use prompts/list for workflow templates.';
        $parts[] = 'Writes to a post clear the caches for that post (WordPress object cache plus supported page-cache plugins) and report them in caches_purged. After bulk or media changes, call flush_cache. CDN/edge caches such as Cloudflare are not cleared.';

        return implode( ' ', $parts );
    }

    /* ════════════════════════════════════════════════════════
     *  Schema shorthand helpers
     * ════════════════════════════════════════════════════════ */

    private static function tool( string $name, string $desc, array $props = [], array $req = [] ): array {
        return [
            'name'        => $name,
            'description' => $desc,
            'inputSchema' => [
                'type'       => 'object',
                'properties' => (object) $props,
                'required'   => $req,
            ],
        ];
    }

    private static function s( string $d, array $enum = [] ): array {
        $schema = [ 'type' => 'string', 'description' => $d ];
        if ( $enum ) $schema['enum'] = $enum;
        return $schema;
    }

    private static function i( string $d ): array { return [ 'type' => 'integer', 'description' => $d ]; }
    private static function b( string $d ): array { return [ 'type' => 'boolean', 'description' => $d ]; }
    private static function o( string $d ): array { return [ 'type' => 'object', 'description' => $d ]; }
    private static function arr( string $d ): array { return [ 'type' => 'array', 'description' => $d ]; }

    /* ════════════════════════════════════════════════════════
     *  Tool definitions (MCP schema — mirrors class-router.php's
     *  tool_manifest(), but with full input schemas for MCP clients)
     * ════════════════════════════════════════════════════════ */

    private static function tool_definitions(): array {
        return [
            /* ── Content ── */
            self::tool( 'create_post',
                'Create any post type (post, page, custom). Supports scheduling, excerpt, password, custom fields.',
                [
                    'title'   => self::s( 'Post title.' ),
                    'content' => self::s( 'Post content (HTML allowed, sanitized).' ),
                    'type'    => self::s( 'Post type. Default: post.' ),
                    'status'  => self::s( 'draft|publish|private|pending|future|trash. Default: draft.' ),
                    'excerpt' => self::s( 'Post excerpt.' ),
                    'slug'    => self::s( 'URL slug.' ),
                    'date'    => self::s( 'ISO datetime to schedule for (sets status=future).' ),
                    'meta'    => self::o( 'Custom field key/value pairs.' ),
                ]
            ),
            self::tool( 'update_post',
                'Update any field of an existing post.',
                [
                    'post_id' => self::i( 'Post ID.' ),
                    'title'   => self::s( 'New title.' ),
                    'content' => self::s( 'New content.' ),
                    'excerpt' => self::s( 'New excerpt.' ),
                    'slug'    => self::s( 'New slug.' ),
                    'status'  => self::s( 'New status.' ),
                    'meta'    => self::o( 'Custom field key/value pairs to set.' ),
                ],
                [ 'post_id' ]
            ),
            self::tool( 'get_post', 'Get full post data including meta and terms.', [ 'post_id' => self::i( 'Post ID.' ) ], [ 'post_id' ] ),
            self::tool( 'list_posts', 'List posts with filters (type, status, author, date, search).', [
                'type'      => self::s( 'Post type. Default: post.' ),
                'status'    => self::s( 'Status filter, or "any".' ),
                'per_page'  => self::i( 'Results per page. Default 20, max 100.' ),
                'page'      => self::i( 'Page number.' ),
                'search'    => self::s( 'Keyword search.' ),
                'author_id' => self::i( 'Filter by author.' ),
            ] ),
            self::tool( 'delete_post', 'Trash or permanently delete a post.', [
                'post_id' => self::i( 'Post ID.' ),
                'force'   => self::b( 'true = permanent delete. false = trash (default).' ),
            ], [ 'post_id' ] ),
            self::tool( 'schedule_post', 'Schedule a post to publish at a specific datetime.', [
                'post_id' => self::i( 'Post ID.' ),
                'date'    => self::s( 'ISO datetime to publish at.' ),
            ], [ 'post_id', 'date' ] ),
            self::tool( 'set_featured_image', 'Set or remove the featured image on a post.', [
                'post_id'  => self::i( 'Post ID.' ),
                'media_id' => self::i( 'Attachment ID. Omit or 0 to remove.' ),
            ], [ 'post_id' ] ),
            self::tool( 'get_taxonomies', 'List all taxonomies and their terms.' ),
            self::tool( 'assign_terms', 'Add/set/remove taxonomy terms on a post.', [
                'post_id'  => self::i( 'Post ID.' ),
                'taxonomy' => self::s( 'Taxonomy slug. Default: category.' ),
                'term_ids' => self::arr( 'Term IDs to assign.' ),
                'append'   => self::b( 'true = add to existing terms. false = replace.' ),
            ], [ 'post_id' ] ),

            /* ── SEO ── */
            self::tool( 'yoast_get_meta', 'Get all SEO meta for a post (title, description, focus keyword, canonical, OpenGraph, Twitter, robots) from whichever SEO plugin is active, Yoast SEO or RankMath. Despite the yoast_ name it works with both.', [ 'post_id' => self::i( 'Post ID.' ) ], [ 'post_id' ] ),
            self::tool( 'yoast_set_meta', 'Set SEO meta (title, description, robots, OpenGraph, Twitter, canonical, schema) on whichever SEO plugin is active, Yoast SEO or RankMath. Despite the yoast_ name it works with both. Only the fields you send are changed.', [
                'post_id'           => self::i( 'Post ID.' ),
                'seo_title'         => self::s( 'SEO title, ideally ≤60 chars.' ),
                'meta_description'  => self::s( 'Meta description, ideally ≤155 chars.' ),
                'focus_keyword'     => self::s( 'Primary focus keyword.' ),
                'canonical_url'     => self::s( 'Canonical URL override.' ),
                'og_title'          => self::s( 'OpenGraph title.' ),
                'og_description'    => self::s( 'OpenGraph description.' ),
                'og_image'          => self::s( 'OpenGraph image URL.' ),
                'twitter_title'     => self::s( 'Twitter card title.' ),
                'twitter_description' => self::s( 'Twitter card description.' ),
                'twitter_image'     => self::s( 'Twitter card image URL.' ),
                'noindex'           => self::s( 'Robots noindex flag.' ),
                'nofollow'          => self::s( 'Robots nofollow flag.' ),
                'is_cornerstone'    => self::s( 'Mark as cornerstone (Yoast) / pillar (RankMath) content.' ),
                'primary_category'  => self::s( 'Primary category term ID.' ),
                'schema_article_type' => self::s( 'Schema article type (Yoast only; ignored on RankMath).' ),
                'schema_page_type'  => self::s( 'Schema page type (Yoast only; ignored on RankMath).' ),
                'raw'               => self::o( 'Advanced: raw meta keys to write. Keys must start with _yoast_ (Yoast) or rank_math_ (RankMath).' ),
            ], [ 'post_id' ] ),
            self::tool( 'yoast_audit', 'Run a readability/keyword audit and return recommendations.', [ 'post_id' => self::i( 'Post ID.' ) ], [ 'post_id' ] ),
            self::tool( 'yoast_sitemap_ping', 'Ping search engines with updated sitemap.' ),

            /* ── Media ── */
            self::tool( 'upload_media', 'Upload an image/file from URL or base64 to the media library.', [
                'url'     => self::s( 'Source URL to download.' ),
                'base64'  => self::s( 'Base64-encoded file data (alternative to url).' ),
                'filename'=> self::s( 'Filename when using base64.' ),
                'title'   => self::s( 'Attachment title.' ),
                'alt'     => self::s( 'Alt text.' ),
                'caption' => self::s( 'Caption.' ),
                'post_id' => self::i( 'Parent post ID.' ),
            ] ),
            self::tool( 'list_media', 'Search and list media library items.', [
                'per_page'  => self::i( 'Results per page. Default: 20.' ),
                'page'      => self::i( 'Page number.' ),
                'search'    => self::s( 'Keyword search.' ),
                'mime_type' => self::s( 'MIME type filter, e.g. "image/jpeg" or "image".' ),
            ] ),
            self::tool( 'get_media', 'Get a single media item with all image sizes and metadata.', [ 'media_id' => self::i( 'Attachment ID.' ) ], [ 'media_id' ] ),
            self::tool( 'delete_media', 'Delete a media attachment from the library.', [
                'media_id' => self::i( 'Attachment ID.' ),
                'force'    => self::b( 'true = permanent delete. false = trash (default).' ),
            ], [ 'media_id' ] ),
            self::tool( 'update_media_meta', 'Update the alt text, caption, title, or description of a media item by its attachment ID.', [
                'media_id'    => self::i( 'Attachment ID.' ),
                'title'       => self::s( 'New title.' ),
                'alt'         => self::s( 'New alt text.' ),
                'caption'     => self::s( 'New caption.' ),
                'description' => self::s( 'New description.' ),
            ], [ 'media_id' ] ),
            self::tool( 'update_media_alt_by_url',
                'Update alt text for an image by its public URL instead of its attachment ID -- resolves the URL to a media_id via WordPress core first. Use this when you only have the rendered <img src>, e.g. a theme logo/header image with no wp-image-N class to read an ID from.',
                [
                    'url' => self::s( "The image's public URL exactly as it appears in page HTML." ),
                    'alt' => self::s( 'The alt text to set.' ),
                ],
                [ 'url', 'alt' ]
            ),

            /* ── Site ── */
            self::tool( 'get_site_info', 'Get WordPress site info: name, URL, version, active theme, SEO plugin detected, post/page/media counts.' ),
            self::tool( 'list_plugins', 'List installed plugins with name, version, and active status. Read-only -- cannot install/activate/deactivate.', [
                'active_only' => self::b( 'If true, return only active plugins.' ),
            ] ),
            self::tool( 'get_options', 'Read specific wp_options by key. Only a short list of site-setting keys can be read (show_on_front, page_on_front, page_for_posts, blogname, blogdescription, permalink_structure, timezone_string, gmt_offset, blog_public); every other key is returned as "[blocked]".', [
                'keys' => self::arr( 'Option key names to read.' ),
            ] ),
            self::tool( 'flush_cache', 'Clear caches so a fix shows up: the WordPress object cache plus the page cache of supported cache plugins (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround). Returns which caches were cleared. CDN/edge caches are not included.', [
                'post_id' => self::i( 'Clear only the caches for this post. Omit to clear everything.' ),
            ] ),
        ];
    }
}
