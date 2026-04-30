<div class="wrap">
    <h1>iProxy Settings</h1>

    <form method="post" action="options.php">
        <?php
        settings_fields( 'iproxy_settings_group' );
        do_settings_sections( 'iproxy-settings' );
        submit_button();
        ?>
    </form>

    <hr>

    <?php
$api_key = get_option( 'iproxy_api_key', '' );
$message = '';
$type = 'error';

if ( empty( $api_key ) ) {

    $message = 'Please enter your iProxy API key to connect.';
    
} else {

    $response = wp_remote_get(
        'https://iproxy.online/api/console/v1/me',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key
            ],
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) {

        $message = 'Unable to connect to iProxy server. Please check your internet connection.';
        
    } else {

        $status = wp_remote_retrieve_response_code( $response );

        if ( $status === 200 ) {

            $message = 'Connected to iProxy successfully.';
            $type = 'success';

        } elseif ( $status === 401 ) {

            $message = 'Invalid API key. Please check and try again. (' . $status . ')';

        } elseif ( $status === 403 ) {

            $message = 'Access denied. Your API key does not have permission. (' . $status . ')';

        } elseif ( $status === 429 ) {

            $message = 'Too many requests. Please try again later. (' . $status . ')';

        } elseif ( $status >= 500 ) {

            $message = 'iProxy server error. Please try again later. (' . $status . ')';

        } else {

            $message = 'Unexpected response from iProxy. (' . $status . ')';
        }
    }
}
?>

<div class="notice notice-<?php echo esc_attr( $type ); ?> inline">
    <p><?php echo esc_html( $message ); ?></p>
</div>