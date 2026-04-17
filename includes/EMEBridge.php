<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class EMEBridge {
    public static function init(): void {
        // Auto-create journal entry when EME registers a payment
        add_action( 'eme_payment_paid', [ __CLASS__, 'on_payment' ], 10, 2 );
    }

    /**
     * When EME marks a payment as paid, create a corresponding journal entry.
     *
     * @param  int   $payment_id  EME payment ID
     * @param  array $payment     EME payment data
     */
    public static function on_payment( int $payment_id, array $payment ): void {
        // Only auto-book if a default income account and COA are configured
        $settings         = get_option( 'eme_accounting_settings', [] );
        $default_account  = (int) ( $settings['default_income_account'] ?? 0 );
        $default_coa     = (int) ( $settings['default_coa'] ?? 0 );
        if ( ! $default_account || ! $default_coa ) {
            return;
        }

        // Prevent duplicate bookings
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}emeacc_journal` WHERE eme_payment_id = %d",
            $payment_id
        ) );
        if ( $exists ) return;

        Journal::save( [
            'entry_date'     => $payment['payment_date'] ?? current_time( 'Y-m-d' ),
            'description'    => sprintf(
                __( 'EME betaling #%d – %s', 'eme-accounting' ),
                $payment_id,
                $payment['event_name'] ?? ''
            ),
            'coa_id'         => $default_coa,
            'amount'         => (float) ( $payment['amount'] ?? 0 ),
            'direction'      => 'in',
            'account_id'     => $default_account,
            'eme_payment_id' => $payment_id,
            'eme_event_id'   => (int) ( $payment['event_id'] ?? 0 ),
        ] );
    }
}
