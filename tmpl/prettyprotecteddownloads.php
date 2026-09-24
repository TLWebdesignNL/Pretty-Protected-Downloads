<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Renders the downloads of one field. This file prepares them and hands them to the
 * display layout chosen for the field (tmpl/prettyprotecteddownloads/{buttons,cards,list}.php).
 * Those are the files to override, in
 * templates/{template}/html/plg_fields_prettyprotecteddownloads/prettyprotecteddownloads/.
 *
 * Available here (from FieldsPlugin::onCustomFieldsPrepareField):
 *   $context      the fields context, such as com_content.article
 *   $field        the field; $field->value is its list of entries
 *   $item         the item the field is on
 *   $fieldParams  the plugin parameters merged with the field's own (Registry)
 *
 * Available to the display layout:
 *   $downloads    list of objects with: title, description (plain text), button, icon,
 *                 class, name (the name the file downloads as), extension, size (bytes,
 *                 or null when not shown), hidden (the form's hidden inputs, as HTML)
 *   $actionUrl    where each download form posts to
 *   $buttonClass  the extra button classes set on the field
 *   $cardClass    the extra card classes set on the field
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\DownloadTokens;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Settings;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Storage;

$entries = Entries::decode($field->value);
$itemId  = (int) ($item->id ?? 0);

if ($entries === [] || $itemId <= 0) {
    return;
}

$app         = Factory::getApplication();
$tokens      = new DownloadTokens($app->getSession(), Settings::tokenLifetime($this->params));
$storage     = Storage::fromParams($this->params);
$showMeta    = (bool) $fieldParams->get('show_meta', 1);
$formToken   = Session::getFormToken();
$actionUrl   = Uri::root() . 'index.php?option=com_ajax&group=fields&plugin=prettyprotecteddownloads&format=raw&task=download';
$buttonClass = trim((string) $fieldParams->get('button_class', ''));
$cardClass   = trim((string) $fieldParams->get('card_class', ''));
$downloads   = [];

foreach ($entries as $entry) {
    if (!Entries::belongsTogether((string) ($entry['uuid'] ?? ''), (string) ($entry['filename'] ?? ''))) {
        continue;
    }

    $name  = Entries::downloadName($entry);
    $file  = $showMeta ? $storage->locate($entry['filename']) : null;
    $token = $tokens->issue($entry['uuid'], (string) $context, $itemId, $field->name);

    $hidden = [
        'uuid'           => $entry['uuid'],
        'context'        => (string) $context,
        'item'           => $itemId,
        'field'          => $field->name,
        'download_token' => $token,
        $formToken       => 1,
    ];

    $downloads[] = (object) [
        'title'       => trim((string) ($entry['title'] ?? '')),
        'description' => trim((string) ($entry['description'] ?? '')),
        'button'      => trim((string) ($entry['button'] ?? '')),
        'icon'        => trim((string) ($entry['icon'] ?? '')),
        'class'       => trim((string) ($entry['class'] ?? '')) ?: 'btn-primary',
        'name'        => $name,
        'extension'   => Entries::extension($name),
        'size'        => $file !== null ? (int) filesize($file) : null,
        'hidden'      => implode('', array_map(
            static fn ($key, $value): string => '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">',
            array_keys($hidden),
            $hidden
        )),
    ];
}

if ($downloads === []) {
    return;
}

$display = (string) $fieldParams->get('display_mode', 'buttons');
$display = \in_array($display, ['buttons', 'cards', 'list'], true) ? $display : 'buttons';

require PluginHelper::getLayoutPath('fields', 'prettyprotecteddownloads', 'prettyprotecteddownloads/' . $display);
