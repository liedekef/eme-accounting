<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * Peppol inbox backed by the e-invoice.be API.
 *
 * Receives inbound documents through a webhook (REST) and/or an hourly
 * poll, stores the metadata in {prefix}emeacc_peppol_documents and the
 * UBL / PDF / extra attachments on disk (via EMEFileAttachments).
 *
 * The e-invoice.be API has no "processed" flag, so local de-duplication is
 * done on the e-invoice document_id. Removing a row would make the next
 * poll re-import it; therefore documents are "ignored", not deleted.
 */
class Peppol {

    const OPTION      = 'eme_accounting_peppol_settings';
    const TABLE       = 'emeacc_peppol_documents';
    const TYPE        = 'peppol';
    const CRON_POLL   = 'eme_accounting_peppol_poll';
    const CRON_FETCH  = 'eme_accounting_peppol_fetch_document';
    const REST_NS     = 'eme-accounting/v1';
    const REST_ROUTE  = '/peppol-webhook';

    const PAGE_SIZE          = 100;
    const MAX_POLL_PAGES     = 3;
    const MAX_BACKFILL_PAGES = 10;
    const MAX_DOCS_PER_RUN   = 50;
    const MAX_DOCS_MANUAL    = 25;     // "sync now" runs inside an AJAX request
    const CURSOR_LAG         = 86400;  // re-scan a day behind the cursor; dedupe is a cheap DB lookup
    const RETRY_BACKOFF      = 3600;   // seconds before an errored document is retried by cron
    const LOCK_NAME          = 'emeacc_peppol_poll_';

    const EVENTS = [ 'document.received', 'document.received.failed' ];

    const DOCUMENT_TYPES = [ 'INVOICE', 'CREDIT_NOTE', 'SELFBILLING_INVOICE', 'SELFBILLING_CREDIT_NOTE' ];

