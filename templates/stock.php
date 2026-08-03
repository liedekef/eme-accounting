<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound // eme-accounting/templates/stock.php
defined( 'ABSPATH' ) || exit;
$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);
$config =  \EMEVzw\Config::get();
$currency  = $config->currency;

$items    = EMEAccounting\Stock::get_all();
$accounts = EMEAccounting\Accounts::get_dropdown();
?>
<div class="wrap eme-acc-wrap">
<fieldset <?php echo $form_disabled; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
<h1><?php esc_html_e( 'Stock', 'eme-accounting' ); ?></h1>
<div class="eme-acc-form-box">
<h2 id="eme-acc-stock-form-title"><?php esc_html_e( 'Stockartikel toevoegen', 'eme-accounting' ); ?></h2>
<form id="eme-acc-stock-form">
<input type="hidden" name="id" value="0">
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Naam', 'eme-accounting' ); ?> *</label><input type="text" name="name" required></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'SKU', 'eme-accounting' ); ?></label><input type="text" name="sku"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Eenheid', 'eme-accounting' ); ?></label><input type="text" name="unit" value="stuk"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Aankoopprijs', 'eme-accounting' ); ?></label><input type="number" name="purchase_price" step="0.01" value="0"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Verkoopprijs', 'eme-accounting' ); ?></label><input type="number" name="sale_price" step="0.01" value="0"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Min. stock', 'eme-accounting' ); ?></label><input type="number" name="min_qty" step="0.001" value="0"></div>
</div>
<p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button></p>
</form>
</div>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Naam', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'SKU', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Huidige voorraad', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Aankoopprijs', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Stockwaarde', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Beweging', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $items as $item ):
    $warning = (float) $item->current_qty <= (float) $item->min_qty && (float) $item->min_qty > 0;
?>
<tr data-stock-row="<?php echo (int) $item->id; ?>">
    <td>
        <?php echo esc_html( $item->name ); ?><?php echo $warning ? ' ⚠️' : ''; ?>
        <div class="row-actions">
            <a href="#" class="eme-acc-edit-stock" data-id="<?php echo (int) $item->id; ?>" data-name="<?php echo esc_attr( $item->name ); ?>" data-sku="<?php echo esc_attr( $item->sku ); ?>" data-unit="<?php echo esc_attr( $item->unit ); ?>" data-purchase-price="<?php echo (float) $item->purchase_price; ?>" data-sale-price="<?php echo (float) $item->sale_price; ?>" data-min-qty="<?php echo (float) $item->min_qty; ?>"><?php esc_html_e( 'Bewerken', 'eme-accounting' ); ?></a> |
            <a href="#" class="eme-acc-delete-stock" data-id="<?php echo (int) $item->id; ?>"><?php esc_html_e( 'Verwijderen', 'eme-accounting' ); ?></a>
        </div>
    </td>
    <td><?php echo esc_html( $item->sku ); ?></td>
    <td class="amount"><?php echo number_format( (float) $item->current_qty, 3, ',', '.' ); ?> <?php echo esc_html( $item->unit ); ?></td>
    <td class="amount"><?php echo esc_html( $formatter->formatCurrency($item->purchase_price, $currency) ); ?></td>
    <td class="amount"><?php echo esc_html( $formatter->formatCurrency( (float) $item->current_qty * (float) $item->purchase_price, $currency ) ); ?></td>
    <td>
        <form id="eme-acc-stock-movement-form" style="display:flex;gap:4px;align-items:center">
            <input type="hidden" name="stock_id" value="<?php echo (int) $item->id; ?>">
            <select name="type" >
                <option value="in"><?php esc_html_e( 'Aankomst', 'eme-accounting' ); ?></option>
                <option value="out"><?php esc_html_e( 'Afgang', 'eme-accounting' ); ?></option>
                <option value="adjustment"><?php esc_html_e( 'Correctie', 'eme-accounting' ); ?></option>
                <option value="write_off"><?php esc_html_e( 'Afschrijving', 'eme-accounting' ); ?></option>
            </select>
            <input type="number" name="qty" placeholder="Qty" step="0.001" min="0.001" style="width:70px;padding:2px 4px">
            <button type="submit" class="button button-small submit-btn"><?php esc_html_e( 'OK', 'eme-accounting' ); ?></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</fieldset>
</div>
