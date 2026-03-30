/**
 * MailHook Spam Protection
 * Prevents multiple form submissions from the same browser/IP
 * within a defined timeframe without human verification.
 */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof mailhookSpamVars === 'undefined') return;

    var blockDuration = parseInt(mailhookSpamVars.block_duration, 10) || (5 * 60 * 1000);
    var warningMsg    = mailhookSpamVars.message;
    var restUrl       = mailhookSpamVars.rest_url;
    var restNonce     = mailhookSpamVars.nonce;

    /**
     * Helper to show the verification modal
     */
    function showVerificationModal(originalTarget) {
        // Prevent multiple modals
        if (document.getElementById('mailhook-spam-modal')) return;

        var num1 = Math.floor(Math.random() * 10) + 1;
        var num2 = Math.floor(Math.random() * 10) + 1;
        var expected = num1 + num2;
        var requireMath = mailhookSpamVars.require_math === '1';

        var challengeHtml = requireMath ? `
            <div class="mailhook-challenge">
                <label>What is <strong>${num1} + ${num2}</strong>?</label>
                <input type="number" id="mailhook-challenge-answer" required>
            </div>
        ` : '';

        var btnText = requireMath ? 'Verify & Submit' : 'Yes, Submit Again';

        var modalHtml = `
            <div id="mailhook-spam-modal" class="mailhook-modal-overlay">
                <div class="mailhook-modal-content">
                    <div class="mailhook-modal-icon">🛡️</div>
                    <h3 class="mailhook-modal-title">Action Required</h3>
                    <p class="mailhook-modal-desc">${warningMsg}</p>
                    
                    ${challengeHtml}
                    
                    <div class="mailhook-modal-actions">
                        <button type="button" id="mailhook-verify-btn" class="mailhook-btn-primary">${btnText}</button>
                        <button type="button" id="mailhook-cancel-btn" class="mailhook-btn-secondary">Cancel</button>
                    </div>
                    <div id="mailhook-modal-error" style="display:none; color: #dc2626; margin-top: 10px; font-size: 14px;">Incorrect answer. Please try again.</div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var modal      = document.getElementById('mailhook-spam-modal');
        var verifyBtn  = document.getElementById('mailhook-verify-btn');
        var cancelBtn  = document.getElementById('mailhook-cancel-btn');
        var answerInput = document.getElementById('mailhook-challenge-answer');
        var errorMsg   = document.getElementById('mailhook-modal-error');

        // Cancel action
        cancelBtn.addEventListener('click', function() {
            modal.remove();
        });

        // Verify action
        verifyBtn.addEventListener('click', function() {
            var answer = 0;
            if (requireMath) {
                if (!answerInput) return;
                answer = parseInt(answerInput.value, 10);
                if (isNaN(answer)) return;
            }

            // Make REST API request to verify
            verifyBtn.innerText = requireMath ? 'Verifying...' : 'Submitting...';
            verifyBtn.disabled = true;

            fetch(restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    answer: answer,
                    expected: expected,
                    nonce: restNonce
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success || data.answer) { // fallback check if success flag missing
                    // Verified: Clear local storage restriction
                    localStorage.removeItem('mailhook_last_submit');
                    
                    // Remove modal
                    modal.remove();

                    // Re-trigger the form submission safely
                    if (originalTarget && typeof originalTarget.submit === 'function' && originalTarget.nodeName === 'FORM') {
                        // Mark it so it bypasses our listener
                        originalTarget.dataset.mailhookVerified = '1';
                        
                        // For elementor/CF7 forms, we might need to actually dispatch a submit event instead of form.submit()
                        // because they rely on JS capturing the submit event to do AJAX.
                        var evt = new Event('submit', { cancelable: true, bubbles: true });
                        originalTarget.dispatchEvent(evt);
                    }
                } else {
                    var errorDetails = data.message ? data.message : 'Unknown error';
                    throw new Error(errorDetails);
                }
            })
            .catch(err => {
                errorMsg.innerText = err.message ? err.message : 'Incorrect answer. Please try again.';
                errorMsg.style.display = 'block';
                verifyBtn.innerText = btnText;
                verifyBtn.disabled = false;
            });
        });
    }

    /**
     * Intercept form submissions document-wide
     */
    document.addEventListener('submit', function(e) {
        var target = e.target;
        
        // Skip if this form was already verified
        if (target.dataset.mailhookVerified === '1') {
            // Remove the flag for next time it's submitted
            target.removeAttribute('data-mailhook-verified');
            // Allow submission to proceed normally
            // Record the new submit time for the next 5 minutes
            localStorage.setItem('mailhook_last_submit', Date.now().toString());
            return;
        }

        var lastSubmitStr = localStorage.getItem('mailhook_last_submit');
        if (lastSubmitStr) {
            var lastSubmit = parseInt(lastSubmitStr, 10);
            var now = Date.now();

            if (now - lastSubmit < blockDuration) {
                // Rate limited: Stop submission
                e.preventDefault();
                e.stopImmediatePropagation();

                // Show verification modal
                showVerificationModal(target);
                return;
            }
        }

        // Allow first submission through
        localStorage.setItem('mailhook_last_submit', Date.now().toString());
        // Form submits normally...

    }, true); // use capturing phase to guarantee we intercept before 3rd party plugins!

});
