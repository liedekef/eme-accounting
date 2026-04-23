<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * Access management for EME Accounting.
 *
 * Underlying layer : two WP capabilities per page
 *   emeacc_view_<page>    – read-only access
 *   emeacc_manage_<page>  – full access (implies view)
 *
 * UX layer : named profiles per WP role.
 * Each role gets ONE profile dropdown in the settings UI.
 * Choosing "Aangepast" reveals the full per-page matrix for fine-tuning.
 *
 * Settings page : configurable like any other page, but manage_options
 * always grants access regardless of caps (guaranteed in require_view).
 */
class Capabilities {

    // ------------------------------------------------------------------ //
    //  Page & profile definitions                                         //
    // ------------------------------------------------------------------ //

    /**
     * All configurable pages, including settings.
     * Key = page slug  |  Value = Dutch label.
     */
    public const PAGES = [
        'dashboard'    => 'Dashboard',
        'journal'      => 'Dagboek',
        'import'       => 'Import / Reconciliatie',
        'stock'        => 'Stock',
        'depreciation' => 'Afschrijvingen',
        'budget'       => 'Begroting',
        'patrimony'    => 'Staat vaan het vermogen',
        'reports'      => 'Rapporten',
        'fiscalyears'  => 'Boekjaren',
        'coa'          => 'Rekeningstelsel',
        'accounts'     => 'Financiële Rekeningen',
        'settings'     => 'Instellingen',
    ];

    /**
     * Named profiles.
     * 'custom' is virtual: caps don't match any named profile.
     */
    public const PROFILES = [

        'none' => [
            'label'       => '— Geen toegang —',
            'description' => 'Geen enkel onderdeel van EME Boekhouding is zichtbaar.',
            'caps'        => [],
        ],

        'viewer' => [
            'label'       => '👁 Alleen lezen',
            'description' => 'Kan alle pagina\'s bekijken maar niets wijzigen.',
            'caps'        => [
                'dashboard'    => 'view',
                'journal'      => 'view',
                'import'       => 'view',
                'stock'        => 'view',
                'depreciation' => 'view',
                'patrimony'    => 'view',
                'budget'       => 'view',
                'reports'      => 'view',
                'fiscalyears'  => 'view',
                'coa'          => 'view',
                'accounts'     => 'view',
                'settings'     => 'none',
            ],
        ],

        'bookkeeper' => [
            'label'       => '📒 Boekhouder',
            'description' => 'Volledig beheer van dagboek, import, stock en afschrijvingen. Rapporten en begroting lezen. Geen toegang tot boekjaren, rekeningstelsel, rekeningen of instellingen.',
            'caps'        => [
                'dashboard'    => 'manage',
                'journal'      => 'manage',
                'import'       => 'manage',
                'stock'        => 'manage',
                'depreciation' => 'manage',
                'patrimony'    => 'manage',
                'budget'       => 'manage',
                'reports'      => 'manage',
                'fiscalyears'  => 'manage',
                'coa'          => 'view',
                'accounts'     => 'manage',
                'settings'     => 'none',
            ],
        ],

        'manager' => [
            'label'       => '🏢 Beheerder',
            'description' => 'Volledig beheer van alle pagina\'s inclusief instellingen.',
            'caps'        => [
                'dashboard'    => 'manage',
                'journal'      => 'manage',
                'import'       => 'manage',
                'stock'        => 'manage',
                'depreciation' => 'manage',
                'patrimony'    => 'manage',
                'budget'       => 'manage',
                'reports'      => 'manage',
                'fiscalyears'  => 'manage',
                'coa'          => 'manage',
                'accounts'     => 'manage',
                'settings'     => 'manage',
            ],
        ],

        'custom' => [
            'label'       => '⚙️ Aangepast',
            'description' => 'Handmatig per pagina ingesteld.',
            'caps'        => [],
        ],
    ];

    // ------------------------------------------------------------------ //
    //  Cap helpers                                                         //
    // ------------------------------------------------------------------ //

    public static function cap_view( string $page ): string {
        return 'emeacc_view_' . $page;
    }

