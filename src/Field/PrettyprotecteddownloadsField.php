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
use Joomla\Registry\Registry;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Repository;

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

        $itemId = $this->itemId();
        $field  = htmlspecialchars((string) $this->fieldname, ENT_QUOTES | ENT_XML1, 'UTF-8');
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
        // Judged by the component rather than the form name, because inside a subform
        // field the form is named after the subform, not after the article.
        if (Factory::getApplication()->getInput()->getCmd('option') !== strtok(Repository::CONTEXT, '.')) {
            return '<div class="alert alert-warning">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ONLY_ARTICLES') . '</div>';
        }

        return parent::getInput();
    }

    /**
     * Keep only rows that name an uploaded file, stored as a JSON list.
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

        return $entries === [] ? '' : (string) json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The article being edited, or 0 for one that has not been saved yet.
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

        return (int) ($input->get('jform', [], 'array')['id'] ?? 0) ?: $input->getInt('a_id', $input->getInt('id', 0));
    }
}
