<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * Peppol source: the e-invoice.be API.
 *
 * Receives inbound documents through a webhook (REST) and/or an hourly poll
 * and hands them to the shared inbox (Peppol). The e-invoice.be API has no
 * "processed" flag, so local de-duplication is done on the e-invoice
 * document_id. Settings live in the shared Peppol option.
 */
class PeppolEInvoice {

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

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_peppol_api_sync', [ __CLASS__, 'ajax_sync' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_retry',    [ __CLASS__, 'ajax_retry' ] );
        add_action( 'wp_ajax_eme_accounting_peppol_api_test', [ __CLASS__, 'ajax_test_connection' ] );

        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_route' ] );

        add_action( self::CRON_POLL, [ __CLASS__, 'poll' ] );
        add_action( self::CRON_FETCH, [ __CLASS__, 'fetch_document_cron' ], 10, 1 );

        // Re-arm the hourly poll when it got lost (activation, manual option
        // import, ...). Only on admin/cron requests, so front-end traffic does
        // not hit the (non-autoloaded) settings option on every page view.
        if ( is_admin() || wp_doing_cron() ) {
            $settings = Peppol::get_settings();
            if ( ! empty( $settings['api_key'] ) && ! empty( $settings['poll_enabled'] ) && ! wp_next_scheduled( self::CRON_POLL ) ) {
                wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_POLL );
            }
        }
    }

    /* ------------------------------------------------------------------
     * Settings / sync position / status
     * ------------------------------------------------------------------ */

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

    /**
     * Source-specific rules for a settings-form update. Called before the new
     * values are stored: when the API key changes, the webhook belongs to the
     * old account and must be removed with the old key before it is lost.
     *
     * @return array|\WP_Error The normalized settings, or an error that aborts the save.
     */
    public static function prepare_settings_update( array $new, array $old ): array|\WP_Error {
        $old_key = (string) ( $old['api_key'] ?? '' );
        $new_key = (string) ( $new['api_key'] ?? '' );
        if ( $old_key === $new_key ) {
            return $new;
        }

        $old_webhook_id = (string) ( $old['webhook_id'] ?? '' );
        if ( $old_key !== '' && $old_webhook_id !== '' ) {
            $deleted = self::delete_remote_webhook( $old_key, $old_webhook_id );
            if ( is_wp_error( $deleted ) ) {
                return $deleted;
            }
        }

        $new['webhook_id']     = '';
        $new['webhook_secret'] = '';
        return $new;
    }

    /** @return true|\WP_Error */
    private static function delete_remote_webhook( string $api_key, string $webhook_id ) {
        $res = ( new PeppolEInvoiceApi( $api_key ) )->delete_webhook( $webhook_id );
        // A 404 means the webhook was already removed on the e-invoice side.
        if ( is_wp_error( $res ) && $res->get_error_code() !== 'peppol_404' ) {
            return new \WP_Error(
                'peppol_webhook_delete_failed',
                sprintf(
                    /* translators: %s: API error message */
                    __( 'De oude webhook kon niet verwijderd worden: %s', 'eme-accounting' ),
                    $res->get_error_message()
                )
            );
        }
        return true;
    }

    public static function sync_poll_cron(): void {
        $settings = Peppol::get_settings();
        $enabled  = ! empty( $settings['api_key'] ) && ! empty( $settings['poll_enabled'] );
        $scheduled = wp_next_scheduled( self::CRON_POLL );

        if ( $enabled && ! $scheduled ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_POLL );
        } elseif ( ! $enabled && $scheduled ) {
            wp_clear_scheduled_hook( self::CRON_POLL );
        }
    }

    public static function webhook_url(): string {
        $settings = Peppol::get_settings();
        if ( ! empty( $settings['webhook_url_override'] ) ) {
            return $settings['webhook_url_override'];
        }
        return rest_url( self::REST_NS . self::REST_ROUTE );
    }

    /** Safe subset for templates / AJAX (never exposes secrets). */
    public static function get_public_status(): array {
        $s = Peppol::get_settings();
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

    /* ------------------------------------------------------------------
     * Client
     * ------------------------------------------------------------------ */

    private static function api(): PeppolEInvoiceApi {
        $settings = Peppol::get_settings();
        return new PeppolEInvoiceApi( (string) $settings['api_key'] );
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
        $fresh   = Peppol::get_settings();
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
        Peppol::update_settings( $fresh );
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

    public static function ajax_retry(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'peppol' );
        global $wpdb;

        $id  = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}" . Peppol::TABLE . "` WHERE id = %d", $id ) );
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

        $settings                 = Peppol::get_settings();
        $settings['company_name'] = Peppol::s( $me['company_name'] ?? ( $me['name'] ?? '' ), 190 );
        Peppol::update_settings( $settings );

        wp_send_json_success( [
            'company_name' => $settings['company_name'],
            'company_email'=> Peppol::s( $me['company_email'] ?? '', 190 ),
            'peppol_ids'   => $me['peppol_ids'] ?? [],
        ] );
    }

    /* ------------------------------------------------------------------
     * Webhook (REST)
     * ------------------------------------------------------------------ */

    /**
     * Create, adopt or update the e-invoice.be webhook for the current site.
     * Called automatically after a settings save; never writes JSON itself so
     * the caller can report errors in the settings page.
     *
     * @return array|\WP_Error
     */
    public static function sync_webhook() {
        $api = self::api();
        if ( ! $api->is_configured() ) {
            return new \WP_Error( 'peppol_no_key', __( 'Stel eerst een Peppol API-key in.', 'eme-accounting' ) );
        }

        $settings = Peppol::get_settings();
        $url      = self::webhook_url();
        $hook     = null;

        if ( ! empty( $settings['webhook_id'] ) ) {
            $res = $api->update_webhook( $settings['webhook_id'], [ 'url' => $url, 'events' => self::EVENTS, 'enabled' => true ] );
            if ( ! is_wp_error( $res ) ) {
                $hook = $res;
            } elseif ( $res->get_error_code() === 'peppol_404' ) {
                // Webhook was deleted on the e-invoice side; create a new one.
                $settings['webhook_id']     = '';
                $settings['webhook_secret'] = '';
                Peppol::update_settings( $settings );
            } else {
                return $res;
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

        // An adopted webhook may have been disabled on the e-invoice.be side.
        if ( $hook !== null && ( $hook['enabled'] ?? true ) === false && ! empty( $hook['id'] ) ) {
            $res = $api->update_webhook( (string) $hook['id'], [ 'url' => $url, 'events' => self::EVENTS, 'enabled' => true ] );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $hook = $res;
        }

        if ( $hook === null ) {
            $res = $api->create_webhook( $url, self::EVENTS );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $hook = $res;
        }

        // Re-read before persisting: poll() and mailbox scans also write this
        // option and must not have their runtime state overwritten here.
        $latest = Peppol::get_settings();
        if ( (string) ( $latest['api_key'] ?? '' ) !== (string) ( $settings['api_key'] ?? '' ) ) {
            return new \WP_Error( 'peppol_settings_changed', __( 'De Peppol-instellingen zijn intussen gewijzigd. Sla ze opnieuw op.', 'eme-accounting' ) );
        }

        $latest['webhook_id']     = Peppol::s( $hook['id'] ?? $settings['webhook_id'], 64 );
        $latest['webhook_secret'] = Peppol::s( $hook['secret'] ?? $settings['webhook_secret'], 128 );
        Peppol::update_settings( $latest );

        if ( $latest['webhook_secret'] === '' ) {
            return new \WP_Error(
                'peppol_webhook_no_secret',
                __( 'e-invoice.be gaf geen webhook-secret terug; inkomende meldingen kunnen niet gecontroleerd worden.', 'eme-accounting' )
            );
        }

        return [
            'webhook_id' => $latest['webhook_id'],
            'url'        => $hook['url'] ?? $url,
            'events'     => $hook['events'] ?? self::EVENTS,
            'status'     => self::get_public_status(),
        ];
    }

    public static function register_rest_route(): void {
        register_rest_route( self::REST_NS, self::REST_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle_webhook( \WP_REST_Request $request ) {
        $settings = Peppol::get_settings();
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
     * Poll / backfill
     * ------------------------------------------------------------------ */

    public static function poll( bool $manual = false ) {
        $settings = Peppol::get_settings();
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
        if ( ( $row['peppol_status'] ?? '' ) === 'FAILED' ) {
            return false;
        }
        if ( empty( $opts['backoff'] ) ) {
            return true;
        }
        $fetched = $row['fetched_at'] ? (int) strtotime( $row['fetched_at'] . ' UTC' ) : 0;
        return $fetched < time() - self::RETRY_BACKOFF;
    }

    /** @return array<string, array{status:string, peppol_status:string, fetched_at:?string}> keyed by document_id */
    private static function existing_docs( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        $table        = $wpdb->prefix . Peppol::TABLE;
        $found        = $wpdb->get_results( $wpdb->prepare( "SELECT document_id, processing_status, peppol_status, fetched_at FROM `{$table}` WHERE document_id IN ({$placeholders})", ...$ids ), ARRAY_A ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $map          = [];
        foreach ( (array) $found as $row ) {
            $map[ (string) $row['document_id'] ] = [
                'status'        => (string) $row['processing_status'],
                'peppol_status' => (string) $row['peppol_status'],
                'fetched_at'    => $row['fetched_at'] ?: null,
            ];
        }
        return $map;
    }

    /* ------------------------------------------------------------------
     * Fetching a document
     * ------------------------------------------------------------------ */

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

        $existing = Peppol::find_existing_document( $doc );
        if ( $existing ) {
            $doc['id'] = $existing->document_id;
        }

        $row_id = Peppol::upsert_document( $doc );
        if ( is_wp_error( $row_id ) ) {
            return $row_id;
        }

        // A successful metadata fetch clears an earlier error; a failing
        // UBL/attachment download below can set it again.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . Peppol::TABLE,
            [ 'processing_status' => 'new' ],
            [ 'id' => (int) $row_id, 'processing_status' => 'error' ]
        );

        self::ensure_ubl( (int) $row_id, $document_id, $api );
        self::ensure_attachments( (int) $row_id, $document_id, $api );

        return true;
    }

    private static function ensure_ubl( int $row_id, string $document_id, PeppolEInvoiceApi $api ): void {
        if ( Peppol::get_attachments()->get_files( $row_id, Peppol::TYPE, 'ubl' ) ) {
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

        $name = Peppol::s( $meta['file_name'] ?? $document_id . '.xml', 255 );
        if ( pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
            $name .= '.xml';
        }

        $bytes = $api->download_file( $url );
        if ( is_wp_error( $bytes ) ) {
            self::set_row_error( $row_id, $bytes->get_error_message() );
            return;
        }

        if ( Peppol::get_attachments()->store_bytes( $row_id, Peppol::TYPE, 'ubl', $name, $bytes, [ 'xml' ] ) === false ) {
            self::set_row_error( $row_id, __( 'UBL kon niet op schijf worden bewaard.', 'eme-accounting' ) );
        }
    }

    private static function ensure_attachments( int $row_id, string $document_id, PeppolEInvoiceApi $api ): void {
        global $wpdb;
        $table = $wpdb->prefix . Peppol::TABLE;
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
        foreach ( Peppol::get_attachments()->get_files( $row_id, Peppol::TYPE, '' ) as $file ) {
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
            $att_id = Peppol::s( $att['id'] ?? '', 64 );
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
            $name = Peppol::s( $att['file_name'] ?? ( 'attachment-' . $att_id ), 255 );
            if ( pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
                continue;
            }

            $key   = ( ( $att['file_type'] ?? '' ) === 'application/pdf' ) ? 'pdf' : 'att';
            $bytes = $api->download_file( $url );
            if ( is_wp_error( $bytes ) ) {
                self::set_row_error( $row_id, $bytes->get_error_message() );
                continue;
            }

            $stored = Peppol::get_attachments()->store_bytes( $row_id, Peppol::TYPE, $key, $name, $bytes );
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

    /* ------------------------------------------------------------------
     * Error bookkeeping
     * ------------------------------------------------------------------ */

    private static function set_row_error( int $row_id, string $message ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . Peppol::TABLE,
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
        $table = $wpdb->prefix . Peppol::TABLE;

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE document_id = %s", $document_id ) );
        if ( $existing ) {
            $update = [
                'processing_status' => 'error',
                'last_error'        => $message,
                'fetched_at'        => gmdate( 'Y-m-d H:i:s' ),
            ];
            if ( $set_state ) {
                $update['peppol_status'] = 'FAILED';
            }
            $wpdb->update( $table, $update, [ 'id' => (int) $existing ] );
            return;
        }

        $row = [
            'document_id'       => Peppol::s( $document_id, 64 ),
            'direction'         => 'INBOUND',
            'peppol_status'     => $set_state ? 'FAILED' : '',
            'currency'          => 'EUR',
            'processing_status' => 'error',
            'last_error'        => $message,
            'received_at'       => gmdate( 'Y-m-d H:i:s' ),
            'fetched_at'        => gmdate( 'Y-m-d H:i:s' ),
        ];
        $wpdb->insert( $table, $row );
    }
}
