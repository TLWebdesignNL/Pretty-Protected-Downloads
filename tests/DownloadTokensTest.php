<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the download tokens: bound to one field of one item, still valid on a second
 * click, reused while fresh, and gone once they expire.
 */

require_once __DIR__ . '/bootstrap.php';

use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\DownloadTokens;

$session = new TestSession();
$tokens  = new DownloadTokens($session, 900);
$now     = 1_000_000;
$token   = $tokens->issue('com_content.article', 7, 'files', $now);

group('A token for one field');
check('it is 32 hex characters', preg_match('/^[a-f0-9]{32}$/', $token) === 1);
check('it opens that field', $tokens->isValid($token, 'com_content.article', 7, 'files', $now));
check('again, on a second click', $tokens->isValid($token, 'com_content.article', 7, 'files', $now + 1));
check('not the same field on another article', !$tokens->isValid($token, 'com_content.article', 8, 'files', $now));
check('not item 7 of another context', !$tokens->isValid($token, 'com_contact.contact', 7, 'files', $now));
check('not another field', !$tokens->isValid($token, 'com_content.article', 7, 'other', $now));
check('a made-up token opens nothing', !$tokens->isValid(str_repeat('0', 32), 'com_content.article', 7, 'files', $now));
check('no token opens nothing', !$tokens->isValid('', 'com_content.article', 7, 'files', $now));

group('Showing the field again');
check('while fresh, the same token comes back', $tokens->issue('com_content.article', 7, 'files', $now + 450) === $token);
check('so the session holds one', count($session->data[DownloadTokens::SESSION_KEY]) === 1);
$renewed = $tokens->issue('com_content.article', 7, 'files', $now + 451);
check('with less than half its life left, a new one', $renewed !== $token);
check('and the old one still works until it expires', $tokens->isValid($token, 'com_content.article', 7, 'files', $now + 900));

group('Expiry');
check('not after its lifetime', !$tokens->isValid($token, 'com_content.article', 7, 'files', $now + 901));
$tokens->issue('com_content.article', 9, 'files', $now + 2000);
check('expired tokens are dropped from the session when a new one is issued', count($session->data[DownloadTokens::SESSION_KEY]) === 1);

group('A long visit');
for ($i = 0; $i < 300; $i++) {
    $last = $tokens->issue('com_content.article', 100 + $i, 'files', $now + 2000);
}
check('the session keeps at most 200 tokens', count($session->data[DownloadTokens::SESSION_KEY]) === 200);
check('the newest survive', $tokens->isValid($last, 'com_content.article', 399, 'files', $now + 2000));

finish();
