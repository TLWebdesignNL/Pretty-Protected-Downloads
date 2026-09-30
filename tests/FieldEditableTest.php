<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers who may upload to, or preview the files of, a field: the checks the item
 * form makes before it offers the field.
 */

require_once __DIR__ . '/bootstrap.php';

use Joomla\CMS\User\User;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\PrettyprotecteddownloadsHelper;

/**
 * A published field in no group, open to Public, editable everywhere.
 */
function field(array $overrides = []): object
{
    return (object) ($overrides + [
        'id'           => 5,
        'context'      => 'com_users.user',
        'params'       => '{"show_on":""}',
        'access'       => 1,
        'state'        => 1,
        'group_access' => null,
        'group_state'  => null,
    ]);
}

$editor = new User([1, 2], ['core.edit.value com_users.field.5']);
$super  = new User([1], ['core.admin', 'core.edit.value com_users.field.5']);
$check  = static fn (object $field, User $user, bool $site = true): bool => PrettyprotecteddownloadsHelper::fieldEditable($field, $user, $site);

group('Edit Custom Field Value');
check('granted: editable', $check(field(), $editor));
check('not granted: refused', !$check(field(), new User([1, 2])));
check('granted on another field: refused', !$check(field(['id' => 6]), $editor));
check('asked on the component of the context', $check(field(['context' => 'com_content.article']), new User([1], ['core.edit.value com_content.field.5'])));
check('categories use their component too', $check(field(['context' => 'com_contact.categories']), new User([1], ['core.edit.value com_contact.field.5'])));

group('Field state');
check('unpublished: refused', !$check(field(['state' => 0]), $editor));
check('trashed: refused', !$check(field(['state' => -2]), $editor));
check('in a published group: editable', $check(field(['group_state' => 1, 'group_access' => 1]), $editor));
check('in an unpublished group: refused', !$check(field(['group_state' => 0, 'group_access' => 1]), $editor));

group('Editable In');
check('both, on the site', $check(field(['params' => '{"show_on":""}']), $editor, true));
check('both, in the administrator', $check(field(['params' => '{"show_on":"0"}']), $editor, false));
check('no params at all counts as both', $check(field(['params' => '']), $editor, true));
check('site only, on the site', $check(field(['params' => '{"show_on":"1"}']), $editor, true));
check('site only, in the administrator: refused', !$check(field(['params' => '{"show_on":"1"}']), $editor, false));
check('administrator only, in the administrator', $check(field(['params' => '{"show_on":"2"}']), $editor, false));
check('administrator only, on the site: refused', !$check(field(['params' => '{"show_on":"2"}']), $editor, true));

group('Access levels');
check('field level not held: refused', !$check(field(['access' => 3]), $editor));
check('group level not held: refused', !$check(field(['group_state' => 1, 'group_access' => 3]), $editor));
check('a super user in the administrator is not held to them', $check(field(['access' => 3, 'group_state' => 1, 'group_access' => 3]), $super, false));
check('but is on the site', !$check(field(['access' => 3]), $super, true));
check('and still needs the field published', !$check(field(['state' => 0]), $super, false));

finish();
