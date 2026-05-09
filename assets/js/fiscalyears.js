/**
 * Fiscal Years page: CRUD operations
 */
(function() {
    var form = document.getElementById('eme-acc-fiscal-year-form');
    var idHidden = document.getElementById('fy_id');
    var yearInput = document.getElementById('fy_year_input');
    var submitBtn = document.getElementById('fy_submit');
    var messageDiv = document.getElementById('fy-message');

    function showMessage(msg, isError) {
        messageDiv.innerHTML = '<div class="notice notice-' + (isError ? 'error' : 'success') + '"><p>' + msg + '</p></div>';
    }

    function clearMessage() {
        messageDiv.innerHTML = '';
    }

    function clearForm() {
        idHidden.value = '';
        yearInput.value = '';
        yearInput.readOnly = false;
        clearMessage();
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!submitBtn) return;
        submitBtn.disabled = true;
        const originalText = submitBtn.textContent;
        submitBtn.textContent = emeAccounting.i18n.saving;
        clearMessage();

        var data = new FormData();
        data.append('action', 'eme_accounting_save_fiscal_year');
        data.append('nonce', emeAccounting.nonce);
        data.append('id', idHidden.value);
        data.append('year', yearInput.value);

        fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                if (r.success) {
                    showMessage(r.data.message, false);
                    clearForm();
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showMessage(r.data, true);
                }
            })
            .catch(function() {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                showMessage(emeAccounting.i18n.error, true);
            });
    });

    document.querySelectorAll('.btn-close').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var year = this.dataset.year;
            const ok = await FTable.confirm( 'Afsluiten', emeAccounting.i18n.confirmClose.replace('%s', year) );
            if (!ok) return;
            this.disabled = true;
            this.textContent = emeAccounting.i18n.processing;

            var data = new FormData();
            data.append('action', 'eme_accounting_close_fiscal_year');
            data.append('nonce', emeAccounting.nonce);
            data.append('year', year);

            fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (r.success) {
                        alert(r.data.message + ' ' + (r.data.result || ''));
                        location.reload();
                    } else {
                        alert(r.data);
                        location.reload();
                    }
                })
                .catch(function() {
                    alert(emeAccounting.i18n.error);
                    location.reload();
                });
        });
    });

    document.querySelectorAll('.btn-reopen').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var year = this.dataset.year;
            const ok = await FTable.confirm( 'Heropenen', emeAccounting.i18n.confirmReopen.replace('%s', year) );
            if (!ok) return;
            this.disabled = true;
            this.textContent = emeAccounting.i18n.processing;

            var data = new FormData();
            data.append('action', 'eme_accounting_reopen_fiscal_year');
            data.append('nonce', emeAccounting.nonce);
            data.append('year', year);

            fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (r.success) {
                        alert(r.data.message);
                        location.reload();
                    } else {
                        alert(r.data);
                        location.reload();
                    }
                })
                .catch(function() {
                    alert(emeAccounting.i18n.error);
                    location.reload();
                });
        });
    });

    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var year = this.dataset.year;
            const ok = await FTable.confirm( 'Verwijderen', emeAccounting.i18n.confirmDelete.replace('%s', year) );
            if (!ok) return;
            this.disabled = true;
            this.textContent = emeAccounting.i18n.processing;

            var data = new FormData();
            data.append('action', 'eme_accounting_delete_fiscal_year');
            data.append('nonce', emeAccounting.nonce);
            data.append('year', year);

            fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (r.success) {
                        alert(r.data.message);
                        location.reload();
                    } else {
                        alert(r.data);
                        location.reload();
                    }
                })
                .catch(function() {
                    alert(emeAccounting.i18n.error);
                    location.reload();
                });
        });
    });

    clearForm();
})();