    public static function cap_manage( string $page ): string {
        return 'emeacc_manage_' . $page;
    }

    /** Minimum cap for WP to show the menu item. */
    public static function menu_cap( string $page ): string {
        // make sure settings page is always accessible to admin
        if (($page == 'settings' ) && current_user_can( 'manage_options' )) {
            return 'manage_options';
        }
        return self::cap_view( $page, true );
    }

    /**
     * Can the current user VIEW a page?
     * manage_options always passes (guarantees settings access for admins).
     */
    public static function can_view( string $page ): bool {
        if ($page == 'settings' ) {
            return current_user_can( 'manage_options' )
                || current_user_can( self::cap_view( $page ) )
                || current_user_can( self::cap_manage( $page ) );
        }
        return current_user_can( self::cap_view( $page ) )
            || current_user_can( self::cap_manage( $page ) );
    }

    /**
     * Can the current user WRITE on a page?
     * manage_options always passes.
     */
    public static function can_manage( string $page ): bool {
        if ($page == 'settings' ) {
            return current_user_can( 'manage_options' )
                || current_user_can( self::cap_manage( $page ) );
        }
        return current_user_can( self::cap_manage( $page ) );
    }

    /**
     * Die with 403 if current user cannot view a page.
     */
    public static function require_view( string $page ): void {
        if ( ! self::can_view( $page ) ) {
            wp_die(
                esc_html__( 'U heeft geen toegang tot deze pagina.', 'eme-accounting' ),
                esc_html__( 'Toegang geweigerd', 'eme-accounting' ),
                [ 'response' => 403 ]
            );
        }
    }

