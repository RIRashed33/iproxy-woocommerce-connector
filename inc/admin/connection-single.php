<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$post_id = $post->ID;
$api_key = get_option('iproxy_api_key', '');
$connection_id = get_post_meta($post_id, 'connection_id', true);

/* =========================
 * SYNC HANDLER
 * ========================= */
if ( isset($_POST['connection_proxy_sync']) ) {

    if (
        ! isset($_POST['connection_proxy_nonce']) ||
        ! wp_verify_nonce($_POST['connection_proxy_nonce'], 'iproxy_connection_proxy_sync')
    ) {
        wp_die('Security check failed');
    }

    if ( empty($api_key) || empty($connection_id) ) {
        wp_die('Missing API key or connection ID');
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
        wp_die('API request failed');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $proxy_accesses = $data['proxy_accesses'] ?? [];

    if ( ! is_array($proxy_accesses) ) {
        $proxy_accesses = [];
    }

    /* =========================
     * INDEX API PROXIES
     * ========================= */
    $api_index = [];
    $api_ids   = [];

    foreach ( $proxy_accesses as $proxy ) {

        $id = $proxy['id'] ?? '';
        if ( empty($id) ) continue;

        $api_index[$id] = $proxy;
        $api_ids[] = $id;
    }

    /* =========================
     * EXISTING DATA
     * ========================= */
    $saved_index = get_post_meta($post_id, 'proxy_accesses', true );
    if ( ! is_array($saved_index) ) {
        $saved_index = [];
    }

    $final_index = $saved_index;

    /* =========================
     * MARK ALL OLD AS NOT EXISTS
     * ========================= */
    foreach ( $final_index as $sid => $sp ) {
        $final_index[$sid]['iproxy_exists'] = 0;
    }

    /* =========================
     * MERGE API DATA (KEEP ORDER ID)
     * ========================= */
    foreach ( $api_index as $id => $proxy ) {

        $proxy['iproxy_exists'] = 1;

        // preserve order id
        $proxy['order_id'] = $saved_index[$id]['order_id'] ?? '';

        $final_index[$id] = $proxy;
    }

    update_post_meta($post_id, 'proxy_accesses', $final_index);
    update_post_meta($post_id, 'proxy_ids', $api_ids);
    update_post_meta($post_id, 'connection_proxy_last_sync', current_time('mysql'));

    echo '<div class="notice notice-success"><p>Proxies synced successfully</p></div>';
}

/* =========================
 * DELETE HANDLER
 * ========================= */
if ( isset($_POST['delete_proxy']) ) {

    if (
        ! isset($_POST['iproxy_delete_nonce']) ||
        ! wp_verify_nonce($_POST['iproxy_delete_nonce'], 'iproxy_delete_proxy')
    ) {
        wp_die('Security check failed');
    }

    $proxy_id = sanitize_text_field($_POST['proxy_id'] ?? '');

    if ( empty($proxy_id) || empty($connection_id) ) {
        wp_die('Missing data');
    }

    $response = wp_remote_request(
        "https://iproxy.online/api/console/v1/connection/{$connection_id}/proxy-access/{$proxy_id}",
        [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key
            ],
            'timeout' => 20,
        ]
    );

    if ( ! is_wp_error($response) ) {

        $proxies = get_post_meta($post_id, 'proxy_accesses', true);

        if ( is_array($proxies) ) {
            unset($proxies[$proxy_id]);
            update_post_meta($post_id, 'proxy_accesses', $proxies);
        }

        echo '<div class="notice notice-success"><p>Proxy deleted successfully</p></div>';
    }
}

/* =========================
 * LOAD PROXIES
 * ========================= */
$proxies = get_post_meta($post_id, 'proxy_accesses', true );
if ( ! is_array($proxies) ) {
    $proxies = [];
}

$api_ids = get_post_meta($post_id, 'proxy_ids', true );
if ( ! is_array($api_ids) ) {
    $api_ids = [];
}

