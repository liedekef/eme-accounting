<?php // eme-accounting/templates/close.php
defined( 'ABSPATH' ) || exit;

$years        = EMEAccounting\FiscalYear::get_available_years();
$closed_years = EMEAccounting\FiscalYear::get_closed_years();
$current_year = EMEAccounting\FiscalYear::get_current_year();

if ( ! in_array( $current_year, $years, true ) ) {
    $years[] = $current_year;
}
sort( $years );
$years = array_reverse( $years );
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Boekjaar Afsluiten', 'eme-accounting' ); ?></h1>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Boekjaar afsluiten', 'eme-accounting' ); ?></h2>
<p><?php esc_html_e( 'Door een boekjaar af te sluiten worden alle ontvangsten en uitgaven samengevat in een resultatenrekening. Het is daarna niet meer mogelijk om nog boekingen toe te voegen aan dat jaar.', 'eme-accounting' ); ?></p>

<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Selecteer boekjaar', 'eme-accounting' ); ?></label>
        <select id="close-year-select">
            <?php foreach ( $years as $y ): ?>
            <option value="<?= $y ?>" <?= in_array( $y, $closed_years, true ) ? 'disabled' : '' ?>>
                <?= $y ?>
                <?php if ( in_array( $y, $closed_years, true ) ): ?>
                    — <?php esc_html_e( 'reeds afgesloten', 'eme-accounting' ) ?>
                <?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<p><button id="btn-close-year" class="button button-primary"><?php esc_html_e( 'Boekjaar afsluiten', 'eme-accounting' ); ?></button></p>
<div id="close-result" style="margin-top:16px"></div>
</div>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Afgesloten boekjaren', 'eme-accounting' ); ?></h2>
<?php if ( empty( $closed_years ) ): ?>
<p><?php esc_html_e( 'Nog geen boekjaren afgesloten.', 'eme-accounting' ); ?></p>
<?php else: ?>
<table class="eme-acc-table">
<thead>
<tr>
    <th><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Resultaat', 'eme-accounting' ); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ( $closed_years as $y ): 
    $result = EMEAccounting\FiscalYear::get_year_result( $y );
    $is_surplus = $result >= 0;
?>
<tr>
    <td><?= $y ?></td>
    <td class="amount <?= $is_surplus ? 'in' : 'out' ?>">
        <?= $is_surplus ? '+' : '–' ?>
        <?= number_format( abs( $result ), 2, ',', '.' ) ?> €
        (<?= $is_surplus ? esc_html__( 'overschot', 'eme-accounting' ) : esc_html__( 'tekort', 'eme-accounting' ) ?>)
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>

<script>
document.getElementById('btn-close-year').addEventListener('click', function() {
    const year = document.getElementById('close-year-select').value;
    if (!year) return;
    
    if (!confirm('Boekjaar ' + year + ' afsluiten? Dit kan niet ongedaan gemaakt worden.')) return;
    
    this.disabled = true;
    this.textContent = 'Bezig…';
    
    fetch(emeAccounting.ajaxUrl, {
        method: 'POST',
        body: new FormData()
            .append('action', 'eme_accounting_close_year')
            .append('nonce', emeAccounting.nonce)
            .append('year', year)
    })
    .then(r => r.json())
    .then(r => {
        this.disabled = false;
        this.textContent = 'Boekjaar afsluiten';
        if (r.success) {
            document.getElementById('close-result').innerHTML = 
                '<div class="notice notice-success"><p>' + r.data.message + ' ' + (r.data.result || '') + '</p></div>';
            setTimeout(() => location.reload(), 1500);
        } else {
            document.getElementById('close-result').innerHTML = 
                '<div class="notice notice-error"><p>' + r.data + '</p></div>';
        }
    })
    .catch(() => {
        this.disabled = false;
        this.textContent = 'Boekjaar afsluiten';
    });
});
</script>