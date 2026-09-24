<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the download tokens: bound to one file, still valid on a second click, and
 * gone once they expire.
 */

require_once __DIR__ . '/bootstrap.php';

use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\DownloadTokens;

$session = new TestSession();
$tokens  = new DownloadTokens($session, 900);
$now     = 1_000_000;
$token   = $tokens->issue('uuid-a', 'com_content.article', 7, 'files', $now);

group('A token for one file');
check('it is 32 hex characters', preg_match('/^[a-f0-9]{32}$/', $token) === 1);
check('it opens that file', $tokens->isValid($token, 'uuid-a', 'com_content.article', 7, 'files', $now));
check('again, on a second click', $tokens->isValid($token, 'uuid-a', 'com_content.article', 7, 'files', $now + 1));
check('not another file', !$tokens->isValid($token, 'uuid-b', 'com_content.article', 7, 'files', $now));
check('not the same file on another article', !$tokens->isValid($token, 'uuid-a', 'com_content.article', 8, 'files', $now));
check('not item 7 of another context', !$tokens->isValid($token, 'uuid-a', 'com_contact.contact', 7, 'files', $now));
check('not through another field', !$tokens->isValid($token, 'uuid-a', 'com_content.article', 7, 'other', $now));
check('a made-up token opens nothing', !$tokens->isValid(str_repeat('0', 32), 'uuid-a', 'com_content.article', 7, 'files', $now));

group('Expiry');
check('valid up to its lifetime', $tokens->isValid($token, 'uuid-a', 'com_content.article', 7, 'files', $now + 900));
check('not after it', !$tokens->isValid($token, 'uuid-a', 'com_content.article', 7, 'files', $now + 901));
$tokens->issue('uuid-b', 'com_content.article', 7, 'files', $now + 2000);
check('expired tokens are dropped from the session when a new one is issued', count($session->data[DownloadTokens::SESSION_KEY]) === 1);

group('A long visit');
for ($i = 0; $i < 600; $i++) {
    $last = $tokens->issue('uuid-' . $i, 'com_content.article', 7, 'files', $now + 2000);
}
check('the session keeps at most 500 tokens', count($session->data[DownloadTokens::SESSION_KEY]) === 500);
check('the newest survive', $tokens->isValid($last, 'uuid-599', 'com_content.article', 7, 'files', $now + 2000));

finish();
