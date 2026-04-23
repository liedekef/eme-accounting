/* eme-accounting/assets/js/admin.js */
/* global emeAccounting, Sortable, FTable */

/* ═══════════════════════════════════════════════════════════════════
 * UTILITIES
 * ═══════════════════════════════════════════════════════════════════ */
function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('nl-BE', {
        style: 'currency', currency: emeAccounting.i18n.currency, minimumFractionDigits: 2,
    }).format(amount);
}

function flashButton(btn, text) {
    const orig = btn.textContent;
    btn.textContent = text;
    btn.disabled = true;
    setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 500);
}

function formToObj(form) {
    const obj = {};
    new FormData(form).forEach((v, k) => { obj[k] = v; });
    return obj;
}

function fadeRemove(el) {
    if (!el) return;
    el.style.transition = 'opacity .3s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 320);
}

function toFormData(obj, fd = new FormData(), prefix = '') {
    for (const key in obj) {
        if (!Object.prototype.hasOwnProperty.call(obj, key)) continue;
        const val     = obj[key];
        const formKey = prefix ? `${prefix}[${key}]` : key;
        if (val !== null && typeof val === 'object' && !(val instanceof File)) {
            toFormData(val, fd, formKey);
        } else {
            fd.append(formKey, val ?? '');
        }
    }
    return fd;
}

function ajax(action, data, onSuccess, onError) {
    const nonce = emeAccounting.nonce;
    fetch(emeAccounting.ajaxUrl, { method: 'POST', body: toFormData({ action, nonce, ...data }) })
        .then(r => r.json())
        .then(r => r.success ? onSuccess?.(r.data) : (onError || alert)(r.data || 'Er is een fout opgetreden.'))
        .catch(() => (onError || alert)('Verbindingsfout met server.'));
}

