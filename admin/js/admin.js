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

        // Also update all Smart Routing rule connection selects
        updateRoutingConnectionSelects();
    }

    function updateRoutingConnectionSelects() {
        const ruleSelects = document.querySelectorAll('.mailhook-rule-selector select');
        if (!ruleSelects.length) return;

        const connections = [];
        connectionsContainer.querySelectorAll('.mailhook-connection-row').forEach(row => {
            connections.push({
                id: row.getAttribute('data-id'),
                name: row.querySelector('.mailhook-connection-name-input').value || 'New Connection'
            });
        });

        ruleSelects.forEach(select => {
            const currentVal = select.value;
            select.innerHTML = '<option value="">' + (data.selectConn || '-- Select a Connection --') + '</option>';
            connections.forEach(conn => {
                const opt = document.createElement('option');
                opt.value = conn.id;
                opt.textContent = conn.name;
                select.appendChild(opt);
            });
            select.value = currentVal;
        });
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

            // Show routing container if hidden
            const routingContainer = document.getElementById('mailhook-routing-rules-container');
            const noAdditionalNotice = document.getElementById('mailhook-no-additional-notice');
            if (routingContainer) routingContainer.style.display = 'block';
            if (noAdditionalNotice) noAdditionalNotice.style.display = 'none';
        });

        // Event delegation for Remove Buttons, Collapse Toggle, and Name Input changes
        connectionsContainer.addEventListener('click', function(e) {
            // Remove Connection logic
            if (e.target.classList.contains('mailhook-remove-connection-btn')) {
                if (confirm(data.confirmDeleteConn || 'Are you sure you want to remove this connection?')) {
                    const row = e.target.closest('.mailhook-connection-row');
                    const id = row.getAttribute('data-id');
                    row.remove();
                    updateBackupSelectOptions();
                    
                    if (connectionsContainer.querySelectorAll('.mailhook-connection-row').length === 0) {
                        connectionsContainer.innerHTML = '<p class="mailhook-no-connections description">No additional connections configured yet.</p>';
                        const routingContainer = document.getElementById('mailhook-routing-rules-container');
                        const noAdditionalNotice = document.getElementById('mailhook-no-additional-notice');
                        if (routingContainer) routingContainer.style.display = 'none';
                        if (noAdditionalNotice) noAdditionalNotice.style.display = 'block';
                    }

                    // Remove rules that use this connection
                    document.querySelectorAll('.mailhook-rule-row').forEach(rule => {
                        const select = rule.querySelector('.mailhook-rule-selector select');
                        if (select && select.value === id) {
                            rule.remove();
                            reindexRules();
                        }
                    });
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
                const row = e.target.closest('.mailhook-connection-name-input').closest('.mailhook-connection-row');
                const title = row.querySelector('.mailhook-connection-title');
                if (title) {
                    title.textContent = e.target.value || 'New Connection';
                }
                updateBackupSelectOptions();
            }
        });
    }

    /* =========================================================
       7. SMART ROUTING RULE BUILDER
    ========================================================= */
    const addRuleBtn = document.getElementById('mailhook-add-rule-btn');
    const rulesContainer = document.querySelector('.mailhook-rules-list');

    if (addRuleBtn && rulesContainer) {
        addRuleBtn.addEventListener('click', function() {
            const ruleRows = rulesContainer.querySelectorAll('.mailhook-rule-row');
            const newRuleIdx = ruleRows.length;
            
            // Get current connections for the select
            const connections = [];
            connectionsContainer.querySelectorAll('.mailhook-connection-row').forEach(row => {
                connections.push({
                    id: row.getAttribute('data-id'),
                    name: row.querySelector('.mailhook-connection-name-input').value || 'New Connection'
                });
            });

            const connOptions = connections.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
            const baseName = `routing_rules[${newRuleIdx}]`;

            const ruleTemplate = `
                <div class="mailhook-rule-row" data-index="${newRuleIdx}">
                    <div class="mailhook-rule-header">
                        <div class="mailhook-rule-selector">
                            <span>Send with</span>
                            <select name="${baseName}[connection_id]" required>
                                <option value="">-- Select a Connection --</option>
                                ${connOptions}
                            </select>
                            <span>if the following conditions are met...</span>
                        </div>
                        <div class="mailhook-rule-actions">
                            <button type="button" class="mailhook-remove-rule-btn" title="Remove Rule">&times;</button>
                        </div>
                    </div>
                    <div class="mailhook-rule-body">
                        <div class="mailhook-groups-container">
                            <!-- Group 0 -->
                            <div class="mailhook-group-row" data-index="0">
                                <div class="mailhook-group-inner">
                                    <div class="mailhook-conditions-container">
                                        <!-- Condition 0 -->
                                        <div class="mailhook-condition-row" data-index="0">
                                            <select name="${baseName}[groups][0][conditions][0][field]">
                                                <option value="subject" selected>Subject</option>
                                                <option value="to">To</option>
                                                <option value="from">From</option>
                                                <option value="body">Body</option>
                                            </select>
                                            <select name="${baseName}[groups][0][conditions][0][operator]">
                                                <option value="contains" selected>Contains</option>
                                                <option value="not_contains">Does not contain</option>
                                                <option value="equals">Is equal to</option>
                                                <option value="starts_with">Starts with</option>
                                                <option value="ends_with">Ends with</option>
                                            </select>
                                            <input type="text" name="${baseName}[groups][0][conditions][0][value]" value="" placeholder="Value..." />
                                            <div class="mailhook-condition-actions">
                                                <button type="button" class="button mailhook-add-condition-btn">And</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mailhook-group-actions">
                                        <button type="button" class="mailhook-remove-group-btn" title="Remove Group">&times;</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="button mailhook-add-group-btn">Add New Group</button>
                    </div>
                </div>
            `;
            rulesContainer.insertAdjacentHTML('beforeend', ruleTemplate);
        });

        // Event delegation for all routing UI
        rulesContainer.addEventListener('click', function(e) {
            const target = e.target;

            // 1. Remove Rule
            if (target.classList.contains('mailhook-remove-rule-btn')) {
                if (confirm(data.confirmDeleteRule || 'Are you sure you want to remove this routing rule?')) {
                    target.closest('.mailhook-rule-row').remove();
                    reindexRules();
                }
                return;
            }

            // 2. Add Group
            if (target.classList.contains('mailhook-add-group-btn')) {
                const ruleRow = target.closest('.mailhook-rule-row');
                const groupsContainer = ruleRow.querySelector('.mailhook-groups-container');
                const ruleIdx = ruleRow.getAttribute('data-index');
                const newGroupIdx = groupsContainer.querySelectorAll('.mailhook-group-row').length;
                const baseName = `routing_rules[${ruleIdx}][groups][${newGroupIdx}]`;

                const groupTemplate = `
                    <div class="mailhook-group-row" data-index="${newGroupIdx}">
                        <div class="mailhook-group-separator"><span>or</span></div>
                        <div class="mailhook-group-inner">
                            <div class="mailhook-conditions-container">
                                <div class="mailhook-condition-row" data-index="0">
                                    <select name="${baseName}[conditions][0][field]">
                                        <option value="subject" selected>Subject</option>
                                        <option value="to">To</option>
                                        <option value="from">From</option>
                                        <option value="body">Body</option>
                                    </select>
                                    <select name="${baseName}[conditions][0][operator]">
                                        <option value="contains" selected>Contains</option>
                                        <option value="not_contains">Does not contain</option>
                                        <option value="equals">Is equal to</option>
                                        <option value="starts_with">Starts with</option>
                                        <option value="ends_with">Ends with</option>
                                    </select>
                                    <input type="text" name="${baseName}[conditions][0][value]" value="" placeholder="Value..." />
                                    <div class="mailhook-condition-actions">
                                        <button type="button" class="button mailhook-add-condition-btn">And</button>
                                    </div>
                                </div>
                            </div>
                            <div class="mailhook-group-actions">
                                <button type="button" class="mailhook-remove-group-btn" title="Remove Group">&times;</button>
                            </div>
                        </div>
                    </div>
                `;
                groupsContainer.insertAdjacentHTML('beforeend', groupTemplate);
                return;
            }

            // 3. Remove Group
            if (target.classList.contains('mailhook-remove-group-btn')) {
                const groupRow = target.closest('.mailhook-group-row');
                const groupsContainer = groupRow.closest('.mailhook-groups-container');
                const ruleRow = groupRow.closest('.mailhook-rule-row');
                
                groupRow.remove();
                
                // If last group, remove rule? Or just re-index? 
                // Let's re-index. If no groups, rule is effectively empty.
                if (groupsContainer.querySelectorAll('.mailhook-group-row').length === 0) {
                    ruleRow.remove();
                }
                reindexRules();
                return;
            }

            // 4. Add Condition
            if (target.classList.contains('mailhook-add-condition-btn')) {
                const conditionsContainer = target.closest('.mailhook-conditions-container');
                const groupRow = target.closest('.mailhook-group-row');
                const ruleRow = target.closest('.mailhook-rule-row');
                
                const ruleIdx = ruleRow.getAttribute('data-index');
                const groupIdx = groupRow.getAttribute('data-index');
                const newCondIdx = conditionsContainer.querySelectorAll('.mailhook-condition-row').length;
                
                const baseName = `routing_rules[${ruleIdx}][groups][${groupIdx}][conditions][${newCondIdx}]`;

                const condTemplate = `
                    <div class="mailhook-condition-row" data-index="${newCondIdx}">
                        <select name="${baseName}[field]">
                            <option value="subject" selected>Subject</option>
                            <option value="to">To</option>
                            <option value="from">From</option>
                            <option value="body">Body</option>
                        </select>
                        <select name="${baseName}[operator]">
                            <option value="contains" selected>Contains</option>
                            <option value="not_contains">Does not contain</option>
                            <option value="equals">Is equal to</option>
                            <option value="starts_with">Starts with</option>
                            <option value="ends_with">Ends with</option>
                        </select>
                        <input type="text" name="${baseName}[value]" value="" placeholder="Value..." />
                        <div class="mailhook-condition-actions">
                            <button type="button" class="button mailhook-add-condition-btn">And</button>
                            <button type="button" class="mailhook-remove-condition-btn" title="Remove Condition">&times;</button>
                        </div>
                    </div>
                `;
                conditionsContainer.insertAdjacentHTML('beforeend', condTemplate);
                return;
            }

            // 5. Remove Condition
            if (target.classList.contains('mailhook-remove-condition-btn')) {
                const row = target.closest('.mailhook-condition-row');
                const container = row.closest('.mailhook-conditions-container');
                row.remove();
                reindexRules();
                return;
            }
        });
    }

    function reindexRules() {
        const ruleRows = document.querySelectorAll('.mailhook-rule-row');
        ruleRows.forEach((ruleRow, ruleIdx) => {
            ruleRow.setAttribute('data-index', ruleIdx);
            
            // Update connection select name
            const connSelect = ruleRow.querySelector('.mailhook-rule-selector select');
            if (connSelect) {
                connSelect.name = `routing_rules[${ruleIdx}][connection_id]`;
            }

            // Update groups
            const groupRows = ruleRow.querySelectorAll('.mailhook-group-row');
            groupRows.forEach((groupRow, groupIdx) => {
                groupRow.setAttribute('data-index', groupIdx);
                
                // Update separator (none for first group)
                let separator = groupRow.querySelector('.mailhook-group-separator');
                if (groupIdx === 0) {
                    if (separator) separator.remove();
                } else {
                    if (!separator) {
                        separator = document.createElement('div');
                        separator.className = 'mailhook-group-separator';
                        separator.innerHTML = '<span>or</span>';
                        groupRow.insertBefore(separator, groupRow.firstChild);
                    }
                }

                // Update conditions
                const condRows = groupRow.querySelectorAll('.mailhook-condition-row');
                condRows.forEach((condRow, condIdx) => {
                    condRow.setAttribute('data-index', condIdx);
                    
                    const inputs = condRow.querySelectorAll('select, input');
                    inputs.forEach(input => {
                        const name = input.name;
                        if (name) {
                            const field = name.split(']').pop().replace('[', ''); // matches [field], [operator], [value]
                            input.name = `routing_rules[${ruleIdx}][groups][${groupIdx}][conditions][${condIdx}][${field}]`;
                        }
                    });

                    // Ensure remove button exists for all but first if count > 1 (optional logic)
                });
            });
        });
    }


}());
