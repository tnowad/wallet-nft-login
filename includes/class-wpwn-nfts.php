<?php
/**
 * NFT read helpers over public RPC providers.
 */

use phpseclib3\Math\BigInteger;

class WPWN_NFTs {
    private WPWN_Settings $settings;

    public function __construct( WPWN_Settings $settings ) {
        $this->settings = $settings;
    }

    public function register(): void {
        // reserved for future hooks (caching warmups, cron, etc.).
    }

    public function describe_contracts_for_user( int $user_id ): array {
        $wallet = wpwn_get_wallet_for_user( $user_id );
        $contracts = $this->settings->get_nft_contracts();

        if ( empty( $wallet ) || empty( $contracts ) ) {
            return array();
        }

        $summary = array();
        foreach ( $contracts as $entry ) {
            $owns = $this->user_has_nft( $user_id, $entry['address'], $entry['tokenId'] ?? null, $entry['type'] ?? 'erc721', $entry['chain'] ?? $this->settings->get( 'default_chain', 'ethereum-mainnet' ) );
            $summary[] = array_merge(
                $entry,
                array(
                    'owns' => $owns,
                )
            );
        }

        return $summary;
    }

    public function user_has_nft( int $user_id, string $contract_address, $token_id = null, string $type = 'erc721', ?string $chain = null ): bool {
        $wallet = wpwn_get_wallet_for_user( $user_id );
        if ( ! $wallet ) {
            return false;
        }

        return $this->wallet_has_nft( $wallet, $contract_address, $token_id, $type, $chain );
    }

    public function wallet_has_nft( string $wallet_address, string $contract_address, $token_id = null, string $type = 'erc721', ?string $chain = null ): bool {
        $wallet_address   = wpwn_normalize_address( $wallet_address );
        $contract_address = wpwn_normalize_address( $contract_address );
        $chain            = $chain ?: $this->settings->get( 'default_chain', 'ethereum-mainnet' );

        if ( ! wpwn_is_eth_address( $wallet_address ) || ! wpwn_is_eth_address( $contract_address ) ) {
            return false;
        }

        $cache_key = 'wpwn_nft_' . md5( implode( '|', array( $wallet_address, $contract_address, (string) $token_id, $type, $chain ) ) );
        $cached    = get_transient( $cache_key );
        if ( null !== $cached ) {
            return (bool) $cached;
        }

        $rpc = $this->settings->get_rpc_endpoint( $chain );
        if ( ! $rpc ) {
            return false;
        }

        $result = false;
        if ( 'erc1155' === strtolower( $type ) ) {
            if ( null === $token_id || '' === $token_id ) {
                return false;
            }
            $result = $this->query_erc1155_balance( $rpc, $contract_address, $wallet_address, $token_id );
        } else {
            $result = $this->query_erc721_balance( $rpc, $contract_address, $wallet_address, $token_id );
        }

        set_transient( $cache_key, $result ? 1 : 0, MINUTE_IN_SECONDS );

        return $result;
    }

    private function query_erc721_balance( string $rpc, string $contract, string $wallet, $token_id = null ): bool {
        if ( null !== $token_id && '' !== $token_id ) {
            $data = '0x6352211e' . $this->pad_32( $this->token_to_hex( $token_id ) );
            $owner = $this->eth_call( $rpc, $contract, $data );
            if ( ! $owner ) {
                return false;
            }

            $clean_owner = strtolower( ltrim( $owner, '0x' ) );
            $hex_wallet  = substr( $clean_owner, -40 );

            return wpwn_normalize_address( '0x' . $hex_wallet ) === $wallet;
        }

        $data   = '0x70a08231' . $this->pad_32( substr( $wallet, 2 ) );
        $result = $this->eth_call( $rpc, $contract, $data );
        if ( ! $result ) {
            return false;
        }

        $balance = $this->hex_to_bigint( $result );

        return $balance->compare( new BigInteger( 0 ) ) > 0;
    }

    private function query_erc1155_balance( string $rpc, string $contract, string $wallet, $token_id ): bool {
        $token_hex = $this->pad_32( $this->token_to_hex( $token_id ) );
        $data      = '0x00fdd58e' . $this->pad_32( substr( $wallet, 2 ) ) . $token_hex;

        $result = $this->eth_call( $rpc, $contract, $data );
        if ( ! $result ) {
            return false;
        }

        $balance = $this->hex_to_bigint( $result );

        return $balance->compare( new BigInteger( 0 ) ) > 0;
    }

    private function eth_call( string $rpc, string $to, string $data ): ?string {
        $payload = array(
            'jsonrpc' => '2.0',
            'id'      => wp_rand( 1000, 9999 ),
            'method'  => 'eth_call',
            'params'  => array(
                array(
                    'to'   => $to,
                    'data' => $data,
                ),
                'latest',
            ),
        );

        $response = wp_remote_post(
            $rpc,
            array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['result'] ) ) {
            return null;
        }

        return strtolower( $body['result'] );
    }

    private function pad_32( string $value ): string {
        $value = strtolower( ltrim( $value, '0x' ) );

        return str_pad( $value, 64, '0', STR_PAD_LEFT );
    }

    private function token_to_hex( $token ): string {
        if ( is_null( $token ) || '' === $token ) {
            $token = '0x0';
        }

        $token = is_numeric( $token ) ? (string) $token : (string) $token;
        $base  = str_starts_with( $token, '0x' ) ? 16 : 10;
        $big   = new BigInteger( ltrim( $token, '0x' ), $base );

        $hex = $big->toHex();
        if ( '' === $hex ) {
            $hex = '0';
        }

        return $hex;
    }

    private function hex_to_bigint( string $hex ): BigInteger {
        $clean = strtolower( ltrim( $hex, '0x' ) );
        if ( '' === $clean ) {
            $clean = '0';
        }

        return new BigInteger( $clean, 16 );
    }
}
