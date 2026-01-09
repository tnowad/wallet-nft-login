<?php
/**
 * Ethereum signature recovery helpers.
 */

use kornrunner\Keccak;
use phpseclib3\Crypt\EC\Curves\secp256k1;
use phpseclib3\Math\BigInteger;

class WPWN_Signature {
    public static function recover_address( string $message, string $signature ): string {
        $signature = strtolower( trim( $signature ) );
        if ( ! str_starts_with( $signature, '0x' ) ) {
            throw new InvalidArgumentException( 'Signature must be hex prefixed.' );
        }

        $sig = substr( $signature, 2 );
        if ( strlen( $sig ) !== 130 ) {
            throw new InvalidArgumentException( 'Signature must be 65 bytes.' );
        }

        $r = new BigInteger( substr( $sig, 0, 64 ), 16 );
        $s = new BigInteger( substr( $sig, 64, 64 ), 16 );
        $v = new BigInteger( substr( $sig, 128, 2 ), 16 );

        $recId = (int) $v->toString();
        if ( $recId >= 27 ) {
            $recId -= 27;
        }

        if ( $recId < 0 || $recId > 3 ) {
            throw new InvalidArgumentException( 'Invalid recovery id.' );
        }

        $curve = new secp256k1();
        $n     = $curve->getOrder();
        $prime = new BigInteger( 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16 );

        if ( $r->compare( $n ) >= 0 || $s->compare( $n ) >= 0 ) {
            throw new InvalidArgumentException( 'Signature values out of range.' );
        }

        $x = clone $r;
        if ( $recId >> 1 ) {
            $x = $x->add( $n );
        }
        if ( $x->compare( $prime ) >= 0 ) {
            throw new InvalidArgumentException( 'X coordinate too large.' );
        }

        $R = self::recover_point( $curve, $x, $recId & 1, $prime );

        $msg_hash = self::hash_message( $message );
        $e        = new BigInteger( $msg_hash, 16 );

        $rInv = $r->modInverse( $n );
        $sMul = self::mod_value( $s->multiply( $rInv ), $n );
        $eNeg = self::mod_value( $n->subtract( self::mod_value( $e, $n ) ), $n );
        $eMul = self::mod_value( $eNeg->multiply( $rInv ), $n );

        // Q = r^{-1} (s * R - e * G)
        $sR  = $curve->multiplyPoint( $R, $sMul );
        $eG  = $curve->multiplyPoint( $curve->getBasePoint(), $eMul );
        $Q   = $curve->addPoint( $sR, $eG );
        $Q   = $curve->convertToAffine( $Q );

        if ( empty( $Q ) ) {
            throw new RuntimeException( 'Failed to recover public key.' );
        }

        $xHex = str_pad( $Q[0]->toBigInteger()->toHex(), 64, '0', STR_PAD_LEFT );
        $yHex = str_pad( $Q[1]->toBigInteger()->toHex(), 64, '0', STR_PAD_LEFT );

        $public_key = hex2bin( '04' . $xHex . $yHex );
        $address    = '0x' . substr( Keccak::hash( substr( $public_key, 1 ), 256 ), 24 );

        return strtolower( $address );
    }

    private static function recover_point( secp256k1 $curve, BigInteger $x, int $is_odd, BigInteger $prime ): array {
        $alpha = self::mod_value( $x->powMod( new BigInteger( 3 ), $prime )->add( new BigInteger( 7 ) ), $prime );
        $exp   = $prime->add( new BigInteger( 1 ) )->divide( new BigInteger( 4 ) )[0];
        $y     = $alpha->powMod( $exp, $prime );

        if ( (int) $y->isOdd() !== $is_odd ) {
            $y = $prime->subtract( $y );
        }

        return array(
            $curve->convertInteger( $x ),
            $curve->convertInteger( $y ),
        );
    }

    private static function hash_message( string $message ): string {
        $prefix = "\x19Ethereum Signed Message:\n" . strlen( $message );
        $hash   = Keccak::hash( $prefix . $message, 256 );

        return $hash;
    }

