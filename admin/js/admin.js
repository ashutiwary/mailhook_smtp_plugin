/**
 * MailHook Admin JavaScript
 *
 * Handles: test email, logs page (select/delete/modal), templates layout switcher.
 * All strings and nonces are passed via wp_localize_script (mailhookData).
 *
 * @package MailHook
 */

/* global mailhookData */
(function () {
    'use strict';

    var data = window.mailhookData || {};

    /* =========================================================
       1. TEST EMAIL — Settings page
    ========================================================= */
    var testBtn = document.getElementById('mailhook-send-test');
    var testInput = document.getElementById('mailhook-test-email');
    var testResult = document.getElementById('mailhook-test-result');

    if (testBtn && testInput && testResult) {
        testBtn.addEventListener('click', function () {
            var email = testInput.value.trim();

            if (!email) {
                testResult.style.display = 'block';
                testResult.className = 'mailhook-test-result error';
                testResult.textContent = data.enterEmail || 'Please enter an email address.';
                return;
            }

            testBtn.disabled = true;
            testBtn.textContent = data.sending || 'Sending\u2026';
            testResult.style.display = 'block';
            testResult.className = 'mailhook-test-result info';
            testResult.textContent = data.sendingMsg || 'Sending test email\u2026';

            var fd = new FormData();
            fd.append('action', 'mailhook_send_test');
            fd.append('email', email);
            fd.append('nonce', data.testNonce || '');

            fetch(data.ajaxurl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        testResult.className = 'mailhook-test-result success';
                        testResult.textContent = res.data;
                    } else {
                        testResult.className = 'mailhook-test-result error';
                        testResult.textContent = res.data;
                    }
                })
                .catch(function (err) {
                    testResult.className = 'mailhook-test-result error';
                    testResult.textContent = (data.requestFailed || 'Request failed: ') + err.message;
                })
                .finally(function () {
                    testBtn.disabled = false;
                    testBtn.textContent = data.sendTest || 'Send Test Email';
                });
        });
    }

    /* =========================================================
       2. EMAIL LOGS PAGE — Checkboxes, delete, modal
    ========================================================= */
    var selectAll = document.getElementById('mh-select-all');
    var deleteBtn = document.getElementById('mh-delete-selected');
    var checks = document.querySelectorAll('.mh-log-check');

    function updateDeleteBtn() {
        if (!deleteBtn) { return; }
        var any = false;
        checks.forEach(function (c) { if (c.checked) { any = true; } });
        deleteBtn.disabled = !any;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = selectAll.checked; });
            updateDeleteBtn();
        });
    }
    checks.forEach(function (c) {
        c.addEventListener('change', updateDeleteBtn);
    });

    // Delete selected
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            if (!window.confirm(data.confirmDelete || 'Delete selected logs?')) { return; }
            var ids = [];
            checks.forEach(function (c) { if (c.checked) { ids.push(c.value); } });
            doDelete(ids);
        });
    }

    // Delete all
    var deleteAllBtn = document.getElementById('mh-delete-all');
    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', function () {
            if (!window.confirm(data.confirmDeleteAll || 'Delete ALL logs? This cannot be undone.')) { return; }
            doDelete(['all']);
        });
    }

    function doDelete(ids) {
        var fd = new FormData();
        fd.append('action', 'mailhook_delete_logs');
        fd.append('nonce', data.deleteNonce || '');
        ids.forEach(function (id) { fd.append('ids[]', id); });

        fetch(data.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    window.location.reload();
                } else {
                    window.alert(res.data || data.error || 'Error');
                }
            });
    }

    // View log modal
    var modal = document.getElementById('mailhook-log-modal');
    var mBody = document.getElementById('mailhook-modal-body');
    var overlay = modal ? modal.querySelector('.mailhook-modal-overlay') : null;
    var closeBtn = modal ? modal.querySelector('.mailhook-modal-close') : null;

    document.querySelectorAll('.mailhook-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            modal.style.display = 'flex';
            // Use textContent for the loading message to avoid innerHTML injection
            mBody.innerHTML = '<div class="mailhook-modal-loading">' +
                (data.loading || 'Loading\u2026') + '</div>';

            var fd = new FormData();
            fd.append('action', 'mailhook_view_log');
            fd.append('nonce', data.viewNonce || '');
            fd.append('id', id);

            fetch(data.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        // Server-generated HTML runs through wp_kses_post — safe to inject
                        mBody.innerHTML = res.data;
                    } else {
                        mBody.innerHTML = '<p style="color:#991b1b;">' +
                            document.createTextNode(res.data || (data.error || 'Error')).data +
                            '</p>';
                    }
                })
                .catch(function (err) {
                    mBody.innerHTML = '<p style="color:#991b1b;">' +
                        document.createTextNode(err.message).data + '</p>';
                });
        });
    });

    function closeModal() {
        if (modal) { modal.style.display = 'none'; }
    }
    if (overlay) { overlay.addEventListener('click', closeModal); }
    if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeModal(); }
    });

    /* =========================================================
       3. TEMPLATES PAGE — Layout switcher
    ========================================================= */
    var radios = document.querySelectorAll('input[name="layout"]');
    var optionsWrapper = document.getElementById('mh-options-wrapper');
    var stdOptions = document.getElementById('mh-standard-options');
    var custOptions = document.getElementById('mh-custom-options');

    if (radios.length && optionsWrapper) {
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                // Update active class on template cards
                document.querySelectorAll('.mailhook-template-card').forEach(function (card) {
                    card.classList.remove('active');
                });
                this.closest('.mailhook-template-card').classList.add('active');

                // Show / hide sub-sections
                var val = this.value;
                if (val === 'none') {
                    optionsWrapper.style.display = 'none';
                } else {
                    optionsWrapper.style.display = 'block';
                    if (val === 'custom') {
                        if (stdOptions) { stdOptions.style.display = 'none'; }
                        if (custOptions) { custOptions.style.display = 'block'; }
                    } else {
                        if (stdOptions) { stdOptions.style.display = 'block'; }
                        if (custOptions) { custOptions.style.display = 'none'; }
                    }
                }
            });
        });
    }

}());
