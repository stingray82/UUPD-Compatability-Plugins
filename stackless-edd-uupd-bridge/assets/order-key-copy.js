/* Per-license copy buttons for Stackless EDD order details. No keys are logged or transmitted. */
(function () {
  'use strict';
  function fallbackCopy(value) {
    var field = document.createElement('textarea');
    field.value = value;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.top = '-9999px';
    document.body.appendChild(field);
    field.select();
    var success = false;
    try { success = document.execCommand('copy'); } catch (_) {}
    field.remove();
    if (!success) return Promise.reject(new Error('Clipboard unavailable'));
    return Promise.resolve();
  }
  function copy(value) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value).catch(function () { return fallbackCopy(value); });
    }
    return fallbackCopy(value);
  }
  function enhance() {
    document.querySelectorAll('#stackless-edd-sl-order .stackless-sl-order-table tbody td:first-child a code').forEach(function (code) {
      var cell = code.closest('td');
      if (!cell || cell.querySelector('.stackless-sl-order-key-copy')) return;
      var key = (code.textContent || '').trim();
      if (!key) return;
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'button button-small stackless-sl-order-key-copy';
      button.textContent = 'Copy';
      button.setAttribute('aria-label', 'Copy licence key');
      var status = document.createElement('span');
      status.className = 'stackless-sl-order-key-copy-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      cell.appendChild(button);
      cell.appendChild(status);
      var resetTimer;
      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        button.disabled = true;
        copy(key).then(function () {
          button.textContent = 'Copied!';
          status.textContent = '';
        }).catch(function () {
          button.textContent = 'Copy';
          status.textContent = 'Copy failed. Select the key to copy manually.';
        }).finally(function () {
          button.disabled = false;
          clearTimeout(resetTimer);
          resetTimer = setTimeout(function () { button.textContent = 'Copy'; status.textContent = ''; }, 2500);
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance);
  else enhance();
})();
