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
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Event\User\AfterSaveEvent as UserAfterSaveEvent;
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
     * How long an upload may wait for its item to be saved before a clean-up may
     * treat it as unused.
     */
    public const CLEANUP_GRACE = 86400;

    /**
     * The content types downloads are sent with, by extension. The type is taken from
     * the name rather than sniffed from the bytes, so a file that is not what its name
     * says is never sent as a page or a script; anything else is a plain octet stream.
     */
    private const CONTENT_TYPES = [
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
     * Stored files a save removed from an item's fields, by context and item id,
     * deleted once the save has gone through.
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
     *
     * @return  void
     */
    private function deleteRemoved(string $context, int $itemId): void
    {
        $key = $context . ':' . $itemId;

        if (empty($this->pendingDeletes[$key])) {
            return;
        }

        $referenced = $this->helper()->referencedFilenames();
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
            'cleanup' => $event->addResult($this->cleanup()),
            'preview' => $this->preview(),
            default   => $this->download(),
        };
    }

    /**
     * Store an upload for an item's field and return its entry.
     *
     * The entry is not part of the item until the editor saves it; until then the
     * file belongs to nothing and a clean-up leaves it alone for a day.
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

        if ($field === '' || !$this->helper()->field($context, $field, $itemId)) {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_FIELD_NOT_FOUND'), 400);
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

        $this->discardReplaced($storage, $input->getString('replace_uuid', ''), $input->getString('replace_filename', ''));

        $entry = Entries::normalise(['uuid' => $uuid, 'filename' => $filename, 'original' => $original]);

        return $entry + ['size' => (int) $file['size']];
    }

    /**
     * Delete the file an upload replaced, when no saved item lists it.
     *
     * A file that was saved with the item stays until the item is saved without it;
     * one that was uploaded and replaced before any save belongs to nothing and would
     * otherwise wait for a clean-up.
     *
     * @param   Storage  $storage   The storage.
     * @param   string   $uuid      The replaced entry uuid.
     * @param   string   $filename  The replaced stored filename.
     *
     * @return  void
     */
    private function discardReplaced(Storage $storage, string $uuid, string $filename): void
    {
        if (Entries::belongsTogether($uuid, $filename) && !isset($this->helper()->referencedFilenames()[$filename])) {
            $storage->delete($filename);
        }
    }

    /**
     * Send an editor the file behind an entry of the item they are editing.
     *
     * An upload the item has not been saved with yet is not listed in the field, so it
     * can be previewed as long as no item lists it at all.
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
        $entry = $field ? $this->find($field->entries, $uuid) : null;

        if (!$entry && Entries::belongsTogether($uuid, $filename) && !isset($this->helper()->referencedFilenames()[$filename])) {
            $entry = ['uuid' => $uuid, 'filename' => $filename];
        }

        $file = $entry ? Storage::fromParams($this->params)->locate((string) $entry['filename']) : null;

        if ($file === null) {
            $this->fail(404);
        }

        $this->send($file, Entries::downloadName($entry));
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

        $item = $this->helper()->item($context, $itemId, $user);

        if (!$item || !$item->visible) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
        }

        $levels = $user->getAuthorisedViewLevels();
        $field  = $this->helper()->field($context, $name, $itemId);

        if (
            !$field
            || (int) $field->state !== 1
            || !\in_array((int) $field->access, $levels, true)
            || ($field->group_state !== null && ((int) $field->group_state !== 1 || !\in_array((int) $field->group_access, $levels, true)))
        ) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
        }

        $entry = $this->find($field->entries, $uuid);
        $file  = $entry ? Storage::fromParams($this->params)->locate((string) $entry['filename']) : null;

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

        $storage = Storage::fromParams($this->params);
        $unused  = $storage->unused($this->helper()->referencedFilenames(), time() - self::CLEANUP_GRACE);
        $deleted = 0;
        $bytes   = 0;

        foreach ($unused as $filename => $file) {
            if ($storage->delete($filename)) {
                $deleted++;
                $bytes += $file['size'];
            }
        }

        return [
            'deleted' => $deleted,
            'bytes'   => $bytes,
            'message' => Text::plural('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_DONE_N', $deleted, HTMLHelper::_('number.bytes', $bytes)),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The entry with this uuid, or null.
     *
     * @param   array[]  $entries  The entries.
     * @param   string   $uuid     The uuid.
     *
     * @return  ?array
     */
    private function find(array $entries, string $uuid): ?array
    {
        foreach ($entries as $entry) {
            if ($uuid !== '' && ($entry['uuid'] ?? '') === $uuid && Entries::belongsTogether($uuid, (string) ($entry['filename'] ?? ''))) {
                return $entry;
            }
        }

        return null;
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
        $mime  = self::CONTENT_TYPES[Entries::extension($file)] ?? 'application/octet-stream';
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
