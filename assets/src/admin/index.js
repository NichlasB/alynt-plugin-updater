document.addEventListener('DOMContentLoaded', () => {
  const checkLinks = document.querySelectorAll('.alynt-pu-check-update');
  const checkAllBtn = document.getElementById('alynt-pu-check-all');
  const checkAllStatus = document.getElementById('alynt-pu-check-all-status');
  const generateSecretBtn = document.getElementById('alynt-pu-generate-secret');
  const copyButtons = document.querySelectorAll('.alynt-pu-copy');

  checkLinks.forEach((link) => {
    link.addEventListener('click', async (event) => {
      event.preventDefault();

      const plugin = link.dataset.plugin;
      const nonce = link.dataset.nonce;
      const originalText = link.textContent;

      link.textContent = alyntPuAdmin.checking;
      link.style.pointerEvents = 'none';

      try {
        const response = await fetch(alyntPuAdmin.ajaxurl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'alynt_pu_check_single_update',
            plugin,
            nonce,
          }),
        });
        const data = await response.json();

        if (data.success) {
          if (data.data.update_available) {
            link.textContent = alyntPuAdmin.updateAvailable.replace('%s', data.data.new_version);
            link.style.color = '#d63638';
            link.style.fontWeight = 'bold';
          } else {
            link.textContent = alyntPuAdmin.upToDate;
            link.style.color = '#00a32a';
          }
        } else {
          link.textContent = alyntPuAdmin.checkFailed;
          link.style.color = '#d63638';
        }
      } catch (error) {
        link.textContent = alyntPuAdmin.checkFailed;
        link.style.color = '#d63638';
      }

      setTimeout(() => {
        link.textContent = originalText;
        link.style.color = '';
        link.style.fontWeight = '';
        link.style.pointerEvents = '';
      }, 5000);
    });
  });

  if (checkAllBtn) {
    checkAllBtn.addEventListener('click', async () => {
      checkAllBtn.disabled = true;
      if (checkAllStatus) {
        checkAllStatus.textContent = alyntPuAdmin.checkingAll;
      }

      try {
        const response = await fetch(alyntPuAdmin.ajaxurl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'alynt_pu_check_all_updates',
            nonce: alyntPuAdmin.checkAllNonce,
          }),
        });
        const data = await response.json();

        if (checkAllStatus) {
          checkAllStatus.textContent = data.success ? alyntPuAdmin.checkAllComplete : alyntPuAdmin.checkAllFailed;
        }
      } catch (error) {
        if (checkAllStatus) {
          checkAllStatus.textContent = alyntPuAdmin.checkAllFailed;
        }
      }

      checkAllBtn.disabled = false;
    });
  }

  if (generateSecretBtn) {
    generateSecretBtn.addEventListener('click', async () => {
      generateSecretBtn.disabled = true;

      try {
        const response = await fetch(alyntPuAdmin.ajaxurl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'alynt_pu_generate_secret',
            nonce: alyntPuAdmin.generateSecretNonce,
          }),
        });
        const data = await response.json();

        if (data.success) {
          const secretField = document.getElementById('alynt_pu_webhook_secret');
          if (secretField) {
            secretField.value = data.data.secret;
          }
        }
      } catch (error) {
        // No UI changes needed.
      }

      generateSecretBtn.disabled = false;
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
        button.textContent = alyntPuAdmin.copied || originalText;
        setTimeout(() => {
          button.textContent = originalText;
        }, 2000);
      } catch (error) {
        // Silent fail.
      }
    });
  });
});
