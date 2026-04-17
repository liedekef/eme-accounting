/**
 * Fiscal Years page: CRUD operations
 */
(function() {
    var form = document.getElementById('eme-acc-fiscal-year-form');
    var idHidden = document.getElementById('fy_id');
    var yearInput = document.getElementById('fy_year_input');
    var startDate = document.getElementById('fy_start_date');
    var endDate = document.getElementById('fy_end_date');
    var submitBtn = document.getElementById('fy_submit');
    var cancelBtn = document.getElementById('fy_cancel');
    var messageDiv = document.getElementById('fy-message');

    function showMessage(msg, isError) {
        messageDiv.innerHTML = '<div class="notice notice-' + (isError ? 'error' : 'success') + '"><p>' + msg + '</p></div>';
    }

    function clearMessage() {
        messageDiv.innerHTML = '';
    }

    function setEditMode(id, year, start, end) {
        idHidden.value = id;
        yearInput.value = year;
        yearInput.readOnly = true;
        startDate.value = start;
        endDate.value = end;
        submitBtn.textContent = emeAccounting.i18n.update;
        cancelBtn.style.display = 'inline-block';
        clearMessage();
    }

    function clearForm() {
        idHidden.value = '';
        yearInput.value = emeAccountingFy.currentYear;
        yearInput.readOnly = false;
        startDate.value = emeAccountingFy.defaultStartDate;
        endDate.value = emeAccountingFy.defaultEndDate;
        submitBtn.textContent = emeAccounting.i18n.save;
        cancelBtn.style.display = 'none';
        clearMessage();
    }

    cancelBtn.addEventListener('click', clearForm);

    document.querySelectorAll('.btn-edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            setEditMode(
                this.dataset.id,
                this.dataset.year,
                this.dataset.start,
                this.dataset.end
            );
        });
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.textContent = emeAccounting.i18n.saving;
        clearMessage();

        var data = new FormData();
        data.append('action', 'eme_accounting_save_fiscal_year');
        data.append('nonce', emeAccounting.nonce);
        data.append('id', idHidden.value);
        data.append('year', yearInput.value);
        data.append('start_date', startDate.value);
        data.append('end_date', endDate.value);

        fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                submitBtn.disabled = false;
                submitBtn.textContent = emeAccounting.i18n.save;
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
                submitBtn.textContent = emeAccounting.i18n.save;
                showMessage(emeAccounting.i18n.error, true);
            });
    });

    document.querySelectorAll('.btn-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var year = this.dataset.year;
            if (!confirm(emeAccounting.i18n.confirmClose.replace('%s', year))) return;
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
        btn.addEventListener('click', function() {
            var year = this.dataset.year;
            if (!confirm(emeAccounting.i18n.confirmReopen.replace('%s', year))) return;
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
        btn.addEventListener('click', function() {
            var year = this.dataset.year;
            if (!confirm(emeAccounting.i18n.confirmDelete.replace('%s', year))) return;
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
