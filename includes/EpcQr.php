<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the EPC069-12 "SEPA Credit Transfer QR" payload and renders it as an
 * inline SVG. The QR library itself is reused from the EME plugin (same hard
 * dependency as dompdf); nothing is written to disk and no GD extension is
 * required.
 */
class EpcQr {

    private const MAX_PAYLOAD = 331;

    /**
     * @return array{payload:string,svg:string}|array{}
     */
    public static function for_document( object $row ): array {
        $payload = self::build_payload( $row );
        if ( $payload === null ) {
            return [];
        }
        $svg = self::svg( $payload );
        if ( $svg === '' ) {
            return [];
        }
        return [
            'payload' => $payload,
            'svg'     => $svg,
        ];
    }

    public static function is_payable( object $row ): bool {
        return self::build_payload( $row ) !== null;
    }

    public static function build_payload( object $row ): ?string {
        if ( ! self::is_payable_document( $row ) ) {
            return null;
        }

        $name = self::clean_text( (string) ( $row->vendor_name ?? '' ), 70 );
        $iban = self::normalise_iban( (string) ( $row->payment_iban ?? '' ) );
        $due  = abs( (float) ( $row->amount_due ?? 0 ) );
        if ( $due <= 0 ) {
            $due = abs( (float) ( $row->invoice_total ?? 0 ) );
        }
        $amount = 'EUR' . number_format( round( $due, 2 ), 2, '.', '' );

        [ $structured, $free ] = self::remittance(
            (string) ( $row->payment_reference ?? '' ),
            (string) ( $row->invoice_id ?? '' )
        );

        $lines = [
            'BCD',
            '002',
            '1',
            'SCT',
            '',
            $name,
            $iban,
            $amount,
            '',
            $structured,
            $free,
        ];

        $payload = implode( "\n", $lines );
        if ( strlen( $payload ) > self::MAX_PAYLOAD ) {
            return null;
        }
        return $payload;
    }

    public static function svg( string $payload ): string {
        if ( $payload === '' ) {
            return '';
        }
        if ( ! class_exists( 'QRCode' ) && defined( 'EME_PLUGIN_DIR' ) ) {
            $file = EME_PLUGIN_DIR . 'includes/class-qrcode.php';
            if ( is_readable( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'QRCode' ) || ! defined( 'QR_ERROR_CORRECT_LEVEL_M' ) ) {
            return '';
        }

        $qr = \QRCode::getMinimumQRCode( $payload, QR_ERROR_CORRECT_LEVEL_M );
        if ( ! $qr ) {
            return '';
        }

        $count  = (int) $qr->getModuleCount();
        $quiet  = 4;
        $total  = $count + $quiet * 2;
        $path   = [];
        for ( $y = 0; $y < $count; $y++ ) {
            for ( $x = 0; $x < $count; $x++ ) {
                if ( $qr->isDark( $y, $x ) ) {
                    $path[] = 'M' . ( $x + $quiet ) . ' ' . ( $y + $quiet ) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . ( $total * 4 ) . '" height="' . ( $total * 4 ) . '"'
            . ' role="img" aria-label="' . esc_attr__( 'Betaal-QR', 'eme-accounting' ) . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . '<path d="' . implode( '', $path ) . '" fill="#000000"/>'
            . '</svg>';
    }

    private static function is_payable_document( object $row ): bool {
        if ( (string) ( $row->processing_status ?? '' ) === 'deleted' ) {
            return false;
        }
        if ( str_contains( strtoupper( (string) ( $row->document_type ?? '' ) ), 'CREDIT_NOTE' ) ) {
            return false;
        }
        if ( strtoupper( (string) ( $row->currency ?? 'EUR' ) ) !== 'EUR' ) {
            return false;
        }
        if ( self::clean_text( (string) ( $row->vendor_name ?? '' ) ) === '' ) {
            return false;
        }
        if ( self::normalise_iban( (string) ( $row->payment_iban ?? '' ) ) === '' ) {
            return false;
        }
        $amount = abs( (float) ( $row->amount_due ?? 0 ) );
        if ( $amount <= 0 ) {
            $amount = abs( (float) ( $row->invoice_total ?? 0 ) );
        }
        return $amount > 0;
    }

    private static function normalise_iban( string $iban ): string {
        $iban = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $iban ) );
        if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban ) ) {
            return '';
        }
        return $iban;
    }

    /**
     * Belgian structured communication: 12 digits, optionally already
     * formatted as +++123/1234/12345+++. Anything else becomes free text.
     *
     * @return array{0:string,1:string} structured reference, free text
     */
    private static function remittance( string $reference, string $invoice_id ): array {
        $reference = self::clean_text( $reference, 140 );

        if ( preg_match( '/^\+\+\+\s*\d{3}\s*\/\s*\d{4}\s*\/\s*\d{5}\s*\+\+\+$/', $reference ) ) {
            $digits = preg_replace( '/\D/', '', $reference );
            return [ self::format_structured( $digits ), '' ];
        }

        $digits = preg_replace( '/\D/', '', $reference );
        if ( strlen( $digits ) === 12 && preg_match( '/^[\d\s+\/.-]+$/', $reference ) ) {
            return [ self::format_structured( $digits ), '' ];
        }

        $free = $reference;
        if ( $free === '' ) {
            $free = self::clean_text( $invoice_id, 140 );
        }
        return [ '', $free ];
    }

    private static function format_structured( string $digits ): string {
        $digits = substr( preg_replace( '/\D/', '', $digits ), 0, 12 );
        if ( strlen( $digits ) !== 12 ) {
            return '';
        }
        return '+++' . substr( $digits, 0, 3 ) . '/' . substr( $digits, 3, 4 ) . '/' . substr( $digits, 7, 5 ) . '+++';
    }

    private static function clean_text( string $value, int $max = 0 ): string {
        $value = str_replace( [ "\r", "\n", "\t" ], ' ', $value );
        $value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
        $value = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
        if ( $max > 0 ) {
            $value = mb_substr( $value, 0, $max );
        }
        return $value;
    }
}
