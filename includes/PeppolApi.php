<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal HTTP client for the e-invoice.be (Peppol) API.
 *
 * Only what the inbound inbox needs: company info, inbox listing,
 * document metadata, UBL/attachment downloads and webhook management.
 *
 * All calls return an array on success or a WP_Error on failure.
 * The API key and webhook secret are never logged.
 */
class PeppolApi {

    const BASE_URL = 'https://api.e-invoice.be';

    private string $api_key;

    public function __construct( string $api_key = '' ) {
        $this->api_key = $api_key;
    }

    public function is_configured(): bool {
        return $this->api_key !== '';
    }

    /* ------------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------------ */

    public function me() {
        return $this->request( 'GET', '/api/me/' );
    }

    public function inbox( array $params = [] ) {
        return $this->request( 'GET', '/api/inbox/', $params );
    }

    public function document( string $document_id ) {
        return $this->request( 'GET', '/api/documents/' . rawurlencode( $document_id ) );
    }

    public function ubl( string $document_id ) {
        return $this->request( 'GET', '/api/documents/' . rawurlencode( $document_id ) . '/ubl' );
    }

    public function attachments( string $document_id ) {
        $res = $this->request( 'GET', '/api/documents/' . rawurlencode( $document_id ) . '/attachments' );
        if ( is_wp_error( $res ) && $res->get_error_code() === 'peppol_404' ) {
            // No attachments is a normal situation.
            return [];
        }
        return $res;
    }

    public function list_webhooks() {
        return $this->request( 'GET', '/api/webhooks/' );
    }

    public function create_webhook( string $url, array $events ) {
        return $this->request( 'POST', '/api/webhooks/', [], [
            'url'     => $url,
            'events'  => array_values( $events ),
            'enabled' => true,
        ] );
    }

    public function update_webhook( string $webhook_id, array $data ) {
        return $this->request( 'PUT', '/api/webhooks/' . rawurlencode( $webhook_id ), [], $data );
    }

    public function delete_webhook( string $webhook_id ) {
        return $this->request( 'DELETE', '/api/webhooks/' . rawurlencode( $webhook_id ) );
    }

    public function test_webhook( string $webhook_id, string $event_type, array $data ) {
        return $this->request( 'POST', '/api/webhooks/' . rawurlencode( $webhook_id ) . '/test', [], [
            'event_type' => $event_type,
            'data'       => $data,
        ] );
    }

    /**
     * Download a (signed) file URL. These URLs carry their own signature
     * and must be fetched without the Authorization header.
     *
     * @return string|WP_Error Raw bytes on success.
     */
    public function download_file( string $url ) {
        $response = wp_remote_get( $url, [
            'timeout'     => 60,
            'redirection' => 5,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error( 'peppol_download', sprintf( 'Download mislukt (HTTP %d).', $code ) );
        }
        return (string) wp_remote_retrieve_body( $response );
    }

    /* ------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------ */

    private function request( string $method, string $path, array $query = [], ?array $body = null ) {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'peppol_no_key', __( 'Geen Peppol API-key ingesteld.', 'eme-accounting' ) );
        }

        $url = self::BASE_URL . $path;
        if ( $query ) {
            $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/json',
            ],
        ];
        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $raw     = (string) wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $code >= 200 && $code < 300 ) {
            return is_array( $decoded ) ? $decoded : [];
        }

        $detail = 'API-fout';
        if ( is_array( $decoded ) && ! empty( $decoded['detail'] ) ) {
            $detail = is_string( $decoded['detail'] ) ? $decoded['detail'] : wp_json_encode( $decoded['detail'] );
        }

        return new \WP_Error(
            'peppol_' . $code,
            sprintf( 'Peppol API %d: %s', $code, $detail )
        );
    }
}
