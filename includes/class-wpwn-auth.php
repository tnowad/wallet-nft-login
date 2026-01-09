<?php
/**
 * Handles rendering and account linking for wallet logins.
 */

class WPWN_Auth {
    private WPWN_Settings $settings;

    private WPWN_NFTs $nfts;

    private bool $should_enqueue_frontend = false;

    public function __construct( WPWN_Settings $settings, WPWN_NFTs $nfts ) {
        $this->settings = $settings;
        $this->nfts     = $nfts;
    }

    public function register(): void {
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'login_form', array( $this, 'render_login_block' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
        add_filter( 'authenticate', array( $this, 'maybe_block_password_login' ), 99, 3 );
    }

    public function register_shortcodes(): void {
        add_shortcode( 'wallet_login_button', array( $this, 'handle_shortcode' ) );
    }

    public function handle_shortcode( array $atts = array() ): string {
        $atts = shortcode_atts(
            array(
                'label' => __( 'Login with wallet', 'wallet-nft-login' ),
                'class' => '',
            ),
            $atts,
            'wallet_login_button'
        );

        $this->should_enqueue_frontend = true;

        ob_start();
        $this->render_button_markup( $atts['label'], $atts['class'], 'frontend' );

        return ob_get_clean();
    }

    public function render_login_block(): void {
        echo '<div class="wpwn-login-panel">';
        echo '<h2>' . esc_html__( 'Wallet access', 'wallet-nft-login' ) . '</h2>';
        $this->render_button_markup( __( 'Login with wallet', 'wallet-nft-login' ), 'button button-primary button-large', 'login' );
        echo '<p class="description">' . esc_html__( 'A signature request will appear inside your preferred wallet. No password is needed.', 'wallet-nft-login' ) . '</p>';
        echo '</div>';
    }

    private function render_button_markup( string $label, string $extra_class, string $context ): void {
        $providers = implode( ',', $this->settings->get_enabled_providers() );
        ?>
        <div class="wpwn-login" data-wpwn-context="<?php echo esc_attr( $context ); ?>" data-wpwn-providers="<?php echo esc_attr( $providers ); ?>">
            <button type="button" class="wpwn-login__button <?php echo esc_attr( $extra_class ); ?>" data-wpwn-login-button>
                <?php echo esc_html( $label ); ?>
            </button>
            <div class="wpwn-login__status" aria-live="polite"></div>
        </div>
        <?php
    }

    public function enqueue_login_assets(): void {
        $this->register_common_assets();
        wp_enqueue_style( 'wpwn-login', WPWN_PLUGIN_URL . 'assets/css/login.css', array(), WPWN_VERSION );
        wp_enqueue_script( 'wpwn-login' );
    }

    public function maybe_enqueue_frontend_assets(): void {
        if ( ! $this->should_enqueue_frontend && ! $this->page_contains_shortcode() ) {
            return;
        }

        $this->register_common_assets();
        wp_enqueue_script( 'wpwn-login' );
        wp_enqueue_style( 'wpwn-login', WPWN_PLUGIN_URL . 'assets/css/login.css', array(), WPWN_VERSION );
    }

    private function page_contains_shortcode(): bool {
        if ( is_admin() ) {
            return false;
        }

        $post = get_post( get_queried_object_id() );
        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        return has_shortcode( $post->post_content, 'wallet_login_button' );
    }

    private function register_common_assets(): void {
        if ( wp_script_is( 'wpwn-login', 'registered' ) ) {
            return;
        }

        wp_register_script(
            'wpwn-login',
            WPWN_PLUGIN_URL . 'assets/js/wallet-login.js',
            array(),
            WPWN_VERSION,
            true
        );

        wp_localize_script( 'wpwn-login', 'WPWN_CONFIG', array(
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
            'restBase'      => esc_url_raw( untrailingslashit( rest_url( 'wpwn/v1' ) ) ),
            'providers'     => $this->settings->get_enabled_providers(),
            'ramper'        => array(
                'appId'       => $this->settings->get( 'ramper_app_id', '' ),
                'environment' => $this->settings->get( 'ramper_environment', 'mainnet' ),
            ),
            'walletConnect' => array(
                'projectId' => $this->settings->get( 'walletconnect_project_id', '' ),
            ),
            'defaultChain'  => $this->settings->get( 'default_chain', 'ethereum-mainnet' ),
            'siteName'      => get_bloginfo( 'name' ),
            'loginRedirect' => apply_filters( 'wpwn_login_redirect', home_url() ),
        ) );
    }

    public function maybe_block_password_login( $user, string $username, string $password ) {
        if ( empty( $this->settings->get( 'disable_password_login', true ) ) ) {
            return $user;
        }

        if ( ! $user instanceof WP_User ) {
            return $user;
        }

        $wallet = get_user_meta( $user->ID, 'wpwn_wallet_address', true );

        if ( empty( $wallet ) ) {
            return $user;
        }

        return new WP_Error(
            'wpwn_password_login_blocked',
            __( 'Password-based login is disabled for wallet accounts.', 'wallet-nft-login' )
        );
    }

    public function attach_wallet_to_user( string $address ): int {
        $address = wpwn_normalize_address( $address );

        if ( empty( $address ) ) {
            throw new InvalidArgumentException( 'Empty wallet address.' );
        }

        $user = $this->find_user_by_wallet( $address );

        if ( $user ) {
            update_user_meta( $user->ID, 'wpwn_wallet_address', $address );
            update_user_meta( $user->ID, 'wpwn_latest_login', time() );

            return (int) $user->ID;
        }

        $username = $this->generate_username( $address );
        $email    = sprintf( '%s@wallet.local', substr( $address, 2 ) );
        $user_id  = wp_insert_user(
            array(
                'user_login'   => $username,
                'user_pass'    => wp_generate_password( 64 ),
                'user_email'   => apply_filters( 'wpwn_generated_email', $email, $address ),
                'display_name' => strtoupper( substr( $address, 0, 6 ) ) . '...' . substr( $address, -4 ),
                'role'         => get_option( 'default_role', 'subscriber' ),
            )
        );

        if ( is_wp_error( $user_id ) ) {
            throw new RuntimeException( $user_id->get_error_message() );
        }

        update_user_meta( $user_id, 'wpwn_wallet_address', $address );
        update_user_meta( $user_id, 'wpwn_latest_login', time() );

        return (int) $user_id;
    }

    public function find_user_by_wallet( string $address ): ?WP_User {
        $address = wpwn_normalize_address( $address );
        if ( empty( $address ) ) {
            return null;
        }

        $users = get_users(
            array(
                'meta_key'     => 'wpwn_wallet_address',
                'meta_value'   => $address,
                'number'       => 1,
                'count_total'  => false,
                'fields'       => 'all',
                'meta_compare' => '=',
            )
        );

        if ( empty( $users ) ) {
            return null;
        }

        return $users[0];
    }

    public function log_user_in( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        do_action( 'wp_login', $user->user_login, $user );
    }

    private function generate_username( string $address ): string {
        $base = 'eth_' . substr( $address, 2 );

        $username = $base;
        $i        = 1;
        while ( username_exists( $username ) ) {
            $username = $base . '_' . $i;
            $i++;
        }

        return $username;
    }
}