    /**
     * AJAX helper: send JSON error and die if the current user
     * cannot manage the given page.
     * Use at the top of every ajax_* write handler.
     */
    public static function require_manage_ajax( string $page ): void {
        if ( ! self::can_manage( $page ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
    }

    /**
     * AJAX helper for read-only actions (list/fetch).
     * Sends JSON error and dies if the user cannot even view the page.
     */
    public static function require_view_ajax( string $page ): void {
        if ( ! self::can_view( $page ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
    }

    // ------------------------------------------------------------------ //
    //  Profile detection                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Detect which named profile matches a role's current caps.
     * Returns 'custom' when nothing matches exactly.
     */
    public static function detect_profile( string $role_slug ): string {
        $role_data = wp_roles()->roles[ $role_slug ] ?? null;
        if ( ! $role_data ) {
            return 'none';
        }
        $current = self::caps_to_page_levels( $role_data['capabilities'] ?? [] );

        foreach ( self::PROFILES as $profile_slug => $profile ) {
            if ( $profile_slug === 'custom' ) {
                continue;
            }
            if ( $current === self::profile_to_page_levels( $profile_slug ) ) {
                return $profile_slug;
            }
        }
        return 'custom';
    }

    private static function caps_to_page_levels( array $caps ): array {
        $levels = [];
        foreach ( array_keys( self::PAGES ) as $page ) {
            if ( ! empty( $caps[ self::cap_manage( $page ) ] ) ) {
                $levels[ $page ] = 'manage';
            } elseif ( ! empty( $caps[ self::cap_view( $page ) ] ) ) {
                $levels[ $page ] = 'view';
            } else {
                $levels[ $page ] = 'none';
            }
        }
        ksort( $levels );
        return $levels;
    }

    private static function profile_to_page_levels( string $profile_slug ): array {
        $template = self::PROFILES[ $profile_slug ]['caps'] ?? [];
        $levels   = [];
        foreach ( array_keys( self::PAGES ) as $page ) {
            $levels[ $page ] = $template[ $page ] ?? 'none';
        }
        ksort( $levels );
        return $levels;
    }

    // ------------------------------------------------------------------ //
    //  Activation / uninstall                                             //
    // ------------------------------------------------------------------ //

    /** On activation: grant 'manager' profile to administrator. */
    public static function add_caps_to_admin(): void {
        self::apply_profile_to_role( 'administrator', 'manager' );
    }

    /** On uninstall: strip all plugin caps from every role. */
    public static function remove_all_caps(): void {
        global $wp_roles;
        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new \WP_Roles();
        }
        foreach ( array_keys( $wp_roles->roles ) as $slug ) {
            $role = get_role( $slug );
            if ( ! $role ) {
                continue;
            }
            foreach ( array_keys( self::PAGES ) as $page ) {
                $cap_view   = self::cap_view( $page );
                $cap_manage = self::cap_manage( $page );
                if (str_starts_with($cap_view, 'emeacc_'))
                    $role->remove_cap( self::cap_view( $page ) );
                if (str_starts_with($cap_manage, 'emeacc_'))
                    $role->remove_cap( self::cap_manage( $page ) );
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Save helpers                                                        //
    // ------------------------------------------------------------------ //

    public static function apply_profile_to_role( string $role_slug, string $profile_slug ): void {
        $role = get_role( $role_slug );
        if ( ! $role || ! isset( self::PROFILES[ $profile_slug ] ) || $profile_slug === 'custom' ) {
            return;
        }
        foreach ( array_keys( self::PAGES ) as $page ) {
            self::apply_level( $role, $page, self::PROFILES[ $profile_slug ]['caps'][ $page ] ?? 'none' );
        }
    }

    public static function apply_page_levels_to_role( string $role_slug, array $levels ): void {
        $role = get_role( $role_slug );
        if ( ! $role ) {
            return;
        }
        foreach ( array_keys( self::PAGES ) as $page ) {
            self::apply_level( $role, $page, $levels[ $page ] ?? 'none' );
        }
    }

    private static function apply_level( \WP_Role $role, string $page, string $level ): void {
        $cap_view   = self::cap_view( $page );
        $cap_manage = self::cap_manage( $page );
        // only do emeacc_ caps
        if (!str_starts_with($cap_view, 'emeacc_') || !str_starts_with($cap_manage, 'emeacc_'))
            return;
        switch ( $level ) {
            case 'manage':
                $role->add_cap( $cap_view );
                $role->add_cap( $cap_manage );
                break;
            case 'view':
                $role->add_cap( $cap_view );
                $role->remove_cap( $cap_manage );
                break;
            default:
                $role->remove_cap( $cap_view );
                $role->remove_cap( $cap_manage );
        }
    }

    /** Handle POST from the access form in settings. */
    public static function handle_save(): void {
        if ( ! isset( $_POST['emeacc_access_nonce'] )
             || ! wp_verify_nonce( $_POST['emeacc_access_nonce'], 'emeacc_access_save' )
             || ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        foreach ( array_keys( wp_roles()->roles ) as $role_slug ) {
            $profile = sanitize_key( $_POST['emeacc_profile'][ $role_slug ] ?? 'none' );

            if ( $profile === 'custom' ) {
                $raw    = $_POST['emeacc_access'][ $role_slug ] ?? [];
                $levels = [];
                foreach ( array_keys( self::PAGES ) as $page ) {
                    $l = sanitize_key( $raw[ $page ] ?? 'none' );
                    $levels[ $page ] = in_array( $l, [ 'none', 'view', 'manage' ], true ) ? $l : 'none';
                }
                self::apply_page_levels_to_role( $role_slug, $levels );
            } elseif ( isset( self::PROFILES[ $profile ] ) ) {
                self::apply_profile_to_role( $role_slug, $profile );
            }
        }
    }

    /** Full matrix for the "custom" detail panel. */
    public static function get_current_matrix(): array {
        $matrix = [];
        foreach ( wp_roles()->roles as $role_slug => $role_data ) {
            $caps = $role_data['capabilities'] ?? [];
            foreach ( array_keys( self::PAGES ) as $page ) {
                if ( ! empty( $caps[ self::cap_manage( $page ) ] ) ) {
                    $matrix[ $role_slug ][ $page ] = 'manage';
                } elseif ( ! empty( $caps[ self::cap_view( $page ) ] ) ) {
                    $matrix[ $role_slug ][ $page ] = 'view';
                } else {
                    $matrix[ $role_slug ][ $page ] = 'none';
                }
            }
        }
        return $matrix;
    }
}
