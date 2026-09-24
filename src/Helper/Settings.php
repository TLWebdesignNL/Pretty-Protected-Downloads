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
