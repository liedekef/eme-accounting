/* eme-accounting/assets/js/admin.js */
/* global emeAccounting, Sortable */

(function () {
    'use strict';

    const { nonce, ajaxUrl, i18n } = emeAccounting;

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
            style: 'currency', currency: 'EUR', minimumFractionDigits: 2,
        }).format(amount);
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
        fetch(ajaxUrl, { method: 'POST', body: toFormData({ action, nonce, ...data }) })
            .then(r => r.json())
            .then(r => r.success ? onSuccess?.(r.data) : (onError || alert)(r.data || 'Er is een fout opgetreden.'))
            .catch(() => (onError || alert)('Verbindingsfout met server.'));
    }

    function flashButton(btn, text) {
        const orig = btn.textContent;
        btn.textContent = text;
        btn.disabled = true;
        setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1800);
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

    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE SUBMIT DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    const submitHandlers = {
        'eme-acc-account-form':        handleAccountSave,
        'eme-acc-coa-form':            handleCoaSave,
        'eme-acc-journal-form':        handleJournalSave,
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
        'eme-acc-delete-entry':   handleJournalDelete,
        'btn-auto-reconcile':     handleAutoReconcile,
        'btn-reconcile-manual':   handleManualReconcile,
        'btn-ignore-line':        handleIgnoreLine,
        'btn-create-from-line':   handleCreateFromLine,
        'btn-load-report':        handleLoadReport,
        'btn-export-csv':         handleExportCsv,
        'eme-acc-edit-row':       handleToggleEditRow,
        // Stock
        'eme-acc-edit-stock':    handleStockEdit,
        'eme-acc-delete-stock': handleStockDelete,
        // Depreciation / Assets
        'eme-acc-edit-asset':   handleAssetEdit,
        'eme-acc-delete-asset': handleAssetDelete,
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
            localStorage.setItem('eme_acc_year', e.target.value);
            e.target.closest('form')?.submit();
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
        btn.textContent = i18n.saving;
        btn.disabled = true;
        ajax('eme_accounting_save_account', formToObj(form), () => {
            flashButton(btn, i18n.saved);
            location.reload();
        });
    }

    function handleAccountEdit(btn) {
        const d    = btn.dataset;
        const form = document.getElementById('eme-acc-account-form');
        form.querySelector('[name="id"]').value              = d.id;
        form.querySelector('[name="name"]').value            = d.name;
        form.querySelector('[name="iban"]').value            = d.iban;
        form.querySelector('[name="bic"]').value             = d.bic;
        form.querySelector('[name="opening_balance"]').value = d.openingBalance;
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
        document.getElementById('eme-acc-account-form-title').textContent = 'Rekening toevoegen';
        document.getElementById('eme-acc-account-cancel').style.display = 'none';
    }

    /* ═══════════════════════════════════════════════════════════════════
     * CHART OF ACCOUNTS PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleCoaSave(form) {
        const btn  = form.querySelector('.submit-btn');
        btn.textContent = i18n.saving;
        btn.disabled = true;
        const data = formToObj(form);
        if (!data.parent_id) data.parent_id = '';
        ajax('eme_accounting_save_coa', data, () => {
            flashButton(btn, i18n.saved);
            location.reload();
        });
    }

    function handleCoaEdit(btn) {
        const li = document.querySelector(`li[data-id="${btn.dataset.id}"]`);
        if (!li) return;
        const d    = li.dataset;
        const form = document.getElementById('eme-acc-coa-form');
        form.querySelector('[name="id"]').value          = d.id;
        form.querySelector('[name="code"]').value        = d.code;
        form.querySelector('[name="name"]').value        = d.name;
        form.querySelector('[name="direction"]').value   = d.direction || 'out';
        form.querySelector('[name="parent_id"]').value   = parseInt(d.parentId) || '';
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
        document.getElementById('eme-acc-coa-form-title').textContent = 'Rekening toevoegen';
        document.getElementById('eme-acc-coa-cancel').style.display = 'none';
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
     * JOURNAL PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleJournalSave(form) {
        const btn = form.querySelector('.submit-btn');
        btn.textContent = i18n.saving;
        btn.disabled = true;
        ajax('eme_accounting_save_entry', formToObj(form), (data) => {
            flashButton(btn, i18n.saved);
            const existing = document.querySelector(`[data-journal-row="${data?.id}"]`);
            if (existing) {
                existing.classList.add('updated');
                setTimeout(() => existing.classList.remove('updated'), 1500);
            } else {
                location.reload();
            }
        });
    }

    function handleJournalDelete(btn) {
        if (!confirm(i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_entry', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`[data-journal-row="${btn.dataset.id}"]`))
        );
    }

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
        btn.textContent = 'Bezig…';
        btn.disabled = true;
        ajax('eme_accounting_auto_reconcile',
            { log_id: btn.dataset.logId, account_id: btn.dataset.accountId },
            (data) => {
                btn.textContent = `Auto-reconciliatie (${data.matched} gematcht)`;
                btn.disabled = false;
                location.reload();
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

    /* ═══════════════════════════════════════════════════════════════════
     * STOCK PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleStockSave(form) {
        ajax('eme_accounting_save_stock', formToObj(form), () => location.reload());
    }

    function handleStockMovement(form) {
        ajax('eme_accounting_stock_movement', formToObj(form), () => {
            const btn = form.querySelector('.submit-btn');
            if (btn) flashButton(btn, 'Opgeslagen ✓');
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

    /* ═══════════════════════════════════════════════════════════════════
     * BUDGET PAGE
     * ═══════════════════════════════════════════════════════════════════ */
    function handleBudgetSave(form) {
        ajax('eme_accounting_save_budget_line', formToObj(form), () => location.reload());
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
        window.location = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=csv`;
    }

    function renderReport(type, data, container) {
        if (type === 'income_expenditure') renderIncomeExpenditure(data, container);
        else if (type === 'patrimony')      renderPatrimony(data, container);
        else                                renderDiary(data, container);
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
                    <td>${e.is_reconciled ? '<span class="tag reconciled">✓</span>' : ''}</td>
                </tr>`;
            });
        }
        container.innerHTML = html + '</table>';
    }

    /* ═══════════════════════════════════════════════════════════════════
     * GENERIC
     * ═══════════════════════════════════════════════════════════════════ */
    function handleToggleEditRow(btn) {
        const target = document.querySelector(`[data-edit-form="${btn.dataset.id}"]`);
        if (target) target.style.display = target.style.display === 'none' ? '' : 'none';
    }

    /* ── Year selector persistence ──────────────────────────────── */
    const yearSel = document.querySelector('#eme-acc-year-select');
    if (yearSel) {
        const saved = localStorage.getItem('eme_acc_year');
        if (saved) yearSel.value = saved;
    }

})();
