<?php
/**
 * Plugin Name: Wallet NFT Login
 * Description: Passwordless WordPress authentication via Sign-In with Ethereum plus NFT awareness helpers.
 * Plugin URI: https://github.com/PHANLAW/pressphoto
 * Author: PHANLAW
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Text Domain: wallet-nft-login
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPWN_VERSION', '0.1.0' );
define( 'WPWN_PLUGIN_FILE', __FILE__ );
define( 'WPWN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPWN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

autoload_wpwn_vendor();
require_wpwn_files();
WPWN_Plugin::init();
register_activation_hook( WPWN_PLUGIN_FILE, array( 'WPWN_Plugin', 'activate' ) );

/**
 * Load Composer autoloader if available.
 */
function autoload_wpwn_vendor(): void {
    $autoload = WPWN_PLUGIN_DIR . 'vendor/autoload.php';

    if ( file_exists( $autoload ) ) {
        require_once $autoload;
    }
}

/**
 * Require plugin class files.
 */
function require_wpwn_files(): void {
    $files = array(
        'includes/helpers.php',
        'includes/class-wpwn-settings.php',
        'includes/class-wpwn-admin.php',
        'includes/class-wpwn-siwe.php',
        'includes/class-wpwn-signature.php',
        'includes/class-wpwn-auth.php',
        'includes/class-wpwn-rest.php',
        'includes/class-wpwn-nfts.php',
        'includes/class-wpwn-plugin.php',
    );

    foreach ( $files as $file ) {
        $path = WPWN_PLUGIN_DIR . $file;

        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}
