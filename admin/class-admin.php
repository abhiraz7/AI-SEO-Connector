<?php
class AISEOC_Admin {

    public static function init(): void {
        add_action( 'admin_menu',                   [ __CLASS__, 'add_menu' ] );
        add_action( 'wp_ajax_aiseoc_save',          [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_aiseoc_regen',         [ __CLASS__, 'ajax_regen_token' ] );
        add_action( 'wp_ajax_aiseoc_clear_logs',    [ __CLASS__, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_aiseoc_create_app_pw', [ __CLASS__, 'ajax_create_app_password' ] );
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

    public static function ajax_save(): void {
        check_ajax_referer( 'aiseoc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        update_option( 'aiseoc_enabled',   isset( $_POST['enabled'] ) ? '1' : '0' );
        update_option( 'aiseoc_log_level', sanitize_key( $_POST['log_level'] ?? 'info' ) );

        $actions = array_map( 'sanitize_key', (array) ( $_POST['allowed_actions'] ?? [] ) );
        update_option( 'aiseoc_allowed_actions', json_encode( $actions ) );

        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    public static function ajax_regen_token(): void {
        check_ajax_referer( 'aiseoc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        $token = AISEOC_Auth::regenerate_token();
        wp_send_json_success( [ 'token' => $token ] );
    }

    public static function ajax_clear_logs(): void {
        check_ajax_referer( 'aiseoc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        AISEOC_Logger::clear();
        wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
    }

    public static function ajax_create_app_password(): void {
        check_ajax_referer( 'aiseoc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        try {
            $creds = AISEOC_Auth::create_application_password();
            wp_send_json_success( $creds );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    public static function render_page(): void {
        $token      = get_option( 'aiseoc_api_token', '' );
        $enabled    = get_option( 'aiseoc_enabled', '1' );
        $log_level  = get_option( 'aiseoc_log_level', 'info' );
        $actions    = json_decode( get_option( 'aiseoc_allowed_actions', '[]' ), true );
        if ( ! is_array( $actions ) ) { $actions = []; }
        $site_url     = get_bloginfo( 'url' );
        $api_base     = $site_url . '/wp-json/aiseoc/v1';
        $logs         = AISEOC_Logger::get_recent( 30 );
        $nonce        = wp_create_nonce( 'aiseoc_nonce' );
        $app_username = get_option( 'aiseoc_app_username', '' );

        $has_yoast = defined( 'WPSEO_VERSION' );
        $has_rankmath = defined( 'RANK_MATH_VERSION' );
        ?>
        <div class="aiseoc-wrap">
        <?php
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
        ?>
        </div>
        <?php
    }

    private static function get_icon_svg(): string {
        return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="#a8a8ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
}
