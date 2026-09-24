<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The database reads the plugin needs: the article a file belongs to, the field that
 * lists it, and which stored files are still named by any field at all.
 */
final class Repository
{
    /**
     * The field type this plugin provides.
     */
    public const TYPE = 'prettyprotecteddownloads';

    /**
     * The one context the plugin serves files for. Its access rules are the ones the
     * download checks, so another context would need rules of its own.
     */
    public const CONTEXT = 'com_content.article';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * An article with what the access checks need, or null.
     *
     * @param   int  $id  The article id.
     *
     * @return  ?object
     */
    public function article(int $id): ?object
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(
                ['a.id', 'a.catid', 'a.state', 'a.access', 'a.created_by', 'a.publish_up', 'a.publish_down', 'c.access', 'c.published'],
                ['id', 'catid', 'state', 'access', 'created_by', 'publish_up', 'publish_down', 'category_access', 'category_published']
            ))
            ->from($db->quoteName('#__content', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
            ->where($db->quoteName('a.id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * A Pretty Protected Downloads field with its value for one article, or null.
     *
     * The field is looked up by name, or by "field{id}" as it is called inside a
     * subform. A field that only appears inside subforms has no value of its own, so its
     * entries are then collected from the subforms that contain it.
     *
     * @param   string  $name    The field name.
     * @param   int     $itemId  The article id.
     *
     * @return  ?object  With id, name, access, state, group_access, group_state and entries.
     */
    public function field(string $name, int $itemId): ?object
    {
        $db     = $this->db;
        $item   = (string) $itemId;
        $type   = self::TYPE;
        $context = self::CONTEXT;
        $query  = $db->getQuery(true)
            ->select($db->quoteName(
                ['f.id', 'f.name', 'f.access', 'f.state', 'g.access', 'g.state', 'fv.value'],
                ['id', 'name', 'access', 'state', 'group_access', 'group_state', 'value']
            ))
            ->from($db->quoteName('#__fields', 'f'))
            ->join('LEFT', $db->quoteName('#__fields_groups', 'g'), $db->quoteName('g.id') . ' = ' . $db->quoteName('f.group_id'))
            ->join(
                'LEFT',
                $db->quoteName('#__fields_values', 'fv'),
                $db->quoteName('fv.field_id') . ' = ' . $db->quoteName('f.id') . ' AND ' . $db->quoteName('fv.item_id') . ' = :item'
            )
            ->where($db->quoteName('f.context') . ' = :context')
            ->where($db->quoteName('f.type') . ' = :type')
            ->bind(':item', $item)
            ->bind(':context', $context)
            ->bind(':type', $type);

        if (preg_match('/^field(\d+)$/', $name, $matches)) {
            $id = (int) $matches[1];
            $query->where($db->quoteName('f.id') . ' = :fieldid')->bind(':fieldid', $id, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('f.name') . ' = :name')->bind(':name', $name);
        }

        $field = $db->setQuery($query, 0, 1)->loadObject();

        if (!$field) {
            return null;
        }

        $field->entries = Entries::decode($field->value ?? '');

        if ($field->entries === []) {
            $field->entries = $this->entriesInSubforms((int) $field->id, $itemId);
        }

        unset($field->value);

        return $field;
    }

    /**
     * The Pretty Protected Downloads fields of the context, with their values for one article.
     *
     * @param   int  $itemId  The article id.
     *
     * @return  object[]  With id, name and value.
     */
    public function fieldsWithValues(int $itemId): array
    {
        return $this->fieldsOfType(self::TYPE, $itemId);
    }

    /**
     * The subform fields of the context, with their values for one article.
     *
     * @param   int  $itemId  The article id.
     *
     * @return  object[]  With id, name, fieldparams and value.
     */
    public function subformsWithValues(int $itemId): array
    {
        return $this->fieldsOfType('subform', $itemId);
    }

    /**
     * The ids of all Pretty Protected Downloads fields, as array keys.
     *
     * @return  array<int, true>
     */
    public function fieldIds(): array
    {
        $db      = $this->db;
        $type    = self::TYPE;
        $query   = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('type') . ' = :type')
            ->bind(':type', $type);

        return array_fill_keys(array_map('intval', $db->setQuery($query)->loadColumn() ?: []), true);
    }

    /**
     * Every stored filename any field value still names, across all items, as keys.
     *
     * This is what makes deleting a file safe: an article saved as a copy shares its
     * files with the original, so a file removed from one may still be listed on the
     * other and must then stay.
     *
     * @return  array<string, true>
     */
    public function referencedFilenames(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('fv.value'))
            ->from($db->quoteName('#__fields_values', 'fv'))
            ->join('INNER', $db->quoteName('#__fields', 'f'), $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'))
            ->whereIn($db->quoteName('f.type'), [self::TYPE, 'subform'], ParameterType::STRING);

        $names = [];

        foreach ($db->setQuery($query)->loadColumn() ?: [] as $value) {
            foreach (Entries::filenamesIn($value) as $filename) {
                $names[$filename] = true;
            }
        }

        return $names;
    }

    /**
     * The fields of one type in the context, with their values for one article.
     *
     * @param   string  $type    The field type.
     * @param   int     $itemId  The article id.
     *
     * @return  object[]
     */
    private function fieldsOfType(string $type, int $itemId): array
    {
        $db      = $this->db;
        $item    = (string) $itemId;
        $context = self::CONTEXT;
        $query   = $db->getQuery(true)
            ->select($db->quoteName(['f.id', 'f.name', 'f.fieldparams', 'fv.value'], ['id', 'name', 'fieldparams', 'value']))
            ->from($db->quoteName('#__fields', 'f'))
            ->join(
                'LEFT',
                $db->quoteName('#__fields_values', 'fv'),
                $db->quoteName('fv.field_id') . ' = ' . $db->quoteName('f.id') . ' AND ' . $db->quoteName('fv.item_id') . ' = :item'
            )
            ->where($db->quoteName('f.context') . ' = :context')
            ->where($db->quoteName('f.type') . ' = :type')
            ->bind(':item', $item)
            ->bind(':context', $context)
            ->bind(':type', $type);

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * The entries a child field holds in every subform of one article that contains it.
     *
     * @param   int  $fieldId  The child field id.
     * @param   int  $itemId   The article id.
     *
     * @return  array[]
     */
    private function entriesInSubforms(int $fieldId, int $itemId): array
    {
        $entries = [];

        foreach ($this->subformsWithValues($itemId) as $subform) {
            if (\in_array($fieldId, Entries::subformChildIds($subform->fieldparams ?? '', [$fieldId => true]), true)) {
                array_push($entries, ...Entries::fromSubform($subform->value ?? '', 'field' . $fieldId));
            }
        }

        return $entries;
    }
}
