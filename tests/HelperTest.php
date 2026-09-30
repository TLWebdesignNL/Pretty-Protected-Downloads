<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the database side of every access decision: who may see and edit an
 * article, contact, category or user profile, which field a request names and which
 * files it lists, which files other items still use, and the whole download check.
 * The queries run for real, on SQLite; see Database.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Database.php';

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Extension\ExtensionManagerInterface;
use Joomla\CMS\User\User;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PrettyprotecteddownloadsHelper;

TestClock::$now = strtotime('2026-06-01 12:00:00 UTC');

/**
 * An entry for a new file.
 */
function entry(string $name): array
{
    $uuid = Entries::uuid();

    return ['uuid' => $uuid, 'filename' => Entries::storedName($name . '.pdf', $uuid), 'original' => $name . '.pdf'];
}

/**
 * The component service of com_content, whose category tree holds only category 10.
 */
$components = new class () implements ExtensionManagerInterface {
    public function bootComponent($component)
    {
        return $component !== 'com_content' ? null : new class () implements CategoryServiceInterface {
            public function getCategory(array $options = [], $section = '')
            {
                return new class () {
                    public function get($id)
                    {
                        return (int) $id === 10 ? (object) ['id' => 10] : null;
                    }
                };
            }
        };
    }
};

$db     = new TestDatabase();
$helper = new PrettyprotecteddownloadsHelper($db, $components);

// ── Fixtures ──────────────────────────────────────────────────────────────

foreach (
    [
        [10, 'com_content', 1, 1, 5],
        [11, 'com_content', 0, 1, 5],
        [12, 'com_content', 1, 3, 5],
        [20, 'com_contact', 1, 1, 5],
    ] as [$id, $extension, $published, $access, $owner]
) {
    $db->insert('categories', ['id' => $id, 'extension' => $extension, 'published' => $published, 'access' => $access, 'created_user_id' => $owner]);
}

$articles = [
    1 => ['published', 1, 10, 1, null, null],
    2 => ['unpublished', 0, 10, 1, null, null],
    3 => ['archived', 2, 10, 1, null, null],
    4 => ['trashed', -2, 10, 1, null, null],
    5 => ['not yet published', 1, 10, 1, '2026-07-01 00:00:00', null],
    6 => ['no longer published', 1, 10, 1, null, '2026-05-01 00:00:00'],
    7 => ['on a level the visitor lacks', 1, 10, 3, null, null],
    8 => ['in a category on a level the visitor lacks', 1, 12, 1, null, null],
    9 => ['in an unpublished category', 1, 11, 1, null, null],
    13 => ['published up in the past and down in the future', 1, 10, 1, '2026-01-01 00:00:00', '2026-12-31 00:00:00'],
];

foreach ($articles as $id => [, $state, $catid, $access, $up, $down]) {
    $db->insert('content', ['id' => $id, 'catid' => $catid, 'state' => $state, 'access' => $access, 'created_by' => 5, 'publish_up' => $up, 'publish_down' => $down]);
}

$db->insert('contact_details', ['id' => 1, 'catid' => 20, 'published' => 1, 'access' => 1, 'created_by' => 5]);
$db->insert('contact_details', ['id' => 2, 'catid' => 20, 'published' => 0, 'access' => 1, 'created_by' => 5]);
$db->insert('users', ['id' => 5, 'block' => 0]);
$db->insert('users', ['id' => 6, 'block' => 1]);
$db->insert('fields_groups', ['id' => 1, 'access' => 1, 'state' => 0]);
$db->insert('fields_groups', ['id' => 2, 'access' => 3, 'state' => 1]);

$fields = [
    [100, 'com_content.article', 0, 'files', 'prettyprotecteddownloads', 1, 1],
    [101, 'com_content.article', 0, 'unpublished', 'prettyprotecteddownloads', 1, 0],
    [102, 'com_content.article', 0, 'special', 'prettyprotecteddownloads', 3, 1],
    [103, 'com_content.article', 1, 'in-unpublished-group', 'prettyprotecteddownloads', 1, 1],
    [104, 'com_content.article', 2, 'in-special-group', 'prettyprotecteddownloads', 1, 1],
    [105, 'com_content.article', 0, 'rows', 'subform', 1, 1],
    [106, 'com_content.article', 0, 'child', 'prettyprotecteddownloads', 1, 1],
    [107, 'com_contact.contact', 0, 'files', 'prettyprotecteddownloads', 1, 1],
    [108, 'com_content.article', 0, 'plain', 'text', 1, 1],
    [109, 'com_users.user', 0, 'files', 'prettyprotecteddownloads', 1, 1],
    [110, 'com_content.categories', 0, 'files', 'prettyprotecteddownloads', 1, 1],
];

