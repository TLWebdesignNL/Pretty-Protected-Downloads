<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the direct-access check of the storage folder: asked once, kept for ten
 * minutes, asked again on request, and told apart from a server that gave no answer.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/Helper/DirectAccess.php';

use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\DirectAccess;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Storage;

$asked   = 0;
$answer  = 403;
$fetch   = static function (string $url) use (&$asked, &$answer): int {
    $asked++;

    if ($answer === null) {
        throw new \RuntimeException('no answer');
    }

    return $answer;
};
$session = new TestSession();
$probe   = new DirectAccess($session, $fetch);
$url     = 'https://example.org/files/prettyprotecteddownloads/index.html';
$now     = 1_000_000;

group('Asking the web server');
check('the answer is its status', $probe->status($url, false, $now) === 403);
check('the second time it is not asked again', $probe->status($url, false, $now + 599) === 403 && $asked === 1);
$answer = 200;
check('after ten minutes it is', $probe->status($url, false, $now + 601) === 200 && $asked === 2);
$answer = 404;
check('Check again asks at once', $probe->status($url, true, $now + 602) === 404 && $asked === 3);
check('another folder is asked about on its own', $probe->status($url . '?other', false, $now + 603) === 404 && $asked === 4);
$answer = null;
check('no answer is null', $probe->status($url, true, $now + 604) === null);
check('and is kept like any other', $probe->status($url, false, $now + 605) === null && $asked === 5);

group('The badge');
check('200 means open', str_contains(DirectAccess::badge(200), 'STATUS_DIRECT_OPEN') && str_contains(DirectAccess::badge(200), 'bg-danger'));
check('anything else means blocked, with the status', str_contains(DirectAccess::badge(403), 'STATUS_DIRECT_BLOCKED:403'));
check('no answer means unknown', str_contains(DirectAccess::badge(null), 'STATUS_DIRECT_UNKNOWN'));

group('The URL');
$site = JPATH_ROOT . '/site';
mkdir($site, 0777, true);
check('a folder in the site has one', DirectAccess::url(new Storage(Storage::WEBROOT, 'my files', $site), 'https://example.org/') === 'https://example.org/my%20files/index.html');
check('a folder outside it has none', DirectAccess::url(new Storage(Storage::OUTSIDE, JPATH_ROOT . '/private', $site), 'https://example.org/') === null);

finish();
