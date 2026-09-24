<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper;

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Extension\ExtensionManagerInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The database reads the plugin needs: whether a user may see or edit the item a
 * file belongs to, the field that lists it, and which stored files are still named
 * by any field at all.
 */
final class Repository
{
    /**
     * The field type this plugin provides.
     */
    public const TYPE = 'prettyprotecteddownloads';

    /**
     * The contexts with a rule for who may see an item, and so the ones the field
     * can be used in. Anything else shows a notice instead of the upload control, and
     * nothing is ever served for it.
     */
    public const CONTEXTS = [
        'com_content.article',
        'com_content.categories',
        'com_contact.contact',
        'com_contact.categories',
        'com_users.user',
    ];

    /**
     * Articles and contacts: the same shape under different names.
     */
    private const CONTENT = [
        'com_content.article' => ['table' => '#__content', 'state' => 'state', 'asset' => 'com_content.article'],
        'com_contact.contact' => ['table' => '#__contact_details', 'state' => 'published', 'asset' => 'com_contact.contact'],
    ];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ExtensionManagerInterface $app
    ) {
    }

    /**
     * @param   string  $context  A fields context.
     *
     * @return  bool
     */
    public static function supports(string $context): bool
    {
        return \in_array($context, self::CONTEXTS, true);
    }

    /**
     * Whether a user may see, and may edit, an item. Null when there is no such item.
     *
     * "See" is what the item's own component asks before it shows the item, so a
     * download is served exactly when the page it sits on would be. "Edit" is what
     * the item's edit form asks.
     *
     * @param   string  $context  The fields context.
     * @param   int     $id       The item id.
     * @param   User    $user     The user, a guest included.
     *
     * @return  ?object  With visible and editable.
     */
    public function item(string $context, int $id, User $user): ?object
    {
        if ($id <= 0 || !self::supports($context)) {
            return null;
        }

        if (isset(self::CONTENT[$context])) {
            return $this->content($context, $id, $user);
        }

        if ($context === 'com_users.user') {
            return $this->user($id, $user);
        }

        return $this->category((string) strtok($context, '.'), $id, $user);
    }

    /**
     * An article or a contact: shown when published or archived, within its dates, in
     * a published category, and open to the user on both its own access level and
     * its category's.
     *
     * @param   string  $context  The fields context.
     * @param   int     $id       The item id.
     * @param   User    $user     The user.
     *
     * @return  ?object
     */
    private function content(string $context, int $id, User $user): ?object
    {
        $shape = self::CONTENT[$context];
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(
                ['a.id', 'a.' . $shape['state'], 'a.access', 'a.created_by', 'a.publish_up', 'a.publish_down', 'c.access', 'c.published'],
                ['id', 'state', 'access', 'created_by', 'publish_up', 'publish_down', 'category_access', 'category_published']
            ))
            ->from($db->quoteName($shape['table'], 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
            ->where($db->quoteName('a.id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        $now     = Factory::getDate()->toSql();
        $levels  = $user->getAuthorisedViewLevels();
        $visible = \in_array((int) $row->state, [1, 2], true)
            && (int) $row->category_published > 0
            && (empty($row->publish_up) || $row->publish_up <= $now)
            && (empty($row->publish_down) || $row->publish_down > $now)
            && \in_array((int) $row->access, $levels, true)
            && \in_array((int) $row->category_access, $levels, true);

        return $this->access($visible, $shape['asset'] . '.' . $id, (int) $row->created_by, $user);
    }

    /**
     * A category: shown when Joomla's own category tree holds it. The tree is built
     * for the current user and contains only published categories they may see, each
     * reachable through parents they may see, so that one question covers it all.
     *
     * @param   string  $component  The component the category belongs to.
     * @param   int     $id         The category id.
     * @param   User    $user       The user.
     *
     * @return  ?object
     */
    private function category(string $component, int $id, User $user): ?object
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('created_user_id'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('extension') . ' = :extension')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':extension', $component);

        $owner = $db->setQuery($query)->loadResult();

        if ($owner === null) {
            return null;
        }

        $extension = $this->app->bootComponent($component);
        $visible   = $extension instanceof CategoryServiceInterface && (bool) $extension->getCategory()->get($id);

        return $this->access($visible, $component . '.category.' . $id, (int) $owner, $user);
    }

    /**
     * A user account: its profile is shown to its owner alone, and edited by its owner
     * or by whoever may edit users.
     *
     * @param   int   $id    The user id.
     * @param   User  $user  The user asking.
     *
     * @return  ?object
     */
    private function user(int $id, User $user): ?object
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('block'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $block = $db->setQuery($query)->loadResult();

        if ($block === null) {
            return null;
        }

        $own = !$user->guest && (int) $user->id === $id;

        return (object) [
            'visible'  => $own && (int) $block === 0,
            'editable' => $own || $user->authorise('core.edit', 'com_users'),
        ];
    }

    /**
     * @param   bool    $visible  Whether the user may see the item.
     * @param   string  $asset    The asset its edit permissions are checked on.
     * @param   int     $owner    The user who created it.
     * @param   User    $user     The user.
     *
     * @return  object
     */
    private function access(bool $visible, string $asset, int $owner, User $user): object
    {
        return (object) [
            'visible'  => $visible,
            'editable' => $user->authorise('core.edit', $asset)
                || ($owner > 0 && $owner === (int) $user->id && $user->authorise('core.edit.own', $asset)),
        ];
    }

    /**
     * A Pretty Protected Downloads field of a context with its value for one item, or null.
     *
     * The field is looked up by name, or by "field{id}" as it is called inside a
     * subform. A field that only appears inside subforms has no value of its own, so its
     * entries are then collected from the subforms that contain it.
     *
     * @param   string  $context  The fields context.
     * @param   string  $name     The field name.
     * @param   int     $itemId   The item id.
     *
     * @return  ?object  With id, name, access, state, group_access, group_state and entries.
     */
    public function field(string $context, string $name, int $itemId): ?object
    {
        $db    = $this->db;
        $item  = (string) $itemId;
        $type  = self::TYPE;
        $query = $db->getQuery(true)
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
            $field->entries = $this->entriesInSubforms($context, (int) $field->id, $itemId);
        }

        unset($field->value);

        return $field;
    }

    /**
     * The Pretty Protected Downloads fields of a context, with their values for one item.
     *
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item id.
     *
     * @return  object[]  With id, name and value.
     */
    public function fieldsWithValues(string $context, int $itemId): array
    {
        return $this->fieldsOfType($context, self::TYPE, $itemId);
    }

    /**
     * The subform fields of a context, with their values for one item.
     *
     * @param   string  $context  The fields context.
     * @param   int     $itemId   The item id.
     *
     * @return  object[]  With id, name, fieldparams and value.
     */
    public function subformsWithValues(string $context, int $itemId): array
    {
        return $this->fieldsOfType($context, 'subform', $itemId);
    }

    /**
     * The ids of all Pretty Protected Downloads fields, as array keys.
     *
     * @return  array<int, true>
     */
    public function fieldIds(): array
    {
        $db    = $this->db;
        $type  = self::TYPE;
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('type') . ' = :type')
            ->bind(':type', $type);

        return array_fill_keys(array_map('intval', $db->setQuery($query)->loadColumn() ?: []), true);
    }

    /**
     * Every stored filename any field value still names, across all items and
     * contexts, as keys.
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
     * The fields of one type in a context, with their values for one item.
     *
     * @param   string  $context  The fields context.
     * @param   string  $type     The field type.
     * @param   int     $itemId   The item id.
     *
     * @return  object[]
     */
    private function fieldsOfType(string $context, string $type, int $itemId): array
    {
        $db    = $this->db;
        $item  = (string) $itemId;
        $query = $db->getQuery(true)
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
     * The entries a child field holds in every subform of one item that contains it.
     *
     * @param   string  $context  The fields context.
     * @param   int     $fieldId  The child field id.
     * @param   int     $itemId   The item id.
     *
     * @return  array[]
     */
    private function entriesInSubforms(string $context, int $fieldId, int $itemId): array
    {
        $entries = [];

        foreach ($this->subformsWithValues($context, $itemId) as $subform) {
            if (\in_array($fieldId, Entries::subformChildIds($subform->fieldparams ?? '', [$fieldId => true]), true)) {
                array_push($entries, ...Entries::fromSubform($subform->value ?? '', 'field' . $fieldId));
            }
        }

        return $entries;
    }
}
