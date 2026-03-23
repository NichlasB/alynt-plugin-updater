document.addEventListener('DOMContentLoaded', () => {
  const checkLinks = document.querySelectorAll('.alynt-pu-check-update');
  const checkAllBtn = document.getElementById('alynt-pu-check-all');
  const checkAllStatus = document.getElementById('alynt-pu-check-all-status');
  const generateSecretBtn = document.getElementById('alynt-pu-generate-secret');
  const copyButtons = document.querySelectorAll('.alynt-pu-copy');

  function setStatusMessage(target, message) {
    if (!target) {
      return;
    }

    target.textContent = '';
    setTimeout(() => {
      target.textContent = message;
    }, 50);
  }

  /**
   * Announce a message to screen readers via the live region.
   *
   * @param {string} message The message to announce.
   */
  function announce(message) {
    const el = document.getElementById('alynt-pu-screen-reader-feedback');
    setStatusMessage(el, message);
  }

  /**
   * Show a brief visible inline notice next to an element.
   *
   * @param {HTMLElement} anchor  Element to insert notice after.
   * @param {string}      message Notice text.
   * @param {string}      type    'success' or 'error'.
   */
  function showInlineNotice(anchor, message, type) {
    const existing = anchor.parentNode.querySelector('.alynt-pu-inline-notice');
    if (existing) {
      existing.remove();
    }
    const span = document.createElement('span');
    span.className = 'alynt-pu-inline-notice';
    span.style.marginLeft = '8px';
    span.style.fontWeight = '600';
    span.style.color = type === 'success' ? '#00a32a' : '#d63638';
    span.textContent = message;
    anchor.parentNode.insertBefore(span, anchor.nextSibling);
    setTimeout(() => span.remove(), 5000);
  }

  /**
   * Send an authenticated WordPress AJAX request.
   *
   * @param {string} action  AJAX action name.
   * @param {Object} payload Additional request payload.
   * @returns {Promise<Object>} Parsed JSON response.
   */
  async function postAjax(action, payload = {}) {
    const response = await fetch(alyntPuAdmin.ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action,
        ...payload,
      }),
    });

    return response.json();
  }

  /**
   * Get an error message that prefers network-specific feedback.
   *
   * @param {string} fallbackMessage Message used when online.
   * @returns {string} User-facing message.
   */
  function getNetworkAwareMessage(fallbackMessage) {
    if (!navigator.onLine) {
      return alyntPuAdmin.networkError;
    }

    return fallbackMessage;
  }

  checkLinks.forEach((link) => {
    link.addEventListener('click', async (event) => {
      event.preventDefault();

      if (link.disabled || link.getAttribute('aria-disabled') === 'true') {
        return;
      }

      const plugin = link.dataset.plugin;
      const nonce = link.dataset.nonce;
      const originalText = link.textContent;
      const statusTarget = link.parentNode.querySelector('.alynt-pu-check-update-status');

      link.disabled = true;
      link.textContent = alyntPuAdmin.checking;
      link.setAttribute('aria-disabled', 'true');
      link.setAttribute('aria-busy', 'true');
      setStatusMessage(statusTarget, alyntPuAdmin.checking);

      try {
        const data = await postAjax('alynt_pu_check_single_update', {
          plugin,
          nonce,
        });

        if (data.success) {
          if (data.data.update_available) {
            const message = alyntPuAdmin.updateAvailable.replace('%s', data.data.new_version);
            link.textContent = message;
            link.style.color = '#d63638';
            link.style.fontWeight = 'bold';
            setStatusMessage(statusTarget, message);
            announce(message);
          } else {
            link.textContent = alyntPuAdmin.upToDate;
            link.style.color = '#00a32a';
            setStatusMessage(statusTarget, alyntPuAdmin.upToDate);
            announce(alyntPuAdmin.upToDate);
          }
        } else {
          const msg = (data.data && data.data.message) || alyntPuAdmin.checkFailed;
          link.textContent = alyntPuAdmin.checkFailed;
          link.style.color = '#d63638';
          link.setAttribute('title', msg);
          setStatusMessage(statusTarget, msg);
          announce(msg);
        }
      } catch (error) {
        const msg = getNetworkAwareMessage(alyntPuAdmin.checkFailed);
        link.textContent = alyntPuAdmin.checkFailed;
        link.style.color = '#d63638';
        link.setAttribute('title', msg);
        setStatusMessage(statusTarget, msg);
        announce(msg);
      }

      setTimeout(() => {
        link.disabled = false;
        link.textContent = originalText;
        link.style.color = '';
        link.style.fontWeight = '';
        link.removeAttribute('aria-disabled');
        link.removeAttribute('aria-busy');
        link.removeAttribute('title');
        setStatusMessage(statusTarget, '');
      }, 5000);
    });
  });

  if (checkAllBtn) {
    checkAllBtn.addEventListener('click', async () => {
      checkAllBtn.disabled = true;
      checkAllBtn.setAttribute('aria-disabled', 'true');
      if (checkAllStatus) {
        checkAllStatus.setAttribute('aria-busy', 'true');
        checkAllStatus.textContent = alyntPuAdmin.checkingAll;
      }

      try {
        const data = await postAjax('alynt_pu_check_all_updates', {
          nonce: alyntPuAdmin.checkAllNonce,
        });

        if (checkAllStatus) {
          checkAllStatus.removeAttribute('aria-busy');
          if (data.success && data.data && data.data.results) {
            const results = Object.values(data.data.results);
            const total = results.length;
            const updates = results.filter((r) => r.update_available).length;
            const errors = results.filter((r) => r.error).length;
            const summary = alyntPuAdmin.checkAllSummary
              .replace('%1$d', total)
              .replace('%2$d', updates)
              .replace('%3$s', updates === 1 ? alyntPuAdmin.singleUpdateLabel : alyntPuAdmin.multipleUpdatesLabel)
              .replace('%4$d', errors)
              .replace('%5$s', errors === 1 ? alyntPuAdmin.singleErrorLabel : alyntPuAdmin.multipleErrorsLabel);
            checkAllStatus.textContent = summary;
            announce(summary);
          } else if (data.success) {
            checkAllStatus.textContent = alyntPuAdmin.checkAllComplete;
            announce(alyntPuAdmin.checkAllComplete);
          } else {
            const msg = (data.data && data.data.message) || alyntPuAdmin.checkAllFailed;
            checkAllStatus.textContent = msg;
            announce(msg);
          }
        }
      } catch (error) {
        if (checkAllStatus) {
          checkAllStatus.removeAttribute('aria-busy');
          const msg = getNetworkAwareMessage(alyntPuAdmin.checkAllFailed);
          checkAllStatus.textContent = msg;
          announce(msg);
        }
      }

      checkAllBtn.disabled = false;
      checkAllBtn.removeAttribute('aria-disabled');
    });
  }

  if (generateSecretBtn) {
    generateSecretBtn.addEventListener('click', async () => {
      const confirmMsg = alyntPuAdmin.confirmGenerateSecret;
      if (!confirm(confirmMsg)) {
        return;
      }

      const originalText = generateSecretBtn.textContent;
      generateSecretBtn.disabled = true;
      generateSecretBtn.setAttribute('aria-disabled', 'true');
      generateSecretBtn.setAttribute('aria-busy', 'true');
      generateSecretBtn.textContent = alyntPuAdmin.generatingSecret;

      try {
        const data = await postAjax('alynt_pu_generate_secret', {
          nonce: alyntPuAdmin.generateSecretNonce,
        });

        if (data.success) {
          const secretField = document.getElementById('alynt_pu_webhook_secret');
          if (secretField) {
            secretField.value = data.data.secret;
          }
          showInlineNotice(generateSecretBtn, alyntPuAdmin.secretGenerated, 'success');
          announce(alyntPuAdmin.secretGenerated);
        } else {
          const msg = (data.data && data.data.message) || alyntPuAdmin.secretFailed;
          showInlineNotice(generateSecretBtn, msg, 'error');
          announce(msg);
        }
      } catch (error) {
        const msg = getNetworkAwareMessage(alyntPuAdmin.secretFailed);
        showInlineNotice(generateSecretBtn, msg, 'error');
        announce(msg);
      }

      generateSecretBtn.disabled = false;
      generateSecretBtn.removeAttribute('aria-disabled');
      generateSecretBtn.removeAttribute('aria-busy');
      generateSecretBtn.textContent = originalText;
    });
  }

  copyButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      const targetId = button.dataset.target;
      const target = document.getElementById(targetId);
      if (!target) {
        return;
      }

      try {
        await navigator.clipboard.writeText(target.value);
        const originalText = button.textContent;
        button.textContent = alyntPuAdmin.copied;
        announce(alyntPuAdmin.copied);
        setTimeout(() => {
          button.textContent = originalText;
        }, 2000);
      } catch (error) {
        const msg = alyntPuAdmin.copyFailed;
        showInlineNotice(button, msg, 'error');
        announce(msg);
      }
    });
  });
});
