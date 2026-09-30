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
 * The uploads an editor made in this session, each bound to the item and field it
 * was uploaded for.
 *
 * An upload belongs to no item until the item is saved with it. Until then this
 * record is what says whose it is: it limits how many unsaved files one session can
 * leave on disk, and it is what an unsaved entry is checked against.
 */
final class PendingUploads
{
    public const SESSION_KEY = 'plg_fields_prettyprotecteddownloads.uploads';

    /**
     * The most uploads one session keeps; the oldest are dropped beyond it.
     */
    private const MAX_UPLOADS = 500;

    /**
     * @param   object  $session   The session, anything with get($name, $default) and set($name, $value).
     * @param   int     $lifetime  Seconds an upload is remembered; after that a clean-up may have deleted it.
     */
    public function __construct(
        private readonly object $session,
        private readonly int $lifetime = 86400
    ) {
    }

    /**
     * Remember an upload.
     *
     * @param   string  $uuid      The entry uuid.
     * @param   string  $filename  The stored filename.
     * @param   string  $context   The fields context of the item.
     * @param   int     $itemId    The item it was uploaded for.
     * @param   string  $field     The field name.
     * @param   ?int    $now       The time, for tests.
     *
     * @return  void
     */
    public function record(string $uuid, string $filename, string $context, int $itemId, string $field, ?int $now = null): void
    {
        $now     = $now ?? time();
        $uploads = $this->live($now);

        $uploads[$uuid] = [
            'filename' => $filename,
            'context'  => $context,
            'item'     => $itemId,
            'field'    => $field,
            'expires'  => $now + $this->lifetime,
        ];

        if (\count($uploads) > self::MAX_UPLOADS) {
            $uploads = \array_slice($uploads, -self::MAX_UPLOADS, null, true);
        }

        $this->session->set(self::SESSION_KEY, $uploads);
    }

    /**
     * How many uploads this session remembers.
     *
     * @param   ?int  $now  The time, for tests.
     *
     * @return  int
     */
    public function count(?int $now = null): int
    {
        return \count($this->live($now ?? time()));
    }

    /**
     * Whether this session uploaded this file for this item.
     *
     * @param   string  $uuid      The entry uuid.
     * @param   string  $filename  The stored filename.
     * @param   string  $context   The fields context of the item.
     * @param   int     $itemId    The item.
     * @param   ?int    $now       The time, for tests.
     *
     * @return  bool
     */
    public function has(string $uuid, string $filename, string $context, int $itemId, ?int $now = null): bool
    {
        $data = $this->live($now ?? time())[$uuid] ?? null;

        return \is_array($data)
            && $itemId > 0
            && (string) ($data['filename'] ?? '') === $filename
            && (string) ($data['context'] ?? '') === $context
            && (int) ($data['item'] ?? 0) === $itemId;
    }

    /**
     * Forget the uploads with these stored filenames: they were saved with an item,
     * or deleted.
     *
     * @param   array<string, true>  $filenames  The stored filenames, as keys.
     * @param   ?int                 $now        The time, for tests.
     *
     * @return  void
     */
    public function forget(array $filenames, ?int $now = null): void
    {
        $uploads = array_filter(
            $this->live($now ?? time()),
            static fn (array $data): bool => !isset($filenames[(string) ($data['filename'] ?? '')])
        );

        $this->session->set(self::SESSION_KEY, $uploads);
    }

    /**
     * The uploads that have not expired.
     *
     * @param   int  $now  The time.
     *
     * @return  array
     */
    private function live(int $now): array
    {
        $uploads = (array) $this->session->get(self::SESSION_KEY, []);

        return array_filter(
            $uploads,
            static fn ($data): bool => \is_array($data) && (int) ($data['expires'] ?? 0) >= $now
        );
    }
}
