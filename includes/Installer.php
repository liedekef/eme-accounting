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
 *  - {prefix}emeacc_journal_lines    Split bookings (one journal → many lines)
 *  - {prefix}emeacc_stock            Stock items
 *  - {prefix}emeacc_stock_movements  Stock in/out history
 *  - {prefix}emeacc_depreciation     Depreciable assets + rules
 *  - {prefix}emeacc_budget           Budget lines per account/category/year
 *  - {prefix}emeacc_import_log       Bank import batches
 *  - {prefix}emeacc_import_lines     Individual bank statement lines (for reconciliation)
 */
class Installer {

    const DB_VERSION_OPTION = 'emeacc_accounting_db_version';
    const DB_VERSION        = '1.0.0';

    public static function activate(): void {
        self::create_tables();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    public static function uninstall(): void {
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
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_accounts (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(150)    NOT NULL,
            iban            VARCHAR(34)     NOT NULL DEFAULT '',
            bic             VARCHAR(11)     NOT NULL DEFAULT '',
            type            ENUM('asset','liability','equity','income','expense') NOT NULL DEFAULT 'asset',
            subtype         VARCHAR(50)     NOT NULL DEFAULT '',
            currency        CHAR(3)         NOT NULL DEFAULT 'EUR',
            opening_balance DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            is_active       TINYINT(1)      NOT NULL DEFAULT 1,
            sort_order      SMALLINT        NOT NULL DEFAULT 0,
            description     TEXT,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type (type),
            KEY idx_active (is_active)
        ) $charset;" );

        // ── Journal entries ─────────────────────────────────────────────
        // Each entry = one financial transaction (may have multiple lines)
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_journal (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_date          DATE            NOT NULL,
            booking_date        DATE                         DEFAULT NULL,
            description         VARCHAR(255)    NOT NULL,
            category            VARCHAR(100)    NOT NULL DEFAULT '',
            amount              DECIMAL(14,2)   NOT NULL,
            direction           ENUM('in','out','transfer')  NOT NULL DEFAULT 'out',
            account_id          BIGINT UNSIGNED NOT NULL,
            contra_account_id   BIGINT UNSIGNED              DEFAULT NULL,
            bank_statement_ref  VARCHAR(100)    NOT NULL DEFAULT '',
            bank_statement_seq  VARCHAR(20)     NOT NULL DEFAULT '',
            payment_method      VARCHAR(50)     NOT NULL DEFAULT '',
            vat_code            VARCHAR(10)     NOT NULL DEFAULT '',
            vat_amount          DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            eme_payment_id      BIGINT UNSIGNED              DEFAULT NULL,
            eme_event_id        BIGINT UNSIGNED              DEFAULT NULL,
            attachment_url      VARCHAR(500)    NOT NULL DEFAULT '',
            is_reconciled       TINYINT(1)      NOT NULL DEFAULT 0,
            reconciled_at       DATETIME                     DEFAULT NULL,
            import_line_id      BIGINT UNSIGNED              DEFAULT NULL,
            fiscal_year         SMALLINT        NOT NULL,
            notes               TEXT,
            created_by          BIGINT UNSIGNED              DEFAULT NULL,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date (entry_date),
            KEY idx_account (account_id),
            KEY idx_fiscal_year (fiscal_year),
            KEY idx_category (category),
            KEY idx_reconciled (is_reconciled),
            KEY idx_eme_payment (eme_payment_id),
            KEY idx_bank_ref (bank_statement_ref)
        ) $charset;" );

        // ── Stock items ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_stock (
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
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_stock_movements (
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
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_depreciation (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                VARCHAR(150)    NOT NULL,
            description         TEXT,
            purchase_date       DATE            NOT NULL,
            purchase_price      DECIMAL(14,2)   NOT NULL,
            residual_value      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            useful_life_years   SMALLINT        NOT NULL DEFAULT 5,
            method              ENUM('linear','declining') NOT NULL DEFAULT 'linear',
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
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_budget (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fiscal_year     SMALLINT        NOT NULL,
            account_id      BIGINT UNSIGNED NOT NULL,
            category        VARCHAR(100)    NOT NULL DEFAULT '',
            direction       ENUM('in','out') NOT NULL DEFAULT 'out',
            budgeted_amount DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
            notes           VARCHAR(255)    NOT NULL DEFAULT '',
            approved_by     BIGINT UNSIGNED              DEFAULT NULL,
            approved_at     DATETIME                     DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_year_account_cat (fiscal_year, account_id, category, direction),
            KEY idx_year (fiscal_year)
        ) $charset;" );

        // ── Bank import batches ─────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_import_log (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id      BIGINT UNSIGNED NOT NULL,
            import_date     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            filename        VARCHAR(255)    NOT NULL DEFAULT '',
            format          VARCHAR(20)     NOT NULL DEFAULT 'csv',
            total_lines     INT             NOT NULL DEFAULT 0,
            matched_lines   INT             NOT NULL DEFAULT 0,
            unmatched_lines INT             NOT NULL DEFAULT 0,
            imported_by     BIGINT UNSIGNED              DEFAULT NULL,
            notes           VARCHAR(255)    NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_account (account_id)
        ) $charset;" );

        // ── Individual bank statement lines ─────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}emeacc_import_lines (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_log_id       BIGINT UNSIGNED NOT NULL,
            account_id          BIGINT UNSIGNED NOT NULL,
            transaction_date    DATE            NOT NULL,
            value_date          DATE                         DEFAULT NULL,
            description         VARCHAR(500)    NOT NULL DEFAULT '',
            counterparty_name   VARCHAR(150)    NOT NULL DEFAULT '',
            counterparty_iban   VARCHAR(34)     NOT NULL DEFAULT '',
            amount              DECIMAL(14,2)   NOT NULL,
            currency            CHAR(3)         NOT NULL DEFAULT 'EUR',
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
            'emeacc_import_lines',
            'emeacc_import_log',
            'emeacc_budget',
            'emeacc_depreciation',
            'emeacc_stock_movements',
            'emeacc_stock',
            'emeacc_journal',
            'emeacc_accounts',
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
        }
    }
}
