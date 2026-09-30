<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The plugin settings, read the same way by the plugin and by the form fields, which
 * are built without it.
 */
final class Settings
{
    public const DEFAULT_EXTENSIONS = 'pdf,doc,docx,odt,rtf,txt,csv,xls,xlsx,ods,ppt,pptx,odp,zip,jpg,jpeg,png,gif,webp,mp3,mp4';

    public const DEFAULT_MAX_MB = 20;

    /**
     * The content types downloads are sent with, by extension. The type is taken from
     * the name rather than sniffed from the bytes, so a file that is not what its name
     * says is never sent as a page or a script; anything else is a plain octet stream.
     */
    public const CONTENT_TYPES = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'rtf'  => 'application/rtf',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'zip'  => 'application/zip',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'mp3'  => 'audio/mpeg',
        'mp4'  => 'video/mp4',
    ];

    /**
     * What an upload's content may be detected as, by extension. An upload whose
     * content is detected as anything else is refused, so a web page or a script can
     * not be stored under the name of a document.
     *
     * The lists are generous where the detection is unsure: the old Office formats are
     * all one kind of container, the new ones and OpenDocument are zip archives that
     * are not always recognised further, and text is often just text/plain. An octet
     * stream is content the detection did not recognise at all, which a page or a
     * script never is.
     *
     * An extension an administrator allows that is not listed here is accepted
     * without this check, and logged.
     */
    public const MIME_TYPES = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', ...self::OLE],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP, ...self::OLE],
        'odt'  => ['application/vnd.oasis.opendocument.text', ...self::ZIP],
        'rtf'  => ['application/rtf', 'text/rtf', 'text/plain'],
        'txt'  => ['text/plain', 'application/x-empty'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/x-empty'],
        'xls'  => ['application/vnd.ms-excel', ...self::OLE],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP, ...self::OLE],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', ...self::ZIP],
        'ppt'  => ['application/vnd.ms-powerpoint', ...self::OLE],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP, ...self::OLE],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', ...self::ZIP],
        'zip'  => [
            'application/x-zip-compressed',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            ...self::ZIP,
        ],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'mp3'  => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'application/octet-stream'],
        'mp4'  => ['video/mp4', 'audio/mp4', 'video/x-m4v', 'video/quicktime', 'application/octet-stream'],
    ];

    /**
     * The old Office formats: a compound document, told apart only sometimes.
     */
    private const OLE = [
        'application/x-ole-storage',
        'application/cdfv2',
        'application/vnd.ms-office',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/octet-stream',
    ];

    /**
     * A zip archive, or content the detection could not tell further.
     */
    private const ZIP = ['application/zip', 'application/octet-stream'];

    /**
     * Extensions never accepted, whatever the settings say: anything a web server
     * might run, or a browser render as a page, if the file ever were reached directly.
     */
    private const NEVER = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp', 'exe', 'bat', 'cmd', 'com',
        'htaccess', 'htpasswd', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'svgz', 'js', 'mjs', 'xml',
    ];

    /**
     * The plugin parameters.
     *
     * @return  Registry
     */
    public static function params(): Registry
    {
        $plugin = PluginHelper::getPlugin('fields', 'prettyprotecteddownloads');

        return new Registry(\is_object($plugin) ? ($plugin->params ?? '') : '');
    }

    /**
     * The extensions an upload may have, lower case.
     *
     * @param   Registry  $params  The plugin parameters.
     *
     * @return  string[]
     */
    public static function allowedExtensions(Registry $params): array
    {
        $list = strtolower((string) $params->get('allowed_extensions', self::DEFAULT_EXTENSIONS));
        $list = preg_split('/[\s,;]+/', $list) ?: [];
        $list = array_map(static fn (string $ext): string => ltrim($ext, '.'), $list);
        $list = array_filter($list, static fn (string $ext): bool => preg_match('/^[a-z0-9]+$/', $ext) === 1);

        return array_values(array_unique(array_diff($list, self::NEVER)));
    }

    /**
     * Whether content detected as this type may be stored under this extension.
     *
     * @param   string  $extension  The lower-case extension.
     * @param   string  $detected   The type the content was detected as.
     *
     * @return  ?bool  Null when there is no rule for the extension.
     */
    public static function typeMatches(string $extension, string $detected): ?bool
    {
        if (!isset(self::MIME_TYPES[$extension])) {
            return null;
        }

        return \in_array(strtolower(trim($detected)), array_map('strtolower', self::MIME_TYPES[$extension]), true);
    }

    /**
     * The largest upload accepted, in bytes: the setting, capped by what PHP accepts.
     *
     * @param   Registry  $params  The plugin parameters.
     *
     * @return  int
     */
    public static function maxBytes(Registry $params): int
    {
        $limits = [
            (int) round(max(0.0, (float) $params->get('max_size', self::DEFAULT_MAX_MB)) * 1024 * 1024),
            self::iniBytes((string) \ini_get('upload_max_filesize')),
            self::iniBytes((string) \ini_get('post_max_size')),
        ];

        $limits = array_filter($limits, static fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * A php.ini size such as "8M" in bytes; 0 means no limit.
     *
     * @param   string  $value  The ini value.
     *
     * @return  int
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)/i', $value, $matches)) {
            return 0;
        }

        $factor = match (strtolower($matches[2])) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        return (int) round((float) $matches[1] * $factor);
    }

    /**
     * Seconds a download token stays valid.
     *
     * @param   Registry  $params  The plugin parameters.
     *
     * @return  int
     */
    public static function tokenLifetime(Registry $params): int
    {
        return max(1, (int) $params->get('token_lifetime', 30)) * 60;
    }
}
