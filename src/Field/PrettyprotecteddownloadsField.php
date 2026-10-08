<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\SubformField;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PendingUploads;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PrettyprotecteddownloadsHelper;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Settings;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Storage;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The field in the article form: a repeatable list of files, each with its own upload
 * control and the texts shown with its download.
 */
class PrettyprotecteddownloadsField extends SubformField
{
    /**
     * @var  string
     */
    protected $type = 'Prettyprotecteddownloads';

    /**
     * @param   \SimpleXMLElement  $element  The field element.
     * @param   mixed              $value    The value.
     * @param   ?string            $group    The group.
     *
     * @return  bool
     */
    public function setup(\SimpleXMLElement $element, $value, $group = null)
    {
        FormHelper::addFieldPrefix('TLWeb\\Plugin\\Fields\\Prettyprotecteddownloads\\Field');

        if (!parent::setup($element, $value, $group)) {
            return false;
        }

        $itemId  = $this->itemId();
        $field   = htmlspecialchars((string) $this->fieldname, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $context = htmlspecialchars((string) ($this->element['context'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $label  = static fn (string $key): string => htmlspecialchars(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ENTRY_' . $key), ENT_QUOTES | ENT_XML1, 'UTF-8');

        $this->multiple   = true;
        $this->min        = 0;
        $this->max        = 100;
        $this->layout     = 'joomla.form.field.subform.repeatable';
        $this->buttons    = ['add' => true, 'remove' => true, 'move' => true];
        $this->formsource = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<form>
    <field name="uuid" type="hidden" filter="string" />
    <field name="filename" type="hidden" filter="string" />
    <field name="original" type="hidden" filter="string" />
    <field
        name="file_control"
        type="prettyprotecteddownloadsitem"
        label="{$label('FILE_LABEL')}"
        context="{$context}"
        itemid="{$itemId}"
        targetfield="{$field}"
    />
    <field name="button" type="text" label="{$label('BUTTON_LABEL')}" filter="string" />
    <field name="title" type="text" label="{$label('TITLE_LABEL')}" filter="string" />
    <field name="description" type="textarea" label="{$label('DESCRIPTION_LABEL')}" filter="string" rows="3" />
    <field name="icon" type="text" label="{$label('ICON_LABEL')}" description="{$label('ICON_DESC')}" filter="string" />
    <field name="class" type="text" label="{$label('CLASS_LABEL')}" description="{$label('CLASS_DESC')}" filter="string" />
</form>
XML;

        return true;
    }

    /**
     * @return  string
     */
    protected function getInput()
    {
        // The context is the field's own, set on the form element by the plugin, so it
        // is known inside a subform as well.
        if (!PrettyprotecteddownloadsHelper::supports((string) ($this->element['context'] ?? ''))) {
            return '<div class="alert alert-warning">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_UNSUPPORTED_CONTEXT') . '</div>';
        }

        return parent::getInput();
    }

    /**
     * Keep only rows that name a file uploaded for this item, stored as a JSON list.
     *
     * @param   mixed      $value  The posted rows.
     * @param   ?string    $group  The group.
     * @param   ?Registry  $input  The whole posted form.
     *
     * @return  string
     */
    public function filter($value, $group = null, ?Registry $input = null)
    {
        $entries = [];

        foreach (Entries::decode(parent::filter($value, $group, $input)) as $row) {
            $entry = Entries::normalise($row);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $entries = $this->bound($entries, $input);

        return $entries === [] ? '' : (string) json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Validate the rows rather than the JSON string filter() made of them: Joomla
     * checks every subform field row by row, and loops over the value to do so.
     *
     * @param   mixed      $value  The filtered value.
     * @param   ?string    $group  The group.
     * @param   ?Registry  $input  The whole posted form.
     *
     * @return  bool|\Exception
     */
    public function validate($value, $group = null, ?Registry $input = null)
    {
        // An empty value of a required field stays as it is, for the required check.
        if (\is_string($value) && ($value !== '' || !$this->required)) {
            $value = Entries::decode($value);
        }

        return parent::validate($value, $group, $input);
    }

    /**
     * The entries that may be saved with the item: the files it already lists, and
     * the ones uploaded for it in this session, as long as the file is still there.
     * Any other entry is dropped, with a warning.
     *
     * This runs while the item's form is validated, which is the last moment the
     * posted value can still be changed: the save events only get a copy of it.
     *
     * The item is the one the form data is saved as. Save as Copy resets that to 0,
     * and a subform row does not carry it at all, so then it is the item the request
     * names, and only when the user may edit that item. Save as Copy may so take
     * over the files of the item it copies.
     *
     * @param   array[]    $entries  Normalised entries.
     * @param   ?Registry  $input    The whole posted form, or one subform row.
     *
     * @return  array[]
     */
    private function bound(array $entries, ?Registry $input): array
    {
        if ($entries === []) {
            return [];
        }

        $app     = Factory::getApplication();
        $context = (string) ($this->element['context'] ?? '');
        $itemId  = (int) ($input?->get('id') ?? 0) ?: $this->itemId();
        $known   = self::known($context, $itemId);
        $pending = new PendingUploads($app->getSession(), Settings::cleanupGrace(Settings::params()));
        $kept    = Entries::bound(
            $entries,
            $known ?? [],
            static fn (array $entry): bool => $known !== null && $pending->has($entry['uuid'], $entry['filename'], $context, $itemId)
        );

        self::warnLeftOut($entries, $kept, 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_WARNING_ENTRIES_DROPPED');

        // An entry whose file is gone would only offer a download that fails. Joomla 6
        // saves one again when an older version of the item is restored, without asking
        // this field. A folder that is not there says nothing about its files.
        $storage = Storage::fromParams(Settings::params());

        if (!is_dir($storage->path())) {
            return $kept;
        }

        $present = array_values(array_filter($kept, static fn (array $entry): bool => $storage->locate($entry['filename']) !== null));

        self::warnLeftOut($kept, $present, 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_WARNING_ENTRIES_MISSING');

        return $present;
    }

    /**
     * Tell the editor which entries a save left out.
     *
     * @param   array[]  $entries  The entries before.
     * @param   array[]  $kept     The entries that stay.
     * @param   string   $key      The language key of the message, with a %s for the file names.
     *
     * @return  void
     */
    private static function warnLeftOut(array $entries, array $kept, string $key): void
    {
        if (\count($kept) === \count($entries)) {
            return;
        }

        $names = array_map(
            static fn (array $entry): string => htmlspecialchars(Entries::downloadName($entry), ENT_QUOTES, 'UTF-8'),
            array_udiff($entries, $kept, static fn (array $a, array $b): int => strcmp($a['uuid'], $b['uuid']))
        );

        Factory::getApplication()->enqueueMessage(Text::sprintf($key, implode(', ', $names)), 'warning');
    }

    /**
     * The stored filenames an item lists, or null when the current user may not edit it.
     *
     * Kept for the request, since every field and every subform row asks.
     *
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item id.
     *
     * @return  ?array<string, true>
     */
    private static function known(string $context, int $itemId): ?array
    {
        static $cache = [];

        $key = $context . ':' . $itemId;

        if (!\array_key_exists($key, $cache)) {
            $app    = Factory::getApplication();
            $user   = $app->getIdentity();
            $helper = new PrettyprotecteddownloadsHelper(Factory::getContainer()->get(DatabaseInterface::class), $app);
            $item   = $user && !$user->guest ? $helper->item($context, $itemId, $user) : null;

            $cache[$key] = $item && $item->editable ? $helper->storedFilenames($context, $itemId) : null;
        }

        return $cache[$key];
    }

    /**
     * The item being edited, or 0 for one that has not been saved yet.
     *
     * @return  int
     */
    private function itemId(): int
    {
        $id = (int) ($this->form?->getValue('id') ?? 0);

        if ($id > 0) {
            return $id;
        }

        $input = Factory::getApplication()->getInput();

        return (int) ($input->get('jform', [], 'array')['id'] ?? 0) ?: $input->getInt('a_id', $input->getInt('user_id', $input->getInt('id', 0)));
    }
}
