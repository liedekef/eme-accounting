<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Vat {
    // Officiële tarieven: aanpassen bij een wetswijziging, komt mee met de plugin-update.
    public const OFFICIAL_RATES = [ '6', '12', '21' ];
    private const CUSTOM_OPTION = 'eme_accounting_vat_rates_custom';

    private static function parse( string $csv ): array {
        $rates = [];
        foreach ( explode( ',', $csv ) as $r ) {
            $r = trim( $r );
            if ( is_numeric( $r ) && $r > 0 && $r < 100 ) {
                $rates[] = (string) (float) $r;
            }
        }
        return array_values( array_unique( $rates ) );
    }

    /** Eigen tarieven zoals opgeslagen (zonder officiële). */
    public static function custom(): array {
        return array_values( array_diff( self::parse( (string) get_option( self::CUSTOM_OPTION, '' ) ), self::OFFICIAL_RATES ) );
    }

    /** Het totaal: officieel + eigen, ontdubbeld en gesorteerd. */
    public static function rates(): array {
        $all = array_values( array_unique( array_merge( self::OFFICIAL_RATES, self::custom() ) ) );
        sort( $all, SORT_NUMERIC );
        return $all;
    }

    public static function save_custom( string $csv ): void {
        $custom = array_diff( self::parse( $csv ), self::OFFICIAL_RATES );
        sort( $custom, SORT_NUMERIC );
        update_option( self::CUSTOM_OPTION, implode( ',', $custom ) );
    }
}