(function () {
    'use strict';

    const { nonce, ajaxUrl, i18n } = emeAccounting;

    function parseBE(val) {
        return parseFloat(val.replace(/\./g, '').replace(',', '.')) || 0;
    }
    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE SUBMIT DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    const submitHandlers = {
        'eme-acc-account-form':        handleAccountSave,
        'eme-acc-coa-form':            handleCoaSave,
        'eme-acc-import-form':         handleImportUpload,
        'eme-acc-stock-form':          handleStockSave,
        'eme-acc-stock-movement-form': handleStockMovement,
        'eme-acc-asset-form':          handleAssetSave,
        'eme-acc-budget-form':         handleBudgetSave,
    };

    document.addEventListener('submit', function (e) {
        // If e.target is an input, e.target.form gives the parent form
        const form = e.target.form || e.target.closest('form');
        if (!form) return;
        const formId = form.getAttribute('id');
        const handler = submitHandlers[formId];
        if (!handler) return;
        e.preventDefault();
        handler(e.target);
    });

    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE CLICK DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    const clickHandlers = {
        'eme-acc-edit-account':   handleAccountEdit,
        'eme-acc-delete-account': handleAccountDelete,
        'eme-acc-account-cancel': handleAccountCancel,
        'eme-acc-edit-coa':       handleCoaEdit,
        'eme-acc-delete-coa':     handleCoaDelete,
        'eme-acc-coa-cancel':     handleCoaCancel,
        'btn-auto-reconcile':     handleAutoReconcile,
        'btn-reconcile-manual':   handleManualReconcile,
        'btn-ignore-line':        handleIgnoreLine,
        'btn-create-from-line':   handleCreateFromLine,
        'btn-delete-import-log': handleDeleteImportLog,
        'btn-load-report':        handleLoadReport,
        'btn-export-csv':         handleExportCsv,
        'btn-export-pdf':         handleExportPDF,
        'eme-acc-edit-row':       handleToggleEditRow,
        // Stock
        'eme-acc-edit-stock':    handleStockEdit,
        'eme-acc-delete-stock': handleStockDelete,
        // Depreciation / Assets
        'eme-acc-edit-asset':   handleAssetEdit,
        'eme-acc-delete-asset': handleAssetDelete,
        // Patrimony
        'eme-acc-delete-patrimony': handleDeletePatrimony,
    };

    document.addEventListener('click', function (e) {
        for (const [cls, handler] of Object.entries(clickHandlers)) {
            const btn = e.target.closest(`#${cls}, .${cls}`);
            if (btn) { handler(btn, e); return; }
        }
    });

    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE CHANGE DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    document.addEventListener('change', function (e) {
        if (e.target.id === 'entry_date') {
            const fy = document.querySelector('#fiscal_year');
            if (fy && !fy.value) fy.value = e.target.value.substring(0, 4);
        }
        if (e.target.id === 'eme-acc-year-select') {
            e.target.closest('form')?.submit();
        }
        if (e.target.id === 'eme-acc-copy-year') {
            handleCopyActualFromYear(e.target.value);
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('#purchase_price, #useful_life_years, #residual_value, #method')) {
            updateDepreciationPreview();
        }
    });

    /* ═══════════════════════════════════════════════════════════════════
     * ACCOUNTS PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleAccountSave(form) {
        const btn = form.querySelector('.submit-btn');
        const originalText = btn.textContent;
        btn.textContent = i18n.saving;
        btn.disabled = true;
        ajax('eme_accounting_save_account', formToObj(form),
            () => {
                flashButton(btn, i18n.saved);
                location.reload();
            },
            (errorMsg) => {  // Error callback met message
                alert(errorMsg);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        );
    }

    function handleAccountEdit(btn) {
        const d    = btn.dataset;
        const form = document.getElementById('eme-acc-account-form');
        form.querySelector('[name="id"]').value              = d.id;
        form.querySelector('[name="name"]').value            = d.name;
        form.querySelector('[name="iban"]').value            = d.iban;
        form.querySelector('[name="bic"]').value             = d.bic;
        form.querySelector('[name="opening_balance"]').value = d.openingBalance;
        form.querySelector('[name="opening_date"]').value    = d.openingDate || '';
        form.querySelector('[name="sort_order"]').value      = d.sortOrder;
        form.querySelector('[name="is_active"]').value       = d.isActive;
        form.querySelector('[name="description"]').value     = d.description;
        document.getElementById('eme-acc-account-form-title').textContent = 'Rekening bewerken';
        document.getElementById('eme-acc-account-cancel').style.display = '';
        form.scrollIntoView({ behavior: 'smooth' });
    }

    function handleAccountDelete(btn) {
        if (!confirm(i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_account', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`[data-account-row="${btn.dataset.id}"]`))
        );
    }

    function handleAccountCancel() {
        const form = document.getElementById('eme-acc-account-form');
        form.reset();
        form.querySelector('[name="id"]').value = '0';
        form.querySelector('[name="opening_date"]').value = '';
        document.getElementById('eme-acc-account-form-title').textContent = 'Rekening toevoegen';
        document.getElementById('eme-acc-account-cancel').style.display = 'none';
    }

    /* ═══════════════════════════════════════════════════════════════════
     * CHART OF ACCOUNTS PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleCoaSave(form) {
        const btn  = form.querySelector('.submit-btn');
        const originalText = btn.textContent;
        btn.textContent = i18n.saving;
        btn.disabled = true;
        const data = formToObj(form);
        if (!data.parent_id) data.parent_id = '';
        ajax('eme_accounting_save_coa', data,
            () => {
                flashButton(btn, i18n.saved);
                location.reload();
            },
            (errorMsg) => {  // Error callback met message
                alert(errorMsg);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        );
    }

    function handleCoaEdit(btn) {
        const li = document.querySelector(`li[data-id="${btn.dataset.id}"]`);
        if (!li) return;
        const d    = li.dataset;
        const form = document.getElementById('eme-acc-coa-form');
        form.querySelector('[name="id"]').value          = d.id;
        form.querySelector('[name="code"]').value        = d.code;
        form.querySelector('[name="code"]').disabled = true;
        form.querySelector('[name="direction"]').value        = d.direction;
        form.querySelector('[name="direction"]').disabled = true;
        form.querySelector('[name="name"]').value        = d.name;
        form.querySelector('[name="parent_id"]').disabled = true;
        form.querySelector('[name="parent_id"]').value = d.parentId;
        form.querySelector('[name="is_active"]').value = d.isActive || '1';
        form.querySelector('[name="description"]').value = d.description || '';
        document.getElementById('eme-acc-coa-form-title').textContent = 'Rekening bewerken';
        document.getElementById('eme-acc-coa-cancel').style.display = '';
        form.scrollIntoView({ behavior: 'smooth' });
    }

    function handleCoaDelete(btn) {
        if (!confirm(i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_coa', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`li[data-id="${btn.dataset.id}"]`))
        );
    }

    function handleCoaCancel() {
        const form = document.getElementById('eme-acc-coa-form');
        form.reset();
        form.querySelector('[name="id"]').value = '0';
        form.querySelector('[name="code"]').readOnly = false;
        form.querySelector('[name="code"]').disabled = false;
        form.querySelector('[name="parent_id"]').disabled = false;
        form.querySelector('[name="direction"]').disabled = false;
        form.querySelector('[name="is_active"]').value = '1';
        document.getElementById('eme-acc-coa-form-title').textContent = 'Rekening toevoegen';
        document.getElementById('eme-acc-coa-cancel').style.display = 'none';
    }

    /* ── COA auto-generate code on parent change ─────────────────── */
    const coaParentSelect = document.querySelector('.eme-acc-parent-select');
    const coaCodeInput = document.querySelector('.eme-acc-code-input');
    if (coaParentSelect && coaCodeInput) {
        coaParentSelect.addEventListener('change', function() {
            const parentId = this.value;
            const form = document.getElementById('eme-acc-coa-form');
            const idField = form.querySelector('[name="id"]');
            if (idField && idField.value !== '0') return;

            if (!parentId) {
                coaCodeInput.value = '';
                coaCodeInput.placeholder = '01-000001';
                return;
            }

            const selectedOption = this.options[this.selectedIndex];
            const direction = selectedOption.dataset.direction;
            const parentCode = selectedOption.textContent.split(' - ')[0];

            if (parentId) {
                ajax('eme_accounting_coa_next_seq', { parent_id: parentId }, (data) => {
                    coaCodeInput.value = data.code;
                });
            }
        });
    }

    /* ── COA drag-and-drop reorder ──────────────────────────────── */
    document.querySelectorAll('.eme-acc-coa-tree').forEach(function (list) {
        if (!window.Sortable) return;
        new Sortable(list, {
            handle: '.handle',
            onEnd(evt) {
                if (evt.oldIndex === evt.newIndex) return;
                const items = Array.from(evt.to.querySelectorAll('li')).map((li, idx) => ({
                    id:         li.dataset.id,
                    parent_id:  parseInt(li.dataset.parentId) || null,
                    code:       li.querySelector('.code')?.textContent ?? '',
                }));
                const fd = new FormData();
                fd.append('action', 'eme_accounting_reorder_coa');
                fd.append('nonce', nonce);
                fd.append('items', JSON.stringify(items));
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(r => { if (!r.success) alert('Herordenen mislukt.'); else location.reload();});
            },
        });
    });

    /* ═══════════════════════════════════════════════════════════════════
     * IMPORT / RECONCILIATION PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleImportUpload(form) {
        const btn = form.querySelector('.submit-btn');
        const fd  = new FormData(form);
        fd.append('action', 'eme_accounting_import_upload');
        fd.append('nonce', nonce);
        btn.textContent = 'Importeren…';
        btn.disabled = true;
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                btn.textContent = 'Importeren';
                btn.disabled = false;
                if (!r.success) { alert(r.data || 'Importfout'); return; }
                const d         = r.data;
                const resultDiv = document.querySelector('#import-result');
                if (resultDiv) {
                    resultDiv.innerHTML = `<div class="notice notice-success"><p>
                        Import klaar: <strong>${d.total}</strong> regels —
                        <strong>${d.matched}</strong> gematcht —
                        <strong>${d.pending}</strong> nog te verifiëren.
                    </p></div>`;
                    resultDiv.style.display = 'block';
                }
                if (d.pending > 0) loadPendingLines(d.log_id);
            })
            .catch(() => alert('Verbindingsfout met server.'));
    }

    function loadPendingLines(logId) {
        const section = document.querySelector('#reconcile-section');
        if (!section) return;
        section.innerHTML = '<p>Laden...</p>';
        fetch(`${ajaxUrl}?action=eme_accounting_reconcile_view&nonce=${nonce}&log_id=${logId}`)
            .then(r => r.text())
            .then(html => { section.innerHTML = html; });
    }

    function handleAutoReconcile(btn) {
        const originalText = btn.textContent;
        btn.textContent = 'Bezig…';
        btn.disabled = true;
        ajax('eme_accounting_auto_reconcile',
            { log_id: btn.dataset.logId, account_id: btn.dataset.accountId },
            (data) => {
                btn.textContent = `Auto-reconciliatie (${data.matched} gematcht)`;
                btn.disabled = false;
                location.reload();
            },
            (errorMsg) => {  // Error callback met message
                alert(errorMsg);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        );
    }

    function handleManualReconcile(btn) {
        const row       = btn.closest('tr');
        const journalId = row?.querySelector('.journal-id-input')?.value;
        if (!journalId) { alert('Voer eerst een dagboek-ID in.'); return; }
        ajax('eme_accounting_reconcile_line',
            { line_id: btn.dataset.lineId, journal_id: journalId },
            () => {
                const cell = row.querySelector('td:last-child');
                if (cell) cell.innerHTML = '<span class="recon-matched">✓ Gematcht</span>';
            }
        );
    }

    function handleIgnoreLine(btn) {
        const row = btn.closest('tr');
        ajax('eme_accounting_ignore_line', { line_id: btn.dataset.lineId }, () => {
            row?.classList.add('recon-ignored');
            const cell = row?.querySelector('td:last-child');
            if (cell) cell.innerHTML = '<span class="recon-ignored">Genegeerd</span>';
        });
    }

    function handleCreateFromLine(btn) {
        const row      = btn.closest('tr');
        const category = row?.querySelector('.category-input')?.value ?? '';
        ajax('eme_accounting_create_entry_from_line',
            { line_id: btn.dataset.lineId, category },
            (data) => { alert(`Boeking aangemaakt (ID: ${data.journal_id})`); location.reload(); }
        );
    }

    function handleDeleteImportLog(btn) {
        if (!confirm(i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_import_log', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`[data-import-log="${btn.dataset.id}"]`))
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * STOCK PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleStockSave(form) {
        ajax('eme_accounting_save_stock', formToObj(form), () => location.reload());
    }

    function handleStockMovement(form) {
        ajax('eme_accounting_stock_movement', formToObj(form), () => {
            const btn = form.querySelector('.submit-btn');
            if (btn) flashButton(btn, i18n.saved);
            setTimeout(() => location.reload(), 900);
        });
    }

    function handleStockEdit(btn) {
        const d    = btn.dataset;
        const form = document.getElementById('eme-acc-stock-form');
        form.querySelector('[name="id"]').value        = d.id;
        form.querySelector('[name="name"]').value       = d.name;
        form.querySelector('[name="sku"]').value        = d.sku;
        form.querySelector('[name="unit"]').value        = d.unit;
        form.querySelector('[name="purchase_price"]').value = d.purchasePrice;
        form.querySelector('[name="sale_price"]').value  = d.salePrice;
        form.querySelector('[name="min_qty"]').value    = d.minQty;
        document.getElementById('eme-acc-stock-form-title').textContent = 'Stock bewerken';
        form.scrollIntoView({ behavior: 'smooth' });
    }

    function handleStockDelete(btn) {
        if (!confirm(i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_stock', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`[data-stock-row="${btn.dataset.id}"]`))
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * DEPRECIATION PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleAssetSave(form) {
        ajax('eme_accounting_save_asset', formToObj(form), () => location.reload());
    }

    function updateDepreciationPreview() {
        const price    = parseFloat(document.querySelector('#purchase_price')?.value)    || 0;
        const life     = parseInt(document.querySelector('#useful_life_years')?.value)   || 1;
        const residual = parseFloat(document.querySelector('#residual_value')?.value)    || 0;
        const method   = document.querySelector('#method')?.value;
        const preview  = document.querySelector('#depreciation-preview');
        if (!preview) return;
        const annual = method === 'declining' ? price * (2 / life) : (price - residual) / life;
        preview.textContent = `Jaarlijkse afschrijving: ${formatCurrency(annual)}`;
    }

    function handleAssetEdit(btn) {
        const d    = btn.dataset;
        const form = document.getElementById('eme-acc-asset-form');
        form.querySelector('[name="id"]').value              = d.id;
        form.querySelector('[name="name"]').value            = d.name;
        form.querySelector('[name="purchase_date"]').value    = d.purchaseDate;
        form.querySelector('[name="purchase_price"]').value   = d.purchasePrice;
        form.querySelector('[name="residual_value"]').value = d.residualValue;
        form.querySelector('#current_book_value').value = d.currentBookValue;
        form.querySelector('[name="useful_life_years"]').value = d.usefulLifeYears;
        form.querySelector('[name="method"]').value           = d.method;
        form.querySelector('[name="asset_type"]').value        = d.assetType || 'machines';
        form.querySelector('[name="account_id"]').value     = d.accountId;
        form.querySelector('[name="depreciation_account_id"]').value = d.depreciationAccountId;
        form.querySelector('[name="description"]').value = d.description || '';
        document.getElementById('eme-acc-asset-form-title').textContent = 'Activum bewerken';
        form.scrollIntoView({ behavior: 'smooth' });
    }

    function handleAssetDelete(btn) {
        if (!confirm(i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_asset', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`[data-asset-row="${btn.dataset.id}"]`))
        );
    }

    // Delete patrimony
    function handleDeletePatrimony(btn) {
        if (!confirm(i18n.confirm_delete_patrimony)) return;
        ajax('eme_accounting_delete_patrimony', { id: btn.dataset.id, nonce: nonce }, function() {
            setTimeout(() => location.reload(), 350);
        });
    }


    /* ═══════════════════════════════════════════════════════════════════
     * BUDGET PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleBudgetSave(form) {
        const data = {
            fiscal_year: form.querySelector('[name="fiscal_year"]')?.value,
            lines: []
        };
        const rows = form.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const coaId = row.querySelector('[name$="[coa_id]"]');
            const amount = row.querySelector('[name$="[budgeted_amount]"]');
            if (coaId && amount && coaId.value) {
                data.lines.push({
                    coa_id: coaId.value,
                    budgeted_amount: amount.value
                });
            }
        });
        ajax('eme_accounting_save_budget_line', data, () => location.reload());
    }

    function handleCopyActualFromYear(year) {
        if (!year) return;
        ajax('eme_accounting_get_budget_year', { year }, (data) => {
            const form = document.getElementById('eme-acc-budget-form');
            if (!form) return;
            Object.keys(data).forEach(coaId => {
                const input = document.querySelector(`[name="lines[${coaId}][budgeted_amount]"]`);
                if (input) input.value = data[coaId] || '';
            });
            document.getElementById('copy-status').textContent = i18n.loaded;
        });
    }

    /* ═══════════════════════════════════════════════════════════════════
     * REPORTS PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function getReportParams() {
        return {
            type: document.querySelector('#report_type')?.value ?? '',
            year: document.querySelector('#report_year')?.value ?? '',
            date: document.querySelector('#report_date')?.value ?? '',
        };
    }

    document.getElementById('report_type')?.addEventListener('change', function() {
        document.getElementById('report-date-wrap').style.display = this.value === 'patrimony' ? '' : 'none';
        document.getElementById('report_year_div').style.display = this.value === 'patrimony' ? 'none' : '';
        document.getElementById('btn-export-pdf').style.display = this.value === 'income_expenditure' ? '' : 'none';
        document.getElementById('btn-export-csv').style.display = this.value === 'events' ? 'none' : '';
    });

    function handleLoadReport() {
        const { type, year, date } = getReportParams();
        const output = document.querySelector('#report-output');
        if (output) output.innerHTML = '<p>Laden…</p>';
        fetch(`${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=json`)
            .then(r => r.json())
            .then(r => {
                if (!output) return;
                r.success ? renderReport(type, r.data, output)
                          : (output.innerHTML = `<p class="error">${esc(r.data)}</p>`);
            });
    }

    function handleExportCsv() {
        const { type, year, date } = getReportParams();
        //window.location = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=csv`;
        const url = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=csv`;
        fetch(url)
        .then(response => response.blob())
        .then(blob => {
            const blobUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `Reports-${year}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(blobUrl);
        })
        .catch(error => {
            console.error('Download failed:', error);
        });
    }

    function handleExportPDF() {
        const { type, year, date } = getReportParams();
        //window.location = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=pdf`;
        const url = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=pdf`;
        fetch(url)
        .then(response => response.blob())
        .then(blob => {
            const blobUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `Reports-${year}.pdf`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(blobUrl);
        })
        .catch(error => {
            console.error('Download failed:', error);
        });
    }

    function renderReport(type, data, container) {
        if (type === 'income_expenditure') renderIncomeExpenditure(data, container);
        else if (type === 'patrimony')     renderPatrimony(data, container);
        else if (type === 'events')        renderEventsReport(data, container);
        else                               renderDiary(data, container);
    }

    function renderIncomeExpenditure(data, container) {
        let html = `<h2>Staat van Ontvangsten en Uitgaven – ${esc(data.fiscal_year)}</h2>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">`;

        html += '<div><h3>Ontvangsten</h3><table class="eme-acc-table">';
        for (const [cat, amt] of Object.entries(data.income))
            html += `<tr><td>${esc(cat)}</td><td class="amount in">${formatCurrency(amt)}</td></tr>`;
        html += `<tr style="font-weight:600"><td>Totaal ontvangsten</td>
                 <td class="amount in">${formatCurrency(data.totals.income)}</td></tr></table></div>`;

        html += '<div><h3>Uitgaven</h3><table class="eme-acc-table">';
        for (const [cat, amt] of Object.entries(data.expenses))
            html += `<tr><td>${esc(cat)}</td><td class="amount out">${formatCurrency(amt)}</td></tr>`;
        html += `<tr style="font-weight:600"><td>Totaal uitgaven</td>
                 <td class="amount out">${formatCurrency(data.totals.expenses)}</td></tr></table></div></div>`;

        const net = data.totals.result;
        html += `<p style="font-size:16px;font-weight:600;margin-top:16px">
            ${net >= 0 ? 'Overschot' : 'Tekort'} boekjaar:
            <span class="amount ${net >= 0 ? 'in' : 'out'}">${formatCurrency(Math.abs(net))}</span></p>`;
        container.innerHTML = html;
    }

    function renderPatrimony(data, container) {
        let html = `<h2>Staat van Vermogen per ${esc(data.date)}</h2>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">`;

        html += '<div><h3>Activa</h3><table class="eme-acc-table">';
        for (const [name, bal] of Object.entries(data.assets))
            html += `<tr><td>${esc(name)}</td><td class="amount">${formatCurrency(bal)}</td></tr>`;
        html += `<tr style="font-weight:600"><td>Totaal activa</td>
                 <td class="amount">${formatCurrency(data.totals.assets)}</td></tr></table></div>`;

        html += '<div><h3>Passiva / Schulden</h3><table class="eme-acc-table">';
        for (const [name, bal] of Object.entries(data.liabilities))
            html += `<tr><td>${esc(name)}</td><td class="amount">${formatCurrency(bal)}</td></tr>`;
        html += `<tr style="font-weight:600"><td>Totaal schulden</td>
                 <td class="amount">${formatCurrency(data.totals.liabilities)}</td></tr></table></div></div>`;

        const eq = data.totals.equity;
        html += `<p style="font-size:16px;font-weight:600;margin-top:16px">
            Eigen vermogen: <span class="amount ${eq >= 0 ? 'in' : 'out'}">${formatCurrency(eq)}</span></p>`;
        container.innerHTML = html;
    }

    function renderDiary(data, container) {
        let html = '<h2>Financieel Dagboek</h2><table class="eme-acc-table">'
            + '<tr><th>Datum</th><th>Omschrijving</th><th>Categorie</th><th>Bankref.</th>'
            + '<th class="amount">Bedrag</th><th>Rekening</th><th>Vereff.</th></tr>';
        for (const [date, entries] of Object.entries(data)) {
            entries.forEach((e, i) => {
                const sign = e.direction === 'in' ? '+' : '–';
                html += `<tr>
                    <td>${i === 0 ? esc(date) : ''}</td>
                    <td>${esc(e.description)}</td><td>${esc(e.category)}</td>
                    <td style="font-size:11px;color:#646970">${esc(e.bank_statement_ref)}</td>
                    <td class="amount ${e.direction === 'in' ? 'in' : 'out'}">${sign} ${formatCurrency(e.amount)}</td>
                    <td>${esc(e.account_name)}</td>
                    <td>${e.is_reconciled ? '<span class="eme-acc-tag eme-acc-reconciled">✓</span>' : ''}</td>
                </tr>`;
            });
        }
        container.innerHTML = html + '</table>';
    }

    function renderEventsReport(data, container) {
        // Drilldown view (details)
        if (data.details) {
            // event info in data.info
            const year = document.querySelector('#report_year')?.value || '';
            let html = `<h2>Event boekingen voor ${esc(data.info.event_name)} met startdatum ${esc(data.info.event_start)}</h2>
                <p><a href="#" onclick="loadEventsReport(${year});return false">← Terug naar overzicht</a></p>
                <table class="eme-acc-table">
                <tr><th>Datum</th><th>Omschrijving</th><th>Categorie</th><th class="amount">Bedrag</th><th>Rekening</th></tr>`;
            data.details.forEach(e => {
                const sign = e.direction === 'in' ? '+' : '–';
                html += `<tr>
                    <td>${esc(e.entry_date)}</td>
                    <td>${esc(e.description)}</td>
                    <td>${esc(e.coa_name)}</td>
                    <td class="amount ${e.direction === 'in' ? 'in' : 'out'}">${sign} ${formatCurrency(e.amount)}</td>
                    <td>${esc(e.account_name)}</td>
                </tr>`;
            });
            const totalIn = data.details.filter(e => e.direction === 'in').reduce((s, e) => s + parseFloat(e.amount), 0);
            const totalOut = data.details.filter(e => e.direction === 'out').reduce((s, e) => s + parseFloat(e.amount), 0);
            html += `<tr style="font-weight:600"><td colspan="3">Totaal ontvangen</td>
                <td class="amount in">${formatCurrency(totalIn)}</td><td></td></tr>
                <tr style="font-weight:600"><td colspan="3">Totaal uitgegeven</td>
                <td class="amount out">${formatCurrency(totalOut)}</td><td></td></tr>
                </table>`;
            container.innerHTML = html;
            return;
        }

        // Overview: summary list
        let html = '<h2>Events Overzicht</h2><table class="eme-acc-table">'
            + '<tr><th>Event</th><th>Event startdatum</th><th class="amount">Ontvangen</th><th class="amount">Uitgegeven</th><th>Saldo</th></tr>';
        
        const entries = Object.values(data.summary || {});
        if (!entries.length) {
            html += '<tr><td colspan="4">Geen boekingen voor dit jaar.</td></tr>';
        } else {
            entries.forEach(ev => {
                const isPositive = ev.total_in >= ev.total_out;
                html += `<tr style="cursor:pointer" onclick="loadEventDetails(${ev.event_id})">
                    <td>${esc(ev.event_name)}</td>
                    <td>${esc(ev.event_start)}</td>
                    <td class="amount in">${formatCurrency(ev.total_in)}</td>
                    <td class="amount out">${formatCurrency(ev.total_out)}</td>
                    <td class="amount ${isPositive ? 'in' : 'out'}">${formatCurrency(Math.abs(ev.total_in - ev.total_out))}</td>
                </tr>`;
            });
            const totalIn = entries.reduce((s, e) => s + e.total_in, 0);
            const totalOut = entries.reduce((s, e) => s + e.total_out, 0);
            html += `<tr style="font-weight:600"><td>Totaal</td>
                <td class="amount in">${formatCurrency(totalIn)}</td>
                <td class="amount out">${formatCurrency(totalOut)}</td>
                <td class="amount ${totalIn >= totalOut ? 'in' : 'out'}">${formatCurrency(Math.abs(totalIn - totalOut))}</td></tr>`;
        }
        container.innerHTML = html + '</table>';
    }

    // Global helpers for event report drilldown (called from onclick)
    window.loadEventsReport = function(year) {
        const { type } = getReportParams();
        const output = document.querySelector('#report-output');
        if (output) output.innerHTML = '<p>Laden…</p>';
        fetch(`${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=events&year=${year}&format=json`)
            .then(r => r.json())
            .then(r => {
                if (!output) return;
                r.success ? renderEventsReport(r.data, output)
                          : (output.innerHTML = `<p class="error">${esc(r.data)}</p>`);
            });
    };

    window.loadEventDetails = function(eventId) {
        const year = document.querySelector('#report_year')?.value || '';
        const output = document.querySelector('#report-output');
        if (output) output.innerHTML = '<p>Laden…</p>';
        fetch(`${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=events&year=${year}&event_id=${eventId}&format=json`)
            .then(r => r.json())
            .then(r => {
                if (!output) return;
                r.success ? renderEventsReport(r.data, output)
                          : (output.innerHTML = `<p class="error">${esc(r.data)}</p>`);
            });
    };

    /* ════════════════════════════════════════════════════════════════
     * PATRIMONY
     * ════════════════════════════════════════════════════════════════ */
    const patrimonyForm = document.getElementById('eme-acc-patrimony-form');
    if (patrimonyForm) {
        patrimonyForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('.submit-btn');
            btn.disabled = true;
            btn.textContent = i18n.saving;
            const formData = new FormData(this);
            const data = {};
            formData.forEach((value, key) => { data[key] = value; });
            ajax('eme_accounting_save_patrimony', data, function() {
                flashButton(btn, i18n.saved);
                location.reload();
            }, function(msg) {
                btn.textContent = i18n.save;
                btn.disabled = false;
                alert(msg);
            });
        });
    }

    function recalcPatrimony() {
        let assets = 0, liab = 0, rights = 0, oblig = 0;
        document.querySelectorAll('.eme-acc-amount').forEach(function(el) {
            const v = parseBE(el.value); // needed to get the real international number
            const s = el.dataset.section;
            if (s === 'assets') assets += v;
            if (s === 'liab')   liab   += v;
            if (s === 'rights') rights += v;
            if (s === 'oblig')  oblig  += v;
        });
        document.getElementById('total-assets').textContent = formatCurrency(assets);
        document.getElementById('total-liab').textContent   = formatCurrency(liab);
        document.getElementById('total-rights').textContent = formatCurrency(rights);
        document.getElementById('total-oblig').textContent  = formatCurrency(oblig);
        const net = assets - liab;
        const netEl = document.getElementById('total-net');
        netEl.textContent = formatCurrency(net);
        netEl.style.color = net < 0 ? '#d63638' : '#00a32a';
    }

    const PatrimonyYearSelect = document.getElementById('eme-acc-patrimony-year');
    if (PatrimonyYearSelect) {
        PatrimonyYearSelect.addEventListener('change', function() {
            window.location = `${window.location.pathname}?page=emeaccounting-patrimony&year=${this.value}`;
        });

        document.querySelectorAll('.eme-acc-amount').forEach(function(el) {
            el.addEventListener('input', recalcPatrimony);
        });
        recalcPatrimony();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * GENERIC
     * ═══════════════════════════════════════════════════════════════════ */
    function handleToggleEditRow(btn) {
        const target = document.querySelector(`[data-edit-form="${btn.dataset.id}"]`);
        if (target) target.style.display = target.style.display === 'none' ? '' : 'none';
    }

    function initSnapSelect(selector, options = {}) {
        // Convert selector to elements array
        const elements = typeof selector === 'string'
            ? Array.from(document.querySelectorAll(selector))
            : [selector];

        if (!elements.length) return [];

        return Array.from(elements).map(el => {
            // Guard: skip if already initialised
            if (el.snapselectInstance) return el.snapselectInstance;

            if (!el.id) {
                el.id = 'snap-select-' + Math.random().toString(36).slice(2, 11);
            }   

            const config = {
                placeholder:    options.placeholder    || undefined,
                defaultText:    options.defaultText    || 'Select',
                liveSearch:     options.liveSearch     !== undefined ? options.liveSearch     : el.multiple ? true : undefined,
                closeOnSelect:  options.closeOnSelect  !== undefined ? options.closeOnSelect  : undefined,
                showClearButton:     options.showClearButton     !== undefined ? options.showClearButton     : undefined,
            };

            const instance = new SnapSelectClass(el, config);
            el.snapselectInstance = instance;
            return instance;
        });
    }
    initSnapSelect('select.eme_snapselect');

    function initSnapSelectRemote(selector, options = {}) {
        // Convert selector to elements array
        const elements = typeof selector === 'string'
            ? Array.from(document.querySelectorAll(selector))
            : [selector];

        if (!elements.length) return [];

        return Array.from(elements).map(el => {
            // Guard: skip if already initialised
            if (el.snapselectInstance) return el.snapselectInstance;

            if (!el.id) {
                el.id = 'snap-select-' + Math.random().toString(36).slice(2, 11);
            }

            const pagesize = options.pagesize || 30;
            // Build the ajax.url function, incorporating any extra data
            const config = {
                placeholder:    options.placeholder    || undefined,
                defaultText:    options.defaultText    || 'Select',
                closeOnSelect:  !el.multiple,
                showClearButton:     options.showClearButton !== undefined ? options.showClearButton : undefined,
                onItemAdd:      typeof options.onItemAdd    === 'function' ? options.onItemAdd    : undefined,
                onItemDelete:   typeof options.onItemDelete === 'function' ? options.onItemDelete : undefined,
                ajax: {
                    // Allow caller to pass a custom url function (e.g. for state→country cascade)
                    url: options.url || ajaxUrl,
                    cache: options.cache !== undefined ? options.cache : undefined,
                    pagesize: pagesize,
                    data: options.data || {},
                    processResults: function(data, search, page) {
                        // EME backend returns { TotalRecordCount, Records:[{id,text},...] }
                        // or a plain array
                        const records = Array.isArray(data)
                            ? data
                            : (data.Records || []);
                        let hasMore;
                        if (data.hasMore !== undefined) {
                            hasMore = data.hasMore;
                        } else {
                            const total   = data.TotalRecordCount !== undefined
                                ? data.TotalRecordCount
                                : records.length;
                            hasMore = total > page * pagesize;
                            // the next if covers the case where all rows are returned in 1 go, not respecting paging
                            if (data.TotalRecordCount !== undefined && records.length >= data.TotalRecordCount)
                                hasMore = false;
                        }
                        return { results: records, hasMore };
                    },
                }
            };
            const instance = new SnapSelectClass(el, config);

            // Expose a .snapselect property on the element
            el.snapselectInstance = instance;

            return instance;
        });
    }
    initSnapSelectRemote('select.eme_snapselect_events_class', {
        placeholder: 'Selecteer event',
        showClearButton: true,
        data: function(search, page) {
            return {
                action: 'eme_accounting_events_list',
                entry_date: document.getElementById('entry_date')?.value || 0,
                search_all: document.getElementById('eventsearch_all')?.checked ? 1 : 0,
                nonce: nonce
            };
        }
    });
    initSnapSelectRemote('select.eme_snapselect_filterevents_class', {
        placeholder: 'Filter op event',
        showClearButton: true,
        data: function(search, page) {
            return {
                action: 'eme_accounting_events_list',
                search_all: document.getElementById('filter_eventsearch_all')?.checked ? 1 : 0,
                nonce: nonce
            };
        }
    });

})();
