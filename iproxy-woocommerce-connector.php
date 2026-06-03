<?php
/**
 * Plugin Name: iProxy WooCommerce Connector
 * Description: Integrate WooCommerce + WooCommerce Subscriptions with iProxy API to sell SOCKS5 proxy access after purchase.
 * Version: 1.0.0
 * Author: Rashedul Islam
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: iproxy-woocommerce-connector
 *
 * @package iproxy-woocommerce-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

/**
 * Load core class
 */
require_once IPROXY_WC_PATH . 'inc/class-iproxy-wc-connector.php';

/**
 * Start
 */
if ( class_exists( '\IPROXY\Connector\IPROXY_WC_Connector' ) ) {
    \IPROXY\Connector\IPROXY_WC_Connector::instance();
}