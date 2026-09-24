/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * The upload control in the article form, and the clean-up button on the settings
 * screen. A file uploads as soon as it is chosen; its entry is written into the row's
 * hidden inputs and becomes part of the article when the article is saved.
 */
((Joomla, document) => {
  'use strict';

  const text = (key, ...args) => {
    let value = Joomla.Text._(`PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_JS_${key}`);
    args.forEach((arg) => { value = value.replace('%s', arg); });
    return value;
  };

  const rowInput = (control, name) => {
    const row = control.closest('.subform-repeatable-group') || control.parentElement;
    return row ? row.querySelector(`[name$="[${name}]"]`) : null;
  };

  const setStatus = (control, message, type) => {
    const status = control.querySelector('.ppd-status');
    status.textContent = message;
    status.className = `ppd-status mt-2 small${type ? ` text-${type}` : ''}`;
  };

  const setProgress = (control, percent) => {
    const progress = control.querySelector('.ppd-progress');
    const bar = progress.querySelector('.progress-bar');

    progress.hidden = percent === null;
    bar.style.width = `${percent || 0}%`;
    progress.setAttribute('aria-valuenow', String(percent || 0));
  };

  const showFile = (control, uuid, filename, name) => {
    const link = control.querySelector('.ppd-link');

    link.href = control.dataset.previewUrl
      .replace('__UUID__', encodeURIComponent(uuid))
      .replace('__FILE__', encodeURIComponent(filename));
    link.querySelector('.ppd-name').textContent = name;
    link.hidden = false;
    control.querySelector('.ppd-empty').hidden = true;
  };

  const check = (control, file) => {
    const max = Number(control.dataset.maxBytes || 0);
    const allowed = (control.dataset.extensions || '').split(',').filter(Boolean);
    const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

    if (max > 0 && file.size > max) {
      return text('ERROR_TOO_LARGE', control.dataset.maxLabel);
    }

    if (!allowed.includes(extension)) {
      return text('ERROR_EXTENSION_SHORT', extension || '-');
    }

    return '';
  };

  const upload = (control, input) => {
    const file = input.files[0];
    const uuid = rowInput(control, 'uuid');
    const filename = rowInput(control, 'filename');
    const original = rowInput(control, 'original');
    const button = rowInput(control, 'button');
    const problem = check(control, file);

    if (!uuid || !filename) {
      return;
    }

    if (problem) {
      setStatus(control, problem, 'danger');
      input.value = '';
      return;
    }

    const data = new FormData();
    data.append('file', file);

    if (uuid.value && filename.value) {
      data.append('replace_uuid', uuid.value);
      data.append('replace_filename', filename.value);
    }

    // XMLHttpRequest rather than fetch, for the upload progress.
    const request = new XMLHttpRequest();
    control.dataset.busy = '1';
    input.disabled = true;
    setProgress(control, 0);
    setStatus(control, text('UPLOADING'), 'muted');

    request.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) {
        setProgress(control, Math.round((event.loaded / event.total) * 100));
      }
    });

    request.addEventListener('loadend', () => {
      let response = null;

      try {
        response = JSON.parse(request.responseText);
      } catch (e) {
        response = null;
      }

      const entry = response && response.success && Array.isArray(response.data) ? response.data[0] : null;

      delete control.dataset.busy;
      input.disabled = false;
      input.value = '';
      setProgress(control, null);

      if (!entry || !entry.uuid || !entry.filename) {
        const reason = (response && response.message) || request.statusText || '';
        setStatus(control, text('UPLOAD_FAILED', reason), 'danger');
        return;
      }

      uuid.value = entry.uuid;
      filename.value = entry.filename;

      if (original) {
        original.value = entry.original || file.name;
      }

      if (button && !button.value.trim()) {
        button.value = (entry.original || file.name).replace(/\.[^.]+$/, '');
      }

      showFile(control, entry.uuid, entry.filename, entry.original || file.name);
      setStatus(control, text('UPLOADED', entry.original || file.name), 'success');
    });

    // The form token goes in the header Session::checkToken() reads first, so it is
    // never part of a URL that could end up in a server log.
    request.open('POST', control.dataset.uploadUrl);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.setRequestHeader('X-CSRF-Token', control.dataset.token);
    request.send(data);
  };

  document.addEventListener('change', (event) => {
    const input = event.target;

    if (input.matches('.ppd-input') && input.files && input.files.length) {
      upload(input.closest('.ppd-control'), input);
    }
  });

  // An article saved while an upload is still running would be saved without it.
  document.addEventListener('submit', (event) => {
    if (document.querySelector('.ppd-control[data-busy]')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      Joomla.renderMessages({ warning: [text('PENDING_UPLOAD')] });
    }
  }, true);

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.ppd-cleanup-button');

    if (!button) {
      return;
    }

    event.preventDefault();

    // eslint-disable-next-line no-alert
    if (!window.confirm(Joomla.Text._('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_CONFIRM'))) {
      return;
    }

    const result = button.parentElement.querySelector('.ppd-cleanup-result');
    button.disabled = true;

    fetch(button.dataset.url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': button.dataset.token },
    })
      .then((response) => response.json())
      .then((response) => {
        const data = response && response.success && Array.isArray(response.data) ? response.data[0] : null;

        if (!data) {
          throw new Error((response && response.message) || '');
        }

        result.className = 'ppd-cleanup-result small mt-2 text-success';
        result.textContent = data.message;
        button.hidden = true;
      })
      .catch((error) => {
        result.className = 'ppd-cleanup-result small mt-2 text-danger';
        result.textContent = text('UPLOAD_FAILED', error.message);
        button.disabled = false;
      });
  });
})(window.Joomla, document);
