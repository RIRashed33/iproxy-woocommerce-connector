<?php

namespace IPROXY\Connector;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class IPROXY_WC_Connector {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    private function define_constants() {

        if ( ! defined( 'IPROXY_WC_PATH' ) ) {
            define( 'IPROXY_WC_PATH', plugin_dir_path( __FILE__ ) );
        }

        if ( ! defined( 'IPROXY_WC_URL' ) ) {
            define( 'IPROXY_WC_URL', plugin_dir_url( __FILE__ ) );
        }

        if ( ! defined( 'IPROXY_WC_BASENAME' ) ) {
            define( 'IPROXY_WC_BASENAME', plugin_basename( __FILE__ ) );
        }

        if ( ! defined( 'IPROXY_WC_VERSION' ) ) {
            define( 'IPROXY_WC_VERSION', '1.0.0' );
        }
    }

    private function init_hooks() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'init', [ $this, 'register_connections_cpt' ] );
    }

    public function register_admin_menu() {

        add_menu_page(
            'Accounts',
            'iProxy',
            'manage_options',
            'iproxy',
            [ $this, 'iproxy_accounts_page' ],
            'dashicons-shield',
            3
        );

        add_submenu_page(
            'iproxy',
            'Connections',
            'All Connections',
            'manage_options',
            'iproxy-connections',
            [ $this, 'iproxy_connections_page' ],
            10
        );

        add_submenu_page(
            'iproxy',
            'Settings',
            'Settings',
            'manage_options',
            'iproxy-settings',
            [ $this, 'settings_page' ],
            30
        );
    }

    public function iproxy_accounts_page() {
        include IPROXY_WC_PATH . 'admin/iproxy_accounts_page.php';
    }

    public function iproxy_connections_page() {
        include IPROXY_WC_PATH . 'admin/iproxy_connections_page.php';
    }

    public function settings_page() {
        include plugin_dir_path( __FILE__ ) . 'admin/settings-page.php';
    }


    public function register_settings() {
        register_setting(
            'iproxy_settings_group',
            'iproxy_api_key',
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_api_key' ],
                'show_in_rest'      => false,
            ]
        );

        add_settings_section(
            'iproxy_main_section',
            'API Settings',
            null,
            'iproxy-settings'
        );

        add_settings_field(
            'iproxy_api_key',
            'API Key',
            [ $this, 'api_key_field' ],
            'iproxy-settings',
            'iproxy_main_section'
        );
    }

    public function api_key_field() {
        $value = get_option( 'iproxy_api_key', '' );
        echo '<input type="text" class="regular-text" name="iproxy_api_key" value="' . esc_attr( $value ) . '">';
    }

    public function sanitize_api_key( $value ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return get_option( 'iproxy_api_key' );
        }
        return sanitize_text_field( $value );
    }

    public function register_connections_cpt() {
        register_post_type( 'iproxy_connection', [
            'label' => 'Connections',
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'supports' => [ 'title', 'custom-fields' ],
            'exclude_from_search' => true,
            'publicly_queryable' => false,
        ] );
    }

}