/* eme-accounting/assets/js/admin.js */
/* global emeAccounting */

(function() {
    'use strict';

    const { nonce, ajaxUrl, i18n } = emeAccounting;

    /**
     * Recursive helper: convert complex JS objects to FormData with bracket keys.
     * E.g., { board_changes: [{ id: 1 }] } becomes board_changes[0][id]=1
     */
    function buildFormData(data, formData = new FormData(), parentKey = '') {
        for (const key in data) {
            if (!data.hasOwnProperty(key)) continue;
            const value = data[key];
            const formKey = parentKey ? `${parentKey}[${key}]` : key;

            // Recursive for objects/arrays (except File instances)
            if (typeof value === 'object' && value !== null && !(value instanceof File)) {
                buildFormData(value, formData, formKey);
            } else {
                formData.append(formKey, value);
            }
        }
        return formData;
    }

    /**
     * Generic AJAX function
     * Automatically uses FormData with recursive keys for nested data.
     */
    function ajax(action, data, onSuccess, onError) {
        // Add action and nonce to the data
        const payload = { action, nonce, ...data };
        
        // Build FormData with nested keys
        const formData = buildFormData(payload);

        // Fetch sends FormData as 'multipart/form-data' automatically
        fetch(ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(r => {
            if (r.success) {
                onSuccess && onSuccess(r.data);
            } else {
                (onError || alert)(r.data || 'Er is een fout opgetreden.');
            }
        })
        .catch(() => (onError || alert)('Verbindingsfout met server.'));
    }

    /* ── Format currency ─────────────────────────────────────────── */
    function formatCurrency(amount, currency = 'EUR') {
        return new Intl.NumberFormat('nl-BE', {
            style: 'currency', currency,
            minimumFractionDigits: 2,
        }).format(amount);
    }

    /* ── Inline save feedback ────────────────────────────────────── */
    function flashButton(btn, text) {
        const orig = btn.textContent;
        btn.textContent = text;
        btn.disabled = true;
        setTimeout(() => {
            btn.textContent = orig;
            btn.disabled = false;
        }, 1800);
    }

    /* ── DOM Helper Functions ────────────────────────────────────── */
    function $(selector, context = document) {
        return context.querySelector(selector);
    }

    function $$(selector, context = document) {
        return Array.from(context.querySelectorAll(selector));
    }

    function closest(el, selector) {
        return el.closest(selector);
    }

    /* ════════════════════════════════════════════════════════════════
     * ACCOUNTS PAGE
     * ════════════════════════════════════════════════════════════════ */
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-account-form');
        if (!form) return;
        e.preventDefault();
        
        const btn = form.querySelector('.submit-btn');
        btn.textContent = i18n.saving;
        btn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });

        ajax('eme_accounting_save_account', data, function() {
            flashButton(btn, i18n.saved);
            if (!form.querySelector('[name="id"]').value) {
                location.reload();
            }
        });
    });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.eme-acc-delete-account');
        if (!btn) return;
        
        if (!confirm(i18n.confirm_delete)) return;
        const id = btn.dataset.id;
        ajax('eme_accounting_delete_account', { id }, function() {
            const row = document.querySelector(`[data-account-row="${id}"]`);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 300);
            }
        });
    });

    /* ════════════════════════════════════════════════════════════════
     * JOURNAL PAGE
     * ════════════════════════════════════════════════════════════════ */
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-journal-form');
        if (!form) return;
        e.preventDefault();
        
        const btn = form.querySelector('.submit-btn');
        btn.textContent = i18n.saving;
        btn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });

        ajax('eme_accounting_save_entry', data, function(data) {
            flashButton(btn, i18n.saved);
            // Update row if editing, else prepend
            const existing = document.querySelector(`[data-journal-row="${data.id}"]`);
            if (existing) {
                existing.classList.add('updated');
                setTimeout(() => existing.classList.remove('updated'), 1500);
            } else {
                location.reload();
            }
        });
    });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.eme-acc-delete-entry');
        if (!btn) return;
        
        if (!confirm(i18n.confirm_delete)) return;
        const id = btn.dataset.id;
        const tr = document.querySelector(`[data-journal-row="${id}"]`);
        ajax('eme_accounting_delete_entry', { id }, function() {
            if (tr) {
                tr.style.transition = 'opacity 0.3s';
                tr.style.opacity = '0';
                setTimeout(() => tr.remove(), 300);
            }
        });
    });

    // Auto-fill fiscal year from entry date
    document.addEventListener('change', function(e) {
        const entryDate = e.target.closest('#entry_date');
        if (!entryDate) return;
        
        const year = entryDate.value.substring(0, 4);
        const fy = document.querySelector('#fiscal_year');
        if (fy && !fy.value) fy.value = year;
    });

    /* ════════════════════════════════════════════════════════════════
     * IMPORT / RECONCILIATION PAGE
     * ════════════════════════════════════════════════════════════════ */

    // Upload form
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-import-form');
        if (!form) return;
        e.preventDefault();
        
        const btn = form.querySelector('.submit-btn');
        const fd = new FormData(form);
        fd.append('action', 'eme_accounting_import_upload');
        fd.append('nonce', nonce);

        btn.textContent = 'Importeren…';
        btn.disabled = true;

        fetch(ajaxUrl, {
            method: 'POST',
            body: fd
        })
        .then(response => response.json())
        .then(r => {
            btn.textContent = 'Importeren';
            btn.disabled = false;
            if (r.success) {
                const d = r.data;
                const resultDiv = document.querySelector('#import-result');
                if (resultDiv) {
                    resultDiv.innerHTML = `<div class="notice notice-success"><p>
                        Import klaar: <strong>${d.total}</strong> regels —
                        <strong>${d.matched}</strong> gematcht —
                        <strong>${d.pending}</strong> nog te verifiëren.
                    </p></div>`;
                    resultDiv.style.display = 'block';
                }
                if (d.pending > 0) {
                    loadPendingLines(d.log_id);
                }
            } else {
                alert(r.data || 'Importfout');
            }
        })
        .catch(() => alert('Verbindingsfout met server.'));
    });

    function loadPendingLines(logId) {
        const section = document.querySelector('#reconcile-section');
        if (section) {
            section.innerHTML = '<p>Laden...</p>';
            fetch(`${ajaxUrl}?action=eme_accounting_reconcile_view&nonce=${nonce}&log_id=${logId}`)
                .then(response => response.text())
                .then(html => {
                    section.innerHTML = html;
                });
        }
    }

    // Auto-reconcile all
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('#btn-auto-reconcile');
        if (!btn) return;
        
        const logId = btn.dataset.logId;
        const accountId = btn.dataset.accountId;
        btn.textContent = 'Bezig…';
        btn.disabled = true;
        ajax('eme_accounting_auto_reconcile',
            { log_id: logId, account_id: accountId },
            function(data) {
                btn.textContent = `Auto-reconciliatie (${data.matched} gematcht)`;
                btn.disabled = false;
                location.reload();
            }
        );
    });

    // Manual reconcile: link import line to existing journal entry
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-reconcile-manual');
        if (!btn) return;
        
        const lineId = btn.dataset.lineId;
        const row = closest(btn, 'tr');
        const journalIdInput = row ? row.querySelector('.journal-id-input') : null;
        const journalId = journalIdInput ? journalIdInput.value : null;
        
        if (!journalId) { alert('Voer eerst een dagboek-ID in.'); return; }
        ajax('eme_accounting_reconcile_line',
            { line_id: lineId, journal_id: journalId },
            function() { 
                const lastCell = row ? row.querySelector('td:last-child') : null;
                if (lastCell) lastCell.innerHTML = '<span class="recon-matched">✓ Gematcht</span>';
            }
        );
    });

    // Ignore a line
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-ignore-line');
        if (!btn) return;
        
        const lineId = btn.dataset.lineId;
        const row = closest(btn, 'tr');
        ajax('eme_accounting_ignore_line', { line_id: lineId }, function() {
            if (row) {
                row.classList.add('recon-ignored');
                const lastCell = row.querySelector('td:last-child');
                if (lastCell) lastCell.innerHTML = '<span class="recon-ignored">Genegeerd</span>';
            }
        });
    });

    // Create journal entry from import line
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-create-from-line');
        if (!btn) return;
        
        const lineId = btn.dataset.lineId;
        const row = closest(btn, 'tr');
        const categoryInput = row ? row.querySelector('.category-input') : null;
        const category = categoryInput ? categoryInput.value : '';
        ajax('eme_accounting_create_entry_from_line',
            { line_id: lineId, category },
            function(data) {
                alert(`Boeking aangemaakt (ID: ${data.journal_id})`);
                location.reload();
            }
        );
    });

    /* ════════════════════════════════════════════════════════════════
     * STOCK PAGE
     * ════════════════════════════════════════════════════════════════ */
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-stock-form');
        if (!form) return;
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });
        ajax('eme_accounting_save_stock', data, () => location.reload());
    });

    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-stock-movement-form');
        if (!form) return;
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });
        ajax('eme_accounting_stock_movement', data, function() {
            const btn = form.querySelector('.submit-btn');
            if (btn) flashButton(btn, 'Opgeslagen ✓');
            setTimeout(() => location.reload(), 800);
        });
    });

    /* ════════════════════════════════════════════════════════════════
     * DEPRECIATION PAGE
     * ════════════════════════════════════════════════════════════════ */
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-asset-form');
        if (!form) return;
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });
        ajax('eme_accounting_save_asset', data, () => location.reload());
    });

    // Live depreciation preview
    function updateDepreciationPreview() {
        const priceInput = document.querySelector('#purchase_price');
        const lifeInput = document.querySelector('#useful_life_years');
        const residualInput = document.querySelector('#residual_value');
        const methodSelect = document.querySelector('#method');
        const previewSpan = document.querySelector('#depreciation-preview');
        
        if (!priceInput || !lifeInput || !residualInput || !methodSelect || !previewSpan) return;
        
        const price = parseFloat(priceInput.value) || 0;
        const life = parseInt(lifeInput.value) || 1;
        const residual = parseFloat(residualInput.value) || 0;
        const method = methodSelect.value;
        let annual;
        if (method === 'declining') {
            annual = (price * (2 / life)).toFixed(2);
        } else {
            annual = ((price - residual) / life).toFixed(2);
        }
        previewSpan.textContent = `Jaarlijkse afschrijving: ${formatCurrency(annual)}`;
    }

    document.addEventListener('input', function(e) {
        if (e.target.closest('#purchase_price, #useful_life_years, #residual_value, #method')) {
            updateDepreciationPreview();
        }
    });

    /* ════════════════════════════════════════════════════════════════
     * BUDGET PAGE
     * ════════════════════════════════════════════════════════════════ */
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('#eme-acc-budget-form');
        if (!form) return;
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });
        ajax('eme_accounting_save_budget_line', data, () => location.reload());
    });

    /* ════════════════════════════════════════════════════════════════
     * REPORTS PAGE
     * ════════════════════════════════════════════════════════════════ */
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('#btn-load-report');
        if (!btn) return;
        
        const typeSelect = document.querySelector('#report_type');
        const yearSelect = document.querySelector('#report_year');
        const dateInput = document.querySelector('#report_date');
        const outputDiv = document.querySelector('#report-output');
        
        const type = typeSelect ? typeSelect.value : '';
        const year = yearSelect ? yearSelect.value : '';
        const date = dateInput ? dateInput.value : '';
        
        if (outputDiv) outputDiv.innerHTML = '<p>Laden…</p>';
        
        fetch(`${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=json`)
            .then(response => response.json())
            .then(r => {
                if (r.success && outputDiv) {
                    renderReport(type, r.data, outputDiv);
                } else if (outputDiv) {
                    outputDiv.innerHTML = `<p class="error">${r.data}</p>`;
                }
            });
    });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('#btn-export-csv');
        if (!btn) return;
        
        const typeSelect = document.querySelector('#report_type');
        const yearSelect = document.querySelector('#report_year');
        const dateInput = document.querySelector('#report_date');
        
        const type = typeSelect ? typeSelect.value : '';
        const year = yearSelect ? yearSelect.value : '';
        const date = dateInput ? dateInput.value : '';
        
        window.location = `${ajaxUrl}?action=eme_accounting_export_report&nonce=${nonce}&type=${type}&year=${year}&date=${date}&format=csv`;
    });

    function renderReport(type, data, container) {
        if (type === 'income_expenditure') {
            renderIncomeExpenditure(data, container);
        } else if (type === 'patrimony') {
            renderPatrimony(data, container);
        } else {
            renderDiary(data, container);
        }
    }

    function renderIncomeExpenditure(data, container) {
        let html = `<h2>Staat van Ontvangsten en Uitgaven – ${esc(data.fiscal_year)}</h2>`;
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">';

        html += '<div><h3>Ontvangsten</h3><table class="eme-acc-table">';
        for (const [cat, amt] of Object.entries(data.income)) {
            html += `<tr><td>${esc(cat)}</td><td class="amount in">${formatCurrency(amt)}</td></tr>`;
        }
        html += `<tr style="font-weight:600"><td>Totaal ontvangsten</td><td class="amount in">${formatCurrency(data.totals.income)}</td></tr>`;
        html += '</table></div>';

        html += '<div><h3>Uitgaven</h3><table class="eme-acc-table">';
        for (const [cat, amt] of Object.entries(data.expenses)) {
            html += `<tr><td>${esc(cat)}</td><td class="amount out">${formatCurrency(amt)}</td></tr>`;
        }
        html += `<tr style="font-weight:600"><td>Totaal uitgaven</td><td class="amount out">${formatCurrency(data.totals.expenses)}</td></tr>`;
        html += '</table></div></div>';

        const net = data.totals.result;
        const cls = net >= 0 ? 'in' : 'out';
        const label = net >= 0 ? 'Overschot' : 'Tekort';
        html += `<p style="font-size:16px;font-weight:600;margin-top:16px">
            ${label} boekjaar: <span class="amount ${cls}">${formatCurrency(Math.abs(net))}</span></p>`;

        container.innerHTML = html;
    }

    function renderPatrimony(data, container) {
        let html = `<h2>Staat van Vermogen per ${esc(data.date)}</h2>`;
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">';

        html += '<div><h3>Activa</h3><table class="eme-acc-table">';
        for (const [name, bal] of Object.entries(data.assets)) {
            html += `<tr><td>${esc(name)}</td><td class="amount">${formatCurrency(bal)}</td></tr>`;
        }
        html += `<tr style="font-weight:600"><td>Totaal activa</td><td class="amount">${formatCurrency(data.totals.assets)}</td></tr>`;
        html += '</table></div>';

        html += '<div><h3>Passiva / Schulden</h3><table class="eme-acc-table">';
        for (const [name, bal] of Object.entries(data.liabilities)) {
            html += `<tr><td>${esc(name)}</td><td class="amount">${formatCurrency(bal)}</td></tr>`;
        }
        html += `<tr style="font-weight:600"><td>Totaal schulden</td><td class="amount">${formatCurrency(data.totals.liabilities)}</td></tr>`;
        html += '</table></div></div>';

        const eq = data.totals.equity;
        const cls = eq >= 0 ? 'in' : 'out';
        html += `<p style="font-size:16px;font-weight:600;margin-top:16px">
            Eigen vermogen: <span class="amount ${cls}">${formatCurrency(eq)}</span></p>`;

        container.innerHTML = html;
    }

    function renderDiary(data, container) {
        let html = '<h2>Financieel Dagboek</h2><table class="eme-acc-table">';
        html += '<tr><th>Datum</th><th>Omschrijving</th><th>Categorie</th><th>Bankref.</th><th class="amount">Bedrag</th><th>Rekening</th><th>Vereff.</th></tr>';
        for (const [date, entries] of Object.entries(data)) {
            entries.forEach((e, i) => {
                const amtClass = e.direction === 'in' ? 'in' : 'out';
                const sign = e.direction === 'in' ? '+' : '–';
                html += `<tr>
                    <td>${i === 0 ? esc(date) : ''}</td>
                    <td>${esc(e.description)}</td>
                    <td>${esc(e.category)}</td>
                    <td style="font-size:11px;color:#646970">${esc(e.bank_statement_ref || '')}</td>
                    <td class="amount ${amtClass}">${sign} ${formatCurrency(e.amount)}</td>
                    <td>${esc(e.account_name || '')}</td>
                    <td>${e.is_reconciled ? '<span class="tag reconciled">✓</span>' : ''}</td>
                </tr>`;
            });
        }
        html += '</table>';
        container.innerHTML = html;
    }

    /* ── Utility ─────────────────────────────────────────────────── */
    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Toggle edit forms ───────────────────────────────────────── */
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.eme-acc-edit-row');
        if (!btn) return;
        
        const id = btn.dataset.id;
        const formRow = document.querySelector(`[data-edit-form="${id}"]`);
        if (formRow) {
            const display = formRow.style.display;
            formRow.style.display = display === 'none' ? '' : 'none';
        }
    });

    /* ── Year selector persistence ───────────────────────────────── */
    const yearSel = document.querySelector('#eme-acc-year-select');
    if (yearSel) {
        const savedYear = localStorage.getItem('eme_acc_year');
        if (savedYear) yearSel.value = savedYear;
        yearSel.addEventListener('change', function() {
            localStorage.setItem('eme_acc_year', this.value);
            const form = closest(this, 'form');
            if (form) form.submit();
        });
    }
})();