foreach ($fields as [$id, $context, $group, $name, $type, $access, $state]) {
    $db->insert('fields', [
        'id'          => $id,
        'context'     => $context,
        'group_id'    => $group,
        'name'        => $name,
        'type'        => $type,
        'params'      => '{"show_on":""}',
        'fieldparams' => $type === 'subform' ? '{"options":{"option0":{"customfield":"106"},"option1":{"customfield":"108"}}}' : '{}',
        'access'      => $access,
        'state'       => $state,
    ]);
}

$a = entry('annual-report');
$b = entry('draft');
$c = entry('appendix');
$d = entry('contact-sheet');
$u = entry('payslip');
$k = entry('category-guide');

$value = static fn (array ...$entries): string => json_encode($entries);

foreach ([100, 101, 102, 103, 104] as $fieldId) {
    $db->insert('fields_values', ['field_id' => $fieldId, 'item_id' => '1', 'value' => $value($a)]);
}

$db->insert('fields_values', ['field_id' => 100, 'item_id' => '2', 'value' => $value($b)]);
$db->insert('fields_values', ['field_id' => 105, 'item_id' => '1', 'value' => json_encode(['row0' => ['field106' => [$c], 'field108' => 'text']])]);
$db->insert('fields_values', ['field_id' => 107, 'item_id' => '1', 'value' => $value($d)]);
$db->insert('fields_values', ['field_id' => 109, 'item_id' => '5', 'value' => $value($u)]);
$db->insert('fields_values', ['field_id' => 110, 'item_id' => '10', 'value' => $value($k)]);

$guest   = new User([1]);
$member  = new User([1, 2], [], 9);
$special = new User([1, 2, 3], [], 9);
$owner   = new User([1, 2], ['core.edit.own com_content.article.1', 'core.edit.own com_content.category.10'], 5);
$editor  = new User([1, 2], ['core.edit com_content.article.2', 'core.edit com_users'], 9);

// ── Articles ──────────────────────────────────────────────────────────────

group('Who sees an article');
check('there is no article 99', $helper->item('com_content.article', 99, $guest) === null);
check('item 0 is never an item', $helper->item('com_content.article', 0, $guest) === null);
check('an unsupported context has no items', $helper->item('com_foo.bar', 1, $guest) === null);

foreach ($articles as $id => [$label, $state, $catid, $access, $up, $down]) {
    $expected = in_array($id, [1, 3, 13], true);
    check(($expected ? 'visible: ' : 'hidden: ') . $label, $helper->item('com_content.article', $id, $guest)->visible === $expected);
}

check('a visitor with the level sees the article on it', $helper->item('com_content.article', 7, $special)->visible);
check('and the one in the category on it', $helper->item('com_content.article', 8, $special)->visible);

group('Who edits an article');
check('a guest does not', !$helper->item('com_content.article', 1, $guest)->editable);
check('its author with Edit Own does', $helper->item('com_content.article', 1, $owner)->editable);
check('Edit Own does not reach another author\'s article', !$helper->item('com_content.article', 2, new User([1], ['core.edit.own com_content.article.2'], 9))->editable);
check('Edit on the article does', $helper->item('com_content.article', 2, $editor)->editable);
check('Edit on one article does not reach another', !$helper->item('com_content.article', 1, $editor)->editable);

// ── Contacts ──────────────────────────────────────────────────────────────

group('Contacts');
check('a published contact is visible', $helper->item('com_contact.contact', 1, $guest)->visible);
check('an unpublished one is not, read from its own state column', !$helper->item('com_contact.contact', 2, $guest)->visible);
check('contact ids are not article ids', $helper->item('com_contact.contact', 3, $guest) === null);

// ── Categories ────────────────────────────────────────────────────────────

group('Categories');
check('a category in the component\'s tree is visible', $helper->item('com_content.categories', 10, $guest)->visible);
check('one outside it is not', !$helper->item('com_content.categories', 11, $guest)->visible);
check('a category of another component is not one of this component', $helper->item('com_content.categories', 20, $guest) === null);
check('its creator with Edit Own edits it', $helper->item('com_content.categories', 10, $owner)->editable);
check('a component without a category service shows nothing', !$helper->item('com_contact.categories', 20, $guest)->visible);

// ── User profiles ─────────────────────────────────────────────────────────

group('User profiles');
check('a profile is visible to its owner', $helper->item('com_users.user', 5, new User([1], [], 5))->visible);
check('and to nobody else', !$helper->item('com_users.user', 5, $member)->visible);
check('nor to a guest', !$helper->item('com_users.user', 5, $guest)->visible);
check('not even its owner sees a blocked profile', !$helper->item('com_users.user', 6, new User([1], [], 6))->visible);
check('its owner edits it', $helper->item('com_users.user', 5, new User([1], [], 5))->editable);
check('so does whoever may edit users', $helper->item('com_users.user', 5, $editor)->editable);
check('nobody else does', !$helper->item('com_users.user', 5, $member)->editable);
check('a user that does not exist is no item', $helper->item('com_users.user', 99, $editor) === null);

