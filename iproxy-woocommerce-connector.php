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

/**
 * Load core class
 */
require_once plugin_dir_path( __FILE__ ) . 'inc/class-iproxy-wc-connector.php';

/**
 * Start
 */
if ( class_exists( '\IPROXY\Connector\IPROXY_WC_Connector' ) ) {
    \IPROXY\Connector\IPROXY_WC_Connector::instance();
}