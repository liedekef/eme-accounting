<?php
defined( 'ABSPATH' ) || exit;
$year  = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$fiscal_years = EMEAccounting\FiscalYear::get_all();
?>
<div class="wrap eme-acc-wrap">
<fieldset>
<h1><?php esc_html_e( 'Financiële Rapporten', 'eme-accounting' ); ?></h1>
<div class="eme-acc-form-box">
<div class="eme-acc-row" "align-items:flex-end">
    <div>
        <label><?php esc_html_e( 'Rapporttype', 'eme-accounting' ); ?></label>
        <select id="report_type">
            <optgroup label="Extern">
                <option value="income_expenditure"><?php esc_html_e( 'Staat van Ontvangsten en Uitgaven', 'eme-accounting' ); ?></option>
                <?php if ( (bool) ( \EMEVzw\Config::get()->vat_liable ?? false ) ): ?>
                <option value="vat_report"><?php esc_html_e( 'BTW-aangifte overzicht', 'eme-accounting' ); ?></option>
                <?php endif; ?>
            </optgroup>
            <optgroup label="Intern">
                <option value="patrimony"><?php esc_html_e( 'Staat van Vermogen (intern)', 'eme-accounting' ); ?></option>
                <option value="cashflow"><?php esc_html_e( 'Cashflow per maand', 'eme-accounting' ); ?></option>
                <option value="financial_diary"><?php esc_html_e( 'Financieel Dagboek', 'eme-accounting' ); ?></option>
                <option value="financial_year"><?php esc_html_e( 'Financieel Jaarverslag per rekening', 'eme-accounting' ); ?></option>
                <option value="events"><?php esc_html_e( 'Evenementen Overzicht', 'eme-accounting' ); ?></option>
            </optgroup>
        </select>
    </div>
    <div id="report_year_div">
        <label><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></label>
        <select id="report_year">
            <?php foreach ( $fiscal_years as $f ): ?>
            <option value="<?= (int) $f->year ?>" <?= selected( $f->year, $year, false ) ?>>
            <?php echo (int) $f->year;
                  if ( $f->status === 'closed' ) esc_html_e( ' (gesloten)', 'eme-accounting' );
            ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div id="report-date-wrap" style="display:none">
        <label><?php esc_html_e( 'Peildatum (Staat van Vermogen)', 'eme-accounting' ); ?></label>
        <input type="date" id="report_date" value="<?= esc_attr( current_time( 'Y-m-d' ) ) ?>">
    </div>
    <div id="report-cashflow-options" style="display:none">
        <label>
            <input type="checkbox" id="cashflow_exclude_transfers" value="1">
            <?php esc_html_e( 'Transfers uitsluiten', 'eme-accounting' ); ?>
        </label>
    </div>
    <div>
        <button id="btn-load-report" class="button button-primary"><?php esc_html_e( 'Rapport laden', 'eme-accounting' ); ?></button>
        <button id="btn-report-export-csv" class="button"><?php esc_html_e( 'Exporteren CSV', 'eme-accounting' ); ?></button>
        <button id="btn-report-export-pdf" class="button"><?php esc_html_e( 'PDF', 'eme-accounting' ); ?></button>
    </div>
</div>
</div>
<div id="report-output" style="margin-top:20px"></div>

</fieldset>
</div>
