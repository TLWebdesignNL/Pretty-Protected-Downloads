<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the record of unsaved uploads: bound to their item, counted, forgotten once
 * saved or deleted, and gone once they expire.
 */

require_once __DIR__ . '/bootstrap.php';

use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PendingUploads;

$session = new TestSession();
$uploads = new PendingUploads($session, 86400);
$now     = 1_000_000;

group('Recording uploads');
check('a new session has none', $uploads->count($now) === 0);
$uploads->record('uuid-a', 'a-uuid-a.pdf', 'com_content.article', 7, 'files', $now);
$uploads->record('uuid-b', 'b-uuid-b.pdf', 'com_content.article', 7, 'files', $now);
check('each upload counts', $uploads->count($now) === 2);
$uploads->record('uuid-a', 'a-uuid-a.pdf', 'com_content.article', 7, 'files', $now);
check('the same upload counts once', $uploads->count($now) === 2);
check('it is bound to its item and field', $session->data[PendingUploads::SESSION_KEY]['uuid-a'] === [
    'filename' => 'a-uuid-a.pdf',
    'context'  => 'com_content.article',
    'item'     => 7,
    'field'    => 'files',
    'expires'  => $now + 86400,
]);

group('Which item an upload belongs to');
check('its own item', $uploads->has('uuid-b', 'b-uuid-b.pdf', 'com_content.article', 7, $now));
check('not another item', !$uploads->has('uuid-b', 'b-uuid-b.pdf', 'com_content.article', 8, $now));
check('not the same id in another context', !$uploads->has('uuid-b', 'b-uuid-b.pdf', 'com_contact.contact', 7, $now));
check('not another stored name under its uuid', !$uploads->has('uuid-b', 'other-uuid-b.pdf', 'com_content.article', 7, $now));
check('not an upload this session never made', !$uploads->has('uuid-x', 'x-uuid-x.pdf', 'com_content.article', 7, $now));
check('not an item that was never saved', !$uploads->has('uuid-b', 'b-uuid-b.pdf', 'com_content.article', 0, $now));
check('not once it expired', !$uploads->has('uuid-b', 'b-uuid-b.pdf', 'com_content.article', 7, $now + 86401));
check('not from another session', !(new PendingUploads(new TestSession()))->has('uuid-b', 'b-uuid-b.pdf', 'com_content.article', 7, $now));

check('their stored names are listed', $uploads->filenames($now) === ['a-uuid-a.pdf', 'b-uuid-b.pdf']);

group('Forgetting');
$uploads->forget(['a-uuid-a.pdf' => true, 'unrelated.pdf' => true], $now);
check('a saved or deleted upload no longer counts', $uploads->count($now) === 1);
check('the others stay', isset($session->data[PendingUploads::SESSION_KEY]['uuid-b']));
check('a forgotten upload belongs to nothing', !$uploads->has('uuid-a', 'a-uuid-a.pdf', 'com_content.article', 7, $now));

group('Expiry');
check('remembered up to its lifetime', $uploads->count($now + 86400) === 1);
check('not after it', $uploads->count($now + 86401) === 0);

group('A long session');
for ($i = 0; $i < 600; $i++) {
    $uploads->record('uuid-' . $i, $i . '-uuid-' . $i . '.pdf', 'com_content.article', 7, 'files', $now);
}
check('the session keeps at most 500 uploads', $uploads->count($now) === 500);
check('the newest survive', isset($session->data[PendingUploads::SESSION_KEY]['uuid-599']));

finish();
