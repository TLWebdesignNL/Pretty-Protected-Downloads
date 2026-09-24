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
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\DownloadTokens;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Repository;
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
 *   task=download  POST, visitors: the file, after the article, category, field and
 *                  field group access checks and a download token check
 *   task=cleanup   POST, administrators: deletes stored files no field names any more
 */
final class Prettyprotecteddownloads extends FieldsPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * How long an upload may wait for its article to be saved before a clean-up may
     * treat it as unused.
     */
    public const CLEANUP_GRACE = 86400;

    /**
     * Stored files an article save removed from its fields, by article id, deleted once
     * the save has gone through.
     *
     * @var  array<int, string[]>
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
            'onAjaxPrettyprotecteddownloads'    => 'onAjax',
        ]);
    }

    /**
     * Make the form field classes of this plugin available to the article form.
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

        return parent::onCustomFieldsPrepareDom($field, $parent, $form);
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
     * Note which stored files this save takes out of the article's fields.
     *
     * @param   BeforeSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function beforeSave(BeforeSaveEvent $event): void
    {
        $itemId = (int) ($event->getItem()->id ?? 0);
        $data   = $event->getData();

        if ($event->getContext() !== Repository::CONTEXT || $itemId <= 0 || !\is_array($data['com_fields'] ?? null)) {
            return;
        }

        $posted     = $data['com_fields'];
        $repository = $this->repository();
        $removed    = [];

        foreach ($repository->fieldsWithValues($itemId) as $field) {
            if (\array_key_exists($field->name, $posted)) {
                array_push($removed, ...Entries::removedFilenames(Entries::decode($field->value ?? ''), Entries::decode($posted[$field->name])));
            }
        }

        $fieldIds = $repository->fieldIds();

        foreach ($repository->subformsWithValues($itemId) as $subform) {
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
            $this->pendingDeletes[$itemId] = array_values(array_unique($removed));
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
        $itemId = (int) ($event->getItem()->id ?? 0);

        if ($event->getContext() !== Repository::CONTEXT || empty($this->pendingDeletes[$itemId])) {
            return;
        }

        $referenced = $this->repository()->referencedFilenames();
        $storage    = Storage::fromParams($this->params);

        foreach ($this->pendingDeletes[$itemId] as $filename) {
            if (!isset($referenced[$filename])) {
                $storage->delete($filename);
            }
        }

        unset($this->pendingDeletes[$itemId]);
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
     * Store an upload for an article's field and return its entry.
     *
     * The entry is not part of the article until the editor saves it; until then the
     * file belongs to nothing and a clean-up leaves it alone for a day.
     *
     * @return  array
     *
     * @throws  \RuntimeException
     */
    private function upload(): array
    {
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $user   = $app->getIdentity();
        $itemId = $input->getInt('item', 0);
        $field  = $input->getString('field', '');

        if (!Session::checkToken()) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $article = $itemId > 0 ? $this->repository()->article($itemId) : null;

        if (!$user || $user->guest || !$article || !$this->canEdit($article, $user)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($field === '' || !$this->repository()->field($field, $itemId)) {
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

        $storage  = Storage::fromParams($this->params);
        $folder   = $storage->prepare();
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
     * A file that was saved with the article stays until the article is saved without
     * it; one that was uploaded and replaced before any save belongs to nothing and
     * would otherwise wait for a clean-up.
     *
     * @param   Storage  $storage   The storage.
     * @param   string   $uuid      The replaced entry uuid.
     * @param   string   $filename  The replaced stored filename.
     *
     * @return  void
     */
    private function discardReplaced(Storage $storage, string $uuid, string $filename): void
    {
        if (Entries::belongsTogether($uuid, $filename) && !isset($this->repository()->referencedFilenames()[$filename])) {
            $storage->delete($filename);
        }
    }

    /**
     * Send an editor the file behind an entry of the article they are editing.
     *
     * An upload the article has not been saved with yet is not listed in the field, so
     * it can be previewed as long as no item lists it at all.
     *
     * @return  never
     */
    private function preview(): never
    {
        $app      = $this->getApplication();
        $input    = $app->getInput();
        $user     = $app->getIdentity();
        $itemId   = $input->getInt('item', 0);
        $uuid     = $input->getString('uuid', '');
        $filename = $input->getString('filename', '');
        $article  = $itemId > 0 ? $this->repository()->article($itemId) : null;

        if (!$user || $user->guest || !$article || !$this->canEdit($article, $user)) {
            $this->fail(403);
        }

        $field = $this->repository()->field($input->getString('field', ''), $itemId);
        $entry = $field ? $this->find($field->entries, $uuid) : null;

        if (!$entry && Entries::belongsTogether($uuid, $filename) && !isset($this->repository()->referencedFilenames()[$filename])) {
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
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $user   = $app->getIdentity() ?: new User();
        $uuid   = $input->post->getString('uuid', '');
        $itemId = $input->post->getInt('item', 0);
        $name   = $input->post->getString('field', '');
        $token  = $input->post->getString('download_token', '');

        if (strtoupper($input->server->getString('REQUEST_METHOD', '')) !== 'POST' || !Session::checkToken('post')) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_EXPIRED');
        }

        $tokens = new DownloadTokens($app->getSession(), Settings::tokenLifetime($this->params));

        if ($uuid === '' || $itemId <= 0 || $name === '' || !$tokens->isValid($token, $uuid, $itemId, $name)) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_EXPIRED');
        }

        $levels  = $user->getAuthorisedViewLevels();
        $article = $this->repository()->article($itemId);

        if (!$article || !$this->isVisible($article, $levels)) {
            $this->refuse('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
        }

        $field = $this->repository()->field($name, $itemId);

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
        $unused  = $storage->unused($this->repository()->referencedFilenames(), time() - self::CLEANUP_GRACE);
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
     * Whether the article, and its category, are published and open to these view levels.
     *
     * @param   object  $article  The article row.
     * @param   int[]   $levels   The visitor's view levels.
     *
     * @return  bool
     */
    private function isVisible(object $article, array $levels): bool
    {
        $now = Factory::getDate()->toSql();

        return (int) $article->state === 1
            && (int) $article->category_published === 1
            && (empty($article->publish_up) || $article->publish_up <= $now)
            && (empty($article->publish_down) || $article->publish_down > $now)
            && \in_array((int) $article->access, $levels, true)
            && \in_array((int) $article->category_access, $levels, true);
    }

    /**
     * Whether a user may edit an article, as the article form itself decides it.
     *
     * @param   object  $article  The article row.
     * @param   User    $user     The user.
     *
     * @return  bool
     */
    private function canEdit(object $article, User $user): bool
    {
        $asset = 'com_content.article.' . (int) $article->id;

        return $user->authorise('core.edit', $asset)
            || ((int) $article->created_by === (int) $user->id && $user->authorise('core.edit.own', $asset));
    }

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
        $mime = 'application/octet-stream';

        if (class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: $mime;
        }

        $ascii = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);

        while (ob_get_level()) {
            ob_end_clean();
        }

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
     * @return  Repository
     */
    private function repository(): Repository
    {
        return new Repository($this->getDatabase());
    }
}
