<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper;

use Joomla\CMS\Language\Text;
use Joomla\Http\HttpFactory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Whether the web server hands out a storage folder's files directly, found out by
 * asking it for the empty index.html the folder was prepared with. An .htaccess on
 * disk says nothing about a server that does not read it.
 *
 * Asking takes a request from the server to itself, so the answer is kept in the
 * session for a while rather than asked for on every opening of the settings.
 */
final class DirectAccess
{
    public const SESSION_KEY = 'plg_fields_prettyprotecteddownloads.probe';

    /**
     * Seconds an answer is kept.
     */
    public const LIFETIME = 600;

    /**
     * fn (string $url): int, the HTTP status of a GET; throws when there is no answer.
     *
     * @var  callable
     */
    private readonly mixed $fetch;

    /**
     * @param   object     $session  The session, anything with get($name, $default) and set($name, $value).
     * @param   ?callable  $fetch    How to ask; a real request with a 5 second timeout by default.
     */
    public function __construct(
        private readonly object $session,
        ?callable $fetch = null
    ) {
        $this->fetch = $fetch ?? static fn (string $url): int => (new HttpFactory())->getHttp()->get($url, [], 5)->getStatusCode();
    }

    /**
     * The URL of a storage folder's index.html, or null when the folder has no URL.
     *
     * @param   Storage  $storage  The storage.
     * @param   string   $root     The site's root URL, with a trailing slash.
     *
     * @return  ?string
     */
    public static function url(Storage $storage, string $root): ?string
    {
        $relative = $storage->relativeToWebroot();

        return $relative === null ? null : $root . str_replace('%2F', '/', rawurlencode($relative)) . '/index.html';
    }

    /**
     * The HTTP status the folder's index.html is served with, or null when the server
     * gave no answer.
     *
     * @param   string  $url    The URL of the folder's index.html.
     * @param   bool    $fresh  Ask again even when an answer is kept.
     * @param   ?int    $now    The time, for tests.
     *
     * @return  ?int
     */
    public function status(string $url, bool $fresh = false, ?int $now = null): ?int
    {
        $now  = $now ?? time();
        $kept = (array) $this->session->get(self::SESSION_KEY, []);

        if (!$fresh && ($kept['url'] ?? null) === $url && (int) ($kept['time'] ?? 0) > $now - self::LIFETIME) {
            return isset($kept['code']) ? (int) $kept['code'] : null;
        }

        try {
            $code = (int) ($this->fetch)($url);
        } catch (\Throwable $e) {
            $code = null;
        }

        $this->session->set(self::SESSION_KEY, ['url' => $url, 'time' => $now, 'code' => $code]);

        return $code;
    }

    /**
     * The status as a badge for the settings screen.
     *
     * @param   ?int  $code  The HTTP status, or null.
     *
     * @return  string
     */
    public static function badge(?int $code): string
    {
        if ($code === null) {
            return '<span class="badge bg-secondary">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_DIRECT_UNKNOWN') . '</span>';
        }

        return $code === 200
            ? '<span class="badge bg-danger">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_DIRECT_OPEN') . '</span>'
            : '<span class="badge bg-success">' . Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_DIRECT_BLOCKED', $code) . '</span>';
    }
}
