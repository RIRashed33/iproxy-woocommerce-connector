<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$api_key = get_option( 'iproxy_api_key', '' );
$message = get_option( 'iproxy_api_status', '' );
$type = 'error';

if ( isset($_POST['iproxy_api_key']) ) {
    $api_key = $_POST['iproxy_api_key'];
    // Update in option
    update_option( 'iproxy_api_key', $api_key );

    //Check api key and Sync
    $response = wp_remote_get(
        'https://iproxy.online/api/console/v1/me',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key
            ],
            'timeout' => 20,
        ]
    );

    if ( is_wp_error( $response ) ) {
        $message = 'Unable to connect to iProxy server. Please check your internet connection.';
        update_option( 'iproxy_api_status', $message);
    } else {
        $status = wp_remote_retrieve_response_code( $response );
        if ( $status === 200 ) {
            $message = 'Connected to iProxy and synced proxy data successfully.';
            update_option( 'iproxy_api_status', 'iProxy connection active.');
            $type = 'success';
            // ✅ CALL CLASS METHOD (IMPORTANT CHANGE)
            if ( class_exists('IPROXY\\Connector\\IPROXY_WC_Connector') ) {
                IPROXY\Connector\IPROXY_WC_Connector::instance()->sync_all_connections();
            }
        } elseif ( $status === 401 ) {
            $message = 'Invalid API key. Please check and try again. (' . $status . ')';
            update_option( 'iproxy_api_status', $message);
        } elseif ( $status === 403 ) {
            $message = 'Access denied. Your API key does not have permission. (' . $status . ')';
            update_option( 'iproxy_api_status', $message);
        } elseif ( $status === 429 ) {
            $message = 'Too many requests. Please try again later. (' . $status . ')';
            update_option( 'iproxy_api_status', $message);
        } elseif ( $status >= 500 ) {
            $message = 'iProxy server error. Please try again later. (' . $status . ')';
            update_option( 'iproxy_api_status', $message);
        } else {
            $message = 'Unexpected response from iProxy. (' . $status . ')';
            update_option( 'iproxy_api_status', $message);
        }
    }
}
?>
<div class="wrap">
    <h1>iProxy Settings</h1>

    <form method="post">
        <?php
        settings_fields( 'iproxy_settings_group' );
        do_settings_sections( 'iproxy-settings' );
        submit_button();
        ?>
    </form>
    <hr>
    <?php if(!empty($message)) : ?>
    <div class="notice notice-<?php echo esc_attr( $type ); ?> inline">
        <p><?php echo esc_html( $message ); ?></p>
    </div>
    <?php endif; ?>
</div>