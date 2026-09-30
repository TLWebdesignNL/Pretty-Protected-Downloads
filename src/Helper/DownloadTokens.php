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
 * Short-lived tokens that tie the download buttons of a field to the visitor they
 * were shown to.
 *
 * A token is kept in the visitor's session, bound to one field of one item in one
 * context. A download is only served for a token this session was given, so a file
 * can not be fetched by guessing or sharing its identifiers, only by a visitor who
 * passed the access checks for that field. The access check on the download itself
 * runs as well, and it is what makes sure the file is one the field lists; the token
 * is the second lock, not the only one.
 *
 * A token stays valid until it expires rather than being spent on first use, so a
 * second click, or a download the browser retries, still works. A field shown again
 * while its token has at least half its lifetime left gets the same token back, so
 * page views do not fill the session.
 */
final class DownloadTokens
{
    public const SESSION_KEY = 'plg_fields_prettyprotecteddownloads.tokens';

    /**
     * The most tokens one session keeps; the oldest are dropped beyond it.
     */
    private const MAX_TOKENS = 200;

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
     * A token for the downloads of one field of one item.
     *
     * @param   string  $context  The fields context of the item.
     * @param   int     $itemId   The item the field belongs to.
     * @param   string  $field    The field name.
     * @param   ?int    $now      The time, for tests.
     *
     * @return  string
     */
    public function issue(string $context, int $itemId, string $field, ?int $now = null): string
    {
        $now    = $now ?? time();
        $tokens = $this->live($now);

        foreach ($tokens as $token => $data) {
            if ($this->matches($data, $context, $itemId, $field) && (int) $data['expires'] - $now >= intdiv($this->lifetime, 2)) {
                return (string) $token;
            }
        }

        $token = bin2hex(random_bytes(16));

        $tokens[$token] = [
            'context' => $context,
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
     * Whether a token was issued to this session for exactly this field, and is still valid.
     *
     * @param   string  $token    The token.
     * @param   string  $context  The fields context of the item.
     * @param   int     $itemId   The item.
     * @param   string  $field    The field name.
     * @param   ?int    $now      The time, for tests.
     *
     * @return  bool
     */
    public function isValid(string $token, string $context, int $itemId, string $field, ?int $now = null): bool
    {
        $data = $token !== '' ? ($this->live($now ?? time())[$token] ?? null) : null;

        return \is_array($data) && $this->matches($data, $context, $itemId, $field);
    }

    /**
     * @param   array   $data     A stored token.
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item.
     * @param   string  $field    The field name.
     *
     * @return  bool
     */
    private function matches(array $data, string $context, int $itemId, string $field): bool
    {
        return (string) ($data['context'] ?? '') === $context
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
