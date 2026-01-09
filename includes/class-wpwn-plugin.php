<?php
/**
 * Core plugin orchestrator.
 */

class WPWN_Plugin {
    private static ?WPWN_Plugin $instance = null;

    private WPWN_Settings $settings;

    private WPWN_Admin $admin;

    private WPWN_Auth $auth;

    private WPWN_Rest $rest;

    private WPWN_NFTs $nfts;

    public static function init(): void {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
    }

    public static function instance(): WPWN_Plugin {
        if ( null === self::$instance ) {
            self::init();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->settings = new WPWN_Settings();
        $this->nfts     = new WPWN_NFTs( $this->settings );
        $this->auth     = new WPWN_Auth( $this->settings, $this->nfts );
        $this->rest     = new WPWN_Rest( $this->settings, $this->auth, $this->nfts );
        $this->admin    = new WPWN_Admin( $this->settings );

        $this->register_hooks();
    }

    private function register_hooks(): void {
        $this->auth->register();
        $this->rest->register();
        $this->admin->register();
        $this->nfts->register();
    }

    public function settings(): WPWN_Settings {
        return $this->settings;
    }

    public function nfts(): WPWN_NFTs {
        return $this->nfts;
    }

    public function auth(): WPWN_Auth {
        return $this->auth;
    }

    public static function activate(): void {
        $defaults = WPWN_Settings::defaults();
        $stored   = get_option( WPWN_Settings::OPTION_NAME, array() );
        update_option( WPWN_Settings::OPTION_NAME, wp_parse_args( $stored, $defaults ) );
    }
}
