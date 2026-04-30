<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation and uninstall.
 * Creates / updates all custom database tables.
 *
 * Tables created:
 *  - {prefix}emeacc_accounts         Financial accounts (bank, cash, savings…)
 *  - {prefix}emeacc_journal          Journal entries (income / expense / transfer)
 *  - {prefix}emeacc_journal_lines    TODO: Split bookings (one journal → many lines)
 *  - {prefix}emeacc_stock            Stock items
 *  - {prefix}emeacc_stock_movements  Stock in/out history
 *  - {prefix}emeacc_depreciation     Depreciable assets + rules
 *  - {prefix}emeacc_budget           Budget lines per account/category/year
 *  - {prefix}emeacc_import_log       Bank import batches
 *  - {prefix}emeacc_import_lines     Individual bank statement lines (for reconciliation)
 */
class Installer {

    const DB_VERSION_OPTION = 'emeacc_accounting_db_version';
    const DB_VERSION        = '17';

    public static function activate(): void {
        self::create_tables();
        self::migrate();
        self::seed_data();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        // Grant all EME Accounting capabilities to the administrator role.
        \EMEAccounting\Capabilities::add_caps_to_admin();
        flush_rewrite_rules();
    }

    /**
     * Run migrations for version upgrades.
     */
    private static function migrate(): void {
        global $wpdb;
        $current_version = (int) get_option( self::DB_VERSION_OPTION, '0' );
        if ( $current_version && $current_version < 12 ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_journal ADD COLUMN IF NOT EXISTS transfer_id BIGINT UNSIGNED DEFAULT NULL AFTER contra_account_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_journal ADD INDEX IF NOT EXISTS idx_transfer (transfer_id)" );
        }
        if ( $current_version && $current_version < 13 ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_journal ADD COLUMN IF NOT EXISTS is_closing_entry TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_year" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_journal ADD INDEX IF NOT EXISTS idx_closing (is_closing_entry)" );
        }
        if ( (int) $current_version < 14 ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_journal ADD COLUMN IF NOT EXISTS updated_by BIGINT UNSIGNED DEFAULT NULL" );
        }
        if ( (int) $current_version < 15 ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_depreciation ADD COLUMN asset_type VARCHAR(150) NOT NULL DEFAULT 'machines'" );
        }
        if ( (int) $current_version < 16 ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_accounts ADD COLUMN opening_date DATE DEFAULT NULL" );
        }
        if ( (int) $current_version < 17 ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}emeacc_journal ADD COLUMN counterparty_vat_number VARCHAR(100) NOT NULL DEFAULT ''" );
        }
    }

    private static function seed_data(): void {
        ChartOfAccounts::seed();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    public static function uninstall(): void {
        // Always clean up capabilities, regardless of the delete_on_uninstall setting.
        \EMEAccounting\Capabilities::remove_all_caps();

        if ( get_option( 'emeacc_accounting_delete_on_uninstall' ) !== 'yes' ) {
            return;
        }
        self::drop_tables();
        delete_option( self::DB_VERSION_OPTION );
        delete_option( 'emeacc_accounting_delete_on_uninstall' );
        delete_option( 'emeacc_accounting_settings' );
    }

    /* ------------------------------------------------------------------
     * Table creation
     * ------------------------------------------------------------------ */
    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // ── Financial accounts ──────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_accounts (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(150)    NOT NULL,
            iban            VARCHAR(34)     NOT NULL DEFAULT '',
            bic             VARCHAR(11)     NOT NULL DEFAULT '',
            opening_balance DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            opening_date    DATE            DEFAULT NULL,
            is_active       TINYINT(1)      NOT NULL DEFAULT 1,
            sort_order      SMALLINT        NOT NULL DEFAULT 0,
            description     TEXT,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $charset;" );

        // ── Fiscal years ─────────────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_fiscal_years (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year            SMALLINT        NOT NULL UNIQUE,
            start_date      DATE            NOT NULL,
            end_date        DATE            NOT NULL,
            status          ENUM('open','closed') DEFAULT 'open',
            closed_at       DATETIME                     DEFAULT NULL,
            closed_by       BIGINT UNSIGNED              DEFAULT NULL,
            closed_reason   VARCHAR(255)                 DEFAULT NULL,
            reopened_at     DATETIME                     DEFAULT NULL,
            reopened_by     BIGINT UNSIGNED              DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_year (year)
        ) $charset;" );

        // ── Chart of accounts (rekeningstelsel) ───────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_chart_of_accounts (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id           BIGINT UNSIGNED              DEFAULT NULL,
            code                VARCHAR(20)     NOT NULL,
            name                VARCHAR(200)    NOT NULL,
            direction           ENUM('in','out') NOT NULL DEFAULT 'out',
            is_active           TINYINT(1)      NOT NULL DEFAULT 1,
            description         TEXT,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_parent (parent_id),
            KEY idx_direction (direction),
            KEY idx_code (code)
        ) $charset;" );

        // ── Journal entries ─────────────────────────────────────────────
        // Each entry = one financial transaction (may have multiple lines)
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_journal (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_date          DATE            NOT NULL,
            booking_date        DATE                         DEFAULT NULL,
            description         VARCHAR(255)    NOT NULL,
            category            VARCHAR(100)    NOT NULL DEFAULT '',
            amount              DECIMAL(14,2)   NOT NULL,
            direction           ENUM('in','out','transfer')  NOT NULL DEFAULT 'out',
            account_id          BIGINT UNSIGNED NOT NULL,
            contra_account_id   BIGINT UNSIGNED              DEFAULT NULL,
            transfer_id         BIGINT UNSIGNED              DEFAULT NULL,
            coa_id             BIGINT UNSIGNED              DEFAULT NULL,
            bank_statement_ref  VARCHAR(100)    NOT NULL DEFAULT '',
            bank_statement_seq  VARCHAR(20)     NOT NULL DEFAULT '',
            payment_method      VARCHAR(50)     NOT NULL DEFAULT '',
            vat_code            VARCHAR(10)     NOT NULL DEFAULT '',
            vat_amount          DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            counterparty_vat_number VARCHAR(100)    NOT NULL DEFAULT '',
            eme_payment_id      BIGINT UNSIGNED              DEFAULT NULL,
            eme_event_id        BIGINT UNSIGNED              DEFAULT NULL,
            attachment_url      VARCHAR(500)    NOT NULL DEFAULT '',
            is_reconciled       TINYINT(1)      NOT NULL DEFAULT 0,
            reconciled_at       DATETIME                     DEFAULT NULL,
            import_line_id      BIGINT UNSIGNED              DEFAULT NULL,
            fiscal_year         SMALLINT        NOT NULL,
            is_closing_entry    TINYINT(1)      NOT NULL DEFAULT 0,
            notes               TEXT,
            created_by          BIGINT UNSIGNED              DEFAULT NULL,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by          BIGINT UNSIGNED              DEFAULT NULL,
            updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date (entry_date),
            KEY idx_account (account_id),
            KEY idx_coa (coa_id),
            KEY idx_fiscal_year (fiscal_year),
            KEY idx_closing (is_closing_entry),
            KEY idx_category (category),
            KEY idx_reconciled (is_reconciled),
            KEY idx_eme_payment (eme_payment_id),
            KEY idx_bank_ref (bank_statement_ref),
            KEY idx_transfer (transfer_id)
        ) $charset;" );

        // ── Stock items ─────────────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_stock (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(150)    NOT NULL,
            sku             VARCHAR(50)     NOT NULL DEFAULT '',
            description     TEXT,
            unit            VARCHAR(20)     NOT NULL DEFAULT 'stuk',
            purchase_price  DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            sale_price      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            current_qty     DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
            min_qty         DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
            account_id      BIGINT UNSIGNED              DEFAULT NULL,
            is_active       TINYINT(1)      NOT NULL DEFAULT 1,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku (sku),
            KEY idx_active (is_active)
        ) $charset;" );

        // ── Stock movements ─────────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_stock_movements (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stock_id        BIGINT UNSIGNED NOT NULL,
            movement_date   DATE            NOT NULL,
            type            ENUM('in','out','adjustment','write_off') NOT NULL,
            qty             DECIMAL(12,3)   NOT NULL,
            unit_price      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            total_value     DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            journal_id      BIGINT UNSIGNED              DEFAULT NULL,
            notes           VARCHAR(255)    NOT NULL DEFAULT '',
            created_by      BIGINT UNSIGNED              DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_stock (stock_id),
            KEY idx_date (movement_date)
        ) $charset;" );

        // ── Depreciable assets ──────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_depreciation (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                VARCHAR(150)    NOT NULL,
            description         TEXT,
            purchase_date       DATE            NOT NULL,
            purchase_price      DECIMAL(14,2)   NOT NULL,
            residual_value      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            useful_life_years   SMALLINT        NOT NULL DEFAULT 5,
            method              VARCHAR(150)    NOT NULL DEFAULT 'linear',
            asset_type          VARCHAR(150)    NOT NULL DEFAULT 'machines',
            account_id          BIGINT UNSIGNED              DEFAULT NULL,
            depreciation_account_id BIGINT UNSIGNED          DEFAULT NULL,
            current_book_value  DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            last_depreciation   DATE                         DEFAULT NULL,
            is_active           TINYINT(1)      NOT NULL DEFAULT 1,
            notes               TEXT,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $charset;" );

        // ── Budget lines ────────────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_budget (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fiscal_year     SMALLINT        NOT NULL,
            coa_id          BIGINT UNSIGNED NOT NULL,
            budgeted_amount DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_year_coa (fiscal_year, coa_id),
            KEY idx_year (fiscal_year)
        ) $charset;" );

        // ── Staat van het Vermogen (Bijlage C – per boekjaar) ───────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_patrimony (
            id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            fiscal_year                 SMALLINT        NOT NULL,

            -- Sectie 1: Waarderingsregels (art. 6)
            depr_machines_new_years     TINYINT         NOT NULL DEFAULT 5,
            depr_machines_used_years    TINYINT         NOT NULL DEFAULT 2,
            depr_computers_new_years    TINYINT         NOT NULL DEFAULT 5,
            depr_computers_used_years   TINYINT         NOT NULL DEFAULT 2,
            depr_furniture_new_years    TINYINT         NOT NULL DEFAULT 5,
            depr_furniture_used_years   TINYINT         NOT NULL DEFAULT 2,
            depr_other_new_years        TINYINT         NOT NULL DEFAULT 5,
            depr_other_used_years       TINYINT         NOT NULL DEFAULT 2,
            depr_method                 VARCHAR(12)     NOT NULL DEFAULT 'linear',
            cash_valuation_note         TEXT,

            -- Sectie 2: Aanpassing waarderingsregels (art. 7)
            valuation_changes           TEXT,

            -- Sectie 3: Bijkomende inlichtingen (art. 14)
            additional_info             TEXT,

            -- Sectie 4: Bezittingen
            asset_real_estate           DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_machines              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_movables              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_stocks                DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_receivables           DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_investments           DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_cash                  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            asset_other                 DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

            -- Sectie 4: Schulden
            liab_financial              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            liab_suppliers              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            liab_members                DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            liab_fiscal_social          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            liab_other                  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

            -- Sectie 4: Rechten
            right_subsidies             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            right_donations             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            right_other                 DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

            -- Sectie 4: Verplichtingen
            oblig_mortgages             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            oblig_guarantees            DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            oblig_other                 DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

            -- Sectie 5
            non_numeric_obligations     TEXT,

            status                      ENUM('draft','final') NOT NULL DEFAULT 'draft',
            notes                       TEXT,
            created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_year (fiscal_year)
        ) $charset;" );
        // ── Bank import batches ─────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_import_log (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id      BIGINT UNSIGNED NOT NULL,
            import_date     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            filename        VARCHAR(255)    NOT NULL DEFAULT '',
            format          VARCHAR(20)     NOT NULL DEFAULT 'csv',
            imported_by     BIGINT UNSIGNED              DEFAULT NULL,
            notes           VARCHAR(255)    NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_account (account_id)
        ) $charset;" );

        // ── Individual bank statement lines ─────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}emeacc_import_lines (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_log_id       BIGINT UNSIGNED NOT NULL,
            account_id          BIGINT UNSIGNED NOT NULL,
            transaction_date    DATE            NOT NULL,
            value_date          DATE                         DEFAULT NULL,
            description         VARCHAR(500)    NOT NULL DEFAULT '',
            counterparty_name   VARCHAR(150)    NOT NULL DEFAULT '',
            counterparty_iban   VARCHAR(34)     NOT NULL DEFAULT '',
            amount              DECIMAL(14,2)   NOT NULL,
            bank_ref            VARCHAR(100)    NOT NULL DEFAULT '',
            structured_ref      VARCHAR(21)     NOT NULL DEFAULT '',
            status              ENUM('pending','matched','ignored','manual') NOT NULL DEFAULT 'pending',
            journal_id          BIGINT UNSIGNED              DEFAULT NULL,
            raw_data            TEXT,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_import (import_log_id),
            KEY idx_account (account_id),
            KEY idx_status (status),
            KEY idx_date (transaction_date)
        ) $charset;" );
    }

    /* ------------------------------------------------------------------
     * Drop all tables (only on explicit uninstall)
     * ------------------------------------------------------------------ */
    private static function drop_tables(): void {
        global $wpdb;
        $tables = [
            'emeacc_fiscal_years',
            'emeacc_import_lines',
            'emeacc_import_log',
            'emeacc_budget',
            'emeacc_depreciation',
            'emeacc_stock_movements',
            'emeacc_stock',
            'emeacc_journal',
            'emeacc_chart_of_accounts',
            'emeacc_accounts',
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
        }
    }
}