// ── Fields ────────────────────────────────────────────────────────────────

group('Finding a field');
$field = $helper->field('com_content.article', 'files', 1);
check('by its name', $field !== null && (int) $field->id === 100);
check('with its context and params, for the edit check', $field->context === 'com_content.article' && $field->params === '{"show_on":""}');
check('with its entries for this item', $field->entries === [$a]);
check('another item\'s value is not this item\'s', $helper->field('com_content.article', 'files', 2)->entries === [$b]);
check('an item without a value has no entries', $helper->field('com_content.article', 'files', 3)->entries === []);
check('by field{id}, as inside a subform', (int) $helper->field('com_content.article', 'field100', 1)->id === 100);
check('not a field of another context', $helper->field('com_content.article', 'field107', 1) === null);
check('not a field of another type', $helper->field('com_content.article', 'plain', 1) === null);
check('not a field that does not exist', $helper->field('com_content.article', 'nothing', 1) === null);
check('a field in no group has no group state', $helper->field('com_content.article', 'files', 1)->group_state === null);
check('a grouped field carries its group state and access', (int) $helper->field('com_content.article', 'in-special-group', 1)->group_access === 3);
check('a field used only in a subform lists the subform\'s entries', $helper->field('com_content.article', 'field106', 1)->entries === [$c]);

group('Stored files of one item');
$stored = $helper->storedFilenames('com_content.article', 1);
check('its own fields\' files', isset($stored[$a['filename']]));
check('and those in its subforms', isset($stored[$c['filename']]));
check('not another item\'s', !isset($stored[$b['filename']]));
check('not the same id in another context', !isset($stored[$d['filename']]));

group('Files still in use anywhere');
$all = $helper->referencedFilenames();
check('every value of every item counts', count(array_intersect_key($all, array_flip([$a['filename'], $b['filename'], $c['filename'], $d['filename'], $u['filename']]))) === 5);
$without = $helper->referencedFilenames('com_content.article', 1);
check('leaving a deleted item out drops its files', !isset($without[$a['filename']]) && !isset($without[$c['filename']]));
check('but not the files of other items', isset($without[$b['filename']]));
check('nor of the same id in another context', isset($without[$d['filename']]));

// ── The download check ────────────────────────────────────────────────────

group('May this visitor download this file');
$download = static fn (User $user, string $context, int $item, string $name, string $uuid) => $helper->downloadable($user, $context, $item, $name, $uuid);
check('yes: a published article and field', $download($guest, 'com_content.article', 1, 'files', $a['uuid']) === $a);
check('yes: from a subform', $download($guest, 'com_content.article', 1, 'field106', $c['uuid']) === $c);
check('yes: a contact', $download($guest, 'com_contact.contact', 1, 'files', $d['uuid']) === $d);
check('yes: a category', $download($guest, 'com_content.categories', 10, 'files', $k['uuid']) === $k);
check('yes: a profile, to its owner', $download(new User([1], [], 5), 'com_users.user', 5, 'files', $u['uuid']) === $u);
check('no: a profile, to anyone else', $download($member, 'com_users.user', 5, 'files', $u['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('no: a hidden article', $download($guest, 'com_content.article', 2, 'files', $b['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('no: an unpublished field', $download($guest, 'com_content.article', 1, 'unpublished', $a['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('no: a field on a level the visitor lacks', $download($guest, 'com_content.article', 1, 'special', $a['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('yes: that field, to a visitor with the level', $download($special, 'com_content.article', 1, 'special', $a['uuid']) === $a);
check('no: a field in an unpublished group', $download($special, 'com_content.article', 1, 'in-unpublished-group', $a['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('no: a field in a group on a level the visitor lacks', $download($guest, 'com_content.article', 1, 'in-special-group', $a['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('yes: that field, to a visitor with the level', $download($special, 'com_content.article', 1, 'in-special-group', $a['uuid']) === $a);
check('no: another item\'s file through this item', $download($guest, 'com_content.article', 1, 'files', $b['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NOT_FOUND');
check('no: a file of this item through another field', $download($guest, 'com_content.article', 1, 'field106', $a['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NOT_FOUND');
check('no: a field of another context', $download($guest, 'com_content.article', 1, 'field107', $d['uuid']) === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NO_ACCESS');
check('no: nothing asked for', $download($guest, 'com_content.article', 1, 'files', '') === 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_NOT_FOUND');

finish();
