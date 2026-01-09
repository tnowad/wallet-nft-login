<?php
/**
 * SIWE nonce utilities and message parser.
 */

class WPWN_SIWE {
    private const TRANSIENT_PREFIX = 'wpwn_siwe_nonce_';

    public static function create_nonce(): string {
        $raw = wp_generate_password( 32, false, false );

        return substr( preg_replace( '/[^a-zA-Z0-9]/', '', $raw ), 0, 17 );
    }

    public static function store_nonce( string $nonce, int $ttl = 600 ): void {
        set_transient( self::TRANSIENT_PREFIX . $nonce, array(
            'issued' => time(),
            'used'   => false,
        ), $ttl );
    }

    public static function nonce_is_valid( string $nonce ): bool {
        $payload = get_transient( self::TRANSIENT_PREFIX . $nonce );

        if ( ! $payload ) {
            return false;
        }

        return empty( $payload['used'] );
    }

    public static function mark_nonce_used( string $nonce ): void {
        $payload = get_transient( self::TRANSIENT_PREFIX . $nonce );

        if ( ! $payload ) {
            return;
        }

        $payload['used'] = true;
        set_transient( self::TRANSIENT_PREFIX . $nonce, $payload, 60 );
    }
}

class WPWN_SIWE_Message {
    private string $raw;

    private string $domain;

    private string $address;

    private string $nonce;

    private string $uri;

    private int $chain_id;

    private string $version;

    private string $issued_at;

    private ?string $statement = null;

    private ?string $expiration = null;

    public function __construct( string $message ) {
        $this->raw = trim( str_replace( array( "\r\n", "\r" ), "\n", $message ) );

        if ( empty( $this->raw ) ) {
            throw new InvalidArgumentException( 'Empty SIWE message.' );
        }

        $this->parse();
    }

    private function parse(): void {
        $segments = preg_split( '/\n\n/', $this->raw );
        if ( count( $segments ) < 2 ) {
            throw new InvalidArgumentException( 'Malformed SIWE payload.' );
        }

        $header_block = array_shift( $segments );
        $fields_block = array_pop( $segments );
        $statement    = trim( implode( "\n\n", $segments ) );
        $header_lines = explode( "\n", $header_block );

        if ( count( $header_lines ) < 2 ) {
            throw new InvalidArgumentException( 'Incomplete SIWE header.' );
        }

        if ( ! preg_match( '/^(?P<domain>.+) wants you to sign in with your Ethereum account:$/', trim( $header_lines[0] ), $matches ) ) {
            throw new InvalidArgumentException( 'Invalid SIWE header format.' );
        }

        $this->domain  = strtolower( trim( $matches['domain'] ) );
        $this->address = wpwn_normalize_address( trim( $header_lines[1] ) );

        if ( ! wpwn_is_eth_address( $this->address ) ) {
            throw new InvalidArgumentException( 'SIWE address is invalid.' );
        }

        if ( ! empty( $statement ) ) {
            $this->statement = $statement;
        }

        $fields = $this->parse_fields_block( $fields_block );

        $this->uri        = esc_url_raw( $fields['URI'] ?? '' );
        $this->version    = $fields['Version'] ?? '1';
        $this->chain_id   = (int) ( $fields['Chain ID'] ?? 1 );
        $this->nonce      = sanitize_text_field( $fields['Nonce'] ?? '' );
        $this->issued_at  = sanitize_text_field( $fields['Issued At'] ?? '' );
        $this->expiration = isset( $fields['Expiration Time'] ) ? sanitize_text_field( $fields['Expiration Time'] ) : null;

        if ( empty( $this->uri ) || empty( $this->nonce ) || empty( $this->issued_at ) ) {
            throw new InvalidArgumentException( 'Missing SIWE required fields.' );
        }

        if ( '1' !== $this->version ) {
            throw new InvalidArgumentException( 'Unsupported SIWE version.' );
        }

        if ( ! preg_match( '/^[a-zA-Z0-9]{8,}$/', $this->nonce ) ) {
            throw new InvalidArgumentException( 'Invalid SIWE nonce.' );
        }

        $issued_timestamp = strtotime( $this->issued_at );
        if ( ! $issued_timestamp || $issued_timestamp < strtotime( '-1 day' ) ) {
            throw new InvalidArgumentException( 'Stale SIWE message.' );
        }

        if ( $this->expiration ) {
            $expiration_timestamp = strtotime( $this->expiration );
            if ( $expiration_timestamp && $expiration_timestamp < time() ) {
                throw new InvalidArgumentException( 'Expired SIWE message.' );
            }
        }
    }

    private function parse_fields_block( string $block ): array {
        $lines = explode( "\n", trim( $block ) );
        $fields = array();

        foreach ( $lines as $line ) {
            if ( false === strpos( $line, ':' ) ) {
                continue;
            }

            list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
            if ( $key && $value ) {
                $fields[ $key ] = $value;
            }
        }

        return $fields;
    }

    public function get_domain(): string {
        return $this->domain;
    }

    public function get_address(): string {
        return $this->address;
    }

    public function get_nonce(): string {
        return $this->nonce;
    }

    public function get_message(): string {
        return $this->raw;
    }

    public function get_chain_id(): int {
        return $this->chain_id;
    }
}
