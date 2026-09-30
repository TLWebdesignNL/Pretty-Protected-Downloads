<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Extension;

use Joomla\CMS\Event\CustomFields\BeforePrepareFieldEvent;
use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeDeleteEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Event\User\AfterDeleteEvent as UserAfterDeleteEvent;
use Joomla\CMS\Event\User\AfterSaveEvent as UserAfterSaveEvent;
use Joomla\CMS\Event\User\BeforeDeleteEvent as UserBeforeDeleteEvent;
use Joomla\CMS\Event\User\BeforeSaveEvent as UserBeforeSaveEvent;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\DownloadTokens;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PendingUploads;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PrettyprotecteddownloadsHelper;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Settings;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Storage;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Pretty Protected Downloads: a custom field that offers files for download without
 * ever giving them a public URL.
 *
 * The plugin is the field type, and through com_ajax also the endpoint that stores
 * uploads and serves downloads:
 *
 *   task=upload    POST, editors: stores a file and returns its entry
 *   task=preview   GET, editors: the file as the editor sees it in the form
 *   task=token     POST, visitors: a fresh download token for one file, after the
 *                  same access checks as a download
 *   task=download  POST, visitors: the file, after the item, field and field group
 *                  access checks and a download token check
 *   task=cleanup   POST, administrators: deletes stored files no field names any more
 *
 * Every request names the fields context the item belongs to, since who may see an
 * item is decided per context; PrettyprotecteddownloadsHelper::CONTEXTS lists the supported ones.
 */
