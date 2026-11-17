<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * Peppol inbox: storage, list/detail, booking and journal links.
 *
 * Documents are stored in {prefix}emeacc_peppol_documents and their UBL / PDF /
 * extra attachments on disk (via EMEFileAttachments). The inbox does not know
 * where a document came from: sources push into it through
 * import_external_document() / upsert_document():
 *
 *   - PeppolEInvoice  e-invoice.be API (webhook + poll)
 *   - PeppolImap      forwarded mails (IMAP mailbox)
 *
 * Documents are "ignored", never deleted: a removed row would be re-imported
 * by the next poll/scan.
 */
class Peppol {

    const OPTION = 'eme_accounting_peppol_settings';
    const TABLE  = 'emeacc_peppol_documents';
    const TYPE   = 'peppol';

    const DOCUMENT_TYPES = [ 'INVOICE', 'CREDIT_NOTE', 'SELFBILLING_INVOICE', 'SELFBILLING_CREDIT_NOTE' ];

    /** @var \EMEFileAttachments|null */
    private static ?\EMEFileAttachments $attachments = null;

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_peppol_list',   [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_get',    [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_ignore', [ __CLASS__, 'ajax_ignore' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_book',   [ __CLASS__, 'ajax_book' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_link',   [ __CLASS__, 'ajax_link_journal' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_unlink', [ __CLASS__, 'ajax_unlink' ] );

        add_action( 'eme_accounting_journal_deleted', [ __CLASS__, 'on_journal_deleted' ] );
    }

    /* ------------------------------------------------------------------
     * Attachments
     * ------------------------------------------------------------------ */

    public static function get_attachments(): \EMEFileAttachments {
        if ( self::$attachments === null ) {
            $upload            = wp_upload_dir();
            self::$attachments = new \EMEFileAttachments(
                $upload['basedir'] . '/eme-accounting',
                $upload['baseurl'] . '/eme-accounting'
            );
        }
        return self::$attachments;
    }

    /* ------------------------------------------------------------------
     * Settings (one option shared by all Peppol sources)
     * ------------------------------------------------------------------ */

    public static function get_settings(): array {
        return wp_parse_args( get_option( self::OPTION, [] ), [
            'api_key'             => '',
            'company_name'        => '',
            'poll_enabled'        => 1,
            'initial_sync_mode'   => 'now',
            'cursor_created_at'   => '',
            'backfill_page'       => 0,
            'backfill_done'       => 1,
            'webhook_id'          => '',
            'webhook_secret'      => '',
            'webhook_url_override'=> '',
            'last_poll_at'        => '',
            'last_poll_status'    => '',
            'imap_enabled'        => 0,
            'imap_host'           => '',
            'imap_port'           => 993,
            'imap_encryption'     => 'ssl',
            'imap_username'       => '',
            'imap_password'       => '',
            'imap_folder'         => 'INBOX',
            'imap_max_messages'   => 25,
            'imap_checked_at'     => 0,   // unix time of the last successful mailbox scan
            'imap_last_poll_at'   => '',
            'imap_last_poll_status'=> '',
        ] );
    }

    public static function update_settings( array $settings ): void {
        unset( $settings['cursor_ids'], $settings['imap_mark_seen'] ); // legacy keys
        update_option( self::OPTION, $settings, false );
    }

    /* ------------------------------------------------------------------
     * AJAX — list / detail / ignore
     * ------------------------------------------------------------------ */

    public static function ajax_list(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'peppol' );
        global $wpdb;

