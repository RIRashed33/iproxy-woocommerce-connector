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
        add_action('edit_form_after_title', [$this, 'iproxy_connection_under_title']);
        add_action('save_post_product', [$this, 'iproxy_save_connection_under_title']);
        add_action('init', [ $this, 'register_duration_attribute' ]);
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

        add_submenu_page(
            null,
            'Connection',
            'Connection',
            'manage_options',
            'connection',
            [ $this, 'connection_page']
        );
    }

    public function iproxy_accounts_page() {
        include IPROXY_WC_PATH . 'admin/iproxy_accounts_page.php';
    }

    public function iproxy_connections_page() {
        include IPROXY_WC_PATH . 'admin/iproxy_connections_page.php';
    }

    public function settings_page() {
        include IPROXY_WC_PATH . 'admin/settings-page.php';
    }

    public function connection_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }

        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

        // 🚫 BLOCK direct/invalid access
        if ( ! $post_id ) {
            wp_die( 'Invalid request' );
        }

        $post = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'iproxy_connection' ) {
            wp_die( 'Connection not found' );
        }

        include IPROXY_WC_PATH . 'admin/connection-single.php';
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

    // All Connection
    public function sync_all_connections() {

        $api_key = get_option('iproxy_api_key', '');

        if ( empty($api_key) ) {
            return;
        }

        $response = wp_remote_get(
            'https://iproxy.online/api/console/v1/connections',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error($response) ) {
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);

        if ( $status !== 200 || empty($data['connections']) ) {
            return;
        }

        $connections = $data['connections'];

        global $wpdb;

        $existing_posts = $wpdb->get_results(
            "SELECT post_id, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = 'connection_id'",
            ARRAY_A
        );

        $map = [];

        foreach ( $existing_posts as $row ) {
            $map[$row['meta_value']] = (int) $row['post_id'];
        }

        $flatten = function( $array, $prefix = '' ) use ( &$flatten ) {

            $result = [];

            foreach ( $array as $key => $value ) {

                $new_key = $prefix ? $prefix . '_' . $key : $key;

                if ( is_array($value) ) {
                    $result = array_merge($result, $flatten($value, $new_key));
                } else {
                    $result[$new_key] = $value;
                }
            }

            return $result;
        };

        $synced = 0;
        $active_ids = [];

        foreach ( $connections as $connection ) {

            $connection_id = $connection['id'] ?? '';
            if ( empty($connection_id) ) continue;

            $active_ids[] = $connection_id;

            $expires_at = $connection['plan_info']['active_plan']['expires_at'] ?? null;

            if ( $expires_at ) {
                $status_label = ( strtotime($expires_at) > time() )
                    ? 'Active'
                    : 'Expired';
            } else {
                $status_label = 'No Active Plan';
            }

            $connection_name = $connection['basic_info']['name'] ?? $connection_id;

            if ( isset($map[$connection_id]) ) {

                $post_id = $map[$connection_id];

                wp_update_post([
                    'ID'         => $post_id,
                    'post_title' => $connection_name
                ]);

            } else {

                $post_id = wp_insert_post([
                    'post_type'   => 'iproxy_connection',
                    'post_title'  => $connection_name,
                    'post_status' => 'publish'
                ]);

                $map[$connection_id] = $post_id;
            }

            $flat_data = $flatten($connection);

            $flat_data['connection_id'] = $connection_id;
            $flat_data['status']        = $status_label;

            foreach ( $flat_data as $key => $value ) {

                if ( is_array($value) ) {
                    $value = wp_json_encode($value);
                }

                if ( is_bool($value) ) {
                    $value = $value ? 1 : 0;
                }

                update_post_meta($post_id, $key, $value);
            }

            $synced++;
        }

        foreach ( $map as $conn_id => $post_id ) {

            if ( ! in_array($conn_id, $active_ids, true) ) {
                update_post_meta($post_id, 'status', 'Not Found');
            }
        }

        update_option('iproxy_last_sync', current_time('mysql'));
    }


    // Products Connection Field Selector
    public function iproxy_connection_under_title($post) {

        // Only for product post type
        if ($post->post_type !== 'product') {
            return;
        }

        // WooCommerce safety check
        if (!class_exists('WooCommerce')) {
            return;
        }

        $selected = get_post_meta($post->ID, '_iproxy_connection_id', true);

        $connections = get_posts([
            'post_type'   => 'iproxy_connection',
            'numberposts' => -1
        ]);

        ?>
        <div class="postbox" style="margin-top:10px;padding:15px;">
            <h2 style="margin-bottom:12px;font-size:20px;padding:0;">Assign iProxy Connection to This Product</h2>

            <select name="iproxy_connection_id" style="width:100%;max-width:400px;">
                <option value="" disabled>Select Connection</option>

                <?php foreach ($connections as $conn):

                    $conn_id = get_post_meta($conn->ID, 'connection_id', true);
                    $title   = get_the_title($conn->ID);

                ?>
                    <option value="<?php echo esc_attr($conn_id); ?>"
                        <?php selected($selected, $conn_id); ?>>

                        <?php echo esc_html($title . ' (' . $conn_id . ')'); ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </div>
        <?php
    }

    public function iproxy_save_connection_under_title($post_id) {

        if (!isset($_POST['iproxy_connection_id'])) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta(
            $post_id,
            '_iproxy_connection_id',
            sanitize_text_field($_POST['iproxy_connection_id'])
        );
    }

public function register_duration_attribute() {

    if ( ! class_exists('WooCommerce') ) {
        return;
    }

    static $already_ran = false;

    // 🔒 RUNTIME LOCK (prevents same request duplicates)
    if ( $already_ran ) {
        return;
    }

    $already_ran = true;

    $slug = 'iproxy_duration';

    // 🔍 DB check (NOT cache-based)
    global $wpdb;

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
            $slug
        )
    );

    if ( $exists ) {
        return; // already exists in DB
    }

    // 🧱 CREATE ATTRIBUTE ONLY ONCE
    wc_create_attribute([
        'name'         => 'Plan Duration',
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ]);

    // 🧹 refresh cache
    delete_transient('wc_attribute_taxonomies');
}


}