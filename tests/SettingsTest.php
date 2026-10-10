<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the upload settings: which extensions can never be allowed, the size limit
 * that is really in effect, and which content each extension may have.
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
check('at most a day, whatever the form was sent', Settings::tokenLifetime(new Registry(['token_lifetime' => 999999])) === 1440 * 60);

group('Clean-up grace');
check('a week by default', Settings::cleanupGrace(new Registry()) === 7 * 86400);
check('the setting, in days', Settings::cleanupGrace(new Registry(['cleanup_grace' => 30])) === 30 * 86400);
check('at least a day', Settings::cleanupGrace(new Registry(['cleanup_grace' => 0])) === 86400);
check('at most a year', Settings::cleanupGrace(new Registry(['cleanup_grace' => 5000])) === 365 * 86400);

group('Content checks');
check('every default extension has a rule', array_diff(Settings::allowedExtensions(new Registry()), array_keys(Settings::MIME_TYPES)) === []);
check('every rule accepts the type the file is sent with', array_filter(
    array_keys(Settings::CONTENT_TYPES),
    static fn (string $ext): bool => Settings::typeMatches($ext, Settings::CONTENT_TYPES[$ext]) !== true
) === []);
check('an extension without a rule is not judged', Settings::typeMatches('epub', 'application/epub+zip') === null);
check('the detected type is compared without case', Settings::typeMatches('doc', 'application/CDFV2') === true);
check('a zip archive may be a docx', Settings::typeMatches('docx', 'application/zip') === true);
check('an old Office file may be told apart or not', Settings::typeMatches('xls', 'application/x-ole-storage') === true);
check('an image is not a PDF', Settings::typeMatches('pdf', 'image/png') === false);

if (class_exists(\finfo::class)) {
    $finfo  = new \finfo(FILEINFO_MIME_TYPE);
    $detect = static fn (string $bytes): string => (string) $finfo->buffer($bytes);
    $html   = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';

    check('a real PDF passes', Settings::typeMatches('pdf', $detect("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n")) === true);
    check('a real PNG passes', Settings::typeMatches('png', $detect(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))) === true);
    check('plain text passes as txt', Settings::typeMatches('txt', $detect("Just some notes.\nSecond line.\n")) === true);
    check('comma separated values pass as csv', Settings::typeMatches('csv', $detect("name,age\nAnna,30\nBob,40\n")) === true);
    check('RTF passes', Settings::typeMatches('rtf', $detect('{\rtf1\ansi Hello}')) === true);
    check('an empty zip passes', Settings::typeMatches('zip', $detect("PK\x05\x06" . str_repeat("\x00", 18))) === true);
    check('a web page named .pdf is refused', Settings::typeMatches('pdf', $detect($html)) === false);
    check('a web page named .txt is refused', Settings::typeMatches('txt', $detect($html)) === false);
    check('a web page named .jpg is refused', Settings::typeMatches('jpg', $detect($html)) === false);
    check('a web page named .docx is refused', Settings::typeMatches('docx', $detect($html)) === false);
    check('a PDF named .png is refused', Settings::typeMatches('png', $detect("%PDF-1.4\n%%EOF\n")) === false);
}

group('Code inside archives');
$off     = new Registry();
$on      = new Registry(['allow_code_in_archives' => 1]);
$relaxed = Settings::scanOptions($on, 'zip', 'application/zip');
check('off by default: a zip archive is inspected in full', Settings::scanOptions($off, 'zip', 'application/zip') === []);
check('when allowed, the content of a zip archive is left alone', $relaxed !== [] && array_filter($relaxed) === []);
check('the name of the archive is still inspected', !isset($relaxed['null_byte']) && !isset($relaxed['forbidden_extensions']));
check('the detected type is compared without case', Settings::scanOptions($on, 'zip', ' Application/ZIP ') === $relaxed);
check('an office document named .zip is an archive too', Settings::scanOptions($on, 'zip', Settings::CONTENT_TYPES['docx']) === $relaxed);
check('content that was not recognised is not taken for an archive', Settings::scanOptions($on, 'zip', 'application/octet-stream') === []);
check('nor is content that could not be detected', Settings::scanOptions($on, 'zip', null) === []);
check('nor is text named .zip', Settings::scanOptions($on, 'zip', 'text/x-php') === []);
check('other file types are inspected in full', Settings::scanOptions($on, 'pdf', 'application/pdf') === [] && Settings::scanOptions($on, 'txt', 'text/plain') === []);
check('a zip archive under another name is inspected in full', Settings::scanOptions($on, 'docx', 'application/zip') === []);
check('an archive type without a content rule is inspected in full', Settings::scanOptions($on, 'tar', 'application/x-tar') === []);

if (class_exists(\finfo::class) && class_exists(\ZipArchive::class)) {
    $package = getenv('TEST_ROOT') . '/package.zip';
    $zip     = new \ZipArchive();
    $zip->open($package, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('plg_example/example.php', "<?php\n\ndefined('_JEXEC') or die;\n");
    $zip->addFromString('plg_example/example.xml', '<?xml version="1.0" encoding="UTF-8"?><extension type="plugin"></extension>');
    $zip->setCompressionName('plg_example/example.php', \ZipArchive::CM_STORE);
    $zip->close();

    $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($package);

    check('a real extension package holds what the inspection refuses', str_contains((string) file_get_contents($package), '<?php'));
    check('and is recognised as an archive', Settings::scanOptions($on, 'zip', $type) === $relaxed);
    check('PHP under the name of an archive is not', Settings::scanOptions($on, 'zip', (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer("<?php\necho 1;\n")) === []);
}

finish();
