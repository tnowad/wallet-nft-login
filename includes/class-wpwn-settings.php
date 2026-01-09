<?php
/**
 * Settings wrapper for Wallet NFT Login.
 */

class WPWN_Settings {
    public const OPTION_NAME = 'wpwn_settings';

    private array $values;

    public function __construct() {
        $stored       = get_option( self::OPTION_NAME, array() );
        $this->values = wp_parse_args( $stored, self::defaults() );
    }

    public static function defaults(): array {
        return array(
            'providers'                => array( 'ramper', 'metamask', 'walletconnect' ),
            'ramper_app_id'            => '',
            'ramper_environment'       => 'mainnet',
            'walletconnect_project_id' => '',
            'default_chain'            => 'ethereum-mainnet',
            'rpc_endpoints'            => array(
                'ethereum-mainnet' => '',
                'ethereum-sepolia' => '',
                'polygon-mainnet'  => '',
            ),
            'nft_contracts'            => array(),
            'disable_password_login'   => true,
        );
    }

    public function get_all(): array {
        return $this->values;
    }

    public function get( string $key, $default = null ) {
        return $this->values[ $key ] ?? $default;
    }

    public function get_enabled_providers(): array {
        $providers = $this->values['providers'] ?? array();

        if ( ! is_array( $providers ) ) {
            $providers = array();
        }

        return array_values( array_unique( array_filter( $providers ) ) );
    }

    public function get_rpc_endpoint( string $chain ): ?string {
        $endpoints = $this->values['rpc_endpoints'] ?? array();

        return $endpoints[ $chain ] ?? null;
    }

    public function get_nft_contracts(): array {
        $contracts = $this->values['nft_contracts'] ?? array();

        return is_array( $contracts ) ? $contracts : array();
    }

    public function persist( array $values ): void {
        $this->values = wp_parse_args( $values, self::defaults() );
        update_option( self::OPTION_NAME, $this->values );
    }
}