        $type          = isset( $_POST['document_type'] ) && $_POST['document_type'] !== '' ? strtoupper( (string) eme_sanitize_request( $_POST['document_type'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $peppol_status = isset( $_POST['peppol_status'] ) && $_POST['peppol_status'] !== '' ? strtoupper( (string) eme_sanitize_request( $_POST['peppol_status'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! in_array( $type, self::DOCUMENT_TYPES, true ) ) {
            $type = '';
        }
        $booking_status = isset( $_POST['booking_status'] ) && $_POST['booking_status'] !== '' ? sanitize_key( eme_sanitize_request( $_POST['booking_status'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! in_array( $booking_status, [ 'unbooked', 'booked' ], true ) ) {
            $booking_status = '';
        }

        $args = [
            'search'         => isset( $_POST['search'] ) && $_POST['search'] !== '' ? eme_sanitize_request( $_POST['search'] ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'document_type'  => $type,
            'status'         => isset( $_POST['status'] ) && $_POST['status'] !== '' ? sanitize_key( eme_sanitize_request( $_POST['status'] ) ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'booking_status' => $booking_status,
            'peppol_status'  => $peppol_status,
            'daterange'      => isset( $_POST['daterange'] ) && $_POST['daterange'] !== '' ? eme_sanitize_request( $_POST['daterange'] ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ];

        [ $where_sql, $prepare ] = self::build_where( $args );
        $table = $wpdb->prefix . self::TABLE;

        if ( $prepare ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` {$where_sql}", ...$prepare ) ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` {$where_sql}" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $limit = eme_get_ftable_limit();
        $order = self::build_order( eme_get_ftable_orderby() );
        $sql   = "SELECT * FROM `{$table}` {$where_sql} ORDER BY {$order} {$limit}";

        if ( $prepare ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare ) ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } else {
            $rows = $wpdb->get_results( $sql ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $records = [];
        foreach ( (array) $rows as $row ) {
            unset( $row->raw_json, $row->items_json, $row->tax_details_json, $row->attachments_json );
            $row->invoice_date_display = $row->invoice_date ? eme_localized_date( $row->invoice_date, EME_TIMEZONE ) : '';
            $row->due_date_display     = $row->due_date ? eme_localized_date( $row->due_date, EME_TIMEZONE ) : '';
            $row->received_at_display  = $row->received_at ? eme_localized_date( $row->received_at, EME_TIMEZONE ) : '';
            $row->journal_edit_url     = ! empty( $row->journal_id ) ? admin_url( 'admin.php?page=emeaccounting-journal&edit=' . (int) $row->journal_id ) : '';
            $row->booking_status       = ! empty( $row->journal_id ) ? 'booked' : 'unbooked';
            $row->files                = self::files_html( (int) $row->id );
            $records[]                 = $row;
        }

        wp_send_json( [
            'Result'           => 'OK',
            'Records'          => $records,
            'TotalRecordCount' => $total,
        ] );
    }

    public static function ajax_get(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'peppol' );
        global $wpdb;

        $id  = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}" . self::TABLE . "` WHERE id = %d", $id ) );
        if ( ! $row ) {
            wp_send_json_error( __( 'Document niet gevonden.', 'eme-accounting' ) );
        }

        $row->items           = json_decode( (string) $row->items_json, true ) ?: [];
        $row->tax_details     = json_decode( (string) $row->tax_details_json, true ) ?: [];
        $row->attachments     = json_decode( (string) $row->attachments_json, true ) ?: [];
        $row->files           = self::files_html( (int) $row->id );
        $row->invoice_date_display = $row->invoice_date ? eme_localized_date( $row->invoice_date, EME_TIMEZONE ) : '';
        $row->journal_edit_url = ! empty( $row->journal_id ) ? admin_url( 'admin.php?page=emeaccounting-journal&edit=' . (int) $row->journal_id ) : '';
        $row->booking_status   = ! empty( $row->journal_id ) ? 'booked' : 'unbooked';
        unset( $row->raw_json );

        wp_send_json_success( $row );
    }

    public static function ajax_ignore(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );
        global $wpdb;

        $id      = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $ignored = (int) (bool) eme_sanitize_request( $_POST['ignored'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $status  = $ignored ? 'ignored' : 'new';

        $updated = $wpdb->update( $wpdb->prefix . self::TABLE, [ 'processing_status' => $status ], [ 'id' => $id ] );
        if ( $updated === false ) {
            wp_send_json_error( __( 'Bijwerken mislukt.', 'eme-accounting' ) );
        }
        wp_send_json_success( [ 'status' => $status ] );
    }

    /* ------------------------------------------------------------------
     * AJAX — booking and journal links
     * ------------------------------------------------------------------ */

    public static function ajax_book(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $id  = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $row = self::get_document_row( $id );
        if ( ! $row ) {
            wp_send_json_error( __( 'Document niet gevonden.', 'eme-accounting' ) );
        }
        if ( ! empty( $row->journal_id ) ) {
            wp_send_json_error( __( 'Dit document is al geboekt.', 'eme-accounting' ) );
        }
        if ( (string) $row->processing_status === 'ignored' ) {
            wp_send_json_error( __( 'Dit document is genegeerd. Zet het eerst terug op nieuw.', 'eme-accounting' ) );
        }

        $journal_id = (int) eme_sanitize_request( $_POST['journal_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $journal_id > 0 ) {
            $result = self::link_document_to_journal( $row, $journal_id );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
            wp_send_json_success( [
                'journal_id'      => $journal_id,
                'journal_edit_url'=> admin_url( 'admin.php?page=emeaccounting-journal&edit=' . $journal_id ),
                'booking_status'  => 'booked',
            ] );
        }

        $account_id = (int) eme_sanitize_request( $_POST['account_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $coa_id     = (int) eme_sanitize_request( $_POST['coa_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $account_id <= 0 ) {
            wp_send_json_error( __( 'Kies een financiële rekening.', 'eme-accounting' ) );
        }
        $account = Accounts::get( $account_id );
        if ( ! $account || ! (int) $account->is_active ) {
            wp_send_json_error( __( 'Ongeldige of inactieve financiële rekening.', 'eme-accounting' ) );
        }
        $coa = $coa_id > 0 ? ChartOfAccounts::get( $coa_id ) : null;
        if ( ! $coa ) {
            wp_send_json_error( __( 'Kies een geldige rubriek uit het rekeningstelsel.', 'eme-accounting' ) );
        }
        if ( (string) $coa->direction !== self::booking_direction( $row ) ) {
            wp_send_json_error( __( 'De gekozen rubriek past niet bij de richting van dit document.', 'eme-accounting' ) );
        }

        $entry_date = eme_sanitize_request( $_POST['entry_date'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $entry_date ) ) {
            $entry_date = self::date_or_null( $row->invoice_date ?? null ) ?: current_time( 'Y-m-d' );
        }

        $amount_raw = (string) eme_sanitize_request( $_POST['amount'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $amount_raw = str_replace( ' ', '', $amount_raw );
        if ( str_contains( $amount_raw, ',' ) ) {
            $amount_raw = str_replace( '.', '', $amount_raw );
            $amount_raw = str_replace( ',', '.', $amount_raw );
        }
        $amount = round( (float) $amount_raw, 2 );
        if ( $amount <= 0 ) {
            $amount = self::booking_amount( $row );
        }
        if ( $amount <= 0 ) {
            wp_send_json_error( __( 'Dit document heeft geen bruikbaar bedrag.', 'eme-accounting' ) );
        }

        $description = trim( (string) eme_sanitize_request( $_POST['description'] ?? '' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $description === '' ) {
            $description = self::booking_description( $row );
        }
        $description = mb_substr( $description, 0, 255 );

        $reference = trim( (string) eme_sanitize_request( $_POST['bank_statement_ref'] ?? '' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $reference === '' ) {
            $reference = self::booking_reference( $row );
        }
        $reference = mb_substr( $reference, 0, 100 );

        $force_create = (int) eme_sanitize_request( $_POST['force_create'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! $force_create ) {
            $candidates = self::booking_candidates( $row, $account_id, $amount );
            if ( $candidates ) {
                wp_send_json_success( [
                    'needs_choice' => true,
                    'candidates'   => $candidates,
                ] );
            }
        }

        [ $vat_code, $vat_amount ] = self::booking_vat( $row );

        $journal_id = Journal::save( [
            'entry_date'              => $entry_date,
            'description'             => $description,
            'amount'                  => $amount,
            'direction'               => self::booking_direction( $row ),
            'account_id'              => $account_id,
            'coa_id'                  => $coa_id,
            'bank_statement_ref'      => $reference,
            'vat_code'                => $vat_code,
            'vat_amount'              => $vat_amount,
            'counterparty_vat_number' => mb_substr( (string) $row->vendor_tax_id, 0, 100 ),
            'counterparty_name'       => mb_substr( (string) $row->vendor_name, 0, 150 ),
            'is_reconciled'           => 0,
            'notes'                   => sprintf( 'Peppol document: %s', (string) $row->document_id ),
        ] );
        if ( is_wp_error( $journal_id ) ) {
            wp_send_json_error( $journal_id->get_error_message() );
        }
        if ( ! $journal_id ) {
            wp_send_json_error( __( 'De boeking kon niet worden aangemaakt.', 'eme-accounting' ) );
        }

        $linked = self::link_document_to_journal( $row, (int) $journal_id );
        if ( is_wp_error( $linked ) ) {
            Journal::delete( (int) $journal_id );
            wp_send_json_error( $linked->get_error_message() );
        }

        wp_send_json_success( [
            'journal_id'       => (int) $journal_id,
            'journal_edit_url' => admin_url( 'admin.php?page=emeaccounting-journal&edit=' . (int) $journal_id ),
            'booking_status'   => 'booked',
        ] );
    }

    public static function ajax_link_journal(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $id         = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $journal_id = (int) eme_sanitize_request( $_POST['journal_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $row        = self::get_document_row( $id );
        if ( ! $row ) {
            wp_send_json_error( __( 'Document niet gevonden.', 'eme-accounting' ) );
        }
        if ( $journal_id <= 0 ) {
            wp_send_json_error( __( 'Ongeldige boeking.', 'eme-accounting' ) );
        }

        $result = self::link_document_to_journal( $row, $journal_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [
            'journal_id'       => $journal_id,
            'journal_edit_url' => admin_url( 'admin.php?page=emeaccounting-journal&edit=' . $journal_id ),
            'booking_status'   => 'booked',
        ] );
    }

    public static function ajax_unlink(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );
        global $wpdb;

        $id  = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $row = self::get_document_row( $id );
        if ( ! $row ) {
            wp_send_json_error( __( 'Document niet gevonden.', 'eme-accounting' ) );
        }

        $table   = $wpdb->prefix . self::TABLE;
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET journal_id = NULL, booked_at = NULL, booked_by = NULL
             WHERE id = %d",
            $id
        ) );
        if ( $updated === false ) {
            wp_send_json_error( __( 'Ontkoppelen mislukt.', 'eme-accounting' ) );
        }

        wp_send_json_success( [ 'booking_status' => 'unbooked' ] );
    }

    /* ------------------------------------------------------------------
     * Attachments (dispatched from Journal)
     * ------------------------------------------------------------------ */

    public static function ajax_delete_attachment(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        // Received invoices are part of the bookkeeping archive: not deletable
        // from the UI (use "negeren" to hide a document instead).
        wp_send_json_error( __( 'Ontvangen Peppol-documenten kunnen niet verwijderd worden.', 'eme-accounting' ) );
    }

    public static function ajax_download_attachment(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'peppol' );

        $type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
        if ( $type !== self::TYPE ) {
            wp_die( 'Invalid attachment type.', '', [ 'response' => 400 ] );
        }
        self::get_attachments()->ajax_download();
    }

    /* ------------------------------------------------------------------
     * Import / storage (used by every source)
     * ------------------------------------------------------------------ */

    /**
     * Store a document that did not come from the e-invoice.be API (IMAP).
     *
     * @param array<int,array{name:string,contents:string,key?:string,type?:string}> $files
     * @return int|\WP_Error
     */
    public static function import_external_document( array $doc, string $xml = '', array $files = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // The same invoice can arrive through the e-invoice.be API and through
        // a forwarded mail. Reuse the existing row in that case so the
        // XML/PDF is attached to the booking the user already sees.
        $document_id = self::s( $doc['id'] ?? '', 64 );
        $exists      = $document_id !== ''
            ? $wpdb->get_row( $wpdb->prepare( "SELECT document_id, peppol_status FROM `{$table}` WHERE document_id = %s", $document_id ) )
            : null;
        if ( $exists ) {
            $doc['id'] = $exists->document_id;
            $doc['_preserve_status'] = (string) $exists->peppol_status !== '';
        } else {
            $invoice_id   = self::s( $doc['invoice_id'] ?? '', 100 );
            $invoice_date = self::date_or_null( $doc['invoice_date'] ?? null );
            $amount       = abs( (float) ( $doc['invoice_total'] ?? 0 ) );
            if ( $invoice_id !== '' && $invoice_date && $amount > 0 ) {
                $where  = 'invoice_id = %s AND invoice_date = %s AND ABS(invoice_total - %f) < 0.005';
                $params = [ $invoice_id, $invoice_date, $amount ];

                $tax_id = self::s( $doc['vendor_tax_id'] ?? '', 64 );
                if ( $tax_id !== '' ) {
                    $where   .= ' AND vendor_tax_id = %s';
                    $params[] = $tax_id;
                } else {
                    $vendor = self::s( $doc['vendor_name'] ?? '', 255 );
                    if ( $vendor !== '' ) {
                        $where   .= ' AND vendor_name = %s';
                        $params[] = $vendor;
                    }
                }

                $match = $wpdb->get_row( $wpdb->prepare(
                    "SELECT document_id, peppol_status FROM `{$table}` WHERE {$where} ORDER BY id LIMIT 1",
                    ...$params
                ) );
                if ( $match ) {
                    $doc['id'] = $match->document_id;
                    $doc['_preserve_status'] = (string) $match->peppol_status !== '';
                }
            }
        }

        $row_id = self::upsert_document( $doc );
        if ( is_wp_error( $row_id ) ) {
            return $row_id;
        }

        $attachments = self::get_attachments();
        $existing    = [];
        foreach ( $attachments->get_files( (int) $row_id, self::TYPE, '' ) as $file ) {
            $existing[ (string) $file['name'] ] = true;
        }

        if ( $xml !== '' && ! $attachments->get_files( (int) $row_id, self::TYPE, 'ubl' ) ) {
            $name = self::s( $doc['ubl_name'] ?? '', 255 );
            if ( $name === '' ) {
                $name = ( self::s( $doc['invoice_id'] ?? '', 100 ) ?: 'document' ) . '.xml';
            }
            if ( pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
                $name .= '.xml';
            }
            $attachments->store_bytes( (int) $row_id, self::TYPE, 'ubl', $name, $xml, [ 'xml' ] );
            $existing[ $name ] = true;
        }

        foreach ( $files as $file ) {
            $name = self::s( $file['name'] ?? '', 255 );
            $data = $file['contents'] ?? '';
            if ( $name === '' || $data === '' || isset( $existing[ $name ] ) ) {
                continue;
            }
            $key = in_array( ( $file['key'] ?? '' ), [ 'pdf', 'att' ], true ) ? (string) $file['key'] : 'att';
            if ( $attachments->store_bytes( (int) $row_id, self::TYPE, $key, $name, $data ) !== false ) {
                $existing[ $name ] = true;
            }
        }

        return (int) $row_id;
    }

    public static function upsert_document( array $doc ) {
        global $wpdb;
        $table       = $wpdb->prefix . self::TABLE;
        $document_id = self::s( $doc['id'] ?? '', 64 );
        if ( $document_id === '' ) {
            return new \WP_Error( 'peppol_invalid_document', __( 'Document zonder id ontvangen.', 'eme-accounting' ) );
        }

        $payments = is_array( $doc['payment_details'] ?? null ) ? $doc['payment_details'] : [];
        $payment  = $payments[0] ?? [];

        $data = [
            'direction'          => self::s( $doc['direction'] ?? 'INBOUND', 16 ),
            'peppol_status'      => self::s( $doc['state'] ?? 'RECEIVED', 20 ),
            'document_type'      => self::s( $doc['document_type'] ?? '', 40 ),
            'invoice_id'         => self::s( $doc['invoice_id'] ?? '', 100 ),
            'invoice_date'       => self::date_or_null( $doc['invoice_date'] ?? null ),
            'due_date'           => self::date_or_null( $doc['due_date'] ?? null ),
            'currency'           => self::s( $doc['currency'] ?? 'EUR', 3 ),
            'purchase_order'     => self::s( $doc['purchase_order'] ?? '', 100 ),
            'vendor_name'        => self::s( $doc['vendor_name'] ?? '', 255 ),
            'vendor_tax_id'      => self::s( $doc['vendor_tax_id'] ?? '', 64 ),
            'vendor_company_id'  => self::s( $doc['vendor_company_id'] ?? '', 64 ),
            'vendor_email'       => self::s( $doc['vendor_email'] ?? '', 190 ),
            'vendor_address'     => self::s( $doc['vendor_address'] ?? '' ),
            'customer_name'      => self::s( $doc['customer_name'] ?? '', 255 ),
            'customer_tax_id'    => self::s( $doc['customer_tax_id'] ?? '', 64 ),
            'customer_company_id'=> self::s( $doc['customer_company_id'] ?? '', 64 ),
            'subtotal'           => (float) ( $doc['subtotal'] ?? 0 ),
            'total_tax'          => (float) ( $doc['total_tax'] ?? 0 ),
            'invoice_total'      => (float) ( $doc['invoice_total'] ?? 0 ),
            'amount_due'         => (float) ( $doc['amount_due'] ?? 0 ),
            'payment_iban'       => self::s( $payment['iban'] ?? '', 34 ),
            'payment_reference'  => self::s( $payment['payment_reference'] ?? '', 140 ),
            'note'               => self::s( $doc['note'] ?? '' ),
            'items_json'         => wp_json_encode( $doc['items'] ?? [] ),
            'tax_details_json'   => wp_json_encode( $doc['tax_details'] ?? [] ),
            'attachments_json'   => wp_json_encode( $doc['attachments'] ?? [] ),
            'raw_json'           => wp_json_encode( $doc ),
            'received_at'        => self::datetime_or_null( $doc['created_at'] ?? null ),
            'fetched_at'         => gmdate( 'Y-m-d H:i:s' ),
            'last_error'         => '',
        ];

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE document_id = %s", $document_id ) );
        if ( $existing ) {
            if ( ! empty( $doc['_preserve_status'] ) ) {
                unset( $data['peppol_status'] );
            }
            $wpdb->update( $table, $data, [ 'id' => (int) $existing ] );
            return (int) $existing;
        }

        $data['document_id']       = $document_id;
        $data['processing_status'] = 'new';
        $inserted                  = $wpdb->insert( $table, $data );
        if ( ! $inserted ) {
            // A parallel fetch (webhook cron vs poll) may have inserted it first.
            $winner = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE document_id = %s", $document_id ) );
            if ( $winner ) {
                return (int) $winner;
            }
            return new \WP_Error( 'peppol_db', __( 'Kon het Peppol-document niet opslaan.', 'eme-accounting' ) );
        }
        return (int) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------
     * Booking helpers
     * ------------------------------------------------------------------ */

    private static function get_document_row( int $id ): ?object {
        global $wpdb;
        if ( $id <= 0 ) {
            return null;
        }
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}" . self::TABLE . "` WHERE id = %d", $id )
        );
    }

    private static function booking_amount( object $row ): float {
        $amount = abs( (float) ( $row->invoice_total ?? 0 ) );
        if ( $amount <= 0 ) {
            $amount = abs( (float) ( $row->amount_due ?? 0 ) );
        }
        return round( $amount, 2 );
    }

    private static function booking_direction( object $row ): string {
        return str_contains( strtoupper( (string) ( $row->document_type ?? '' ) ), 'CREDIT_NOTE' ) ? 'in' : 'out';
    }

    private static function booking_reference( object $row ): string {
        $reference = (string) ( $row->payment_reference ?? '' );
        if ( $reference === '' ) {
            $reference = (string) ( $row->invoice_id ?? '' );
        }
        return mb_substr( $reference, 0, 100 );
    }

    private static function booking_description( object $row ): string {
        $label = self::booking_direction( $row ) === 'in'
            ? __( 'Peppol creditnota', 'eme-accounting' )
            : __( 'Peppol factuur', 'eme-accounting' );

        if ( ! empty( $row->invoice_id ) ) {
            $label .= ' ' . $row->invoice_id;
        }
        if ( ! empty( $row->vendor_name ) ) {
            $label .= ' – ' . $row->vendor_name;
        }
        return mb_substr( $label, 0, 255 );
    }

    /** @return array{0:string,1:float} vat code and vat amount */
    private static function booking_vat( object $row ): array {
        $config = \EMEVzw\Config::get();
        if ( empty( $config->vat_liable ) ) {
            return [ '', 0.0 ];
        }

        $taxes = json_decode( (string) ( $row->tax_details_json ?? '' ), true );
        $taxes = is_array( $taxes ) ? $taxes : [];
        $rates = [];
        foreach ( $taxes as $tax ) {
            if ( ! is_array( $tax ) ) {
                continue;
            }
            $rate = $tax['rate'] ?? ( $tax['tax_rate'] ?? null );
            if ( is_numeric( $rate ) ) {
                $rates[] = (string) (float) $rate;
            }
        }
        $rates = array_values( array_unique( $rates ) );

        $code = '';
        if ( count( $rates ) === 1 ) {
            $rate = (string) $rates[0];
            if ( (float) $rate === 0.0 ) {
                $code = 'exempt';
            } elseif ( in_array( $rate, Vat::rates(), true ) ) {
                $code = $rate;
            }
        }

        return [ $code, round( (float) ( $row->total_tax ?? 0 ), 2 ) ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function booking_candidates( object $row, int $account_id, ?float $amount = null ): array {
        global $wpdb;

        $amount = $amount !== null ? round( abs( $amount ), 2 ) : self::booking_amount( $row );
        if ( $amount <= 0 || $account_id <= 0 ) {
            return [];
        }

        $direction = self::booking_direction( $row );
        $date      = self::date_or_null( $row->invoice_date ?? null ) ?: current_time( 'Y-m-d' );
        $journal   = $wpdb->prefix . 'emeacc_journal';
        $peppol    = $wpdb->prefix . self::TABLE;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT j.id, j.entry_date, j.description, j.amount, j.direction, j.bank_statement_ref, j.is_reconciled
             FROM `{$journal}` j
             WHERE j.account_id = %d
               AND j.direction = %s
               AND j.amount = %f
               AND j.transfer_id IS NULL
               AND j.is_closing_entry = 0
               AND j.entry_date BETWEEN DATE_SUB(%s, INTERVAL 60 DAY) AND DATE_ADD(%s, INTERVAL 60 DAY)
               AND NOT EXISTS ( SELECT 1 FROM `{$peppol}` p WHERE p.journal_id = j.id )
             ORDER BY ABS(DATEDIFF(j.entry_date, %s)), j.id DESC
             LIMIT 10",
            $account_id,
            $direction,
            $amount,
            $date,
            $date,
            $date
        ) );

        $out = [];
        foreach ( (array) $rows as $candidate ) {
            $out[] = [
                'id'                 => (int) $candidate->id,
                'entry_date'         => (string) $candidate->entry_date,
                'entry_date_display' => eme_localized_date( $candidate->entry_date, EME_TIMEZONE ),
                'description'        => (string) $candidate->description,
                'amount'             => (float) $candidate->amount,
                'direction'          => (string) $candidate->direction,
                'bank_statement_ref' => (string) $candidate->bank_statement_ref,
                'is_reconciled'      => (int) $candidate->is_reconciled,
                'journal_edit_url'   => admin_url( 'admin.php?page=emeaccounting-journal&edit=' . (int) $candidate->id ),
            ];
        }
        return $out;
    }

    private static function link_document_to_journal( object $row, int $journal_id ): bool|\WP_Error {
        global $wpdb;

        if ( ! empty( $row->journal_id ) ) {
            return new \WP_Error( 'peppol_already_booked', __( 'Dit document is al geboekt.', 'eme-accounting' ) );
        }

        $entry = Journal::get( $journal_id );
        if ( ! $entry ) {
            return new \WP_Error( 'peppol_journal_missing', __( 'Boeking niet gevonden.', 'eme-accounting' ) );
        }
        if ( ! empty( $entry->transfer_id ) ) {
            return new \WP_Error( 'peppol_journal_transfer', __( 'Een transfer kan niet aan een Peppol-document gekoppeld worden.', 'eme-accounting' ) );
        }
        if ( ! empty( $entry->is_closing_entry ) ) {
            return new \WP_Error( 'peppol_journal_closing', __( 'Een afsluitboeking kan niet gekoppeld worden.', 'eme-accounting' ) );
        }
        if ( (string) $entry->direction !== self::booking_direction( $row ) ) {
            return new \WP_Error( 'peppol_journal_direction', __( 'De richting van de boeking komt niet overeen met het document.', 'eme-accounting' ) );
        }

        $expected = self::booking_amount( $row );
        if ( $expected > 0 && abs( (float) $entry->amount - $expected ) > 0.005 ) {
            return new \WP_Error( 'peppol_journal_amount', __( 'Het bedrag van de boeking komt niet overeen met het document.', 'eme-accounting' ) );
        }

        $table = $wpdb->prefix . self::TABLE;
        $other = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE journal_id = %d AND id <> %d LIMIT 1",
            $journal_id,
            (int) $row->id
        ) );
        if ( $other ) {
            return new \WP_Error( 'peppol_journal_in_use', __( 'Deze boeking is al aan een ander Peppol-document gekoppeld.', 'eme-accounting' ) );
        }

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET journal_id = %d, booked_at = %s, booked_by = %d
             WHERE id = %d AND journal_id IS NULL",
            $journal_id,
            current_time( 'mysql' ),
            (int) get_current_user_id(),
            (int) $row->id
        ) );
        if ( $updated === false || $updated !== 1 ) {
            return new \WP_Error( 'peppol_booking_conflict', __( 'Het document kon niet geboekt worden. Misschien is het intussen al door iemand anders geboekt.', 'eme-accounting' ) );
        }
        return true;
    }

    public static function on_journal_deleted( int $entry_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET journal_id = NULL, booked_at = NULL, booked_by = NULL
             WHERE journal_id = %d",
            $entry_id
        ) );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function files_html( int $row_id ): string {
        $atts = self::get_attachments();
        return $atts->get_links_html( $row_id, self::TYPE, 'ubl' )
            . $atts->get_links_html( $row_id, self::TYPE, 'pdf' )
            . $atts->get_links_html( $row_id, self::TYPE, 'att' );
    }

    private static function build_where( array $args ): array {
        global $wpdb;
        $where   = [];
        $prepare = [];

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where[]  = '(invoice_id LIKE %s OR vendor_name LIKE %s OR vendor_tax_id LIKE %s OR customer_name LIKE %s OR purchase_order LIKE %s OR note LIKE %s)';
            $prepare  = array_merge( $prepare, array_fill( 0, 6, $like ) );
        }
        if ( ! empty( $args['document_type'] ) ) {
            $where[]   = 'document_type = %s';
            $prepare[] = $args['document_type'];
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]   = 'processing_status = %s';
            $prepare[] = $args['status'];
        }
        if ( ! empty( $args['booking_status'] ) ) {
            $where[] = $args['booking_status'] === 'booked' ? 'journal_id IS NOT NULL' : 'journal_id IS NULL';
        }
        if ( ! empty( $args['peppol_status'] ) ) {
            $where[]   = 'peppol_status = %s';
            $prepare[] = $args['peppol_status'];
        }
        if ( ! empty( $args['daterange'] ) ) {
            $parts = array_map( 'trim', explode( ',', (string) $args['daterange'] ) );
            if ( count( $parts ) >= 2 && $parts[0] !== '' && $parts[1] !== '' ) {
                $where[]   = 'invoice_date >= %s';
                $where[]   = 'invoice_date <= %s';
                $prepare[] = $parts[0];
                $prepare[] = $parts[1];
            }
        }

        return [ $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $prepare ];
    }

    private static function build_order( string $raw ): string {
        $allowed = [ 'id', 'invoice_date', 'received_at', 'invoice_total', 'vendor_name', 'invoice_id', 'document_type', 'peppol_status', 'processing_status', 'journal_id' ];
        $out     = [];

        foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $part ) {
            if ( preg_match( '/^([a-z_]+)(?:\s+(asc|desc))?$/i', $part, $m ) ) {
                $column = strtolower( $m[1] );
                if ( $column === 'booking_status' ) {
                    $column = 'journal_id';
                }
                if ( ! in_array( $column, $allowed, true ) ) {
                    continue;
                }
                $dir   = isset( $m[2] ) && strtoupper( $m[2] ) === 'DESC' ? 'DESC' : 'ASC';
                $out[] = $column . ' ' . $dir;
            }
        }

        return $out ? implode( ', ', $out ) : 'received_at DESC, id DESC';
    }

    public static function s( $value, int $max = 0 ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = (string) $value;
        if ( $max > 0 ) {
            $value = mb_substr( $value, 0, $max );
        }
        return $value;
    }

    private static function date_or_null( $value ) {
        if ( empty( $value ) ) {
            return null;
        }
        $ts = strtotime( (string) $value );
        return $ts ? gmdate( 'Y-m-d', $ts ) : null;
    }

    private static function datetime_or_null( $value ) {
        if ( empty( $value ) ) {
            return null;
        }
        $ts = strtotime( (string) $value );
        return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
    }
}
