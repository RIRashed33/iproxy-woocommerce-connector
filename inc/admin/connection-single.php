<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$post_id = $post->ID;
$proxies = get_post_meta($post_id, 'proxy_accesses', true );
if(!is_array($proxies)){
    $proxies = [];
}
?>

<div class="wrap">
    <h1 style="margin-bottom: 24px;">Proxy Accesses - <?php echo esc_html( get_the_title($post_id) ); ?></h1>

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
                <th>Copy</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ( $proxies as $id => $proxy ) :

            $host = $proxy['hostname'] ?? '';
            $port = $proxy['port'] ?? '';
            $user = $proxy['auth']['login'] ?? '';
            $pass = $proxy['auth']['password'] ?? '';
            $order_id = $proxy['order_id'] ?? '';
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

            <!-- COPY -->
            <td>
                <button class="copy-btn" data-copy="<?php echo esc_attr($copy); ?>">📋</button>
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