final class Prettyprotecteddownloads extends FieldsPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * How many uploads one session may have waiting for their item to be saved.
     */
    public const MAX_PENDING_UPLOADS = 50;

    /**
     * Stored files a save removed from an item's fields, or that belonged to an item
     * being deleted, by context and item id, deleted once the save or the delete has
     * gone through.
     *
     * @var  array<string, string[]>
     */
    private array $pendingDeletes = [];

    /**
     * @return  array
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onCustomFieldsBeforePrepareField'  => 'beforePrepareField',
            'onContentBeforeSave'               => 'beforeSave',
            'onContentAfterSave'                => 'afterSave',
            'onUserBeforeSave'                  => 'beforeUserSave',
            'onUserAfterSave'                   => 'afterUserSave',
            'onContentBeforeDelete'             => 'beforeDelete',
            'onContentAfterDelete'              => 'afterDelete',
            'onUserBeforeDelete'                => 'beforeUserDelete',
            'onUserAfterDelete'                 => 'afterUserDelete',
            'onAjaxPrettyprotecteddownloads'    => 'onAjax',
        ]);
    }

    /**
     * Make the form field classes of this plugin available to the item form, and tell
     * the form field which context its item belongs to.
     *
     * @param   object       $field   The field.
     * @param   \DOMElement  $parent  The fieldset element.
     * @param   Form         $form    The form.
     *
     * @return  ?\DOMElement
     */
    public function onCustomFieldsPrepareDom($field, \DOMElement $parent, Form $form): ?\DOMElement
    {
        FormHelper::addFieldPrefix('TLWeb\\Plugin\\Fields\\Prettyprotecteddownloads\\Field');

        $node = parent::onCustomFieldsPrepareDom($field, $parent, $form);

        if ($node instanceof \DOMElement && !empty($field->context)) {
            $node->setAttribute('context', (string) $field->context);
        }

        return $node;
    }

    /**
     * Hand the layout a list of entries rather than the stored JSON.
     *
     * @param   BeforePrepareFieldEvent  $event  The event.
     *
     * @return  void
     */
    public function beforePrepareField(BeforePrepareFieldEvent $event): void
    {
        $field = $event->getField();

        if ($this->isTypeSupported($field->type)) {
            $field->value = Entries::decode($field->value);
        }
    }

    // ── Saving ────────────────────────────────────────────────────────────────

    /**
     * Note which stored files this save takes out of the item's fields.
     *
     * @param   BeforeSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function beforeSave(BeforeSaveEvent $event): void
    {
        $item    = $event->getItem();
        $data    = $event->getData();
        $context = $this->fieldsContext($event->getContext(), $item);

        if ($context !== null && \is_array($data) && \is_array($data['com_fields'] ?? null)) {
            $this->noteRemoved($context, (int) ($item->id ?? 0), $data['com_fields']);
        }
    }

    /**
     * Delete the files the save removed, unless another item still lists them.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function afterSave(AfterSaveEvent $event): void
    {
        $item    = $event->getItem();
        $context = $this->fieldsContext($event->getContext(), $item);

        if ($context !== null) {
            $this->deleteRemoved($context, (int) ($item->id ?? 0));
        }
    }

    /**
     * The same for a user account, whose profile fields are saved through the user
     * events rather than the content events.
     *
     * @param   UserBeforeSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function beforeUserSave(UserBeforeSaveEvent $event): void
    {
        $data = $event->getData();

        if (\is_array($data['com_fields'] ?? null)) {
            $this->noteRemoved('com_users.user', (int) ($event->getUser()['id'] ?? 0), $data['com_fields']);
        }
    }

    /**
     * @param   UserAfterSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function afterUserSave(UserAfterSaveEvent $event): void
    {
        if ($event->getSavingResult()) {
            $this->deleteRemoved('com_users.user', (int) ($event->getUser()['id'] ?? 0));
        }
    }

    // ── Deleting ──────────────────────────────────────────────────────────────

    /**
     * Note the stored files of an item that is about to be deleted. Its field values
     * are removed by the fields system after the delete, possibly before this plugin
     * gets there, so they are read now.
     *
     * @param   BeforeDeleteEvent  $event  The event.
     *
     * @return  void
     */
    public function beforeDelete(BeforeDeleteEvent $event): void
    {
        $item    = $event->getItem();
        $context = $this->fieldsContext($event->getContext(), $item);

        if ($context !== null) {
            $this->noteDeleted($context, (int) ($item->id ?? 0));
        }
    }

    /**
     * Delete the files of a deleted item, unless another item still lists them.
     *
     * @param   AfterDeleteEvent  $event  The event.
     *
     * @return  void
     */
    public function afterDelete(AfterDeleteEvent $event): void
    {
        $item    = $event->getItem();
        $context = $this->fieldsContext($event->getContext(), $item);

        if ($context !== null) {
            $this->deleteRemoved($context, (int) ($item->id ?? 0), true);
        }
    }

    /**
     * @param   UserBeforeDeleteEvent  $event  The event.
     *
     * @return  void
     */
    public function beforeUserDelete(UserBeforeDeleteEvent $event): void
    {
        $this->noteDeleted('com_users.user', (int) ($event->getUser()['id'] ?? 0));
    }

    /**
     * @param   UserAfterDeleteEvent  $event  The event.
     *
     * @return  void
     */
    public function afterUserDelete(UserAfterDeleteEvent $event): void
    {
        $itemId = (int) ($event->getUser()['id'] ?? 0);

        if ($event->getDeletingResult()) {
            $this->deleteRemoved('com_users.user', $itemId, true);
        } else {
            unset($this->pendingDeletes['com_users.user:' . $itemId]);
        }
    }

    /**
     * Remember every stored file an item's fields name.
     *
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item id.
     *
     * @return  void
     */
    private function noteDeleted(string $context, int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        $filenames = array_keys($this->helper()->storedFilenames($context, $itemId));

        if ($filenames !== []) {
            $this->pendingDeletes[$context . ':' . $itemId] = $filenames;
        }
    }

    /**
     * Compare what an item's fields hold with what is being saved, and remember the
     * stored files that are on their way out.
     *
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item id.
     * @param   array   $posted   The posted com_fields values.
     *
     * @return  void
     */
    private function noteRemoved(string $context, int $itemId, array $posted): void
    {
        if ($itemId <= 0) {
            return;
        }

        $helper = $this->helper();
        $removed    = [];

        foreach ($helper->fieldsWithValues($context, $itemId) as $field) {
            if (\array_key_exists($field->name, $posted)) {
                array_push($removed, ...Entries::removedFilenames(Entries::decode($field->value ?? ''), Entries::decode($posted[$field->name])));
            }
        }

        $fieldIds = $helper->fieldIds();

        foreach ($helper->subformsWithValues($context, $itemId) as $subform) {
            if (!\array_key_exists($subform->name, $posted)) {
                continue;
            }

            foreach (Entries::subformChildIds($subform->fieldparams ?? '', $fieldIds) as $childId) {
                $key = 'field' . $childId;

                array_push(
                    $removed,
                    ...Entries::removedFilenames(Entries::fromSubform($subform->value ?? '', $key), Entries::fromSubform($posted[$subform->name], $key))
                );
            }
        }

        if ($removed !== []) {
            $this->pendingDeletes[$context . ':' . $itemId] = array_values(array_unique($removed));
        }
    }

    /**
     * Delete the files noted for an item, unless another item still lists them.
     *
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item id.
     * @param   bool    $deleted  Whether the item itself was deleted, so its own values, if still there, do not count.
     *
     * @return  void
     */
    private function deleteRemoved(string $context, int $itemId, bool $deleted = false): void
    {
        $key = $context . ':' . $itemId;

        if (empty($this->pendingDeletes[$key])) {
            return;
        }

        $referenced = $deleted ? $this->helper()->referencedFilenames($context, $itemId) : $this->helper()->referencedFilenames();
        $storage    = Storage::fromParams($this->params);

        foreach ($this->pendingDeletes[$key] as $filename) {
            if (!isset($referenced[$filename])) {
                $storage->delete($filename);
            }
        }

        unset($this->pendingDeletes[$key]);
    }

    /**
     * The fields context a save event belongs to, or null when it is not a supported
     * one. Joomla names the same thing differently per screen (an article saved on the
     * site is "com_content.form", a category "com_categories.category"), so the names
     * are normalised the way the fields system itself does it.
     *
     * @param   string  $context  The event context.
     * @param   mixed   $item     The item being saved.
     *
     * @return  ?string
     */
    private function fieldsContext(string $context, mixed $item): ?string
    {
        if (str_starts_with($context, 'com_categories.category')) {
            $context = (string) ($item->extension ?? '') . '.categories';
        }

        $parts = FieldsHelper::extract($context, $item);

        if (\is_array($parts) && \count($parts) === 2) {
            $context = $parts[0] . '.' . $parts[1];
        }

        return PrettyprotecteddownloadsHelper::supports($context) ? $context : null;
    }

    // ── com_ajax ──────────────────────────────────────────────────────────────

    /**
     * The com_ajax entry point: index.php?option=com_ajax&group=fields&plugin=prettyprotecteddownloads
     *
     * @param   AjaxEvent  $event  The event.
     *
     * @return  void
     */
    public function onAjax(AjaxEvent $event): void
    {
        $task = $this->getApplication()->getInput()->getCmd('task', 'download');

        match ($task) {
            'upload'  => $event->addResult($this->upload()),
            'token'   => $event->addResult($this->token()),
            'cleanup' => $event->addResult($this->cleanup()),
            'preview' => $this->preview(),
            default   => $this->download(),
        };
    }

    /**
     * Store an upload for an item's field and return its entry.
     *
     * The entry is not part of the item until the editor saves it; until then the
     * file belongs to nothing, and a clean-up leaves it alone for the grace period
     * set in the plugin.
     *
     * @return  array
     *
     * @throws  \RuntimeException
     */
    private function upload(): array
    {
        $app     = $this->getApplication();
        $input   = $app->getInput();
        $user    = $app->getIdentity();
        $context = $input->getString('context', '');
        $itemId  = $input->getInt('item', 0);
        $field   = $input->getString('field', '');

        if (!Session::checkToken()) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $item = $user && !$user->guest ? $this->helper()->item($context, $itemId, $user) : null;

        if (!$item || !$item->editable) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $definition = $field !== '' ? $this->helper()->field($context, $field, $itemId) : null;

        if (!$definition) {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_FIELD_NOT_FOUND'), 400);
        }

        if (!PrettyprotecteddownloadsHelper::fieldEditable($definition, $user, $app->isClient('site'))) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $pending = new PendingUploads($app->getSession(), Settings::cleanupGrace($this->params));

        // Uploads that have since been saved with their item no longer count; the
        // site-wide lookup that tells is only made once the limit is reached.
        if ($pending->count() >= self::MAX_PENDING_UPLOADS) {
            $pending->forget($this->helper()->referencedFilenames());

            if ($pending->count() >= self::MAX_PENDING_UPLOADS) {
                throw new \RuntimeException(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_TOO_MANY_PENDING', self::MAX_PENDING_UPLOADS), 429);
            }
        }

        $file = $input->files->get('file', null, 'raw');

        if (!\is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $tooLarge = \in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            throw new \RuntimeException(
                $tooLarge
                    ? Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_TOO_LARGE', HTMLHelper::_('number.bytes', Settings::maxBytes($this->params)))
                    : Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_UPLOAD_FAILED'),
                400
            );
        }

        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_UPLOAD_FAILED'), 400);
        }

        $maxBytes = Settings::maxBytes($this->params);

        if ($maxBytes > 0 && (int) $file['size'] > $maxBytes) {
            throw new \RuntimeException(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_TOO_LARGE', HTMLHelper::_('number.bytes', $maxBytes)), 400);
        }

        $original  = (string) $file['name'];
        $allowed   = Settings::allowedExtensions($this->params);
        $extension = Entries::extension($original);

        if (!\in_array($extension, $allowed, true)) {
            throw new \RuntimeException(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_EXTENSION', $extension, implode(', ', $allowed)), 400);
        }

        // The same inspection the Media Manager applies: forbidden extensions anywhere
        // in the name, and PHP hidden inside the content.
        if (!InputFilter::isSafeFile($file)) {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_UNSAFE'), 400);
        }

        // The content must be what the extension says, as far as it can be told.
        $detected = $this->detectType((string) $file['tmp_name']);
        $matches  = $detected === null ? null : Settings::typeMatches($extension, $detected);

        if ($matches === false) {
            throw new \RuntimeException(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_TYPE_MISMATCH', $extension), 400);
        }

        if ($matches === null) {
            Log::add(
                \sprintf('Upload of a .%s file accepted without a content check (detected: %s).', $extension, $detected ?? 'unavailable'),
                Log::WARNING,
                'plg_fields_prettyprotecteddownloads'
            );
        }

        $storage = Storage::fromParams($this->params);

        try {
            $folder = $storage->prepare();
        } catch (\RuntimeException $e) {
            // The reason names a server path; that is for the log and the super user,
            // not for every editor's screen.
            Log::add($e->getMessage(), Log::ERROR, 'plg_fields_prettyprotecteddownloads');

            throw new \RuntimeException(
                $user->authorise('core.admin') ? $e->getMessage() : Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_STORAGE_UNAVAILABLE'),
                500
            );
        }

        $uuid     = Entries::uuid();
        $filename = Entries::storedName($original, $uuid);

        if ($filename === null || !move_uploaded_file((string) $file['tmp_name'], $folder . $filename)) {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_UPLOAD_FAILED'), 500);
        }

        @chmod($folder . $filename, 0640);

        $pending->record($uuid, $filename, $context, $itemId, $field);

        $this->discardReplaced($storage, $pending, $context, $itemId, $input->getString('replace_uuid', ''), $input->getString('replace_filename', ''));

        $this->cleanupIfDue($storage);

        $entry = Entries::normalise(['uuid' => $uuid, 'filename' => $filename, 'original' => $original]);

        return $entry + ['size' => (int) $file['size']];
    }

    /**
     * Delete the file an upload replaced, when this session uploaded it for this item
     * and no saved item lists it.
     *
     * A file that was saved with the item stays until the item is saved without it;
     * one that was uploaded and replaced before any save belongs to nothing and would
     * otherwise wait for a clean-up. Anyone else's file is left alone.
     *
     * @param   Storage         $storage   The storage.
     * @param   PendingUploads  $pending   This session's uploads.
     * @param   string          $context   The fields context of the item.
     * @param   int             $itemId    The item.
     * @param   string          $uuid      The replaced entry uuid.
     * @param   string          $filename  The replaced stored filename.
     *
     * @return  void
     */
    private function discardReplaced(Storage $storage, PendingUploads $pending, string $context, int $itemId, string $uuid, string $filename): void
    {
        if (
            Entries::belongsTogether($uuid, $filename)
            && $pending->has($uuid, $filename, $context, $itemId)
            && !isset($this->helper()->referencedFilenames()[$filename])
            && $storage->delete($filename)
        ) {
            $pending->forget([$filename => true]);
        }
    }

    /**
     * Send an editor the file behind an entry of the item they are editing.
     *
     * An upload the item has not been saved with yet is not listed in the field, so it
     * can be previewed when this session uploaded it for this item.
     *
     * @return  never
     */
    private function preview(): never
    {
        $app      = $this->getApplication();
        $input    = $app->getInput();
        $user     = $app->getIdentity();
        $context  = $input->getString('context', '');
        $itemId   = $input->getInt('item', 0);
        $uuid     = $input->getString('uuid', '');
        $filename = $input->getString('filename', '');
        $item     = $user && !$user->guest ? $this->helper()->item($context, $itemId, $user) : null;

        if (!$item || !$item->editable) {
            $this->fail(403);
        }

        $field = $this->helper()->field($context, $input->getString('field', ''), $itemId);

        if (!$field) {
            $this->fail(404);
        }

        if (!PrettyprotecteddownloadsHelper::fieldEditable($field, $user, $app->isClient('site'))) {
            $this->fail(403);
        }

        $entry = Entries::find($field->entries, $uuid);

        if (!$entry && Entries::belongsTogether($uuid, $filename) && (new PendingUploads($app->getSession(), Settings::cleanupGrace($this->params)))->has($uuid, $filename, $context, $itemId)) {
            $entry = ['uuid' => $uuid, 'filename' => $filename];
        }

        $file = $entry ? Storage::fromParams($this->params)->locate((string) $entry['filename']) : null;

        if ($file === null) {
            $this->fail(404);
        }

        $this->send($file, Entries::downloadName($entry));
    }

    /**
     * Issue a download token for one file, for the button a visitor is about to press.
     *
     * The download buttons ask for this when they are pressed rather than carrying a
     * token from when the page was rendered, so they keep working on a page served
     * from a cache, whose rendered tokens belong to another visitor's session. The
     * request needs the form token, which Joomla's caches do replace, and passes the
     * same access checks as the download itself.
     *
     * @return  array{token: string}
     *
     * @throws  \RuntimeException
     */
    private function token(): array
    {
        $app     = $this->getApplication();
        $input   = $app->getInput();
        $user    = $app->getIdentity() ?: new User();
        $context = $input->post->getString('context', '');
        $uuid    = $input->post->getString('uuid', '');
        $itemId  = $input->post->getInt('item', 0);
        $name    = $input->post->getString('field', '');

        if (strtoupper($input->server->getString('REQUEST_METHOD', '')) !== 'POST' || !Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_EXPIRED'), 403);
        }

        $refusal = $this->helper()->downloadable($user, $context, $itemId, $name, $uuid);

        if (\is_string($refusal)) {
            throw new \RuntimeException(Text::_($refusal), 403);
        }

        $tokens = new DownloadTokens($app->getSession(), Settings::tokenLifetime($this->params));

        return ['token' => $tokens->issue($uuid, $context, $itemId, $name)];
    }

    /**
     * Send a visitor a file, after every check the page that offered it passed.
     *
     * @return  never
     */
    private function download(): never
    {
        $app     = $this->getApplication();
        $input   = $app->getInput();
        $user    = $app->getIdentity() ?: new User();
        $context = $input->post->getString('context', '');
        $uuid    = $input->post->getString('uuid', '');
        $itemId  = $input->post->getInt('item', 0);
        $name    = $input->post->getString('field', '');
        $token   = $input->post->getString('download_token', '');

        if (strtoupper($input->server->getString('REQUEST_METHOD', '')) !== 'POST' || !Session::checkToken('post')) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_EXPIRED');
        }

        $tokens = new DownloadTokens($app->getSession(), Settings::tokenLifetime($this->params));

        if ($uuid === '' || $itemId <= 0 || $name === '' || !$tokens->isValid($token, $uuid, $context, $itemId, $name)) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_EXPIRED');
        }

        $entry = $this->helper()->downloadable($user, $context, $itemId, $name, $uuid);

        if (\is_string($entry)) {
            $this->refuse($entry);
        }

        $file = Storage::fromParams($this->params)->locate((string) $entry['filename']);

        if ($file === null) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NOT_FOUND');
        }

        $this->send($file, Entries::downloadName($entry));
    }

    /**
     * Delete the stored files no field names any more.
     *
     * @return  array{deleted: int, bytes: int, message: string}
     *
     * @throws  \RuntimeException
     */
    private function cleanup(): array
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();

        if (!Session::checkToken()) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        if (!$app->isClient('administrator') || !$user || !$user->authorise('core.edit', 'com_plugins')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        ['deleted' => $deleted, 'bytes' => $bytes] = $this->deleteUnused(Storage::fromParams($this->params));

        return [
            'deleted' => $deleted,
            'bytes'   => $bytes,
            'message' => Text::plural('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_DONE_N', $deleted, HTMLHelper::_('number.bytes', $bytes)),
        ];
    }

    /**
     * The automatic clean-up: once a day, the first upload deletes the stored files
     * no field names and that are older than the grace period, the same as the
     * button on the settings screen.
     *
     * @param   Storage  $storage  The storage.
     *
     * @return  void
     */
    private function cleanupIfDue(Storage $storage): void
    {
        if (!$storage->claimCleanup(86400)) {
            return;
        }

        ['deleted' => $deleted, 'bytes' => $bytes] = $this->deleteUnused($storage);

        if ($deleted > 0) {
            Log::add(\sprintf('Automatic clean-up deleted %d unused files (%d bytes).', $deleted, $bytes), Log::INFO, 'plg_fields_prettyprotecteddownloads');
        }
    }

    /**
     * Delete the stored files no field names and that are older than the grace period.
     *
     * @param   Storage  $storage  The storage.
     *
     * @return  array{deleted: int, bytes: int}
     */
    private function deleteUnused(Storage $storage): array
    {
        $unused  = $storage->unused($this->helper()->referencedFilenames(), time() - Settings::cleanupGrace($this->params));
        $deleted = 0;
        $bytes   = 0;

        foreach ($unused as $filename => $file) {
            if ($storage->delete($filename)) {
                $deleted++;
                $bytes += $file['size'];
            }
        }

        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The type a file's content is detected as, or null when it cannot be told.
     *
     * @param   string  $file  The absolute path.
     *
     * @return  ?string
     */
    private function detectType(string $file): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file);

        return \is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Stream a file as an attachment and end the request.
     *
     * @param   string  $file  The absolute path.
     * @param   string  $name  The name to save it as.
     *
     * @return  never
     */
    private function send(string $file, string $name): never
    {
        $mime  = Settings::CONTENT_TYPES[Entries::extension($file)] ?? 'application/octet-stream';
        $ascii = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);

        while (ob_get_level()) {
            ob_end_clean();
        }

        // Compression would make the Content-Length wrong and the download truncated.
        @ini_set('zlib.output_compression', 'Off');

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");

        readfile($file);

        $this->getApplication()->close();
    }

    /**
     * Send a visitor back to the page they came from, with a message.
     *
     * @param   string  $key  The language key of the message.
     *
     * @return  never
     */
    private function refuse(string $key): never
    {
        $app      = $this->getApplication();
        $referrer = $app->getInput()->server->getString('HTTP_REFERER', '');
        $target   = $referrer !== '' && Uri::isInternal($referrer) ? $referrer : Uri::root();

        $app->enqueueMessage(Text::_($key), 'warning');
        $app->redirect($target);
    }

    /**
     * End an editor request with a bare status.
     *
     * @param   int  $status  The HTTP status.
     *
     * @return  never
     */
    private function fail(int $status): never
    {
        $app = $this->getApplication();

        $app->setHeader('status', $status, true);
        $app->sendHeaders();
        echo Text::_($status === 403 ? 'JERROR_ALERTNOAUTHOR' : 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NOT_FOUND');
        $app->close();
    }

    /**
     * @return  PrettyprotecteddownloadsHelper
     */
    private function helper(): PrettyprotecteddownloadsHelper
    {
        return new PrettyprotecteddownloadsHelper($this->getDatabase(), $this->getApplication());
    }
}
