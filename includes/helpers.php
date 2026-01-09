<?php
/**
 * Helper functions exposed to themes and other plugins.
 */

function wpwn_plugin(): WPWN_Plugin {
    return WPWN_Plugin::instance();
}

function wpwn_current_user_has_nft( string $contract_address, $token_id = null, string $type = 'erc721', ?string $chain = null ): bool {
    if ( ! is_user_logged_in() ) {
        return false;
    }

    return wpwn_plugin()->nfts()->user_has_nft( get_current_user_id(), $contract_address, $token_id, $type, $chain );
}

function wpwn_current_user_has_any_nft( string $contract_address, ?string $chain = null, string $type = 'erc721' ): bool {
    return wpwn_current_user_has_nft( $contract_address, null, $type, $chain );
}

function wpwn_get_wallet_for_user( ?int $user_id = null ): ?string {
    $user_id = $user_id ?? get_current_user_id();

    if ( ! $user_id ) {
        return null;
    }

    $address    = get_user_meta( $user_id, 'wpwn_wallet_address', true );
    $normalized = $address ? wpwn_normalize_address( $address ) : '';

    return $normalized ?: null;
}

function wpwn_is_eth_address( string $address ): bool {
    return (bool) preg_match( '/^0x[a-f0-9]{40}$/', strtolower( $address ) );
}

function wpwn_normalize_address( ?string $address ): string {
    $address = strtolower( trim( (string) $address ) );
    if ( '' === $address ) {
        return '';
    }

    if ( ! str_starts_with( $address, '0x' ) ) {
        $address = '0x' . $address;
    }

    return wpwn_is_eth_address( $address ) ? $address : '';
}

function wpwn_pretty_json( $value ): string {
    if ( empty( $value ) ) {
        if ( is_array( $value ) && array_values( $value ) !== $value ) {
            return '{}';
        }

        return '[]';
    }

    return wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}
