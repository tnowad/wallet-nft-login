<?php
/**
 * REST API endpoints for nonce, verification, and NFT utilities.
 */

class WPWN_Rest {
    private WPWN_Settings $settings;

    private WPWN_Auth $auth;

    private WPWN_NFTs $nfts;

    public function __construct( WPWN_Settings $settings, WPWN_Auth $auth, WPWN_NFTs $nfts ) {
        $this->settings = $settings;
        $this->auth     = $auth;
        $this->nfts     = $nfts;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            'wpwn/v1',
            '/nonce',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'issue_nonce' ),
            )
        );

        register_rest_route(
            'wpwn/v1',
            '/verify',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'verify_signature' ),
                'args'                => array(
                    'message'   => array( 'required' => true ),
                    'signature' => array( 'required' => true ),
                ),
            )
        );

        register_rest_route(
            'wpwn/v1',
            '/nfts',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'current_user_nfts' ),
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            )
        );
    }

    public function issue_nonce( WP_REST_Request $request ): WP_REST_Response {
        $nonce = WPWN_SIWE::create_nonce();
        WPWN_SIWE::store_nonce( $nonce );
        $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $domain ) ) {
            $domain = wp_parse_url( home_url(), PHP_URL_PATH ) ?: home_url();
        }
        $domain = strtolower( $domain );

        return rest_ensure_response(
            array(
                'nonce'  => $nonce,
                'domain' => $domain,
                'chain'  => $this->settings->get( 'default_chain', 'ethereum-mainnet' ),
            )
        );
    }

    public function verify_signature( WP_REST_Request $request ) {
        $message   = (string) $request->get_param( 'message' );
        $signature = (string) $request->get_param( 'signature' );

        if ( empty( $message ) || empty( $signature ) ) {
            return new WP_Error( 'wpwn_missing_payload', __( 'Missing SIWE payload.', 'wallet-nft-login' ), array( 'status' => 400 ) );
        }

        try {
            $siwe = new WPWN_SIWE_Message( $message );
        } catch ( InvalidArgumentException $e ) {
            return new WP_Error( 'wpwn_invalid_message', $e->getMessage(), array( 'status' => 400 ) );
        }

        if ( ! WPWN_SIWE::nonce_is_valid( $siwe->get_nonce() ) ) {
            return new WP_Error( 'wpwn_nonce_invalid', __( 'Nonce has expired or was already used.', 'wallet-nft-login' ), array( 'status' => 400 ) );
        }

        $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $domain ) ) {
            $domain = wp_parse_url( home_url(), PHP_URL_PATH ) ?: home_url();
        }
        $domain = strtolower( $domain );
        if ( $siwe->get_domain() !== $domain ) {
            return new WP_Error( 'wpwn_domain_mismatch', __( 'Domain mismatch in SIWE message.', 'wallet-nft-login' ), array( 'status' => 400 ) );
        }

        try {
            $recovered = WPWN_Signature::recover_address( $message, $signature );
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[wpwn] Signature recovery exception: ' . $e->getMessage() );
                error_log( '[wpwn] SIWE message: ' . $message );
                error_log( '[wpwn] Signature: ' . $signature );
            }

            return new WP_Error( 'wpwn_signature_error', $e->getMessage(), array( 'status' => 400 ) );
        }

        if ( strtolower( $recovered ) !== strtolower( $siwe->get_address() ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[wpwn] address mismatch: expected=%s recovered=%s', $siwe->get_address(), $recovered ) );

                // Try a set of recovery variants and log the results to help diagnosis.
                if ( method_exists( 'WPWN_Signature', 'attempt_recovery_variants' ) ) {
                    $variants = WPWN_Signature::attempt_recovery_variants( $message, $signature );
                    foreach ( $variants as $v ) {
                        error_log( sprintf( '[wpwn] variant method=%s recovered=%s hash=%s', $v['method'], $v['recovered'] ?? 'none', $v['hash'] ) );
                    }
                }
            }

            return new WP_Error( 'wpwn_address_mismatch', __( 'Recovered address does not match SIWE payload.', 'wallet-nft-login' ), array( 'status' => 400 ) );
        }

        WPWN_SIWE::mark_nonce_used( $siwe->get_nonce() );

        try {
            $user_id = $this->auth->attach_wallet_to_user( $recovered );
            $this->auth->log_user_in( $user_id );
        } catch ( Exception $e ) {
            return new WP_Error( 'wpwn_login_failed', $e->getMessage(), array( 'status' => 500 ) );
        }

        return rest_ensure_response(
            array(
                'success'  => true,
                'address'  => $recovered,
                'userId'   => $user_id,
                'redirect' => apply_filters( 'wpwn_login_redirect', home_url(), $user_id, $recovered ),
            )
        );
    }

    public function current_user_nfts(): WP_REST_Response {
        $user_id = get_current_user_id();

        return rest_ensure_response(
            array(
                'wallet'   => wpwn_get_wallet_for_user( $user_id ),
                'contracts' => $this->nfts->describe_contracts_for_user( $user_id ),
            )
        );
    }
}
