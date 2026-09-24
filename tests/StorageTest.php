<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the storage folder: where it is, closing it when it has a URL, and that
 * nothing but a stored file can be reached, deleted or cleaned up through it.
 */

require_once __DIR__ . '/bootstrap.php';

use Joomla\Registry\Registry;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Storage;

$site    = JPATH_ROOT . '/site';
$outside = JPATH_ROOT . '/private/downloads';
mkdir($site, 0777, true);

group('Where the folder is');
check('outside: the absolute path as given', (new Storage(Storage::OUTSIDE, $outside . '/', $site))->path() === $outside);
check('outside without a path: not configured', (new Storage(Storage::OUTSIDE, '', $site))->path() === '');
check('inside without a path: the default folder', (new Storage(Storage::WEBROOT, '', $site))->path() === $site . '/' . Storage::DEFAULT_WEBROOT_FOLDER);
check('inside: a relative path is read from the site root', (new Storage(Storage::WEBROOT, 'secret', $site))->path() === $site . '/secret');
check('the settings pick the path of the chosen method', Storage::fromParams(new Registry(['storage_method' => 'webroot', 'storage_path' => '/nope', 'storage_path_webroot' => 'x']))->path() === JPATH_ROOT . '/x');
check('an unknown method is treated as outside', Storage::fromParams(new Registry(['storage_method' => 'bogus', 'storage_path' => $outside]))->path() === $outside);

group('Inside or outside the web root');
check('a folder next to the site is outside', !(new Storage(Storage::OUTSIDE, $outside, $site))->isInsideWebroot());
check('a folder in the site is inside, before it exists', (new Storage(Storage::WEBROOT, '', $site))->isInsideWebroot());
check('a "outside" path that is really in the site is inside', (new Storage(Storage::OUTSIDE, $site . '/oops', $site))->isInsideWebroot());
check('a sibling whose name starts like the site is outside', !(new Storage(Storage::OUTSIDE, $site . '-files', $site))->isInsideWebroot());

group('Preparing the folder');
$storage = new Storage(Storage::OUTSIDE, $outside, $site);
$status  = $storage->status();
check('before: not there, but it can be made', !$status['exists'] && $status['creatable']);
check('prepare returns the folder with a separator', $storage->prepare() === $outside . '/');
check('the folder exists', is_dir($outside));
check('it is closed with .htaccess', str_contains((string) file_get_contents($outside . '/.htaccess'), 'Require all denied'));
check('and has an empty index', is_file($outside . '/index.html'));
check('the status now reports it writable and closed', $storage->status()['writable'] && $storage->status()['closed']);
check('an unconfigured folder refuses to prepare', throws(static fn () => (new Storage(Storage::OUTSIDE, '', $site))->prepare()));

group('Reaching files');
$uuid = Entries::uuid();
$name = 'report-' . $uuid . '.pdf';
file_put_contents($outside . '/' . $name, '%PDF-1.4');
file_put_contents(JPATH_ROOT . '/private/secret.txt', 'no');
check('a stored file is found', $storage->locate($name) === $outside . '/' . $name);
check('a path out of the folder is not', $storage->locate('../secret.txt') === null);
check('nor the protection files', $storage->locate('.htaccess') === null);
check('nor a file that is not there', $storage->locate('gone-' . $uuid . '.pdf') === null);
check('deleting outside the folder does nothing', !$storage->delete('../secret.txt') && is_file(JPATH_ROOT . '/private/secret.txt'));

group('Unused files');
$old   = 'old-' . Entries::uuid() . '.pdf';
$fresh = 'fresh-' . Entries::uuid() . '.pdf';
file_put_contents($outside . '/' . $old, 'x');
touch($outside . '/' . $old, time() - 2 * 86400);
touch($outside . '/' . $name, time() - 2 * 86400);
file_put_contents($outside . '/' . $fresh, 'x');
$files = $storage->files();
$listed   = array_keys($files);
$expected = [$fresh, $old, $name];
sort($listed);
sort($expected);
check('the listing holds the stored files, not the protection files', $listed === $expected);
$unused = $storage->unused([$name => true], time() - 86400);
check('an old file no field names is unused', isset($unused[$old]));
check('a file a field names is not', !isset($unused[$name]));
check('a fresh upload is given its grace period', !isset($unused[$fresh]));
check('deleting a stored file works', $storage->delete($old) && !is_file($outside . '/' . $old));

finish();