    public static function recover_address_from_hash( string $hashHex, string $signature ): string {
        $signature = strtolower( trim( $signature ) );
        if ( ! str_starts_with( $signature, '0x' ) ) {
            throw new InvalidArgumentException( 'Signature must be hex prefixed.' );
        }

        $sig = substr( $signature, 2 );
        if ( strlen( $sig ) !== 130 ) {
            throw new InvalidArgumentException( 'Signature must be 65 bytes.' );
        }

        $r = new BigInteger( substr( $sig, 0, 64 ), 16 );
        $s = new BigInteger( substr( $sig, 64, 64 ), 16 );
        $v = new BigInteger( substr( $sig, 128, 2 ), 16 );

        $recId = (int) $v->toString();
        if ( $recId >= 27 ) {
            $recId -= 27;
        }

        if ( $recId < 0 || $recId > 3 ) {
            throw new InvalidArgumentException( 'Invalid recovery id.' );
        }

        $curve = new secp256k1();
        $n     = $curve->getOrder();
        $prime = new BigInteger( 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16 );

        if ( $r->compare( $n ) >= 0 || $s->compare( $n ) >= 0 ) {
            throw new InvalidArgumentException( 'Signature values out of range.' );
        }

        $x = clone $r;
        if ( $recId >> 1 ) {
            $x = $x->add( $n );
        }
        if ( $x->compare( $prime ) >= 0 ) {
            throw new InvalidArgumentException( 'X coordinate too large.' );
        }

        $R = self::recover_point( $curve, $x, $recId & 1, $prime );

        $e = new BigInteger( ltrim( $hashHex, '0x' ), 16 );

        $rInv = $r->modInverse( $n );
        $sMul = self::mod_value( $s->multiply( $rInv ), $n );
        $eNeg = self::mod_value( $n->subtract( self::mod_value( $e, $n ) ), $n );
        $eMul = self::mod_value( $eNeg->multiply( $rInv ), $n );

        // Q = r^{-1} (s * R - e * G)
        $sR  = $curve->multiplyPoint( $R, $sMul );
        $eG  = $curve->multiplyPoint( $curve->getBasePoint(), $eMul );
        $Q   = $curve->addPoint( $sR, $eG );
        $Q   = $curve->convertToAffine( $Q );

        if ( empty( $Q ) ) {
            throw new RuntimeException( 'Failed to recover public key.' );
        }

        $xHex = str_pad( $Q[0]->toBigInteger()->toHex(), 64, '0', STR_PAD_LEFT );
        $yHex = str_pad( $Q[1]->toBigInteger()->toHex(), 64, '0', STR_PAD_LEFT );

        $public_key = hex2bin( '04' . $xHex . $yHex );
        $address    = '0x' . substr( Keccak::hash( substr( $public_key, 1 ), 256 ), 24 );

        return strtolower( $address );
    }

    public static function attempt_recovery_variants( string $message, string $signature ): array {
        $results = array();

        // Variant 1: canonical SIWE personal_sign (prefixed)
        $hash1 = self::hash_message( $message );
        try {
            $rec = self::recover_address_from_hash( $hash1, $signature );
            $results[] = array( 'method' => 'siwe_prefixed', 'hash' => $hash1, 'recovered' => $rec );
        } catch ( Exception $e ) {
            $results[] = array( 'method' => 'siwe_prefixed', 'hash' => $hash1, 'error' => $e->getMessage() );
        }

        // Variant 2: trimmed message (strip final whitespace)
        $trimmed = trim( $message );
        $hash2 = self::hash_message( $trimmed );
        try {
            $rec = self::recover_address_from_hash( $hash2, $signature );
            $results[] = array( 'method' => 'siwe_prefixed_trimmed', 'hash' => $hash2, 'recovered' => $rec );
        } catch ( Exception $e ) {
            $results[] = array( 'method' => 'siwe_prefixed_trimmed', 'hash' => $hash2, 'error' => $e->getMessage() );
        }

        // Variant 3: raw keccak(message) without prefix
        $rawHash = Keccak::hash( $message, 256 );
        try {
            $rec = self::recover_address_from_hash( $rawHash, $signature );
            $results[] = array( 'method' => 'raw_keccak', 'hash' => $rawHash, 'recovered' => $rec );
        } catch ( Exception $e ) {
            $results[] = array( 'method' => 'raw_keccak', 'hash' => $rawHash, 'error' => $e->getMessage() );
        }

        // Variant 4: normalized newlines (CRLF)
        $crlf = str_replace( "\n", "\r\n", $message );
        $hash3 = self::hash_message( $crlf );
        try {
            $rec = self::recover_address_from_hash( $hash3, $signature );
            $results[] = array( 'method' => 'siwe_prefixed_crlf', 'hash' => $hash3, 'recovered' => $rec );
        } catch ( Exception $e ) {
            $results[] = array( 'method' => 'siwe_prefixed_crlf', 'hash' => $hash3, 'error' => $e->getMessage() );
        }

        return $results;
    }

    private static function mod_value( BigInteger $value, BigInteger $modulus ): BigInteger {
        list( , $remainder ) = $value->divide( $modulus );
        if ( $remainder->isNegative() ) {
            $remainder = $remainder->add( $modulus );
        }

        return $remainder;
    }
}
