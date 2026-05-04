<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
 * DELETE CONNECTION HANDLER
 * ========================================================= */
if ( isset($_POST['delete_connection']) ) {

    if (
        ! isset($_POST['iproxy_delete_nonce']) ||
        ! wp_verify_nonce($_POST['iproxy_delete_nonce'], 'iproxy_delete_connection')
    ) {
        wp_die('Security check failed');
    }

    if ( ! current_user_can('manage_options') ) {
        wp_die('Permission denied');
    }

    $post_id = intval($_POST['post_id'] ?? 0);

    if ( $post_id ) {

        // HARD DELETE (uncomment if you want permanent delete)
        wp_delete_post($post_id, true);
        echo '<div class="notice notice-success"><p>Connection deleted</p></div>';
    }
}


/* =========================================================
 * SYNC HANDLER
 * ========================================================= */
if ( isset( $_POST['iproxy_sync'] ) ) {

    if (
        ! isset( $_POST['iproxy_sync_nonce'] ) ||
        ! wp_verify_nonce( $_POST['iproxy_sync_nonce'], 'iproxy_sync_connections' )
    ) {
        echo '<div class="notice notice-error"><p>Security check failed</p></div>';
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-error"><p>Permission denied</p></div>';
        return;
    }

    $api_key = get_option( 'iproxy_api_key', '' );

    if ( empty( $api_key ) ) {
        echo '<div class="notice notice-error"><p>API Key missing</p></div>';
        return;
    }

    /* =========================
     * API REQUEST
     * ========================= */
    $response = wp_remote_get(
        'https://iproxy.online/api/console/v1/connections',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key
            ],
            'timeout' => 20,
        ]
    );

    if ( is_wp_error( $response ) ) {
        echo '<div class="notice notice-error"><p>API request failed</p></div>';
        return;
    }

    $status = wp_remote_retrieve_response_code( $response );
    $data   = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $status !== 200 || empty( $data['connections'] ) ) {
        echo '<div class="notice notice-error"><p>Invalid API response</p></div>';
        return;
    }

    $connections = $data['connections'];

    global $wpdb;

    /* =========================
     * EXISTING POSTS MAP (FAST)
     * ========================= */
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

    /* =========================
     * FLATTEN FUNCTION
     * ========================= */
    $flatten = function( $array, $prefix = '' ) use ( &$flatten ) {

        $result = [];

        foreach ( $array as $key => $value ) {

            $new_key = $prefix ? $prefix . '_' . $key : $key;

            if ( is_array( $value ) ) {
                $result = array_merge(
                    $result,
                    $flatten( $value, $new_key )
                );
            } else {
                $result[$new_key] = $value;
            }
        }

        return $result;
    };

    $synced = 0;
    $active_ids = [];

    /* =========================
     * LOOP CONNECTIONS
     * ========================= */
    foreach ( $connections as $connection ) {

        $connection_id = $connection['id'] ?? '';

        if ( empty( $connection_id ) ) continue;

        $active_ids[] = $connection_id;

        /* =========================
         * STATUS LOGIC
         * ========================= */
        $expires_at = $connection['plan_info']['active_plan']['expires_at'] ?? null;

        if ( $expires_at ) {
            $status_label = ( strtotime($expires_at) > time() )
                ? 'Active'
                : 'Expired';
        } else {
            $status_label = 'No Active Plan';
        }

        /* =========================
         * TITLE FIX
         * ========================= */
        $connection_name = $connection['basic_info']['name'] ?? $connection_id;

        /* =========================
         * FIND OR CREATE POST
         * ========================= */
        if ( isset( $map[$connection_id] ) ) {

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

        /* =========================
         * SAVE META
         * ========================= */
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

    /* =========================
     * MARK NOT FOUND
     * ========================= */
    foreach ( $map as $conn_id => $post_id ) {

        if ( ! in_array($conn_id, $active_ids, true) ) {
            update_post_meta($post_id, 'status', 'Not Found');
        }
    }

    echo '<div class="notice notice-success"><p>Synced ' . intval($synced) . ' connections successfully</p></div>';

    update_option('iproxy_last_sync', current_time('mysql'));
}
?>

<div class="wrap">

    <div style="margin-bottom: 24px;">
        <h1>All Connections</h1>

        <div style="display:flex;align-items:center;gap:24px;">
            <form method="post">
                <?php wp_nonce_field('iproxy_sync_connections', 'iproxy_sync_nonce'); ?>
                <input type="submit" name="iproxy_sync" class="button button-primary" value="Sync All Connections">
            </form>

            <?php
            $last_sync = get_option('iproxy_last_sync', '');
            if ($last_sync) {
                echo '<span>Last sync: ' . esc_html($last_sync) . '</span>';
            }
            ?>
        </div>
    </div>

    <?php
    $posts = get_posts([
        'post_type'   => 'iproxy_connection',
        'numberposts' => -1
    ]);
    ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Name</th>
                <th>ID</th>
                <th>Status</th>
                <th>External IP</th>
                <th>SIM</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
        <?php foreach ( $posts as $post ) :

            if ( get_post_meta($post->ID, 'status', true) === 'Deleted' ) {
                continue;
            }

            $id      = get_post_meta($post->ID, 'connection_id', true);
            $status  = get_post_meta($post->ID, 'status', true);
            $ip      = get_post_meta($post->ID, 'app_data_device_info_ip_public_ipv4', true);
            $SIM = get_post_meta($post->ID, 'app_data_device_info_network_operator_mobile', true);
        ?>
            <tr>
                <td><?php echo esc_html(get_the_title($post->ID)); ?></td>
                <td><?php echo esc_html($id); ?></td>
                <td>
                    <?php if($status === 'Active') : ?>
                    <span style="color:#329700;font-weight:700;">
                        <?php echo esc_html($status); ?>
                    </span>
                    <?php else : ?>
                    <span style="color:#bd4702;font-weight:700;">
                        <?php echo esc_html($status); ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($ip); ?></td>
                <td><?php echo esc_html($SIM); ?></td>
                <td>

                    <a class="button"
                       href="<?php echo admin_url('admin.php?page=connection&post_id=' . $post->ID); ?>">
                        View
                    </a>

                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field('iproxy_delete_connection', 'iproxy_delete_nonce'); ?>
                        <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>">
                        <button type="submit"
                                name="delete_connection"
                                class="button button-link-delete"
                                onclick="return confirm('Delete this connection?');">
                            Delete
                        </button>
                    </form>

                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>