// echo '<pre>';
// print_r($proxies);
// echo '</pre>';

?>

<div class="wrap">
    <h1>Proxy Accesses</h1>

    <div style="margin-bottom:24px;">
        <div style="display:flex;align-items:center;gap:24px;">
            
            <h2 style="margin:0;"><?php echo esc_html( get_the_title($post_id) ); ?></h2>

            <form method="post">
                <?php wp_nonce_field( 'iproxy_connection_proxy_sync', 'connection_proxy_nonce' ); ?>
                <input type="submit" name="connection_proxy_sync" class="button button-primary" value="Sync">
            </form>

            <?php
            $last_sync = get_post_meta($post_id, 'connection_proxy_last_sync', true );
            if ( $last_sync ) {
                echo '<span>Last sync: ' . esc_html($last_sync) . '</span>';
            }
            ?>
        </div>
    </div>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Order ID</th>
                <th>Host</th>
                <th>Port</th>
                <th>Login</th>
                <th>Password</th>
                <th>Expiry</th>
                <th>Status</th>
                <th>Copy</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ( $proxies as $id => $proxy ) :

            $host = $proxy['hostname'] ?? '';
            $port = $proxy['port'] ?? '';
            $user = $proxy['auth']['login'] ?? '';
            $pass = $proxy['auth']['password'] ?? '';

            $order_id = $proxy['order_id'] ?? '';
            $exists   = ! empty($proxy['iproxy_exists']);

            $copy = "{$host}:{$port}:{$user}:{$pass}";
        ?>

        <tr>
            <td><?php echo esc_html($id); ?></td>
            <td><?php echo esc_html($order_id ?: '-'); ?></td>
            <td><?php echo esc_html($host); ?></td>
            <td><?php echo esc_html($port); ?></td>
            <td><?php echo esc_html($user); ?></td>
            <td><?php echo esc_html($pass); ?></td>

            <!-- ================= EXPIRY RESTORED ================= -->
            <td>
                <?php
                $expires_raw = $proxy['expires_at'] ?? '';

                if ( ! empty($expires_raw) ) {

                    $ts  = strtotime($expires_raw);
                    $now = current_time('timestamp');

                    $is_active = ($ts && $ts > $now);

                    $formatted = date('d M Y, h:i A', $ts);

                    echo '<strong style="color:' . ($is_active ? '#329700' : '#e64404') . '">';
                    echo esc_html($formatted);
                    echo '</strong>';

                } else {
                    echo '<span style="color:#777;">No Expiry</span>';
                }
                ?>
            </td>

            <!-- ================= IPROXY STATUS ================= -->
            <td>
                <?php if ( $exists ) : ?>
                    <span style="color:#329700;font-weight:700;">Active</span>
                <?php else : ?>
                    <span style="color:#e64404;font-weight:700;">Not exists in iProxy</span>
                <?php endif; ?>
            </td>

            <!-- COPY -->
            <td>
                <button class="copy-btn" data-copy="<?php echo esc_attr($copy); ?>">📋</button>
            </td>

            <!-- DELETE -->
            <td>
                <form method="post" onsubmit="return confirm('Delete this proxy?');">
                    <input type="hidden" name="delete_proxy" value="1">
                    <input type="hidden" name="proxy_id" value="<?php echo esc_attr($id); ?>">
                    <?php wp_nonce_field('iproxy_delete_proxy', 'iproxy_delete_nonce'); ?>
                    <button class="button button-link-delete">Delete</button>
                </form>
            </td>
        </tr>

        <?php endforeach; ?>

        </tbody>
    </table>
</div>

<script>
document.addEventListener('click', function(e){
    const btn = e.target.closest('.copy-btn');
    if(!btn) return;

    navigator.clipboard.writeText(btn.dataset.copy);

    btn.textContent = "✔";
    setTimeout(()=>btn.textContent="📋",1500);
});
</script>