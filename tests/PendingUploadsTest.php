<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the record of unsaved uploads: counted, forgotten once saved or deleted,
 * and gone once they expire.
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

group('Forgetting');
$uploads->forget(['a-uuid-a.pdf' => true, 'unrelated.pdf' => true], $now);
check('a saved or deleted upload no longer counts', $uploads->count($now) === 1);
check('the others stay', isset($session->data[PendingUploads::SESSION_KEY]['uuid-b']));

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
