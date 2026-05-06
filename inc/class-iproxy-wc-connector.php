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
        add_action('init', [ $this, 'register_plan_attribute' ]);

        //woocommerce_thankyou
        //woocommerce_payment_complete
        add_action('woocommerce_payment_complete', function($order_id) {

            try {
                $this->create_proxies_after_payment($order_id);
            } catch (Exception $e) {
                error_log('iProxy Fatal: ' . $e->getMessage());
            }

        }, 10, 1);
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

        foreach ($connections as $connection) {

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
            } else {
                $this->sync_connection_proxies( $conn_id, $post_id );
            }
        }

        update_option('iproxy_last_sync', current_time('mysql'));
    }

    //Sync Connection Proxies
    public function sync_connection_proxies ( $connection_id, $post_id ) {
        $api_key = get_option('iproxy_api_key', '');
        if (empty($api_key)) {
            error_log("iProxy: Missing API key. Proxy update cannot proceed.");
            return;
        }

        $response = wp_remote_get(
            'https://iproxy.online/api/console/v1/connection/' . $connection_id . '/proxy-access',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error($response) ) {
            error_log("iProxy: API request failed. Proxy update cannot proceed.");
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $proxy_accesses = $data['proxy_accesses'] ?? [];

        if (!is_array($proxy_accesses) ) {
            $proxy_accesses = [];
        }

        $api_index = [];
        $api_ids   = [];
        foreach ( $proxy_accesses as $proxy ) {
            $id = $proxy['id'] ?? '';
            if ( empty($id) ) continue;
            $api_index[$id] = $proxy;
            $api_ids[] = $id;
        }

        $saved_index = get_post_meta($post_id, 'proxy_accesses', true );
        if ( ! is_array($saved_index) ) {
            $saved_index = [];
        }
        $final_index = $saved_index;

        foreach ( $final_index as $sid => $sp ) {
            $final_index[$sid]['iproxy_exists'] = 0;
        }

        foreach ( $api_index as $id => $proxy ) {
            $proxy['iproxy_exists'] = 1;
            $proxy['order_id'] = $saved_index[$id]['order_id'] ?? '';
            $final_index[$id] = $proxy;
        }

        update_post_meta($post_id, 'proxy_accesses', $final_index);
        update_post_meta($post_id, 'proxy_ids', $api_ids);
        update_post_meta($post_id, 'connection_proxy_last_sync', current_time('mysql'));
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
                <option value="" disabled selected>Select Connection</option>

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

    public function register_plan_attribute() {
        if ( ! class_exists('WooCommerce') || ! function_exists('wc_create_attribute') ) {
            return;
        }
        $slug = 'plan'; // taxonomy = pa_plan
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attribute_id 
                FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
                WHERE attribute_name = %s",
                $slug
            )
        );

        if ( $exists ) {
            return;
        }

        $result = wc_create_attribute([
            'name'         => 'Plan',
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        if ( is_wp_error($result) ) {
            error_log('iProxy Plan Attribute Error: ' . $result->get_error_message());
            return;
        }

        delete_transient('wc_attribute_taxonomies');
    }

    //Store proxies in connection
    public function store_proxies( $prepared_proxies, $order_id ) {
        global $wpdb;
        foreach ( $prepared_proxies as $conn_id => $proxy ) {
            $post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = 'connection_id' 
                AND meta_value = %s 
                LIMIT 1",
                $conn_id
            ));

            if ( !$post_id ) {
                error_log("iProxy: missing post for connection {$conn_id}");
                continue;
            }

            // Load existing proxies
            $proxies = get_post_meta($post_id, 'proxy_accesses', true);

            if ( ! is_array($proxies) ) {
                $proxies = [];
            }

            // Add proxy
            $proxy_id = $proxy['id'];
            $proxy['order_id'] = "#" . $order_id;
            $proxy['iproxy_exists'] = 1;
            $proxies[$proxy_id] = $proxy;

            // Load API IDs
            $api_ids = get_post_meta($post_id, 'proxy_ids', true);

            if ( ! is_array($api_ids) ) {
                $api_ids = [];
            }

            if ( ! in_array($proxy_id, $api_ids, true) ) {
                $api_ids[] = $proxy_id;
            }

            // Save
            update_post_meta($post_id, 'proxy_accesses', $proxies);
            update_post_meta($post_id, 'proxy_ids', $api_ids);
        }
    }


    public function create_proxies_after_payment($order_id) {
        $api_key = get_option('iproxy_api_key', '');
        if ( empty($api_key) ) {
            error_log("iProxy: missing API key");
            return;
        }

        $order = wc_get_order($order_id);
        if ( ! $order ) {
            error_log("iProxy: invalid order ID {$order_id}");
            return;
        }

        $user_proxies = [];
        // $user_id = $order->get_user_id();
        // if ($user_id) {
        //     $user_proxies = get_user_meta($user_id, '_user_iproxy_proxies', true);
        // }else {
        //     error_log("iProxy: guest user, skipping proxy logic for order {$order_id}");
        // }
        $email = strtolower( trim( $order->get_billing_email() ) );
        $email_key = '_user_iproxy_proxies' . md5( $email );
        if (!empty( $email )) {
            $user_proxies = get_option( $email_key, [] );
            if ( ! is_array( $user_proxies ) ) {
                $user_proxies = [];
            }
        } else {
            error_log("iProxy: missing email for order {$order_id}");
        }

        // Prepare proxy accesses [con_id => total_days]
        $proxy_accesses_point = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! is_a($item, 'WC_Order_Item_Product') ) continue;
            $product_id = $item->get_product_id();
            if ( ! $product_id ) continue;

            $connection_id = get_post_meta($product_id, '_iproxy_connection_id', true);
            if ( empty($connection_id) ) {
                error_log("iProxy: missing connection for product {$product_id}");
                continue;
            }

            $days = intval($item->get_meta('pa_plan'));

            // fallback safety
            if ($days === 0) {
                $days = 3;
            }

            $qty = $item->get_quantity();
            $total_days = $days * $qty;

            if ( isset($proxy_accesses_point[$connection_id]) ) {
                $proxy_accesses_point[$connection_id] += $total_days;
            } else {
                $proxy_accesses_point[$connection_id] = $total_days;
            }
        }

        $prepared_proxies = [];
        foreach($proxy_accesses_point as $conn_id => $days){
            if ( isset($user_proxies[$conn_id]) && !empty($user_proxies[$conn_id]['id']) ) {
                $existing_proxy = $user_proxies[$conn_id];
                $proxy_id       = $existing_proxy['id'];
                $expires_at_raw = $existing_proxy['expires_at'] ?? '';
                $now = time();
                $expires_ts = $expires_at_raw ? strtotime($expires_at_raw) : 0;
                if ( $expires_ts && $expires_ts > $now ) {
                    $base_time = $expires_ts;
                } else {
                    $base_time = $now;
                }
                $expires_at = gmdate('Y-m-d\TH:i:s\Z', strtotime("+{$days} days", $base_time));
                $body = [
                    'expires_at'     => $expires_at,
                    'description'    => "Order ID: #{$order_id}"
                ];

                $response = wp_remote_post(
                    "https://iproxy.online/api/console/v1/connection/{$conn_id}/proxy-access/{$proxy_id}/update",
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $api_key,
                            'Content-Type'  => 'application/json',
                        ],
                        'body'    => wp_json_encode($body),
                        'timeout' => 20,
                    ]
                );

                if(is_wp_error($response) ) {
                    error_log("iProxy API error: " . $response->get_error_message());
                    continue;
                }

                $code = wp_remote_retrieve_response_code($response);
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if ( $code === 201 || !empty($data['id']) ) {
                    $prepared_proxies[$conn_id] = $data;
                    $user_proxies[$conn_id] = $data;
                } elseif ($code === 404) {
                    $expires_at = gmdate('Y-m-d\TH:i:s\Z', strtotime("+{$days} days"));
                    $body = [
                        'listen_service' => 'socks5',
                        'auth_type'      => 'userpass',
                        'description'    => "Order ID: #{$order_id}",
                        'expires_at'     => $expires_at,
                    ];

                    $response = wp_remote_post(
                        "https://iproxy.online/api/console/v1/connection/{$conn_id}/proxy-access",
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $api_key,
                                'Content-Type'  => 'application/json',
                            ],
                            'body'    => wp_json_encode($body),
                            'timeout' => 20,
                        ]
                    );

                    if ( is_wp_error($response) ) {
                        error_log("iProxy API error: " . $response->get_error_message());
                        continue;
                    }

                    $code = wp_remote_retrieve_response_code($response);
                    $data = json_decode(wp_remote_retrieve_body($response), true);
                    if ( $code !== 201 || empty($data['id']) ) {
                        error_log("iProxy: Failed to create new api");
                        continue;
                    }

                    $prepared_proxies[$conn_id] = $data;
                    $user_proxies[$conn_id] = $data;
                }
            } else {
                $expires_at = gmdate('Y-m-d\TH:i:s\Z', strtotime("+{$days} days"));
                $body = [
                    'listen_service' => 'socks5',
                    'auth_type'      => 'userpass',
                    'description'    => "Order ID: #{$order_id}",
                    'expires_at'     => $expires_at,
                ];

                $response = wp_remote_post(
                    "https://iproxy.online/api/console/v1/connection/{$conn_id}/proxy-access",
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $api_key,
                            'Content-Type'  => 'application/json',
                        ],
                        'body'    => wp_json_encode($body),
                        'timeout' => 20,
                    ]
                );

                if ( is_wp_error($response) ) {
                    error_log("iProxy API error: " . $response->get_error_message());
                    continue;
                }

                $code = wp_remote_retrieve_response_code($response);
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if ( $code !== 201 || empty($data['id']) ) {
                    error_log("iProxy: Failed to create new api");
                    continue;
                }

                $prepared_proxies[$conn_id] = $data;
                $user_proxies[$conn_id] = $data;
            }
        }

        // Store proxies in Connection
        $this->store_proxies( $prepared_proxies, $order_id );
        // Store proxies in Option By email and send Email
        if ( ! empty( $email ) ) {
            update_option( $email_key, $user_proxies );
            //SEND EMAIL AFTER SAVE
            $subject = "Your Proxy Details - Order #{$order_id}";
            $message = "Hello,\n\nYour proxy has been activated:\n\n";

            if(is_array($prepared_proxies)){
                foreach ( $prepared_proxies as $conn_id => $proxy ) {
                    $host = $proxy['hostname'] ?? '';
                    $port = $proxy['port'] ?? '';
                    $user = $proxy['auth']['login'] ?? '';
                    $pass = $proxy['auth']['password'] ?? '';
                    $expire_raw = $proxy['expires_at'] ?? '';
                    $expire = $expire_raw ? date('d M Y, h:i A', strtotime($expire_raw)) : 'No Expiry';
                    $message .= "Proxy: {$host}:{$port}:{$user}:{$pass} \n";
                    $message .= "Expire Date: {$expire}\n";
                    $message .= "----------------------------------------------\n";
                }
            }
            $message .= "Please purchase again before expiry.\n";
            wp_mail( $email, $subject, $message );
        } else {
            error_log("iProxy: missing email for order {$order_id}");
        }
    }
}