<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * Optional IMAP ingestion for Peppol documents forwarded by Doccle.
 *
 * Doccle mails the UBL XML and can also attach a PDF. Documents are stored in
 * the same inbox table as the e-invoice.be API, keyed by the UBL UUID.
 */
class PeppolImap {

    const CRON_POLL = 'eme_accounting_peppol_imap_poll';
    const LOCK_NAME = 'emeacc_peppol_imap_poll_';
    const MAX_XML   = 5242880;

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_peppol_mailbox_test', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_mailbox_sync', [ __CLASS__, 'ajax_sync' ] );
        add_action( self::CRON_POLL, [ __CLASS__, 'poll' ] );

        if ( is_admin() || wp_doing_cron() ) {
            $settings = Peppol::get_settings();
            if ( self::is_available() && self::is_configured( $settings ) && ! empty( $settings['imap_enabled'] ) && ! wp_next_scheduled( self::CRON_POLL ) ) {
                wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_POLL );
            }
        }
    }

    public static function sync_cron(): void {
        $settings  = Peppol::get_settings();
        $enabled   = self::is_available() && self::is_configured( $settings ) && ! empty( $settings['imap_enabled'] );
        $scheduled = wp_next_scheduled( self::CRON_POLL );

        if ( $enabled && ! $scheduled ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_POLL );
        } elseif ( ! $enabled && $scheduled ) {
            wp_clear_scheduled_hook( self::CRON_POLL );
        }
    }

    public static function is_available(): bool {
        return class_exists( PeppolImapClient::class ) && function_exists( 'stream_socket_client' );
    }

    public static function is_configured( array $settings ): bool {
        return ! empty( $settings['imap_host'] )
            && ! empty( $settings['imap_username'] )
            && ! empty( $settings['imap_password'] );
    }

    public static function get_public_status(): array {
        $s = Peppol::get_settings();
        return [
            'available'      => self::is_available() ? 1 : 0,
            'enabled'        => (int) $s['imap_enabled'],
            'configured'     => self::is_configured( $s ) ? 1 : 0,
            'host'           => (string) $s['imap_host'],
            'port'           => (int) $s['imap_port'],
            'encryption'     => (string) $s['imap_encryption'],
            'username'       => (string) $s['imap_username'],
            'folder'         => (string) $s['imap_folder'],
            'mark_seen'      => (int) $s['imap_mark_seen'],
            'max_messages'   => (int) $s['imap_max_messages'],
            'last_poll_at'   => (string) $s['imap_last_poll_at'],
            'last_poll_status' => (string) $s['imap_last_poll_status'],
        ];
    }

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $settings = Peppol::get_settings();
        if ( ! self::is_configured( $settings ) ) {
            wp_send_json_error( __( 'Vul eerst de IMAP-server, gebruiker en wachtwoord in.', 'eme-accounting' ) );
        }
        if ( ! self::is_available() ) {
            wp_send_json_error( __( 'De IMAP-client is niet beschikbaar op deze server.', 'eme-accounting' ) );
        }

        $client = self::open( $settings );
        if ( is_wp_error( $client ) ) {
            wp_send_json_error( $client->get_error_message() );
        }

        $unseen = $client->search( [ 'UNSEEN' ] );
        if ( $client->get_last_error() !== '' ) {
            $error = $client->get_last_error();
            $client->logout();
            wp_send_json_error( $error );
        }
        $messages = $client->message_count();
        $client->logout();

        wp_send_json_success( [
            'mailbox'  => self::mailbox_label( $settings ),
            'messages' => $messages,
            'unseen'   => count( $unseen ),
        ] );
    }

    public static function ajax_sync(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $result = self::poll( true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [
            'summary' => $result,
            'status'  => self::get_public_status(),
        ] );
    }

    public static function poll( bool $manual = false ) {
        $settings = Peppol::get_settings();
        if ( ! self::is_configured( $settings ) ) {
            return $manual ? new \WP_Error( 'peppol_imap_no_settings', __( 'Geen IMAP-instellingen ingevuld.', 'eme-accounting' ) ) : [];
        }
        if ( ! $manual && empty( $settings['imap_enabled'] ) ) {
            return [];
        }
        if ( ! self::is_available() ) {
            $error = __( 'De interne IMAP-client is niet beschikbaar op deze server.', 'eme-accounting' );
            self::save_status( $error );
            return $manual ? new \WP_Error( 'peppol_imap_missing', $error ) : [];
        }

        global $wpdb;
        $lock_name = self::LOCK_NAME . $wpdb->prefix;
        $got_lock  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
        if ( $got_lock != 1 ) {
            return $manual ? new \WP_Error( 'peppol_imap_busy', __( 'Er loopt al een mailbox-synchronisatie.', 'eme-accounting' ) ) : [];
        }

        try {
            return self::run_poll( $settings, $manual );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    private static function run_poll( array $settings, bool $manual ): array|\WP_Error {
        $summary = [ 'processed' => 0, 'skipped' => 0, 'errors' => 0 ];
        $client  = self::open( $settings );
        if ( is_wp_error( $client ) ) {
            self::save_status( $client->get_error_message() );
            return $manual ? $client : $summary;
        }

        try {
            $criteria = ! empty( $settings['imap_mark_seen'] )
                ? [ 'UNSEEN' ]
                : [ 'SINCE "' . gmdate( 'd-M-Y', time() - 7 * DAY_IN_SECONDS ) . '"' ];

            $ids = $client->search( $criteria );
            if ( $client->get_last_error() !== '' ) {
                self::save_status( $client->get_last_error() );
                return $manual ? new \WP_Error( 'peppol_imap_search', $client->get_last_error() ) : $summary;
            }
            rsort( $ids );

            $limit = max( 1, min( 100, (int) $settings['imap_max_messages'] ) );
            $ids   = array_slice( $ids, 0, $limit );

            foreach ( $ids as $msgno ) {
                $result = self::process_message( $client, (int) $msgno, $settings );
                $summary['processed'] += (int) ( $result['processed'] ?? 0 );
                $summary['skipped']   += (int) ( $result['skipped'] ?? 0 );
                $summary['errors']    += (int) ( $result['errors'] ?? 0 );
            }

            if ( $ids ) {
                self::save_status( sprintf(
                    /* translators: 1: processed, 2: skipped, 3: errors */
                    __( 'Verwerkt: %1$d, overgeslagen: %2$d, fouten: %3$d', 'eme-accounting' ),
                    $summary['processed'],
                    $summary['skipped'],
                    $summary['errors']
                ) );
            } else {
                self::save_status( __( 'Geen berichten gevonden.', 'eme-accounting' ) );
            }
        } finally {
            $client->logout();
        }

        return $summary;
    }

    private static function process_message( PeppolImapClient $client, int $msgno, array $settings ): array {
        $result = [ 'processed' => 0, 'skipped' => 0, 'errors' => 0 ];

        $raw_header = (string) $client->fetch_header( $msgno );
        $message_id = trim( self::header_value( $raw_header, 'Message-ID' ) );
        if ( $message_id === '' ) {
            $message_id = 'message-' . $msgno;
        }
        $subject = self::decode_header_value( self::header_value( $raw_header, 'Subject' ) );
        $date    = (int) strtotime( self::header_value( $raw_header, 'Date' ) );
        $date    = $date > 0 ? gmdate( 'Y-m-d H:i:s', $date ) : gmdate( 'Y-m-d H:i:s' );

        $attachments = self::extract_attachments( $client, $msgno, $raw_header );
        if ( $attachments === null ) {
            $result['errors'] = 1;
            return $result;
        }
        $xml_files   = array_values( array_filter( $attachments, static function ( array $file ): bool {
            return self::is_xml_attachment( $file );
        } ) );
        $other_files = array_values( array_filter( $attachments, static function ( array $file ): bool {
            return ! self::is_xml_attachment( $file );
        } ) );

        if ( ! $xml_files ) {
            $result['skipped'] = 1;
            self::mark_seen( $client, $msgno, $settings );
            return $result;
        }

        $meta = [];
        foreach ( $attachments as $index => $file ) {
            $meta[] = [
                'id'        => $message_id . '-' . $index,
                'file_name' => $file['name'],
                'file_type' => $file['mime'],
            ];
        }

        $files = [];
        foreach ( $other_files as $file ) {
            $files[] = [
                'name'     => $file['name'],
                'contents' => $file['contents'],
                'key'      => $file['mime'] === 'application/pdf' ? 'pdf' : 'att',
            ];
        }

        foreach ( $xml_files as $xml_file ) {
            $doc = self::parse_ubl( $xml_file['contents'] );
            if ( is_wp_error( $doc ) ) {
                $result['errors']++;
                continue;
            }

            $doc['created_at']   = $date;
            $doc['attachments']  = $meta;
            $doc['ubl_name']     = $xml_file['name'];
            $doc['_source']      = 'imap';
            $doc['_message_id']  = $message_id;
            $doc['_subject']     = $subject;

            $stored = Peppol::import_external_document( $doc, $xml_file['contents'], $files );
            if ( is_wp_error( $stored ) ) {
                $result['errors']++;
            } else {
                $result['processed']++;
            }
        }

        self::mark_seen( $client, $msgno, $settings );
        return $result;
    }

    private static function mark_seen( PeppolImapClient $client, int $msgno, array $settings ): void {
        if ( ! empty( $settings['imap_mark_seen'] ) ) {
            $client->mark_seen( $msgno );
        }
    }

    /* ------------------------------------------------------------------
     * IMAP transport
     * ------------------------------------------------------------------ */

    private static function open( array $settings ) {
        $host = trim( (string) $settings['imap_host'] );
        $host = preg_replace( '#^[a-z]+://#i', '', $host );
        $host = trim( $host, "{} \t\n\r\0\x0B" );
        $host = str_replace( [ '{', '}' ], '', $host );

        $port = (int) $settings['imap_port'];
        if ( $port <= 0 || $port > 65535 ) {
            $port = 993;
        }

        $encryption = (string) $settings['imap_encryption'];
        if ( ! in_array( $encryption, [ 'ssl', 'tls', 'none' ], true ) ) {
            $encryption = 'ssl';
        }

        $client = new PeppolImapClient();
        if ( ! $client->connect( $host, $port, $encryption === 'none' ? '' : $encryption ) ) {
            return new \WP_Error( 'peppol_imap_connect', $client->get_last_error() ?: __( 'Kan geen verbinding maken met de IMAP-server.', 'eme-accounting' ) );
        }
        if ( ! $client->login( (string) $settings['imap_username'], (string) $settings['imap_password'] ) ) {
            $client->logout();
            return new \WP_Error( 'peppol_imap_login', $client->get_last_error() ?: __( 'IMAP-login mislukt.', 'eme-accounting' ) );
        }

        $folder = trim( (string) $settings['imap_folder'] ) ?: 'INBOX';
        if ( $client->open_mailbox( $folder, empty( $settings['imap_mark_seen'] ) ) === false ) {
            $error = $client->get_last_error();
            $client->logout();
            return new \WP_Error( 'peppol_imap_folder', $error ?: __( 'Kan de IMAP-map niet openen.', 'eme-accounting' ) );
        }

        return $client;
    }

    private static function mailbox_label( array $settings ): string {
        return (string) $settings['imap_username'] . '@' . (string) $settings['imap_host'] . '/' . ( (string) $settings['imap_folder'] ?: 'INBOX' );
    }

    private static function save_status( string $status ): void {
        $settings = Peppol::get_settings();
        $settings['imap_last_poll_at']     = gmdate( 'Y-m-d H:i:s' );
        $settings['imap_last_poll_status'] = mb_substr( $status, 0, 255 );
        Peppol::update_settings( $settings );
    }

    /* ------------------------------------------------------------------
     * MIME
     * ------------------------------------------------------------------ */

    private static function extract_attachments( PeppolImapClient $client, int $msgno, string $raw_header ): ?array {
        $structure = $client->fetch_structure( $msgno );
        if ( $structure === null ) {
            return null;
        }

        $out = [];
        foreach ( $structure as $section => $info ) {
            $part_header = $client->fetch_section( $msgno, $section . '.MIME' );
            if ( $part_header === null || trim( $part_header ) === '' ) {
                $part_header = $raw_header;
            }

            $mime = strtolower( (string) ( $info['mimetype'] ?? '' ) );
            if ( $mime === '' ) {
                $mime = self::content_type( $part_header );
            }
            if ( $mime === '' ) {
                $mime = 'application/octet-stream';
            }

            $name = self::content_disposition_filename( $part_header );
            if ( $name === '' ) {
                $name = self::content_type_param( $part_header, 'name' );
            }
            $name = trim( basename( str_replace( '\\', '/', rawurldecode( self::decode_header_value( $name ) ) ) ) );

            if ( $name === '' && ! in_array( $mime, [ 'application/xml', 'text/xml', 'application/pdf' ], true ) ) {
                continue;
            }

            $body = $client->fetch_section( $msgno, (string) $section );
            if ( ! is_string( $body ) || $body === '' ) {
                continue;
            }

            $encoding = strtolower( self::header_value( $part_header, 'Content-Transfer-Encoding' ) );
            $body     = self::decode_transfer( $body, $encoding );
            if ( $body === '' ) {
                continue;
            }

            if ( str_starts_with( $mime, 'text/' ) && $mime !== 'text/xml' ) {
                $body = self::to_utf8( $body, self::content_type_param( $part_header, 'charset' ) );
            }
            if ( strlen( $body ) > 10485760 ) {
                continue;
            }

            if ( $name === '' ) {
                $name = $mime === 'application/pdf' ? 'attachment.pdf' : 'document.xml';
            }

            $out[] = [
                'name'     => $name,
                'mime'     => $mime,
                'contents' => $body,
            ];
        }

        return $out;
    }

    private static function header_value( string $headers, string $name ): string {
        $headers = str_replace( "\r\n", "\n", $headers );
        $headers = preg_replace( "/\n[ \t]+/", ' ', $headers );
        if ( preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.*)$/mi', (string) $headers, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    private static function decode_header_value( string $value ): string {
        if ( $value === '' ) {
            return '';
        }
        if ( function_exists( 'iconv_mime_decode' ) ) {
            $decoded = iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' );
            if ( is_string( $decoded ) && $decoded !== '' ) {
                return $decoded;
            }
        }
        return $value;
    }

    private static function content_type( string $headers ): string {
        $value = self::header_value( $headers, 'Content-Type' );
        if ( $value === '' ) {
            return '';
        }
        $value = trim( explode( ';', $value, 2 )[0] );
        return strtolower( $value );
    }

    private static function content_type_param( string $headers, string $name ): string {
        $value = self::header_value( $headers, 'Content-Type' );
        $param = self::header_param( $value, $name );
        if ( $param === '' ) {
            $param = self::header_param( $value, $name . '*' );
            if ( $param !== '' && str_contains( $param, "''" ) ) {
                $param = substr( $param, strpos( $param, "''" ) + 2 );
            }
        }
        return $param;
    }

    private static function content_disposition_filename( string $headers ): string {
        $value = self::header_value( $headers, 'Content-Disposition' );
        $name  = self::header_param( $value, 'filename' );
        if ( $name === '' ) {
            $name = self::header_param( $value, 'filename*' );
            if ( $name !== '' && str_contains( $name, "''" ) ) {
                $name = substr( $name, strpos( $name, "''" ) + 2 );
            }
        }
        return $name;
    }

    private static function header_param( string $header, string $name ): string {
        if ( $header === '' ) {
            return '';
        }
        $pattern = '/(?:^|;)\s*' . preg_quote( $name, '/' ) . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i';
        if ( preg_match( $pattern, $header, $m ) ) {
            $value = $m[1] !== '' ? stripcslashes( $m[1] ) : $m[2];
            return rawurldecode( $value );
        }
        return '';
    }

    private static function decode_transfer( string $body, string $encoding ): string {
        if ( $encoding === 'base64' ) {
            $decoded = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            return is_string( $decoded ) ? $decoded : '';
        }
        if ( $encoding === 'quoted-printable' ) {
            return quoted_printable_decode( $body );
        }
        return $body;
    }

    private static function to_utf8( string $body, string $charset ): string {
        if ( $charset === '' || strtoupper( $charset ) === 'UTF-8' ) {
            return $body;
        }
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $converted = @mb_convert_encoding( $body, 'UTF-8', $charset );
            if ( is_string( $converted ) ) {
                return $converted;
            }
        }
        return $body;
    }

    private static function is_xml_attachment( array $file ): bool {
        $name = strtolower( (string) ( $file['name'] ?? '' ) );
        $mime = strtolower( (string) ( $file['mime'] ?? '' ) );
        return pathinfo( $name, PATHINFO_EXTENSION ) === 'xml' || in_array( $mime, [ 'application/xml', 'text/xml' ], true );
    }

    /* ------------------------------------------------------------------
     * UBL
     * ------------------------------------------------------------------ */

    private static function parse_ubl( string $xml ) {
        if ( $xml === '' || strlen( $xml ) > self::MAX_XML ) {
            return new \WP_Error( 'peppol_ubl_size', __( 'De XML-bijlage is leeg of te groot.', 'eme-accounting' ) );
        }

        $dom  = new \DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok   = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        if ( ! $ok || ! $dom->documentElement ) {
            return new \WP_Error( 'peppol_ubl_invalid', __( 'De XML-bijlage is geen geldige UBL.', 'eme-accounting' ) );
        }

        $root_name = strtolower( (string) $dom->documentElement->localName );
        if ( ! in_array( $root_name, [ 'invoice', 'creditnote' ], true ) ) {
            return new \WP_Error( 'peppol_ubl_type', __( 'De XML-bijlage is geen UBL-factuur of -creditnota.', 'eme-accounting' ) );
        }

        $xpath = new \DOMXPath( $dom );
        $root  = $dom->documentElement;

        $profile   = self::text( $xpath, './*[local-name()="CustomizationID"]', $root ) . ' ' . self::text( $xpath, './*[local-name()="ProfileID"]', $root );
        $self_bill = stripos( $profile, 'self' ) !== false && stripos( $profile, 'bill' ) !== false;
        $is_credit = $root_name === 'creditnote';

        $supplier = self::first( $xpath, './*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]', $root );
        $customer = self::first( $xpath, './*[local-name()="AccountingCustomerParty"]/*[local-name()="Party"]', $root );

        $total_node = self::first( $xpath, './*[local-name()="LegalMonetaryTotal"]', $root );
        $line_total = self::money( $xpath, './*[local-name()="LineExtensionAmount"]', $total_node );
        $tax_total  = self::money( $xpath, './*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]', $root );
        $tax_incl   = self::money( $xpath, './*[local-name()="TaxInclusiveAmount"]', $total_node );
        $payable    = self::money( $xpath, './*[local-name()="PayableAmount"]', $total_node );

        $items  = self::items( $xpath, $root, $root_name );
        $taxes  = self::taxes( $xpath, $root );
        $uuid   = self::text( $xpath, './*[local-name()="UUID"]', $root );
        $uuid   = preg_replace( '/[^A-Za-z0-9-]/', '', $uuid );
        $doc_id = $uuid !== '' ? 'imap-' . $uuid : 'imap-' . substr( hash( 'sha256', $xml ), 0, 40 );

        if ( $line_total === 0.0 ) {
            foreach ( $items as $item ) {
                $line_total += (float) ( $item['line_total'] ?? 0 );
            }
        }
        if ( $tax_total === 0.0 ) {
            foreach ( $taxes as $tax ) {
                $tax_total += (float) ( $tax['amount'] ?? 0 );
            }
        }

        $invoice_total = $tax_incl !== 0.0 ? $tax_incl : ( $payable !== 0.0 ? $payable : $line_total + $tax_total );
        $amount_due    = $payable !== 0.0 ? $payable : $invoice_total;

        $payment = self::first( $xpath, './*[local-name()="PaymentMeans"]', $root );
        $iban    = $payment ? self::text( $xpath, './/*[local-name()="PayeeFinancialAccount"]/*[local-name()="ID"]', $payment ) : '';
        $reference = $payment ? self::text( $xpath, './*[local-name()="PaymentID"]', $payment ) : '';
        if ( $reference === '' ) {
            $reference = self::text( $xpath, './*[local-name()="PaymentTerms"]/*[local-name()="Note"]', $root );
        }

        return [
            'id'                 => mb_substr( $doc_id, 0, 64 ),
            'direction'          => 'INBOUND',
            'state'              => 'RECEIVED',
            'document_type'      => $is_credit
                ? ( $self_bill ? 'SELFBILLING_CREDIT_NOTE' : 'CREDIT_NOTE' )
                : ( $self_bill ? 'SELFBILLING_INVOICE' : 'INVOICE' ),
            'invoice_id'         => self::text( $xpath, './*[local-name()="ID"]', $root ),
            'invoice_date'       => self::text( $xpath, './*[local-name()="IssueDate"]', $root ),
            'due_date'           => self::text( $xpath, './*[local-name()="DueDate"]', $root ),
            'currency'           => self::text( $xpath, './*[local-name()="DocumentCurrencyCode"]', $root ) ?: 'EUR',
            'purchase_order'     => self::text( $xpath, './/*[local-name()="OrderReference"]/*[local-name()="ID"]', $root ),
            'vendor_name'        => self::party_name( $xpath, $supplier ),
            'vendor_tax_id'      => $supplier ? self::text( $xpath, './/*[local-name()="PartyTaxScheme"]/*[local-name()="CompanyID"]', $supplier ) : '',
            'vendor_company_id'  => $supplier ? self::text( $xpath, './/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyID"]', $supplier ) : '',
            'vendor_email'       => $supplier ? self::text( $xpath, './/*[local-name()="Contact"]/*[local-name()="ElectronicMail"]', $supplier ) : '',
            'vendor_address'     => self::party_address( $xpath, $supplier ),
            'customer_name'      => self::party_name( $xpath, $customer ),
            'customer_tax_id'    => $customer ? self::text( $xpath, './/*[local-name()="PartyTaxScheme"]/*[local-name()="CompanyID"]', $customer ) : '',
            'customer_company_id'=> $customer ? self::text( $xpath, './/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyID"]', $customer ) : '',
            'subtotal'           => round( $line_total, 2 ),
            'total_tax'          => round( $tax_total, 2 ),
            'invoice_total'      => round( $invoice_total, 2 ),
            'amount_due'         => round( $amount_due, 2 ),
            'payment_details'    => [ [ 'iban' => $iban, 'payment_reference' => $reference ] ],
            'note'               => self::text( $xpath, './*[local-name()="Note"]', $root ),
            'items'              => $items,
            'tax_details'        => $taxes,
        ];
    }

    private static function first( \DOMXPath $xpath, string $expression, ?\DOMNode $context = null ): ?\DOMNode {
        $nodes = $context ? $xpath->query( $expression, $context ) : $xpath->query( $expression );
        return $nodes && $nodes->length ? $nodes->item( 0 ) : null;
    }

    private static function text( \DOMXPath $xpath, string $expression, ?\DOMNode $context = null ): string {
        $node = self::first( $xpath, $expression, $context );
        return $node ? trim( $node->textContent ) : '';
    }

    private static function money( \DOMXPath $xpath, string $expression, ?\DOMNode $context = null ): float {
        $value = self::text( $xpath, $expression, $context );
        return $value !== '' && is_numeric( $value ) ? (float) $value : 0.0;
    }

    private static function party_name( \DOMXPath $xpath, ?\DOMNode $party ): string {
        if ( ! $party ) {
            return '';
        }
        foreach ( [
            './/*[local-name()="PartyName"]/*[local-name()="Name"]',
            './/*[local-name()="PartyLegalEntity"]/*[local-name()="RegistrationName"]',
            './*[local-name()="Name"]',
        ] as $expression ) {
            $value = self::text( $xpath, $expression, $party );
            if ( $value !== '' ) {
                return $value;
            }
        }
        return '';
    }

    private static function party_address( \DOMXPath $xpath, ?\DOMNode $party ): string {
        if ( ! $party ) {
            return '';
        }
        $address = self::first( $xpath, './/*[local-name()="PostalAddress"]', $party );
        $parts   = [];
        foreach ( [
            './*[local-name()="StreetName"]',
            './*[local-name()="AdditionalStreetName"]',
            './*[local-name()="BuildingNumber"]',
            './*[local-name()="PostalZone"]',
            './*[local-name()="CityName"]',
            './*[local-name()="CountrySubentity"]',
            './*[local-name()="Country"]/*[local-name()="IdentificationCode"]',
        ] as $expression ) {
            $value = self::text( $xpath, $expression, $address );
            if ( $value !== '' ) {
                $parts[] = $value;
            }
        }
        return implode( ', ', $parts );
    }

    private static function items( \DOMXPath $xpath, \DOMNode $root, string $root_name ): array {
        $expression = $root_name === 'creditnote'
            ? './*[local-name()="CreditNoteLine"]'
            : './*[local-name()="InvoiceLine"]';
        $nodes = $xpath->query( $expression, $root );
        $items = [];

        foreach ( $nodes ?: [] as $line ) {
            $quantity = self::text( $xpath, './*[local-name()="InvoicedQuantity"]', $line );
            if ( $quantity === '' ) {
                $quantity = self::text( $xpath, './*[local-name()="CreditedQuantity"]', $line );
            }
            $items[] = [
                'id'          => self::text( $xpath, './*[local-name()="ID"]', $line ),
                'name'        => self::text( $xpath, './/*[local-name()="Item"]/*[local-name()="Name"]', $line ),
                'description' => self::text( $xpath, './/*[local-name()="Item"]/*[local-name()="Description"]', $line ),
                'quantity'    => $quantity !== '' && is_numeric( $quantity ) ? (float) $quantity : 0,
                'unit_price'  => self::money( $xpath, './/*[local-name()="Price"]/*[local-name()="PriceAmount"]', $line ),
                'line_total'  => self::money( $xpath, './*[local-name()="LineExtensionAmount"]', $line ),
                'vat_rate'    => self::money( $xpath, './/*[local-name()="ClassifiedTaxCategory"]/*[local-name()="Percent"]', $line ),
            ];
        }

        return $items;
    }

    private static function taxes( \DOMXPath $xpath, \DOMNode $root ): array {
        $nodes = $xpath->query( './*[local-name()="TaxTotal"]/*[local-name()="TaxSubtotal"]', $root );
        $taxes = [];

        foreach ( $nodes ?: [] as $node ) {
            $taxes[] = [
                'category'       => self::text( $xpath, './/*[local-name()="TaxCategory"]/*[local-name()="ID"]', $node ),
                'rate'           => self::money( $xpath, './/*[local-name()="TaxCategory"]/*[local-name()="Percent"]', $node ),
                'taxable_amount' => self::money( $xpath, './*[local-name()="TaxableAmount"]', $node ),
                'amount'         => self::money( $xpath, './*[local-name()="TaxAmount"]', $node ),
            ];
        }

        return $taxes;
    }
}
