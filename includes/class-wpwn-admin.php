<?php
/**
 * Admin settings page.
 */

class WPWN_Admin {
    private WPWN_Settings $settings;

    public function __construct( WPWN_Settings $settings ) {
        $this->settings = $settings;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_menu(): void {
        add_options_page(
            __( 'Wallet Login', 'wallet-nft-login' ),
            __( 'Wallet Login', 'wallet-nft-login' ),
            'manage_options',
            'wallet-nft-login',
            array( $this, 'render_page' )
        );
    }

    public function register_settings(): void {
        register_setting(
            'wallet_nft_login',
            WPWN_Settings::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => WPWN_Settings::defaults(),
            )
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options          = $this->settings->get_all();
        $rpc_pretty       = wpwn_pretty_json( $options['rpc_endpoints'] ?? array() );
        $contracts_pretty = wpwn_pretty_json( $options['nft_contracts'] ?? array() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Wallet Login', 'wallet-nft-login' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'wallet_nft_login' );
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enabled providers', 'wallet-nft-login' ); ?></th>
                        <td>
                            <?php foreach ( array( 'ramper', 'walletconnect', 'metamask' ) as $provider ) :
                                $checked = in_array( $provider, $options['providers'] ?? array(), true );
                                ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[providers][]" value="<?php echo esc_attr( $provider ); ?>" <?php checked( $checked ); ?> />
                                    <?php echo esc_html( ucfirst( $provider ) ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'At least one provider must be available for login.', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable passwords', 'wallet-nft-login' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[disable_password_login]" value="1" <?php checked( ! empty( $options['disable_password_login'] ) ); ?> />
                                <?php esc_html_e( 'Block username/password authentication for wallet-linked accounts.', 'wallet-nft-login' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Ramper App ID', 'wallet-nft-login' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[ramper_app_id]" value="<?php echo esc_attr( $options['ramper_app_id'] ?? '' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Create a Ramper project and paste the App ID here to enable the embedded wallet.', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Ramper Environment', 'wallet-nft-login' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[ramper_environment]" value="<?php echo esc_attr( $options['ramper_environment'] ?? 'mainnet' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Use mainnet, testnet, or a custom chain reference supported by Ramper.', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'WalletConnect Project ID', 'wallet-nft-login' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[walletconnect_project_id]" value="<?php echo esc_attr( $options['walletconnect_project_id'] ?? '' ); ?>" />
                            <p class="description"><?php esc_html_e( 'WalletConnect v2 requires a project ID from cloud.walletconnect.com.', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Default chain key', 'wallet-nft-login' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[default_chain]" value="<?php echo esc_attr( $options['default_chain'] ?? 'ethereum-mainnet' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Must match one of the keys defined in the RPC endpoint JSON below.', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'RPC endpoints (JSON)', 'wallet-nft-login' ); ?></th>
                        <td>
                            <textarea name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[rpc_endpoints_json]" rows="8" class="large-text code"><?php echo esc_textarea( $rpc_pretty ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Provide a JSON object where keys are chain identifiers (ethereum-mainnet, polygon-mainnet, etc.) and values are HTTPS RPC URLs (Infura, Alchemy, Ankr).', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'NFT contracts (JSON)', 'wallet-nft-login' ); ?></th>
                        <td>
                            <textarea name="<?php echo esc_attr( WPWN_Settings::OPTION_NAME ); ?>[nft_contracts_json]" rows="10" class="large-text code"><?php echo esc_textarea( $contracts_pretty ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Optional array of objects: [{"address":"0x...","type":"erc721","chain":"ethereum-mainnet","tokenId":null}] used by helper functions.', 'wallet-nft-login' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Changes', 'wallet-nft-login' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_settings( array $input ): array {
        $current = $this->settings->get_all();
        $clean   = WPWN_Settings::defaults();

        $provider_input = $input['providers'] ?? array();
        $clean['providers'] = array();
        if ( is_array( $provider_input ) ) {
            foreach ( $provider_input as $provider ) {
                $provider = sanitize_key( $provider );
                if ( in_array( $provider, array( 'ramper', 'walletconnect', 'metamask' ), true ) ) {
                    $clean['providers'][] = $provider;
                }
            }
        }

        $clean['disable_password_login'] = ! empty( $input['disable_password_login'] );
        $clean['ramper_app_id']          = sanitize_text_field( $input['ramper_app_id'] ?? '' );
        $clean['ramper_environment']     = sanitize_text_field( $input['ramper_environment'] ?? 'mainnet' );
        $clean['walletconnect_project_id'] = sanitize_text_field( $input['walletconnect_project_id'] ?? '' );
        $clean['default_chain']            = sanitize_key( $input['default_chain'] ?? ( $current['default_chain'] ?? 'ethereum-mainnet' ) );

        $rpc_json        = $input['rpc_endpoints_json'] ?? wpwn_pretty_json( $current['rpc_endpoints'] ?? array() );
        $contracts_json  = $input['nft_contracts_json'] ?? wpwn_pretty_json( $current['nft_contracts'] ?? array() );
        $clean['rpc_endpoints'] = $this->decode_map_json( $rpc_json, 'rpc_endpoints_json' );
        $clean['nft_contracts'] = $this->decode_contracts_json( $contracts_json );

        if ( empty( $clean['providers'] ) ) {
            add_settings_error( 'wallet_nft_login', 'wpwn_providers', __( 'Select at least one wallet provider.', 'wallet-nft-login' ) );
            $clean['providers'] = $current['providers'] ?? WPWN_Settings::defaults()['providers'];
        }

        if ( empty( $clean['rpc_endpoints'] ) ) {
            add_settings_error( 'wallet_nft_login', 'wpwn_chain', __( 'Provide at least one RPC endpoint.', 'wallet-nft-login' ) );
        }

        if ( ! empty( $clean['rpc_endpoints'] ) && ! array_key_exists( $clean['default_chain'], $clean['rpc_endpoints'] ) ) {
            add_settings_error( 'wallet_nft_login', 'wpwn_chain', __( 'Default chain must exist inside RPC endpoints.', 'wallet-nft-login' ) );
            $clean['default_chain'] = array_key_first( $clean['rpc_endpoints'] );
        }

        return $clean;
    }

    private function decode_map_json( string $json, string $field_key ): array {
        $decoded = json_decode( wp_unslash( $json ), true );

        if ( null === $decoded || ! is_array( $decoded ) ) {
            add_settings_error( 'wallet_nft_login', $field_key, __( 'Invalid JSON payload.', 'wallet-nft-login' ) );

            return array();
        }

        $clean = array();
        foreach ( $decoded as $chain => $url ) {
            $chain = sanitize_key( $chain );
            $url   = esc_url_raw( $url );

            if ( empty( $chain ) || empty( $url ) ) {
                continue;
            }

            $clean[ $chain ] = $url;
        }

        return $clean;
    }

    private function decode_contracts_json( string $json ): array {
        $decoded = json_decode( wp_unslash( $json ), true );

        if ( null === $decoded || ! is_array( $decoded ) ) {
            add_settings_error( 'wallet_nft_login', 'nft_contracts_json', __( 'NFT contract JSON must be an array.', 'wallet-nft-login' ) );

            return array();
        }

        $normalized = array();
        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $address = strtolower( sanitize_text_field( $entry['address'] ?? '' ) );
            if ( ! wpwn_is_eth_address( $address ) ) {
                continue;
            }

            $normalized[] = array(
                'address'  => $address,
                'type'     => in_array( $entry['type'] ?? '', array( 'erc721', 'erc1155' ), true ) ? $entry['type'] : 'erc721',
                'chain'    => sanitize_key( $entry['chain'] ?? 'ethereum-mainnet' ),
                'tokenId'  => isset( $entry['tokenId'] ) ? sanitize_text_field( (string) $entry['tokenId'] ) : null,
                'label'    => sanitize_text_field( $entry['label'] ?? '' ),
            );
        }

        return $normalized;
    }
}
