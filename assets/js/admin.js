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

function parseBE(val) {
    return parseFloat(String(val).replace(/\./g, '').replace(',', '.')) || 0;
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
                url: options.url || emeAccounting.ajaxUrl,
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

(function () {
    'use strict';

    const { nonce, ajaxUrl, i18n } = emeAccounting;

    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE SUBMIT DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    const submitHandlers = {
        'eme-acc-account-form':        handleAccountSave,
        'eme-acc-coa-form':            handleCoaSave,
        // 'eme-acc-import-form' is now handled inline in templates/import.php
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
        // Budget
        'eme-acc-delete-budget': handleDeleteBudget,
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
     * All import-page logic is now handled inline in templates/import.php
     * via ftable (childtable pattern). No handlers needed here.
     * ═══════════════════════════════════════════════════════════════════ */

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
        const assetToSave = formToObj(form);
        assetToSave.purchase_price = parseBE(String(assetToSave.purchase_price));
        assetToSave.residual_value = parseBE(String(assetToSave.residual_value));
        ajax('eme_accounting_save_asset', assetToSave, () => location.reload());
    }

    function updateDepreciationPreview() {
        const price    = parseBE(document.querySelector('#purchase_price')?.value  || '0');
        const life     = parseInt(document.querySelector('#useful_life_years')?.value)   || 1;
        const residual = parseBE(document.querySelector('#residual_value')?.value  || '0');
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
        form.querySelector('[name="purchase_price"]').value   = new Intl.NumberFormat('nl-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(d.purchasePrice) || 0);
        form.querySelector('[name="residual_value"]').value = new Intl.NumberFormat('nl-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(d.residualValue) || 0);
        form.querySelector('#current_book_value').value = new Intl.NumberFormat('nl-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(d.currentBookValue) || 0);
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

    // Delete budget (all lines for a fiscal year)
    function handleDeleteBudget(btn) {
        if (!confirm(i18n.confirm_delete_budget)) return;
        ajax('eme_accounting_delete_budget', { year: btn.dataset.year, nonce: nonce }, function() {
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
                    budgeted_amount: parseBE(amount.value)
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
            type:              document.querySelector('#report_type')?.value ?? '',
            year:              document.querySelector('#report_year')?.value ?? '',
            date:              document.querySelector('#report_date')?.value ?? '',
            exclude_transfers: document.querySelector('#cashflow_exclude_transfers')?.checked ? '1' : '0',
        };
    }

    document.getElementById('report_type')?.addEventListener('change', function() {
        const t = this.value;
        document.getElementById('report-date-wrap').style.display = t === 'patrimony' ? '' : 'none';
        document.getElementById('report_year_div').style.display  = t === 'patrimony' ? 'none' : '';
        document.getElementById('btn-export-pdf').style.display        = ['income_expenditure', 'financial_year', 'cashflow'].includes(t) ? '' : 'none';
        document.getElementById('btn-export-csv').style.display        = ['events', 'vat_report', 'cashflow'].includes(t) ? 'none' : '';
        document.getElementById('report-cashflow-options').style.display = t === 'cashflow' ? '' : 'none';
        document.getElementById('btn-load-report').click();
    });
    // force initial click
    document.getElementById('btn-load-report')?.click();

    function handleLoadReport() {
        const params = getReportParams();
        const output = document.querySelector('#report-output');
        if (output) output.innerHTML = '<p>Laden…</p>';
        const query = `action=eme_accounting_export_report&nonce=${nonce}&type=${params.type}&year=${params.year}&date=${params.date}&exclude_transfers=${params.exclude_transfers}&format=json`;
        fetch(`${ajaxUrl}?${query}`)
            .then(r => r.json())
            .then(r => {
                if (!output) return;
                r.success ? renderReport(params.type, r.data, output)
                          : (output.innerHTML = `<p class="error">${esc(r.data)}</p>`);
            });
    }

    function handleExportCsv() {
        const params = getReportParams();
        const query = `action=eme_accounting_export_report&nonce=${nonce}&type=${params.type}&year=${params.year}&date=${params.date}&exclude_transfers=${params.exclude_transfers}&format=csv`;
        const url = `${ajaxUrl}?${query}`;
        fetch(url)
        .then(response => response.blob())
        .then(blob => {
            const blobUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `Reports-${params.type}-${params.year}.csv`;
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
        const params = getReportParams();
        const url = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${params.type}&year=${params.year}&date=${params.date}&exclude_transfers=${params.exclude_transfers}&format=pdf`;
        fetch(url)
        .then(response => response.blob())
        .then(blob => {
            const blobUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `Reports-${params.type}-${params.year}.pdf`;
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
        if (type === 'income_expenditure')  renderIncomeExpenditure(data, container);
        else if (type === 'patrimony')      renderPatrimony(data, container);
        else if (type === 'events')         renderEventsReport(data, container);
        else if (type === 'financial_year') renderFinancialYearReport(data, container);
        else if (type === 'vat_report')     renderVatReport(data, container);
        else if (type === 'cashflow')       renderCashflow(data, container);
        else                                renderDiary(data, container);
    }

    function renderCashflow(data, container) {
        const months = ['Jan','Feb','Mrt','Apr','Mei','Jun','Jul','Aug','Sep','Okt','Nov','Dec'];
        const fmt = (n) => formatCurrency(n);
        const colorNet = (n) => n >= 0
            ? `<span style="color:green;font-weight:bold">${fmt(n)}</span>`
            : `<span style="color:red;font-weight:bold">${fmt(n)}</span>`;

        let html = `<h2>Cashflow per maand – ${esc(String(data.fiscal_year))}</h2>`;
        html += `<div style="overflow-x:auto">`;
        html += `<table class="widefat striped" style="min-width:900px;font-size:13px">`;

        // Header row
        html += `<thead><tr><th>Rekening</th>`;
        for (let m = 1; m <= 12; m++) html += `<th style="text-align:right">${months[m-1]}</th>`;
        html += `<th style="text-align:right">Totaal</th></tr></thead><tbody>`;

        const accountIds = Object.keys(data.data || {});

        for (const aid of accountIds) {
            const name    = esc(data.accounts[aid] || aid);
            const months_ = data.data[aid];
            let accIn = 0, accOut = 0;

            // In-row
            html += `<tr><td rowspan="3"><strong>${name}</strong></td>`;
            for (let m = 1; m <= 12; m++) {
                accIn += months_[m].in;
                html += `<td style="text-align:right;color:green">${months_[m].in > 0 ? fmt(months_[m].in) : ''}</td>`;
            }
            html += `<td style="text-align:right;color:green">${fmt(accIn)}</td></tr>`;

            // Out-row
            html += `<tr>`;
            for (let m = 1; m <= 12; m++) {
                accOut += months_[m].out;
                html += `<td style="text-align:right;color:red">${months_[m].out > 0 ? fmt(-1*months_[m].out) : ''}</td>`;
            }
            html += `<td style="text-align:right;color:red">${fmt(-1*accOut)}</td></tr>`;

            // Net-row
            html += `<tr style="border-bottom:2px solid #ccc">`;
            for (let m = 1; m <= 12; m++) {
                html += `<td style="text-align:right">${colorNet(months_[m].net)}</td>`;
            }
            html += `<td style="text-align:right">${colorNet(accIn - accOut)}</td></tr>`;
        }

        // Grand total row
        const t = data.month_totals || {};
        html += `<tr style="background:#f0f0f1;font-weight:bold"><td>Totaal netto</td>`;
        for (let m = 1; m <= 12; m++) {
            html += `<td style="text-align:right">${colorNet((t[m] || {}).net || 0)}</td>`;
        }
        html += `<td style="text-align:right">${colorNet(data.totals?.net || 0)}</td></tr>`;

        html += `</tbody></table></div>`;
        container.innerHTML = html;
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
                html += `<tr>
                    <td>${esc(e.entry_date)}</td>
                    <td>${esc(e.description)}</td>
                    <td>${esc(e.coa_name)}</td>
                    <td class="amount ${e.direction === 'in' ? 'in' : 'out'}">${formatCurrency(e.amount)}</td>
                    <td>${esc(e.account_name)}</td>
                </tr>`;
            });
            const totalIn = data.details.filter(e => e.direction === 'in').reduce((s, e) => s + parseFloat(e.amount), 0);
            let totalOut = data.details.filter(e => e.direction === 'out').reduce((s, e) => s + parseFloat(e.amount), 0);
            if (totalOut>0) totalOut*=-1;
            html += `<tr style="font-weight:600"><td colspan="3">Totaal ontvangen</td>
                <td class="amount in">${formatCurrency(totalIn)}</td><td></td></tr>
                <tr style="font-weight:600"><td colspan="3">Totaal uitgegeven</td>
                <td class="amount out">${formatCurrency(totalOut)}</td><td></td></tr>
                </table>`;
            container.innerHTML = html;
            return;
        }

        // Overview: summary list
        let html = '<h2>Evenementen Overzicht</h2><table class="eme-acc-table">'
            + '<tr><th>Event</th><th>Event startdatum</th><th class="amount">Ontvangen</th><th class="amount">Uitgegeven</th><th class="amount">Saldo</th></tr>';
        
        const entries = Object.values(data.summary || {});
        if (!entries.length) {
            html += '<tr><td colspan="4">Geen boekingen voor dit jaar.</td></tr>';
        } else {
            // out-entries are signed negatives
            entries.forEach(ev => {
                const isPositive = ev.total_in >= -1*ev.total_out;
                html += `<tr style="cursor:pointer" onclick="loadEventDetails(${ev.event_id})">
                    <td>${esc(ev.event_name)}</td>
                    <td>${esc(ev.event_start)}</td>
                    <td class="amount in">${formatCurrency(ev.total_in)}</td>
                    <td class="amount out">${formatCurrency(ev.total_out)}</td>
                    <td class="amount ${isPositive ? 'in' : 'out'}">${formatCurrency(ev.total_in + ev.total_out)}</td>
                </tr>`;
            });
            const totalIn = entries.reduce((s, e) => s + e.total_in, 0);
            const totalOut = entries.reduce((s, e) => s + e.total_out, 0);
            html += `<tr style="font-weight:600">
                <td>Totaal</td>
                <td></td>
                <td class="amount in">${formatCurrency(totalIn)}</td>
                <td class="amount out">${formatCurrency(totalOut)}</td>
                <td class="amount ${totalIn >= -1*totalOut ? 'in' : 'out'}">${formatCurrency(totalIn + totalOut)}</td></tr>`;
        }
        container.innerHTML = html + '</table>';
    }

    function renderFinancialYearReport(data, container) {
        let html = `<h2>Financieel Jaarverslag per rekening – ${esc(data.fiscal_year)}</h2>`;

        const inAccounts = data.accounts.filter(a => a.direction === 'in');
        const outAccounts = data.accounts.filter(a => a.direction === 'out');

        html += `<div style="display:grid;grid-template-columns:1fr;gap:20px">
            <div><h3>Ontvangsten</h3>
            <table class="eme-acc-table">
            <thead><tr><th>Rekening</th><th class="amount">Totaal</th></tr></thead>
            <tbody>`;
        let totalIn = 0;
        for (const acc of inAccounts) {
            html += `<tr style="font-weight:600"><td>${esc(acc.code)} - ${esc(acc.name)}</td><td class="amount in">${formatCurrency(acc.total)}</td></tr>`;
            totalIn += acc.total;
            if (acc.children) {
                for (const child of acc.children) {
                    if (child.total)
                        html += `<tr><td style="padding-left:20px">${esc(child.code)} - ${esc(child.name)}</td><td class="amount in">${formatCurrency(child.total)}</td></tr>`;
                }
            }
        }
        html += `<tr style="font-weight:600"><td>Totaal ontvangsten</td><td class="amount in">${formatCurrency(totalIn)}</td></tr>`;
        html += `</tbody></table></div>`;

        html += `<div><h3>Uitgaven</h3>
            <table class="eme-acc-table">
            <thead><tr><th>Rekening</th><th class="amount">Totaal</th></tr></thead>
            <tbody>`;
        let totalOut = 0;
        for (const acc of outAccounts) {
            html += `<tr style="font-weight:600"><td>${esc(acc.code)} - ${esc(acc.name)}</td><td class="amount out">${formatCurrency(acc.total)}</td></tr>`;
            totalOut += acc.total;
            if (acc.children) {
                for (const child of acc.children) {
                    if (child.total)
                        html += `<tr><td style="padding-left:20px">${esc(child.code)} - ${esc(child.name)}</td><td class="amount out">${formatCurrency(child.total)}</td></tr>`;
                }
            }
        }
        html += `<tr style="font-weight:600"><td>Totaal uitgaven</td><td class="amount out">${formatCurrency(totalOut)}</td></tr>`;
        html += `</tbody></table></div></div>`;

        const saldo = totalIn - totalOut;
        html += `<p style="font-size:16px;font-weight:600;margin-top:16px">
            Saldo: <span class="amount ${saldo >= 0 ? 'in' : 'out'}">${formatCurrency(saldo)}</span></p>`;
        container.innerHTML = html;
    }

    function renderVatReport(data, container) {
        const fmt = (n) => new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' }).format(n);

        let html = `<h2>BTW-aangifte overzicht – ${esc(data.fiscal_year)}</h2>
            <table class="widefat fixed striped" style="max-width:680px">
                <thead>
                    <tr>
                        <th>Kwartaal</th>
                        <th style="text-align:right">BTW op ontvangsten (te betalen)</th>
                        <th style="text-align:right">BTW op uitgaven (aftrekbaar)</th>
                        <th style="text-align:right">Saldo</th>
                    </tr>
                </thead>
                <tbody>`;

        Object.values(data.quarters).forEach(q => {
            const balStyle = q.balance < 0 ? 'color:#d63638' : (q.balance > 0 ? 'color:#00a32a' : '');
            html += `<tr>
                <td><strong>Q${q.quarter}</strong></td>
                <td style="text-align:right">${q.vat_in  > 0 ? fmt(q.vat_in)  : '–'}</td>
                <td style="text-align:right">${q.vat_out > 0 ? fmt(q.vat_out) : '–'}</td>
                <td style="text-align:right;${balStyle}"><strong>${fmt(q.balance)}</strong></td>
            </tr>`;
        });

        html += `</tbody>
            <tfoot>
                <tr style="font-weight:bold;border-top:2px solid #ccc">
                    <td>Totaal</td>
                    <td style="text-align:right">${fmt(data.totals.vat_in)}</td>
                    <td style="text-align:right">${fmt(data.totals.vat_out)}</td>
                    <td style="text-align:right">${fmt(data.totals.balance)}</td>
                </tr>
            </tfoot>
        </table>
        <p style="color:#646970;margin-top:8px;font-size:12px">
            Saldo = BTW op ontvangsten − BTW op uitgaven.
            Positief = te betalen aan FOD Financiën. Negatief = tegoed.
        </p>`;

        container.innerHTML = html;
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

    initSnapSelect('select.eme_snapselect');
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
