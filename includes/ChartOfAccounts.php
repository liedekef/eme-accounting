<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class ChartOfAccounts {

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_coa',   [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_delete_coa',   [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_eme_accounting_reorder_coa',  [ __CLASS__, 'ajax_reorder' ] );
        add_action( 'wp_ajax_eme_accounting_coa_next_seq', [ __CLASS__, 'ajax_next_seq' ] );
    }

    public static function seed(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
        if ( $count > 0 ) {
            return;
        }

$accounts = [
            // Ontvangsten
            [ 'code' => '01', 'name' => 'Lidgelden', 'direction' => 'in', 'children' => [
                [ 'code' => '01-000001', 'name' => 'Lidgelden' ],
            ] ],
            [ 'code' => '02', 'name' => 'Schenkingen en legaten', 'direction' => 'in', 'children' => [
                [ 'code' => '02-000001', 'name' => 'Giften' ],
            ] ],
            [ 'code' => '03', 'name' => 'Subsidies', 'direction' => 'in', 'children' => [
                [ 'code' => '03-000001', 'name' => 'Projectsubsidies Agentschap Sociaal-Cultureel Werk' ],
                [ 'code' => '03-000002', 'name' => 'Andere Rijks- en Gemeenschapssubsidies' ],
                [ 'code' => '03-000003', 'name' => 'Subsidies Stad/Gemeente' ],
                [ 'code' => '03-000004', 'name' => 'Gewestsubsidies voor tewerkgesteld personeel' ],
                [ 'code' => '03-000005', 'name' => 'Subsidies projectfondsen (KBS, CERA,...)' ],
                [ 'code' => '03-000006', 'name' => 'Andere subsidies' ],
            ] ],
            [ 'code' => '04', 'name' => 'Andere ontvangsten', 'direction' => 'in', 'children' => [
                [ 'code' => '04-000001', 'name' => 'Verkoop dranken en eetwaren (in bijv. kantine of instuifruimte)' ],
                [ 'code' => '04-000002', 'name' => 'Uitzonderlijke verkoop dranken en eetwaren (fundraising op bijv. eetfeest of grote fuif)' ],
                [ 'code' => '04-000003', 'name' => 'Verkoop diversen' ],
                [ 'code' => '04-000004', 'name' => 'Opbrengsten activiteiten' ],
                [ 'code' => '04-000005', 'name' => 'Opbrengsten uit kadervorming' ],
                [ 'code' => '04-000006', 'name' => 'Huuropbrengsten' ],
                [ 'code' => '04-000007', 'name' => 'Opbrengsten uit thesauriebeleggingen (termijnrekening)' ],
                [ 'code' => '04-000008', 'name' => 'Opbrengsten uit beschikbare waarden bankrekening' ],
                [ 'code' => '04-000009', 'name' => 'Andere financiële opbrengsten' ],
                [ 'code' => '04-000010', 'name' => 'Andere opbrengsten' ],
                [ 'code' => '04-000011', 'name' => 'Elektriciteit en gas (creditnota)' ],
                [ 'code' => '04-000012', 'name' => 'Ontvangsten waarborgen' ],
                [ 'code' => '04-000013', 'name' => 'Vergoedingen vanwege verzekeringsmaatschappijen' ],
                [ 'code' => '04-000014', 'name' => 'Vergoedingen voor publiciteitsopname' ],
                [ 'code' => '04-000015', 'name' => 'Andere vergoedingen' ],
                [ 'code' => '04-000016', 'name' => 'Kassaverschil' ],
                [ 'code' => '04-000017', 'name' => "Creditnota's" ],
                [ 'code' => '04-000018', 'name' => 'Terugbetaling (dubbel betaalde) factuur' ],
            ] ],
            // Uitgaven
            [ 'code' => '01', 'name' => 'Goederen', 'direction' => 'out', 'children' => [
                [ 'code' => '01-000001', 'name' => 'Aankopen drank en eetwaren (om te verkopen)' ],
                [ 'code' => '01-000002', 'name' => 'Uitzonderlijke aankopen drank en eetwaren (fundraising op bijv. eetfeest of grote fuif)' ],
                [ 'code' => '01-000003', 'name' => 'Aankopen t-shirts (om te verkopen)' ],
                [ 'code' => '01-000004', 'name' => 'Andere aankopen (om te verkopen)' ],
            ] ],
            [ 'code' => '02', 'name' => 'Bezoldigingen', 'direction' => 'out', 'children' => [
                [ 'code' => '02-000001', 'name' => 'Brutolonen' ],
                [ 'code' => '02-000002', 'name' => 'Eindejaarspremie' ],
                [ 'code' => '02-000003', 'name' => 'Vakantiegeld' ],
                [ 'code' => '02-000004', 'name' => 'Maaltijdcheques' ],
                [ 'code' => '02-000005', 'name' => 'Patronale bijdrage RSZ' ],
                [ 'code' => '02-000006', 'name' => 'Verzekering tegen arbeidsongevallen' ],
                [ 'code' => '02-000007', 'name' => 'Reiskosten van en naar het werk' ],
                [ 'code' => '02-000008', 'name' => 'Reiskosten werkverplaatsing' ],
                [ 'code' => '02-000009', 'name' => 'Diverse sociale lasten' ],
                [ 'code' => '02-000010', 'name' => 'Diverse sociale voordelen' ],
                [ 'code' => '02-000011', 'name' => 'Kosten sociaal secretariaat' ],
                [ 'code' => '02-000012', 'name' => 'Kosten medisch secretariaat' ],
                [ 'code' => '02-000014', 'name' => 'Andere honoraria' ],
            ] ],
            [ 'code' => '03', 'name' => 'Diensten en diverse goederen', 'direction' => 'out', 'children' => [
                [ 'code' => '03-000001', 'name' => 'Huur lokalen en andere verblijfskosten' ],
                [ 'code' => '03-000002', 'name' => 'Onderhoud en herstellingen' ],
                [ 'code' => '03-000003', 'name' => 'Water' ],
                [ 'code' => '03-000004', 'name' => 'Gas en andere brandstoffen' ],
                [ 'code' => '03-000005', 'name' => 'Elektriciteit' ],
                [ 'code' => '03-000006', 'name' => 'Internet' ],
                [ 'code' => '03-000007', 'name' => 'Telefoon en GSM' ],
                [ 'code' => '03-000008', 'name' => 'Postzegels' ],
                [ 'code' => '03-000009', 'name' => 'Drukwerk' ],
                [ 'code' => '03-000010', 'name' => 'Tijdschrift' ],
                [ 'code' => '03-000011', 'name' => 'Abonnementen op dagbladen en tijdschriften' ],
                [ 'code' => '03-000012', 'name' => 'Activiteitskosten (programmatiekosten)' ],
                [ 'code' => '03-000013', 'name' => 'Vrijwilligersvergoeding Toog' ],
                [ 'code' => '03-000014', 'name' => 'Drankbonnetjes instructeurs' ],
                [ 'code' => '03-000015', 'name' => 'Vergoeding onderhoud plein en materiaal' ],
                [ 'code' => '03-000016', 'name' => 'Vormingskosten' ],
                [ 'code' => '03-000017', 'name' => 'Verplaatsingskosten' ],
                [ 'code' => '03-000018', 'name' => 'Verzekering brand' ],
                [ 'code' => '03-000019', 'name' => 'Verzekering burgelijke aansprakelijkheid' ],
                [ 'code' => '03-000020', 'name' => 'Andere verzekering (niet voor personeel)' ],
                [ 'code' => '03-000021', 'name' => 'Bijdragen voor lidmaatschappen' ],
                [ 'code' => '03-000022', 'name' => 'Reclame en advertenties' ],
                [ 'code' => '03-000023', 'name' => 'Giften en geschenken' ],
                [ 'code' => '03-000024', 'name' => 'Representatiekosten' ],
                [ 'code' => '03-000025', 'name' => 'Aankopen onderhoudsproducten' ],
                [ 'code' => '03-000026', 'name' => 'Diensten en vergoedingen' ],
                [ 'code' => '03-000027', 'name' => 'Aankopen kantoorbenodigdheden' ],
                [ 'code' => '03-000028', 'name' => 'Aankopen verbruiks-, werkings- en didactisch materieel' ],
                [ 'code' => '03-000029', 'name' => 'Aankopen drank en etenswaren (voor eigen gebruik)' ],
                [ 'code' => '03-000030', 'name' => 'Aankopen muziek, film en gaming' ],
                [ 'code' => '03-000031', 'name' => 'Aankopen kledij (voor eigen gebruik)' ],
                [ 'code' => '03-000032', 'name' => 'Aankopen duurzame materialen' ],
                [ 'code' => '03-000033', 'name' => 'Aankopen bedrijfsmiddelen > € 1000' ],
                [ 'code' => '03-000034', 'name' => 'Andere aankopen (voor eigen gebruik)' ],
                [ 'code' => '03-000035', 'name' => 'Aankopen materiaal agility' ],
                [ 'code' => '03-000036', 'name' => 'Aankopen materiaal gehoorzaamheid' ],
                [ 'code' => '03-000037', 'name' => 'Vergoedingen keurders' ],
                [ 'code' => '03-000038', 'name' => 'Vergoedingen externe instructeurs (workshops ed)' ],
                [ 'code' => '03-000039', 'name' => 'Aankopen BHV algemeen' ],
            ] ],
            [ 'code' => '04', 'name' => 'Andere uitgaven', 'direction' => 'out', 'children' => [
                [ 'code' => '04-000001', 'name' => 'Onroerende voorheffing' ],
                [ 'code' => '04-000002', 'name' => 'Provinciale en gemeentelijke belastingen' ],
                [ 'code' => '04-000003', 'name' => 'Diverse taksen' ],
                [ 'code' => '04-000004', 'name' => 'Boeten' ],
                [ 'code' => '04-000005', 'name' => 'Publicatiekosten' ],
                [ 'code' => '04-000006', 'name' => 'Auteursrechten' ],
                [ 'code' => '04-000007', 'name' => 'Teruggave subsidies' ],
                [ 'code' => '04-000008', 'name' => 'Uitgegeven waarborgen' ],
                [ 'code' => '04-000009', 'name' => 'Kosten voor schulden op langer dan één jaar' ],
                [ 'code' => '04-000010', 'name' => 'Kosten voor schulden op ten hoogste één jaar' ],
                [ 'code' => '04-000011', 'name' => 'Diverse financiële kosten' ],
                [ 'code' => '04-000012', 'name' => 'Diefstal' ],
                [ 'code' => '04-000013', 'name' => 'Investeringen in eigen gebouw' ],
                [ 'code' => '04-000014', 'name' => 'Kassaverschil Toog' ],
                [ 'code' => '04-000015', 'name' => 'Terugbetaling inschrijvingsgelden activiteiten' ],
                [ 'code' => '04-000016', 'name' => 'Terugbetalingen lidgeld' ],
                [ 'code' => '04-000017', 'name' => 'Kassaverschil algemene kassa' ],
                [ 'code' => '04-000018', 'name' => 'Domeinnaam BHV site' ],
            ] ],
        ];

        $parent_ids = [];

        foreach ( $accounts as $group ) {
            $wpdb->insert( $table, [
                'code'      => $group['code'],
                'name'      => $group['name'],
                'direction' => $group['direction']
            ] );
            $parent_id = (int) $wpdb->insert_id;
            $parent_ids[ $group['code'] ] = $parent_id;

            $sort = 0;
            foreach ( $group['children'] as $child ) {
                $wpdb->insert( $table, [
                    'parent_id' => $parent_id,
                    'code'     => $child['code'],
                    'name'     => $child['name'],
                    'direction'=> $group['direction']
                ] );
            }
        }
    }

    public static function get_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';
        return $wpdb->get_results( "SELECT * FROM `$table` ORDER BY code" );
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}emeacc_chart_of_accounts` WHERE id = %d", $id )
        );
    }

    public static function get_by_code_dir( string $code, string $direction ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}emeacc_chart_of_accounts` WHERE code = %s AND direction = %s ", $code, $direction )
        );
    }

    public static function get_by_direction( string $direction, bool $active_only = true ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';
        if ( $active_only ) {
            return $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `$table` WHERE direction = %s AND is_active = 1 ORDER BY code", $direction )
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE direction = %s ORDER BY code", $direction )
        );
    }

    public static function get_dropdown( ?string $direction = null, bool $active_only = true ): array {
        $accounts = $direction ? self::get_by_direction( $direction, $active_only ) : self::get_all();
        $out = [];
        foreach ( $accounts as $a ) {
            $out[ (int) $a->id ] = $a;
        }
        return $out;
    }

    public static function get_tree(): array {
        $all = self::get_all();
        $by_parent = [];
        foreach ( $all as $a ) {
            $pid = $a->parent_id ?? 0;
            if ( ! isset( $by_parent[ $pid ] ) ) {
                $by_parent[ $pid ] = [];
            }
            $by_parent[ $pid ][] = $a;
        }
        $flatten = function ( int $parent_id, int $depth = 0 ) use ( &$flatten, $by_parent ): array {
            $result = [];
            if ( ! isset( $by_parent[ $parent_id ] ) ) {
                return $result;
            }
            foreach ( $by_parent[ $parent_id ] as $item ) {
                $item->depth       = $depth;
                $item->has_children = isset( $by_parent[ (int) $item->id ] );
                $result[] = $item;
                $result = array_merge( $result, $flatten( (int) $item->id, $depth + 1 ) );
            }
            return $result;
        };
        return $flatten( 0 );
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';

        if ( empty( $data['name'] ) ) {
            return new \WP_Error( 'missing_fields', __( 'Naam is verplicht.', 'eme-accounting' ) );
        }

        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $id > 0 ) {
            $row = [
                'name' => eme_sanitize_request( $data['name'] ),
            ];
            if ( array_key_exists( 'is_active', $data ) ) {
                $row['is_active'] = (int) (bool) $data['is_active'];
            }
            if ( ! empty( $data['description'] ) ) {
                $row['description'] = eme_sanitize_request( $data['description'] );
            }
            $wpdb->update( $table, $row, [ 'id' => $id ] );
            return $id;
        }

        if ( empty( $data['code'] ) ) {
            return new \WP_Error( 'missing_fields', __( 'Code is verplicht.', 'eme-accounting' ) );
        }

        $is_used = self::get_by_code_dir( $data['code'], $data['direction'] );
        if ($is_used) {
            return new \WP_Error( 'missing_fields', __( 'Code reeds in gebruik.', 'eme-accounting' ) );
        }
        $row = [
            'parent_id'   => ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null,
            'code'        => sanitize_text_field( $data['code'] ),
            'name'        => eme_sanitize_request( $data['name'] ),
            'direction'   => in_array( $data['direction'] ?? 'out', [ 'in', 'out' ], true ) ? $data['direction'] : 'out',
            'is_active'   => (int) (bool) ( $data['is_active'] ?? 1 ),
            'description' => eme_sanitize_request( $data['description'] ?? '' ),
        ];

        $wpdb->insert( $table, $row );
        return (int) $wpdb->insert_id;
    }

    public static function get_next_sequence( int $parent_id ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';

        $parent = self::get( $parent_id );
        if ( ! $parent ) {
            return '';
        }

        $max_seq = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) FROM `$table` WHERE parent_id = %d",
                $parent_id
            )
        );

        $next_seq = $max_seq + 1;
        return $parent->code . '-' . str_pad( (string) $next_seq, 6, '0', STR_PAD_LEFT );
    }

    public static function has_children( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_chart_of_accounts` WHERE parent_id = %d", $id )
        ) > 0;
    }

    public static function is_used_in_journal( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_journal` WHERE coa_id = %d", $id )
        ) > 0;
    }

    public static function delete( int $id ): bool|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';

        $account = self::get( $id );
        if ( ! $account ) {
            return new \WP_Error( 'not_found', __( 'Rekening niet gevonden.', 'eme-accounting' ) );
        }

        $has_children = self::has_children( $id );
        if ( ! $account->parent_id && $has_children ) {
            return new \WP_Error( 'has_children', __( 'Kan hoofdrekening niet verwijderen. Verwijder eerst de subrekeningen.', 'eme-accounting' ) );
        }

        if ( self::is_used_in_journal( $id ) ) {
            return new \WP_Error( 'in_use', __( 'Deze rekening wordt gebruikt in dagboek entries. Verwijder of wijzig eerst de gekoppelde entries.', 'eme-accounting' ) );
        }

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        return true;
    }

    public static function reorder( array $items ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_chart_of_accounts';
        $parent_id = 0;
        $parent_code = '';
        $idx = 0;
        foreach ( $items as $i => $item ) {
            if (empty($item['parent_id'])) {
                $parent_code = $item['code'];
                $parent_id = intval($item['id']);
                $idx = 0;
                continue;
            }
            if (empty($parent_code)) {
                continue;
            }
            $idx++;

            $row = [];
            $row['code'] = $parent_code . '-' . str_pad( (string) $idx, 6, '0', STR_PAD_LEFT );
            $row['parent_id'] = $parent_id;

            $wpdb->update( $table, $row, [ 'id' => (int) $item['id'] ] );
        }
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $result = self::save( $_POST );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'id' => $result ] );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $result = self::delete( (int) ( $_POST['id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }

    public static function ajax_reorder(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $items = json_decode( eme_sanitize_request($_POST['items'] ?? '[]'), true );
        self::reorder( $items );
        wp_send_json_success();
    }

    public static function ajax_next_seq(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        if ( $parent_id <= 0 ) {
            wp_send_json_error( __( 'Ongeldige hoofdrekening.', 'eme-accounting' ) );
        }
        $code = self::get_next_sequence( $parent_id );
        wp_send_json_success( [ 'code' => $code ] );
    }
}
