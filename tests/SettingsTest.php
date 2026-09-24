<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the upload settings: which extensions can never be allowed, and the size
 * limit that is really in effect.
 */

require_once __DIR__ . '/bootstrap.php';

use Joomla\Registry\Registry;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Settings;

group('Allowed extensions');
$defaults = Settings::allowedExtensions(new Registry());
check('the defaults include the common document types', in_array('pdf', $defaults, true) && in_array('docx', $defaults, true));
$typed = Settings::allowedExtensions(new Registry(['allowed_extensions' => ' .PDF; docx  ,, odt ']));
check('dots, case, spaces and semicolons are forgiven', $typed === ['pdf', 'docx', 'odt']);
$dangerous = Settings::allowedExtensions(new Registry(['allowed_extensions' => 'pdf,php,phtml,svg,html,js,htaccess']));
check('what a server could run or a browser render is never allowed', $dangerous === ['pdf']);
check('nonsense entries are dropped', Settings::allowedExtensions(new Registry(['allowed_extensions' => 'pdf,p/df,*'])) === ['pdf']);

group('Sizes');
check('8M is 8 MiB', Settings::iniBytes('8M') === 8 * 1024 * 1024);
check('1G is 1 GiB', Settings::iniBytes('1g') === 1024 ** 3);
check('512K is 512 KiB', Settings::iniBytes('512K') === 512 * 1024);
check('a plain number is bytes', Settings::iniBytes('1000') === 1000);
check('empty means no limit', Settings::iniBytes('') === 0);

$php = min(array_filter([Settings::iniBytes((string) ini_get('upload_max_filesize')), Settings::iniBytes((string) ini_get('post_max_size'))]));
check('a small setting wins over PHP', Settings::maxBytes(new Registry(['max_size' => 0.5])) === min(524288, $php));
check('a huge setting is capped by PHP', Settings::maxBytes(new Registry(['max_size' => 100000])) === $php);
check('0 leaves only the PHP limit', Settings::maxBytes(new Registry(['max_size' => 0])) === $php);

group('Download button lifetime');
check('minutes become seconds', Settings::tokenLifetime(new Registry(['token_lifetime' => 15])) === 900);
check('at least one minute', Settings::tokenLifetime(new Registry(['token_lifetime' => 0])) === 60);

finish();
