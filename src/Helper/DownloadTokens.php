<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Short-lived tokens that tie a download button to the visitor it was shown to.
 *
 * A token is issued for every file a page renders and kept in the visitor's session,
 * bound to the file, the item and the field. A download is only served for a token
 * this session was given, so a file can not be fetched by guessing or sharing its
 * identifiers, only from a page the visitor was allowed to see. The access check
 * on the download itself runs as well; the token is the second lock, not the only one.
 *
 * A token stays valid until it expires rather than being spent on first use, so a
 * second click, or a download the browser retries, still works.
 */
final class DownloadTokens
{
    public const SESSION_KEY = 'plg_fields_prettyprotecteddownloads.tokens';

    /**
     * The most tokens one session keeps; the oldest are dropped beyond it.
     */
    private const MAX_TOKENS = 500;

    /**
     * @param   object  $session   The session, anything with get($name, $default) and set($name, $value).
     * @param   int     $lifetime  Seconds a token stays valid.
     */
    public function __construct(
        private readonly object $session,
        private readonly int $lifetime = 900
    ) {
    }

    /**
     * Issue a token for one file.
     *
     * @param   string    $uuid    The entry uuid.
     * @param   int       $itemId  The item the field belongs to.
     * @param   string    $field   The field name.
     * @param   ?int      $now     The time, for tests.
     *
     * @return  string
     */
    public function issue(string $uuid, int $itemId, string $field, ?int $now = null): string
    {
        $now    = $now ?? time();
        $tokens = $this->live($now);
        $token  = bin2hex(random_bytes(16));

        $tokens[$token] = [
            'uuid'    => $uuid,
            'item'    => $itemId,
            'field'   => $field,
            'expires' => $now + $this->lifetime,
        ];

        if (\count($tokens) > self::MAX_TOKENS) {
            $tokens = \array_slice($tokens, -self::MAX_TOKENS, null, true);
        }

        $this->session->set(self::SESSION_KEY, $tokens);

        return $token;
    }

    /**
     * Whether a token was issued to this session for exactly this file, and is still valid.
     *
     * @param   string  $token   The token.
     * @param   string  $uuid    The entry uuid.
     * @param   int     $itemId  The item.
     * @param   string  $field   The field name.
     * @param   ?int    $now     The time, for tests.
     *
     * @return  bool
     */
    public function isValid(string $token, string $uuid, int $itemId, string $field, ?int $now = null): bool
    {
        $data = $this->live($now ?? time())[$token] ?? null;

        return \is_array($data)
            && hash_equals((string) ($data['uuid'] ?? ''), $uuid)
            && (int) ($data['item'] ?? 0) === $itemId
            && (string) ($data['field'] ?? '') === $field;
    }

    /**
     * The tokens that have not expired.
     *
     * @param   int  $now  The time.
     *
     * @return  array
     */
    private function live(int $now): array
    {
        $tokens = (array) $this->session->get(self::SESSION_KEY, []);

        return array_filter(
            $tokens,
            static fn ($data): bool => \is_array($data) && (int) ($data['expires'] ?? 0) >= $now
        );
    }
}
