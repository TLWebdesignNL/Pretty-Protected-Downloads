/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * The download buttons. When one is pressed it first asks for a fresh download token
 * and then sends the form with it, so the button also works on a page served from a
 * cache, whose rendered tokens belong to someone else's session. Without scripts the
 * form sends the token it was rendered with instead.
 */
((document) => {
  'use strict';

  document.addEventListener('submit', (event) => {
    const form = event.target;
    const slot = form instanceof HTMLFormElement ? form.querySelector('input[data-ppd-token-url]') : null;

    if (!slot) {
      return;
    }

    event.preventDefault();

    if (form.dataset.ppdBusy) {
      return;
    }

    form.dataset.ppdBusy = '1';

    const body = new FormData(form);
    body.delete('download_token');

    fetch(slot.dataset.ppdTokenUrl, { method: 'POST', body, credentials: 'same-origin' })
      .then((response) => response.json())
      .then((response) => {
        const data = response && response.success && Array.isArray(response.data) ? response.data[0] : null;
        slot.value = (data && data.token) || '';
      })
      .catch(() => {
        slot.value = '';
      })
      .finally(() => {
        delete form.dataset.ppdBusy;

        // Sent even without a token: the download then answers with the reason.
        form.submit();
      });
  });
})(document);
