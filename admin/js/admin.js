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

    /* ── Toast helper ── */
    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'mh-toast mh-toast-' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);
        // Animate in
        requestAnimationFrame(function () { toast.classList.add('mh-toast-visible'); });
        // Auto-remove after 3.5 s
        setTimeout(function () {
            toast.classList.remove('mh-toast-visible');
            setTimeout(function () { toast.parentNode && toast.parentNode.removeChild(toast); }, 400);
        }, 3500);
    }

    /* ── Resend failed email ── */
    document.querySelectorAll('.mailhook-resend-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id    = btn.getAttribute('data-id');
            var nonce = btn.getAttribute('data-nonce');

            btn.disabled    = true;
            btn.textContent = 'Sending\u2026';

            var fd = new FormData();
            fd.append('action', 'mailhook_resend_email');
            fd.append('nonce',  nonce);
            fd.append('id',     id);

            fetch(data.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        showToast(res.data.message || 'Email resent successfully!', 'success');
                        // Reload after short delay so the new log entry is visible
                        setTimeout(function () { window.location.reload(); }, 1800);
                    } else {
                        showToast(res.data || 'Failed to resend.', 'error');
                        btn.disabled    = false;
                        btn.textContent = 'Resend';
                    }
                })
                .catch(function (err) {
                    showToast('Request error: ' + err.message, 'error');
                    btn.disabled    = false;
                    btn.textContent = 'Resend';
                });
        });
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

    /* =========================================================
       4. SETTINGS TABS & DYNAMIC ALERTS EMAILS
    ========================================================= */
    const tabs = document.querySelectorAll('.mailhook-nav-tabs .nav-tab');
    const tabContents = document.querySelectorAll('.mailhook-tab-content');
    const testCardContainer = document.getElementById('mailhook-test-card-container');

    if (tabs.length > 0) {
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href').substring(1);
                
                // Update active tab link
                tabs.forEach(t => t.classList.remove('nav-tab-active'));
                this.classList.add('nav-tab-active');
                
                // Show target content, hide others
                tabContents.forEach(content => {
                    content.style.display = content.id === targetId ? 'block' : 'none';
                });

                // Only show Send Test Email card on the General tab
                if (testCardContainer) {
                    testCardContainer.style.display = targetId === 'tab-general' ? 'block' : 'none';
                }

                // Update hidden input for form submission
                const activeTabInput = document.getElementById('mailhook-active-tab');
                if (activeTabInput) {
                    activeTabInput.value = this.getAttribute('data-tab');
                }

                // Update URL parameter without reloading (for shareability and refresh state)
                if (window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.set('tab', this.getAttribute('data-tab'));
                    window.history.replaceState({}, '', url);
                }
            });
        });

        // Initialize active tab from URL if present
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) {
            const tabToActivate = document.querySelector(`.mailhook-nav-tabs .nav-tab[data-tab="${activeTab}"]`);
            if (tabToActivate) {
                tabToActivate.click();
            }
        }
    }

    /* Dynamic Email Inputs for Alerts */
    const addEmailBtn = document.getElementById('mailhook-add-email-btn');
    const emailsContainer = document.getElementById('mailhook-alert-emails-container');
    const maxEmails = 3;

    function updateEmailButtons() {
        if (!emailsContainer) return;
        const rows = emailsContainer.querySelectorAll('.mailhook-alert-email-row');
        
        // Hide "Add" button if max reached
        if (addEmailBtn) {
            addEmailBtn.style.display = rows.length >= maxEmails ? 'none' : 'inline-block';
        }

        // Show/hide remove buttons depending on count
        rows.forEach((row, index) => {
            let removeBtn = row.querySelector('.mailhook-remove-email-btn');
            if (rows.length === 1) {
                if(removeBtn) removeBtn.remove();
            } else {
                if (!removeBtn) {
                    removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'button mailhook-remove-email-btn';
                    removeBtn.title = 'Remove this email';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.addEventListener('click', function() {
                        row.remove();
                        updateEmailButtons();
                    });
                    row.appendChild(removeBtn);
                }
            }
        });
    }

    if (addEmailBtn && emailsContainer) {
        addEmailBtn.addEventListener('click', function() {
            const rows = emailsContainer.querySelectorAll('.mailhook-alert-email-row');
            if (rows.length >= maxEmails) return;

            const newRow = document.createElement('div');
            newRow.className = 'mailhook-alert-email-row';
            newRow.style.cssText = 'margin-bottom: 10px; display: flex; align-items: center; gap: 10px;';
            newRow.innerHTML = '<input type="email" name="alert_emails[]" value="" class="regular-text" placeholder="Enter email address" />';
            
            emailsContainer.appendChild(newRow);
            updateEmailButtons();
        });

        // Attach events to existing remove buttons
        emailsContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('mailhook-remove-email-btn')) {
                e.target.closest('.mailhook-alert-email-row').remove();
                updateEmailButtons();
            }
        });

        updateEmailButtons();
    }

    /* Test Alert Button */
    const testAlertBtn = document.getElementById('mailhook-test-alert');
    if (testAlertBtn) {
        testAlertBtn.addEventListener('click', function() {
            const resultSpan = document.querySelector('.mailhook-test-alert-result');
            
            testAlertBtn.disabled = true;
            testAlertBtn.textContent = 'Sending...';
            if(resultSpan) {
                resultSpan.textContent = '';
                resultSpan.className = 'mailhook-test-alert-result';
            }

            const fd = new FormData();
            fd.append('action', 'mailhook_send_test_alert');
            fd.append('nonce', data.testNonce || '');

            fetch(data.ajaxurl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if(resultSpan) {
                    resultSpan.textContent = res.data;
                    resultSpan.classList.add(res.success ? 'success' : 'error');
                } else {
                    showToast(res.data, res.success ? 'success' : 'error');
                }
            })
            .catch(function(err) {
                 if(resultSpan) {
                    resultSpan.textContent = 'Request failed: ' + err.message;
                    resultSpan.classList.add('error');
                }
            })
            .finally(function() {
                testAlertBtn.disabled = false;
                testAlertBtn.textContent = 'Test Alert';
            });
        });
    }

}());
