<?php
class AISEOC_Admin {

    /** Tool groups a caller may enable; anything else posted is dropped. */
    const KNOWN_GROUPS = [ 'content', 'seo', 'media', 'site' ];

    public static function init(): void {
        add_action( 'admin_menu',                     [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_head',                     [ __CLASS__, 'print_menu_dot_css' ] );
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
        $rgb   = [ 'green' => '0,163,42', 'yellow' => '219,166,23', 'red' => '214,54,56', 'white' => '140,143,148' ];
        $color = AISEOC_Status::current()['color'];
        $item  = '#adminmenu li.toplevel_page_' . AISEOC_SLUG;
        echo '<style>'
           . $item . '{--aiseoc-dot-rgb:' . ( $rgb[ $color ] ?? $rgb['white'] ) . '}'
           . $item . ' .wp-menu-image{position:relative}'
           . $item . ' .wp-menu-image::after{content:"";position:absolute;top:5px;right:3px;width:8px;height:8px;border-radius:50%;'
           . 'background:rgb(var(--aiseoc-dot-rgb));box-shadow:0 0 0 2px #1d2327}'
           . '</style>';
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

        try {
            include AISEOC_PLUGIN_DIR . 'admin/dashboard.php';
        } catch ( \Throwable $e ) {
            echo '<div class="notice notice-error" style="padding:12px 16px;font-family:monospace">'
               . '<strong>AI SEO Connector — dashboard error</strong><br><br>'
               . esc_html( $e->getMessage() ) . '<br>'
               . esc_html( $e->getFile() . ':' . $e->getLine() )
               . '<br><br>The API still works; this only affects the settings screen. '
               . 'Check your PHP error log for the full trace.</div>';
            AISEOC_Logger::log( 'error', 'Dashboard render failed: ' . $e->getMessage() );
        }
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
