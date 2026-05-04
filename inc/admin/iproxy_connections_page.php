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
        wp_delete_post($post_id, true);
        echo '<div class="notice notice-success"><p>Connection deleted</p></div>';
    }
}


/* =========================================================
 * SYNC HANDLER (NOW CALL CLASS METHOD)
 * ========================================================= */
if ( isset($_POST['iproxy_sync']) ) {

    if (
        ! isset($_POST['iproxy_sync_nonce']) ||
        ! wp_verify_nonce($_POST['iproxy_sync_nonce'], 'iproxy_sync_connections')
    ) {
        wp_die('Security check failed');
    }

    if ( ! current_user_can('manage_options') ) {
        wp_die('Permission denied');
    }

    // ✅ CALL CLASS METHOD (IMPORTANT CHANGE)
    if ( class_exists('IPROXY\\Connector\\IPROXY_WC_Connector') ) {
        IPROXY\Connector\IPROXY_WC_Connector::instance()->sync_all_connections();
    }

    echo '<div class="notice notice-success"><p>Sync completed</p></div>';
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

            $id     = get_post_meta($post->ID, 'connection_id', true);
            $status = get_post_meta($post->ID, 'status', true);
            $ip     = get_post_meta($post->ID, 'app_data_device_info_ip_public_ipv4', true);
            $sim    = get_post_meta($post->ID, 'app_data_device_info_network_operator_mobile', true);

        ?>
            <tr>
                <td><?php echo esc_html(get_the_title($post->ID)); ?></td>

                <td><?php echo esc_html($id); ?></td>

                <td>
                    <?php if ($status === 'Active') : ?>
                        <span style="color:#329700;font-weight:700;"><?php echo esc_html($status); ?></span>
                    <?php else : ?>
                        <span style="color:#bd4702;font-weight:700;"><?php echo esc_html($status); ?></span>
                    <?php endif; ?>
                </td>

                <td><?php echo esc_html($ip); ?></td>

                <td><?php echo esc_html($sim); ?></td>

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