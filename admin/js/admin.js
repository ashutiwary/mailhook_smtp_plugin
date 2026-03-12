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

    /* =========================================================
       6. ADDITIONAL CONNECTIONS (Backup Routing)
    ========================================================= */
    const addConnectionBtn = document.getElementById('mailhook-add-connection-btn');
    const connectionsContainer = document.getElementById('mailhook-connections-container');
    const backupSelect = document.getElementById('backup_connection_id');

    function updateBackupSelectOptions() {
        if (!backupSelect || !connectionsContainer) return;
        
        // Save current selection to restore if possible
        const currentVal = backupSelect.value;
        const rows = connectionsContainer.querySelectorAll('.mailhook-connection-row');
        
        // Reset options to just "None"
        backupSelect.innerHTML = '<option value="none">' + (data.noneDisabled || 'None (Disabled)') + '</option>';
        
        // Re-populate from current rows
        rows.forEach(function(row) {
            const id = row.getAttribute('data-id');
            const nameInput = row.querySelector('.mailhook-connection-name-input');
            const name = nameInput ? nameInput.value : 'New Connection';
            
            const option = document.createElement('option');
            option.value = id;
            option.textContent = name;
            backupSelect.appendChild(option);
        });

        // Try to restore previous selection
        backupSelect.value = currentVal;
        if (backupSelect.selectedIndex === -1) {
            backupSelect.value = 'none'; // Revert to none if previous selection was deleted
        }
    }

    /* Backup Toggle Interaction */
    const backupToggle = document.getElementById('backup_enabled');
    const backupSelectorWrap = document.getElementById('backup-connection-selector-wrap');
    if (backupToggle && backupSelectorWrap) {
        backupToggle.addEventListener('change', function() {
            backupSelectorWrap.style.display = this.checked ? 'flex' : 'none';
        });
    }

    if (addConnectionBtn && connectionsContainer) {
        addConnectionBtn.addEventListener('click', function() {
            const rows = connectionsContainer.querySelectorAll('.mailhook-connection-row');
            const newIndex = rows.length > 0 ? parseInt(rows[rows.length - 1].getAttribute('data-index')) + 1 : 0;
            const newId = 'mh_conn_' + Math.random().toString(36).substr(2, 9);
            const baseName = 'additional_connections[' + newIndex + ']';
            
            // Remove the "No connections yet" message if it exists
            const noConnMsg = connectionsContainer.querySelector('.mailhook-no-connections');
            if (noConnMsg) noConnMsg.remove();

            const template = `
                <div class="mailhook-connection-row" data-index="${newIndex}" data-id="${newId}">
                    <div class="mailhook-connection-header">
                        <div class="mailhook-connection-header-left">
                            <span class="mailhook-connection-toggle-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </span>
                            <h3 class="mailhook-connection-title">New Connection</h3>
                        </div>
                        <div class="mailhook-connection-header-actions">
                            <button type="button" class="mailhook-remove-connection-btn" title="Remove Connection">Remove</button>
                        </div>
                    </div>

                    <div class="mailhook-connection-body">
                        <input type="hidden" name="${baseName}[id]" value="${newId}">
                        <div class="mailhook-connection-grid">
                            <div class="mailhook-connection-column">
                                <div class="mailhook-field-group">
                                    <label>Connection Name</label>
                                    <input type="text" name="${baseName}[name]" value="New Connection" class="mailhook-connection-name-input" required />
                                    <p class="description">A friendly name to identify this connection.</p>
                                </div>
                                <div class="mailhook-field-group">
                                    <label>SMTP Host</label>
                                    <input type="text" name="${baseName}[smtp_host]" value="" placeholder="smtp.example.com" />
                                </div>
                                <div class="mailhook-field-row">
                                    <div class="mailhook-field-group">
                                        <label>SMTP Port</label>
                                        <input type="number" name="${baseName}[smtp_port]" value="587" />
                                    </div>
                                    <div class="mailhook-field-group">
                                        <label>Encryption</label>
                                        <select name="${baseName}[smtp_encryption]">
                                            <option value="none">None</option>
                                            <option value="ssl">SSL</option>
                                            <option value="tls" selected>TLS</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mailhook-field-group">
                                    <label>SMTP Authentication</label>
                                    <div class="mailhook-radio-group">
                                        <label><input type="radio" name="${baseName}[smtp_auth]" value="1" checked /> Yes</label>
                                        <label><input type="radio" name="${baseName}[smtp_auth]" value="0" /> No</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mailhook-connection-column">
                                <div class="mailhook-field-group">
                                    <label>SMTP Username</label>
                                    <input type="text" name="${baseName}[smtp_username]" value="" autocomplete="off" />
                                </div>
                                <div class="mailhook-field-group">
                                    <label>SMTP Password</label>
                                    <input type="password" name="${baseName}[smtp_password]" value="" autocomplete="new-password" />
                                </div>
                                <div class="mailhook-field-group">
                                    <label>From Email Override</label>
                                    <input type="email" name="${baseName}[from_email]" value="" placeholder="noreply@example.com" />
                                    <p class="description">Leave blank to use primary.</p>
                                </div>
                                <div class="mailhook-field-group">
                                    <label>From Name Override</label>
                                    <input type="text" name="${baseName}[from_name]" value="" placeholder="My Site" />
                                    <p class="description">Leave blank to use primary.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Append template
            connectionsContainer.insertAdjacentHTML('beforeend', template);
            updateBackupSelectOptions();
        });

        // Event delegation for Remove Buttons, Collapse Toggle, and Name Input changes
        connectionsContainer.addEventListener('click', function(e) {
            // Remove Connection logic
            if (e.target.classList.contains('mailhook-remove-connection-btn')) {
                if (confirm(data.confirmDeleteConn || 'Are you sure you want to remove this connection?')) {
                    e.target.closest('.mailhook-connection-row').remove();
                    updateBackupSelectOptions();
                    
                    if (connectionsContainer.querySelectorAll('.mailhook-connection-row').length === 0) {
                        connectionsContainer.innerHTML = '<p class="mailhook-no-connections description">No additional connections configured yet.</p>';
                    }
                }
                return;
            }

            // Collapse Toggle logic
            const header = e.target.closest('.mailhook-connection-header');
            if (header && !e.target.classList.contains('mailhook-remove-connection-btn')) {
                const row = header.closest('.mailhook-connection-row');
                row.classList.toggle('collapsed');
            }
        });
        
        // Listen for name changes to update titles and dropdown immediately
        connectionsContainer.addEventListener('input', function(e) {
            if (e.target.classList.contains('mailhook-connection-name-input')) {
                const row = e.target.closest('.mailhook-connection-row');
                const title = row.querySelector('.mailhook-connection-title');
                if (title) {
                    title.textContent = e.target.value || 'New Connection';
                }
                updateBackupSelectOptions();
            }
        });
    }

}());
