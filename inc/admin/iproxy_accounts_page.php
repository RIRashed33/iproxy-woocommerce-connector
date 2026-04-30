<div class="wrap">
    <h1>iProxy Account</h1>

    <?php
    $api_key = get_option( 'iproxy_api_key', '' );

    if ( empty( $api_key ) ) {
        echo '<div class="notice notice-error inline"><p>Please connect a valid API key in Settings.</p></div>';
        return;
    }

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
        echo '<div class="notice notice-error inline"><p>Connection failed. Please check API or internet.</p></div>';
        return;
    }

    $status = wp_remote_retrieve_response_code( $response );
    $body   = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $status !== 200 || empty( $body ) ) {
        echo '<div class="notice notice-error inline"><p>Invalid API key or unauthorized access.</p></div>';
        return;
    }

    // Basic info
    $display_name = $body['display_name'] ?? '';
    $email        = $body['email'] ?? '';
    $balance      = $body['balance'] ?? '0';
    $created_at   = $body['created_at'] ?? '';
    $verified     = ! empty( $body['email_verified'] );

    // Extra info
    $user_id      = $body['id'] ?? '';
    $username     = $body['username'] ?? '';
    $telegram     = $body['telegram'] ?? '';
    $lang         = $body['lang_system'] ?? '';
    $proxy_prefix = $body['proxy_prefix'] ?? '';

    // Subscription info
    $subs = $body['subscriptions'] ?? [];
    ?>

    <div class="card" style="max-width:700px;padding:20px;">

        <h2>Account Overview</h2>

        <p><strong>Name:</strong> <?php echo esc_html( $display_name ); ?></p>

        <p><strong>Email:</strong> <?php echo esc_html( $email ); ?>
            <?php if ( $verified ) : ?>
                <span style="color:green;">✔ Verified</span>
            <?php else : ?>
                <span style="color:red;">✖ Not Verified</span>
            <?php endif; ?>
        </p>

        <p><strong>User ID:</strong> <?php echo esc_html( $user_id ); ?></p>

        <p><strong>Username:</strong> <?php echo esc_html( $username ); ?></p>

        <p><strong>Telegram:</strong> <?php echo esc_html( $telegram ); ?></p>

        <p><strong>Language:</strong> <?php echo esc_html( $lang ); ?></p>

        <p><strong>Balance:</strong> $<?php echo esc_html( $balance ); ?></p>

        <p><strong>Proxy Prefix:</strong> <?php echo esc_html( $proxy_prefix ); ?></p>

        <p><strong>Account Created:</strong>
            <?php echo esc_html( date( 'Y-m-d', strtotime( $created_at ) ) ); ?>
        </p>

        <hr>

        <h3>Subscriptions</h3>

        <p><strong>Legal:</strong> <?php echo ! empty( $subs['legal'] ) ? 'Yes' : 'No'; ?></p>
        <p><strong>Announcements:</strong> <?php echo ! empty( $subs['announcements'] ) ? 'Yes' : 'No'; ?></p>
        <p><strong>Newsletters:</strong> <?php echo ! empty( $subs['newsletters'] ) ? 'Yes' : 'No'; ?></p>
        <p><strong>Notifications:</strong> <?php echo ! empty( $subs['notifications'] ) ? 'Yes' : 'No'; ?></p>
        <p><strong>Promotions:</strong> <?php echo ! empty( $subs['promotions'] ) ? 'Yes' : 'No'; ?></p>

    </div>

</div>