    /** @var \EMEFileAttachments|null */
    private static ?\EMEFileAttachments $attachments = null;

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_peppol_list',            [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_get',             [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_sync',            [ __CLASS__, 'ajax_sync' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_ignore',          [ __CLASS__, 'ajax_ignore' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_retry',           [ __CLASS__, 'ajax_retry' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_webhook_sync',    [ __CLASS__, 'ajax_webhook_sync' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_webhook_delete',  [ __CLASS__, 'ajax_webhook_delete' ] );

        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_route' ] );

        add_action( self::CRON_POLL, [ __CLASS__, 'poll' ] );
        add_action( self::CRON_FETCH, [ __CLASS__, 'fetch_document_cron' ], 10, 1 );

        // Re-arm the hourly poll when it got lost (activation, manual option
        // import, ...). Only on admin/cron requests, so front-end traffic does
        // not hit the (non-autoloaded) settings option on every page view.
        if ( is_admin() || wp_doing_cron() ) {
            $settings = self::get_settings();
            if ( ! empty( $settings['api_key'] ) && ! empty( $settings['poll_enabled'] ) && ! wp_next_scheduled( self::CRON_POLL ) ) {
                wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_POLL );
            }
        }
    }

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
     * Settings
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
        ] );
    }

    public static function update_settings( array $settings ): void {
        $old     = get_option( self::OPTION, [] );
        $old_key = is_array( $old ) ? (string) ( $old['api_key'] ?? '' ) : '';
        if ( $old_key !== (string) ( $settings['api_key'] ?? '' ) ) {
            // The webhook (id + secret) belongs to the account of the old key.
            $settings['webhook_id']     = '';
            $settings['webhook_secret'] = '';
        }
        unset( $settings['cursor_ids'] ); // legacy key
        update_option( self::OPTION, $settings, false );
    }

    /**
     * Persist only what poll() owns (cursor, backfill, last run), on top of a
     * fresh copy of the settings. poll() can run for minutes; writing back the
     * array it read at the start would overwrite an API key, webhook id/secret
     * or poll toggle saved in the meantime.
     *
     * The sync position itself is only written when it still matches the
     * snapshot taken at the start of the run: a reset performed while the poll
     * was running must not be reverted by the poll's stale cursor.
     */
    private static function save_runtime_state( array $local, array $original ): void {
        $fresh   = self::get_settings();
        $runtime = [ 'cursor_created_at', 'backfill_page', 'backfill_done' ];

        $changed = false;
        foreach ( $runtime as $key ) {
            if ( (string) ( $fresh[ $key ] ?? '' ) !== (string) ( $original[ $key ] ?? '' ) ) {
                $changed = true;
                break;
            }
        }
        if ( ! $changed ) {
            foreach ( $runtime as $key ) {
                $fresh[ $key ] = $local[ $key ];
            }
        }

        $fresh['last_poll_at']     = $local['last_poll_at'];
        $fresh['last_poll_status'] = $local['last_poll_status'];
        self::update_settings( $fresh );
    }

    /**
     * Apply the first-sync choice. The cursor is always "now"; a full sync
     * additionally walks the history via backfill_page. 'now' mode gets a
     * floor (sync_from) so the poll's look-behind never imports older documents.
     */
    public static function reset_sync_position( array $settings, string $mode ): array {
        $mode = $mode === 'full' ? 'full' : 'now';
        $now  = gmdate( 'Y-m-d\TH:i:s\Z' );

        $settings['initial_sync_mode'] = $mode;
        $settings['cursor_created_at'] = $now;
        $settings['sync_from']         = $mode === 'full' ? '' : $now;
        $settings['backfill_page']     = $mode === 'full' ? 1 : 0;
        $settings['backfill_done']     = $mode === 'full' ? 0 : 1;
        unset( $settings['cursor_ids'] );
        return $settings;
    }

    public static function sync_poll_cron(): void {
        $settings = self::get_settings();
        $enabled  = ! empty( $settings['api_key'] ) && ! empty( $settings['poll_enabled'] );
        $scheduled = wp_next_scheduled( self::CRON_POLL );

        if ( $enabled && ! $scheduled ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_POLL );
        } elseif ( ! $enabled && $scheduled ) {
            wp_clear_scheduled_hook( self::CRON_POLL );
        }
    }

    public static function webhook_url(): string {
        $settings = self::get_settings();
        if ( ! empty( $settings['webhook_url_override'] ) ) {
            return $settings['webhook_url_override'];
        }
        return rest_url( self::REST_NS . self::REST_ROUTE );
    }

    /** Safe subset for templates / AJAX (never exposes secrets). */
    public static function get_public_status(): array {
        $s = self::get_settings();
        return [
            'api_configured'     => ! empty( $s['api_key'] ),
            'company_name'       => $s['company_name'],
            'poll_enabled'       => (int) $s['poll_enabled'],
            'last_poll_at'       => $s['last_poll_at'],
            'last_poll_status'   => $s['last_poll_status'],
            'backfill_page'      => (int) $s['backfill_page'],
            'backfill_done'      => (int) $s['backfill_done'],
            'has_cursor'         => ! empty( $s['cursor_created_at'] ),
            'webhook_configured' => ! empty( $s['webhook_id'] ) && ! empty( $s['webhook_secret'] ),
            'webhook_url'        => self::webhook_url(),
        ];
    }

    private static function api(): PeppolApi {
        $settings = self::get_settings();
        return new PeppolApi( (string) $settings['api_key'] );
    }

    /* ------------------------------------------------------------------
     * AJAX — list / detail
     * ------------------------------------------------------------------ */

    public static function ajax_list(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'peppol' );
        global $wpdb;

        $type  = isset( $_POST['document_type'] ) && $_POST['document_type'] !== '' ? strtoupper( (string) eme_sanitize_request( $_POST['document_type'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $state = isset( $_POST['state'] ) && $_POST['state'] !== '' ? strtoupper( (string) eme_sanitize_request( $_POST['state'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! in_array( $type, self::DOCUMENT_TYPES, true ) ) {
            $type = '';
        }

        $args = [
            'search'        => isset( $_POST['search'] ) && $_POST['search'] !== '' ? eme_sanitize_request( $_POST['search'] ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'document_type' => $type,
            'status'        => isset( $_POST['status'] ) && $_POST['status'] !== '' ? sanitize_key( eme_sanitize_request( $_POST['status'] ) ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'state'         => $state,
            'daterange'     => isset( $_POST['daterange'] ) && $_POST['daterange'] !== '' ? eme_sanitize_request( $_POST['daterange'] ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
        unset( $row->raw_json );

        wp_send_json_success( $row );
    }

    /* ------------------------------------------------------------------
     * AJAX — actions
     * ------------------------------------------------------------------ */

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

    public static function ajax_retry(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );
        global $wpdb;

        $id  = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}" . self::TABLE . "` WHERE id = %d", $id ) );
        if ( ! $doc ) {
            wp_send_json_error( __( 'Document niet gevonden.', 'eme-accounting' ) );
        }

        $result = self::fetch_document( (string) $doc->document_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $api = self::api();
        if ( ! $api->is_configured() ) {
            wp_send_json_error( __( 'Geen Peppol API-key ingesteld.', 'eme-accounting' ) );
        }

        $me = $api->me();
        if ( is_wp_error( $me ) ) {
            wp_send_json_error( $me->get_error_message() );
        }

        $settings                 = self::get_settings();
        $settings['company_name'] = self::s( $me['company_name'] ?? ( $me['name'] ?? '' ), 190 );
        self::update_settings( $settings );

        wp_send_json_success( [
            'company_name' => $settings['company_name'],
            'company_email'=> self::s( $me['company_email'] ?? '', 190 ),
            'peppol_ids'   => $me['peppol_ids'] ?? [],
        ] );
    }

    public static function ajax_webhook_sync(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $api = self::api();
        if ( ! $api->is_configured() ) {
            wp_send_json_error( __( 'Stel eerst een Peppol API-key in.', 'eme-accounting' ) );
        }

        $settings = self::get_settings();
        $url      = self::webhook_url();
        $hook     = null;

        if ( ! empty( $settings['webhook_id'] ) ) {
            $res = $api->update_webhook( $settings['webhook_id'], [ 'url' => $url, 'events' => self::EVENTS ] );
            if ( ! is_wp_error( $res ) ) {
                $hook = $res;
            } elseif ( $res->get_error_code() === 'peppol_404' ) {
                // Webhook was deleted on the e-invoice side; create a new one.
                $settings['webhook_id']     = '';
                $settings['webhook_secret'] = '';
                self::update_settings( $settings );
            } else {
                wp_send_json_error( $res->get_error_message() );
            }
        }

        if ( $hook === null ) {
            // Adopt an existing webhook for this URL before creating a duplicate.
            $list = $api->list_webhooks();
            if ( ! is_wp_error( $list ) && is_array( $list ) ) {
                foreach ( $list as $candidate ) {
                    if ( isset( $candidate['url'] ) && $candidate['url'] === $url && ! empty( $candidate['id'] ) ) {
                        $hook = $candidate;
                        break;
                    }
                }
            }
        }

        // The secret is normally only returned when a webhook is created. A hook
        // we have no secret for can never be verified (every delivery would get
        // a 503), so replace it instead of adopting it.
        if ( $hook !== null && empty( $hook['secret'] ) && empty( $settings['webhook_secret'] ) ) {
            $stale_id = (string) ( $hook['id'] ?? $settings['webhook_id'] );
            if ( $stale_id !== '' ) {
                $del = $api->delete_webhook( $stale_id );
                if ( is_wp_error( $del ) && $del->get_error_code() !== 'peppol_404' ) {
                    wp_send_json_error( $del->get_error_message() );
                }
            }
            $hook                   = null;
            $settings['webhook_id'] = '';
        }

        if ( $hook === null ) {
            $res = $api->create_webhook( $url, self::EVENTS );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( $res->get_error_message() );
            }
            $hook = $res;
        }

        $settings['webhook_id']     = self::s( $hook['id'] ?? $settings['webhook_id'], 64 );
        $settings['webhook_secret'] = self::s( $hook['secret'] ?? $settings['webhook_secret'], 128 );
        self::update_settings( $settings );

        if ( $settings['webhook_secret'] === '' ) {
            wp_send_json_error( __( 'e-invoice.be gaf geen webhook-secret terug; inkomende meldingen kunnen niet gecontroleerd worden.', 'eme-accounting' ) );
        }

        wp_send_json_success( [
            'webhook_id' => $settings['webhook_id'],
            'url'        => $hook['url'] ?? $url,
            'events'     => $hook['events'] ?? self::EVENTS,
            'status'     => self::get_public_status(),
        ] );
    }

    public static function ajax_webhook_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );

        $settings = self::get_settings();
        if ( ! empty( $settings['webhook_id'] ) ) {
            $api = self::api();
            $res = $api->delete_webhook( $settings['webhook_id'] );
            // A 404 means it is already gone: fine.
            if ( is_wp_error( $res ) && $res->get_error_code() !== 'peppol_404' ) {
                wp_send_json_error( $res->get_error_message() );
            }
        }

        $settings['webhook_id']     = '';
        $settings['webhook_secret'] = '';
        self::update_settings( $settings );

        wp_send_json_success( [ 'status' => self::get_public_status() ] );
    }

    /* ------------------------------------------------------------------
     * Attachment proxy (called by Journal's shared dispatcher)
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
     * Webhook (REST)
     * ------------------------------------------------------------------ */

    public static function register_rest_route(): void {
        register_rest_route( self::REST_NS, self::REST_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle_webhook( \WP_REST_Request $request ) {
        $settings = self::get_settings();
        $secret   = (string) $settings['webhook_secret'];
        if ( $secret === '' ) {
            return new \WP_REST_Response( [ 'error' => 'Peppol webhook is not configured.' ], 503 );
        }

        $body      = (string) $request->get_body();
        $signature = (string) $request->get_header( 'x-signature' );

        try {
            $payload   = json_decode( $body, false, 512, JSON_THROW_ON_ERROR );
            $canonical = self::canonical_json( $payload );
        } catch ( \JsonException $e ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid JSON.' ], 400 );
        }

        $expected = 'sha256=' . hash_hmac( 'sha256', $canonical, $secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
        }

        $event       = json_decode( $body, true );
        $type        = is_array( $event ) ? (string) ( $event['type'] ?? '' ) : '';
        $document_id = is_array( $event ) && is_array( $event['data'] ?? null ) ? (string) ( $event['data']['document_id'] ?? '' ) : '';

        if ( $document_id === '' || ! in_array( $type, self::EVENTS, true ) ) {
            // Unknown but validly signed: acknowledge so it is not retried.
            return new \WP_REST_Response( [ 'ok' => true, 'ignored' => true ], 200 );
        }

        if ( $type === 'document.received.failed' ) {
            self::mark_failed( $document_id, __( 'e-invoice.be kon het inkomende document niet verwerken.', 'eme-accounting' ) );
        } else {
            // Keep the webhook response fast: downloading UBL + attachments
            // easily exceeds the 10 second delivery window.
            wp_schedule_single_event( time(), self::CRON_FETCH, [ $document_id ] );
            // wp-cron normally only fires on the next page view; kick it now.
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
        }

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /** Port of the canonical JSON algorithm from the e-invoice.be docs. */
    private static function canonical_json( $value ): string {
        if ( $value instanceof \stdClass ) {
            $members = (array) $value;
            ksort( $members, SORT_STRING );
            $parts = [];
            foreach ( $members as $key => $member ) {
                $parts[] = self::canonical_string( (string) $key ) . ': ' . self::canonical_json( $member );
            }
            return '{' . implode( ', ', $parts ) . '}';
        }
        if ( is_array( $value ) ) {
            return '[' . implode( ', ', array_map( [ __CLASS__, 'canonical_json' ], $value ) ) . ']';
        }
        if ( is_string( $value ) ) {
            return self::canonical_string( $value );
        }
        return (string) wp_json_encode( $value );
    }

    private static function canonical_string( string $text ): string {
        $json = wp_json_encode( $text, JSON_UNESCAPED_SLASHES );
        return str_replace( "\x7f", '\u007f', (string) $json );
    }

    /* ------------------------------------------------------------------
     * Polling
     * ------------------------------------------------------------------ */

    public static function poll( bool $manual = false ) {
        $settings = self::get_settings();
        if ( empty( $settings['api_key'] ) ) {
            return $manual ? new \WP_Error( 'peppol_no_key', __( 'Geen Peppol API-key ingesteld.', 'eme-accounting' ) ) : [];
        }
        if ( ! $manual && empty( $settings['poll_enabled'] ) ) {
            return [];
        }

        // Cron and "sync now" must not run side by side. A MySQL named lock is
        // atomic (unlike a transient), so two requests cannot both acquire it.
        global $wpdb;
        $lock_name = self::LOCK_NAME . $wpdb->prefix;
        $got_lock  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
        if ( $got_lock != 1 ) {
            return $manual ? new \WP_Error( 'peppol_busy', __( 'Er loopt al een synchronisatie, probeer het zo opnieuw.', 'eme-accounting' ) ) : [];
        }

        try {
            return self::run_poll( $settings, $manual );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    private static function run_poll( array $settings, bool $manual ): array {
        $original_settings = $settings;
        $api        = self::api();
        $limit      = $manual ? self::MAX_DOCS_MANUAL : self::MAX_DOCS_PER_RUN;
        $processed  = 0;
        $skipped    = 0;
        $errors     = 0;
        $poll_error = '';

        // ── 1. Normal poll: everything newer than the cursor (minus a lag) ──
        $cursor_ts = ! empty( $settings['cursor_created_at'] ) ? (int) strtotime( $settings['cursor_created_at'] ) : 0;

        // sync_from: null = settings from before this field existed (no lag),
        // '' = no floor (full history), otherwise nothing older is imported.
        $sync_from = $settings['sync_from'] ?? null;
        if ( $sync_from === null ) {
            $floor_ts = $cursor_ts;
        } else {
            $floor_ts = $sync_from !== '' ? (int) strtotime( (string) $sync_from ) : 0;
        }
        $stop_ts = $cursor_ts ? max( $floor_ts, $cursor_ts - self::CURSOR_LAG ) : $floor_ts;

        $opts            = [ 'limit' => $limit, 'retry_errors' => true, 'backoff' => ! $manual ];
        $newest          = '';
        $page            = 1;
        $normal_complete = true;

        for ( $i = 0; $i < self::MAX_POLL_PAGES; $i++ ) {
            $res = $api->inbox( [
                'sort_by'    => 'created_at',
                'sort_order' => 'desc',
                'page'       => $page,
                'page_size'  => self::PAGE_SIZE,
            ] );
            if ( is_wp_error( $res ) ) {
                $errors++;
                $normal_complete = false;
                $poll_error      = $res->get_error_message();
                break;
            }

            $items = is_array( $res['items'] ?? null ) ? $res['items'] : [];
            if ( $i === 0 && $items ) {
                $newest = (string) ( $items[0]['created_at'] ?? '' );
            }

            $eligible = [];
            $stop     = false;
            foreach ( $items as $item ) {
                if ( empty( $item['id'] ) ) {
                    continue;
                }
                $ts = ! empty( $item['created_at'] ) ? (int) strtotime( (string) $item['created_at'] ) : 0;
                if ( $stop_ts && $ts && $ts < $stop_ts ) {
                    $stop = true;
                    break;
                }
                $eligible[] = $item;
            }

            $limit_hit = false;
            if ( $eligible && ! self::process_items( $eligible, $processed, $skipped, $errors, $opts ) ) {
                $limit_hit       = true;
                $normal_complete = false;
            }

            if ( $stop || $limit_hit || empty( $res['has_next_page'] ) ) {
                break;
            }
            $page++;
        }

        // Hitting the page cap with more pages left means the scan is not
        // complete either: leave the cursor where it is.
        if ( $page > self::MAX_POLL_PAGES ) {
            $normal_complete = false;
        }

        // Only move the cursor when the scan finished without errors or a
        // per-run limit. Otherwise the next poll could skip documents that
        // this run did not get to.
        if ( $newest !== '' && $normal_complete && (int) strtotime( $newest ) > $cursor_ts ) {
            $settings['cursor_created_at'] = $newest;
        }

        // ── 2. One-time backfill of older documents ──────────────────────
        if ( empty( $settings['backfill_done'] ) && (int) $settings['backfill_page'] > 0 ) {
            $opts      = [ 'limit' => $limit, 'retry_errors' => false, 'backoff' => true ];
            $page      = (int) $settings['backfill_page'];
            $pages     = 0;
            $exhausted = false;
            $limit_hit = false;

            while ( $pages < self::MAX_BACKFILL_PAGES ) {
                $res = $api->inbox( [
                    'sort_by'    => 'created_at',
                    'sort_order' => 'desc',
                    'page'       => $page,
                    'page_size'  => self::PAGE_SIZE,
                ] );
                if ( is_wp_error( $res ) ) {
                    $errors++;
                    if ( $poll_error === '' ) {
                        $poll_error = $res->get_error_message();
                    }
                    break;
                }

                $items = is_array( $res['items'] ?? null ) ? $res['items'] : [];
                if ( $items && ! self::process_items( $items, $processed, $skipped, $errors, $opts ) ) {
                    $limit_hit = true;
                    break;
                }

                if ( empty( $res['has_next_page'] ) ) {
                    $exhausted = true;
                    break;
                }
                $page++;
                $pages++;
            }

            if ( $exhausted ) {
                $settings['backfill_done'] = 1;
                $settings['backfill_page'] = 0;
            } elseif ( ! $limit_hit ) {
                $settings['backfill_page'] = $page;
            }
        }

        $settings['last_poll_at']     = gmdate( 'Y-m-d H:i:s' );
        $settings['last_poll_status'] = $poll_error !== '' ? $poll_error : sprintf(
            /* translators: 1: processed, 2: skipped, 3: errors */
            __( 'Verwerkt: %1$d, overgeslagen: %2$d, fouten: %3$d', 'eme-accounting' ),
            $processed,
            $skipped,
            $errors
        );
        self::save_runtime_state( $settings, $original_settings );

        return [
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    /**
     * Fetch metadata/files for each inbox item that is not yet in the DB
     * (and, for the normal poll, retry rows that ended in an error).
     *
     * @param array $opts limit (int), retry_errors (bool), backoff (bool).
     * @return bool False when the per-run document limit was reached.
     */
    private static function process_items( array $items, int &$processed, int &$skipped, int &$errors, array $opts ): bool {
        $ids = [];
        foreach ( $items as $item ) {
            if ( ! empty( $item['id'] ) ) {
                $ids[] = (string) $item['id'];
            }
        }
        $existing = self::existing_docs( $ids );

        foreach ( $items as $item ) {
            $id = (string) ( $item['id'] ?? '' );
            if ( $id === '' ) {
                continue;
            }
            if ( isset( $existing[ $id ] ) && ! self::should_retry( $existing[ $id ], $opts ) ) {
                $skipped++;
                continue;
            }
            if ( $processed >= (int) $opts['limit'] ) {
                return false;
            }
            $result = self::fetch_document( $id );
            if ( is_wp_error( $result ) ) {
                $errors++;
            } else {
                $processed++;
            }
        }
        return true;
    }

    private static function should_retry( array $row, array $opts ): bool {
        if ( empty( $opts['retry_errors'] ) || $row['status'] !== 'error' ) {
            return false;
        }
        // e-invoice.be permanently rejected this document; retrying cannot help.
        if ( ( $row['state'] ?? '' ) === 'FAILED' ) {
            return false;
        }
        if ( empty( $opts['backoff'] ) ) {
            return true;
        }
        $fetched = $row['fetched_at'] ? (int) strtotime( $row['fetched_at'] . ' UTC' ) : 0;
        return $fetched < time() - self::RETRY_BACKOFF;
    }

    /** @return array<string, array{status:string, state:string, fetched_at:?string}> keyed by document_id */
    private static function existing_docs( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        $table        = $wpdb->prefix . self::TABLE;
        $found        = $wpdb->get_results( $wpdb->prepare( "SELECT document_id, processing_status, state, fetched_at FROM `{$table}` WHERE document_id IN ({$placeholders})", ...$ids ), ARRAY_A ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $map          = [];
        foreach ( (array) $found as $row ) {
            $map[ (string) $row['document_id'] ] = [
                'status'     => (string) $row['processing_status'],
                'state'      => (string) $row['state'],
                'fetched_at' => $row['fetched_at'] ?: null,
            ];
        }
        return $map;
    }

    public static function fetch_document_cron( $document_id ): void {
        self::fetch_document( (string) $document_id );
    }

    /**
     * Download metadata, UBL and attachments for one document.
     *
     * @return true|\WP_Error
     */
    public static function fetch_document( string $document_id ) {
        $document_id = trim( sanitize_text_field( $document_id ) );
        if ( $document_id === '' ) {
            return new \WP_Error( 'peppol_no_document', __( 'Leeg document-id.', 'eme-accounting' ) );
        }

        $api = self::api();
        if ( ! $api->is_configured() ) {
            return new \WP_Error( 'peppol_no_key', __( 'Geen Peppol API-key ingesteld.', 'eme-accounting' ) );
        }

        $doc = $api->document( $document_id );
        if ( is_wp_error( $doc ) ) {
            self::mark_failed( $document_id, $doc->get_error_message(), false );
            return $doc;
        }

        $row_id = self::upsert_document( $doc );
        if ( is_wp_error( $row_id ) ) {
            return $row_id;
        }

        // A successful metadata fetch clears an earlier error; a failing
        // UBL/attachment download below can set it again.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [ 'processing_status' => 'new' ],
            [ 'id' => (int) $row_id, 'processing_status' => 'error' ]
        );

        self::ensure_ubl( (int) $row_id, $document_id, $api );
        self::ensure_attachments( (int) $row_id, $document_id, $api );

        return true;
    }

    /* ------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    private static function upsert_document( array $doc ) {
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
            'state'              => self::s( $doc['state'] ?? 'RECEIVED', 20 ),
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

    private static function ensure_ubl( int $row_id, string $document_id, PeppolApi $api ): void {
        if ( self::get_attachments()->get_files( $row_id, self::TYPE, 'ubl' ) ) {
            return;
        }

        $meta = $api->ubl( $document_id );
        if ( is_wp_error( $meta ) ) {
            self::set_row_error( $row_id, $meta->get_error_message() );
            return;
        }

        $url = (string) ( $meta['signed_url'] ?? '' );
        if ( $url === '' ) {
            self::set_row_error( $row_id, __( 'Geen UBL-download-URL ontvangen.', 'eme-accounting' ) );
            return;
        }

        $name = self::s( $meta['file_name'] ?? $document_id . '.xml', 255 );
        if ( pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
            $name .= '.xml';
        }

        $bytes = $api->download_file( $url );
        if ( is_wp_error( $bytes ) ) {
            self::set_row_error( $row_id, $bytes->get_error_message() );
            return;
        }

        if ( self::get_attachments()->store_bytes( $row_id, self::TYPE, 'ubl', $name, $bytes, [ 'xml' ] ) === false ) {
            self::set_row_error( $row_id, __( 'UBL kon niet op schijf worden bewaard.', 'eme-accounting' ) );
        }
    }

    private static function ensure_attachments( int $row_id, string $document_id, PeppolApi $api ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT attachments_json FROM `{$table}` WHERE id = %d", $row_id ) );

        $old     = $row && $row->attachments_json ? json_decode( (string) $row->attachments_json, true ) : [];
        $old     = is_array( $old ) ? $old : [];
        $old_map = [];
        foreach ( $old as $att ) {
            if ( ! empty( $att['id'] ) ) {
                $old_map[ (string) $att['id'] ] = $att;
            }
        }

        $local_files = [];
        foreach ( self::get_attachments()->get_files( $row_id, self::TYPE, '' ) as $file ) {
            $local_files[ $file['field_name'] ] = true;
        }

        $list = $api->attachments( $document_id );
        if ( is_wp_error( $list ) ) {
            self::set_row_error( $row_id, $list->get_error_message() );
            return;
        }
        if ( ! is_array( $list ) || ! $list ) {
            return;
        }

        $changed = false;
        foreach ( $list as &$att ) {
            $att_id = self::s( $att['id'] ?? '', 64 );
            if ( $att_id !== '' && isset( $old_map[ $att_id ]['_local'] ) ) {
                $att['_local'] = $old_map[ $att_id ]['_local'];
            }
            if ( ! empty( $att['_local'] ) && isset( $local_files[ $att['_local'] ] ) ) {
                continue;
            }

            $url = (string) ( $att['file_url'] ?? '' );
            if ( $url === '' ) {
                continue;
            }
            $name = self::s( $att['file_name'] ?? ( 'attachment-' . $att_id ), 255 );
            if ( pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
                continue;
            }

            $key   = ( ( $att['file_type'] ?? '' ) === 'application/pdf' ) ? 'pdf' : 'att';
            $bytes = $api->download_file( $url );
            if ( is_wp_error( $bytes ) ) {
                self::set_row_error( $row_id, $bytes->get_error_message() );
                continue;
            }

            $stored = self::get_attachments()->store_bytes( $row_id, self::TYPE, $key, $name, $bytes );
            if ( $stored === false ) {
                self::set_row_error( $row_id, sprintf(
                    /* translators: %s: filename */
                    __( 'Bijlage %s kon niet op schijf worden bewaard.', 'eme-accounting' ),
                    $name
                ) );
                continue;
            }
            $att['_local']       = $stored;
            $local_files[ $stored ] = true;
            $changed             = true;
        }
        unset( $att );

        if ( $changed ) {
            $wpdb->update( $table, [ 'attachments_json' => wp_json_encode( $list ) ], [ 'id' => $row_id ] );
        }
    }

    private static function set_row_error( int $row_id, string $message ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'processing_status' => 'error',
                'last_error'        => $message,
            ],
            [ 'id' => $row_id ]
        );
    }

    /**
     * @param bool $set_state True for the "document.received.failed" event
     *                        (e-invoice.be could not process it). False for a
     *                        failed fetch on our side, which must not overwrite
     *                        the state of a document we already have.
     */
    public static function mark_failed( string $document_id, string $message, bool $set_state = true ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE document_id = %s", $document_id ) );
        if ( $existing ) {
            $update = [
                'processing_status' => 'error',
                'last_error'        => $message,
                'fetched_at'        => gmdate( 'Y-m-d H:i:s' ),
            ];
            if ( $set_state ) {
                $update['state'] = 'FAILED';
            }
            $wpdb->update( $table, $update, [ 'id' => (int) $existing ] );
            return;
        }

        $row = [
            'document_id'       => self::s( $document_id, 64 ),
            'direction'         => 'INBOUND',
            'state'             => $set_state ? 'FAILED' : '',
            'currency'          => 'EUR',
            'processing_status' => 'error',
            'last_error'        => $message,
            'received_at'       => gmdate( 'Y-m-d H:i:s' ),
            'fetched_at'        => gmdate( 'Y-m-d H:i:s' ),
        ];
        $wpdb->insert( $table, $row );
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
        if ( ! empty( $args['state'] ) ) {
            $where[]   = 'state = %s';
            $prepare[] = $args['state'];
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
        $allowed = [ 'id', 'invoice_date', 'received_at', 'invoice_total', 'vendor_name', 'invoice_id', 'document_type', 'state', 'processing_status' ];
        $out     = [];

        foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $part ) {
            if ( preg_match( '/^([a-z_]+)(?:\s+(asc|desc))?$/i', $part, $m ) ) {
                $column = strtolower( $m[1] );
                if ( ! in_array( $column, $allowed, true ) ) {
                    continue;
                }
                $dir   = isset( $m[2] ) && strtoupper( $m[2] ) === 'DESC' ? 'DESC' : 'ASC';
                $out[] = $column . ' ' . $dir;
            }
        }

        return $out ? implode( ', ', $out ) : 'received_at DESC, id DESC';
    }

    private static function s( $value, int $max = 0 ): string {
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
