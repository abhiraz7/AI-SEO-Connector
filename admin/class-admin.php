<?php
class AISEOC_Admin {

    /** Tool groups a caller may enable; anything else posted is dropped. */
    const KNOWN_GROUPS = [ 'content', 'seo', 'media', 'site' ];

    public static function init(): void {
        add_action( 'admin_menu',                     [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_head',                     [ __CLASS__, 'print_menu_dot_css' ] );
        add_filter( 'admin_body_class',               [ __CLASS__, 'body_class' ] );
        add_action( 'wp_ajax_aiseoc_save',            [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_aiseoc_regen',           [ __CLASS__, 'ajax_regen_token' ] );
        add_action( 'wp_ajax_aiseoc_clear_logs',      [ __CLASS__, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_aiseoc_create_app_pw',   [ __CLASS__, 'ajax_create_app_password' ] );
        add_action( 'wp_ajax_aiseoc_status',          [ __CLASS__, 'ajax_status' ] );
        add_action( 'wp_ajax_aiseoc_toggle_pause',    [ __CLASS__, 'ajax_toggle_pause' ] );
        add_action( 'wp_ajax_aiseoc_doctor',          [ __CLASS__, 'ajax_doctor' ] );
    }

    public static function add_menu(): void {
        add_menu_page(
            'AI SEO Connector',
            'AI SEO Connector',
            'manage_options',
            AISEOC_SLUG,
            [ __CLASS__, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode( self::get_icon_svg() ),
            58
        );
    }

    /**
     * Status badge on the menu ICON, visible from every admin screen.
     * It is drawn on the icon (not appended to the label) because WordPress
     * moves the label text off-screen when the sidebar is collapsed, which
     * would take a label-attached dot with it. Color is a CSS variable so the
     * settings screen can update it live without a reload.
     */
    public static function print_menu_dot_css(): void {
        $rgb   = [ 'green' => '52,211,153', 'yellow' => '251,191,36', 'red' => '248,113,113', 'white' => '229,231,235' ];
        $color = AISEOC_Status::current()['color'];
        $item  = '#adminmenu li.toplevel_page_' . AISEOC_SLUG;
        echo '<style>'
           . $item . '{--aiseoc-dot-rgb:' . ( $rgb[ $color ] ?? $rgb['white'] ) . '}'
           . $item . ' .wp-menu-image{position:relative}'
           . $item . ' .wp-menu-image::after{content:"";position:absolute;top:5px;right:3px;width:8px;height:8px;border-radius:50%;'
           . 'background:rgb(var(--aiseoc-dot-rgb));box-shadow:0 0 0 2px #1d2327,0 0 9px rgba(var(--aiseoc-dot-rgb),.85)}'
           . '</style>';
    }

    /** Lets the settings screen restyle the surrounding WordPress chrome. */
    public static function body_class( $classes ) {
        if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === AISEOC_SLUG ) {
            $classes .= ' aiseoc-screen';
        }
        return $classes;
    }

    private static function guard(): void {
        check_ajax_referer( 'aiseoc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    }

    /**
     * Saves the enabled tool groups. It deliberately does NOT touch
     * aiseoc_enabled: pausing/resuming is its own action, so saving the
     * groups can never switch the connection off as a side effect.
     */
    public static function ajax_save(): void {
        self::guard();

        update_option( 'aiseoc_log_level', sanitize_key( $_POST['log_level'] ?? 'info' ) );

        $actions = array_map( 'sanitize_key', (array) ( $_POST['allowed_actions'] ?? [] ) );
        $actions = array_values( array_intersect( $actions, self::KNOWN_GROUPS ) );
        update_option( 'aiseoc_allowed_actions', json_encode( $actions ) );

        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    public static function ajax_regen_token(): void {
        self::guard();
        $token = AISEOC_Auth::regenerate_token();
        wp_send_json_success( [ 'token' => $token, 'status' => AISEOC_Status::current() ] );
    }

    public static function ajax_clear_logs(): void {
        self::guard();
        AISEOC_Logger::clear();
        wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
    }

    public static function ajax_create_app_password(): void {
        self::guard();

        try {
            $creds = AISEOC_Auth::create_application_password();
            wp_send_json_success( $creds );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    public static function ajax_status(): void {
        self::guard();
        wp_send_json_success( AISEOC_Status::current() );
    }

    public static function ajax_toggle_pause(): void {
        self::guard();
        $paused = ! empty( $_POST['paused'] );
        update_option( 'aiseoc_enabled', $paused ? '0' : '1' );
        AISEOC_Logger::log( 'info', $paused ? 'Connection paused.' : 'Connection resumed.' );
        wp_send_json_success( [ 'status' => AISEOC_Status::current() ] );
    }

    public static function ajax_doctor(): void {
        self::guard();
        wp_send_json_success( AISEOC_Doctor::run() );
    }

    public static function render_page(): void {
        $token      = get_option( 'aiseoc_api_token', '' );
        // Tolerate the option being stored as an array (e.g. by a migration or
        // import tool) instead of a JSON string, rather than fataling the screen.
        $raw_actions = get_option( 'aiseoc_allowed_actions', '[]' );
        $actions     = is_array( $raw_actions ) ? $raw_actions : json_decode( (string) $raw_actions, true );
        if ( ! is_array( $actions ) ) { $actions = []; }
        $api_base     = AISEOC_Router::api_base();
        $logs         = AISEOC_Logger::get_recent( 30 );
        $nonce        = wp_create_nonce( 'aiseoc_nonce' );
        $app_username = get_option( 'aiseoc_app_username', '' );
        $status       = AISEOC_Status::current();

        $has_yoast    = defined( 'WPSEO_VERSION' );
        $has_rankmath = defined( 'RANK_MATH_VERSION' );

        // Accent colors follow WordPress's own Admin Color Scheme, but only
        // when the site owner deliberately picked a non-default one -- so the
        // default look stays distinctive for the common case. Curated values
        // (WP core's own hexes are too dark for a glow on this background);
        // visually unverified for schemes other than the default.
        $scheme_accents = [
            'modern'    => [ '#3858e9', '#8b5cf6' ],
            'blue'      => [ '#4796b3', '#06b6d4' ],
            'coffee'    => [ '#c7a589', '#e0b088' ],
            'ectoplasm' => [ '#a3b745', '#8b5cf6' ],
            'midnight'  => [ '#e14d43', '#f97316' ],
            'ocean'     => [ '#9ebaa0', '#5fa8d3' ],
            'sunrise'   => [ '#dd823b', '#f59e0b' ],
        ];
        $admin_color = get_user_option( 'admin_color' );
        [ $accent_1, $accent_2 ] = $scheme_accents[ $admin_color ] ?? [ '#6366f1', '#8b5cf6' ];
        $accent_1_rgb = self::hex_to_rgb( $accent_1 );
        $accent_2_rgb = self::hex_to_rgb( $accent_2 );

        try {
            include AISEOC_PLUGIN_DIR . 'admin/dashboard.php';
        } catch ( \Throwable $e ) {
            echo '<div style="margin:40px;padding:20px;border:1px solid #ef4444;border-radius:8px;background:#1a1a1a;color:#f8d7da;font-family:monospace;font-size:13px">'
               . '<strong>AI SEO Connector — dashboard error</strong><br><br>'
               . esc_html( $e->getMessage() ) . '<br>'
               . esc_html( $e->getFile() . ':' . $e->getLine() )
               . '<br><br>The API still works; this only affects the settings screen. '
               . 'Check your PHP error log for the full trace.</div>';
            AISEOC_Logger::log( 'error', 'Dashboard render failed: ' . $e->getMessage() );
        }
    }

    /** "#6366f1" -> "99,102,241", for use inside rgba(var(--x-rgb), .2). */
    private static function hex_to_rgb( string $hex ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
            return '99,102,241';
        }
        return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
    }

    /**
     * WordPress forces admin menu icons to render as a flat monochrome
     * silhouette, so this has to read clearly as a SHAPE alone, not rely on
     * color. A shield with a checkmark fits this plugin's trust-focused
     * framing better than an abstract line pattern.
     */
    private static function get_icon_svg(): string {
        return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2 4 5.5v6C4 16.5 7.4 20.7 12 22c4.6-1.3 8-5.5 8-10.5v-6L12 2Z" fill="#fff"/><path d="m8.5 12 2.2 2.2L15.5 9" stroke="#1e1e2e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
}
