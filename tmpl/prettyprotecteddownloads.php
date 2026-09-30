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
 *                 class, name (the name the file downloads as), label (the title, or
 *                 the name when there is none), extension, size (bytes, or null when
 *                 not shown), meta (type and size as text, or '' when not shown),
 *                 hidden (the form's hidden inputs, as HTML; keep them in the form,
 *                 download.js finds its forms by them)
 *   $actionUrl    where each download form posts to
 *   $buttonClass  the extra button classes set on the field
 *   $cardClass    the extra card classes set on the field
 *   $headingLevel the element each card title is: h2 to h6, or p for no heading
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
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
$endpoint    = Uri::root() . 'index.php?option=com_ajax&group=fields&plugin=prettyprotecteddownloads';
$actionUrl   = $endpoint . '&format=raw&task=download';
$tokenUrl    = $endpoint . '&format=json&task=token';
$escape      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$buttonClass = trim((string) $fieldParams->get('button_class', ''));
$cardClass   = trim((string) $fieldParams->get('card_class', ''));
$downloads   = [];

$token        = null;
$headingLevel = (string) $fieldParams->get('heading_level', 'h3');
$headingLevel = \in_array($headingLevel, ['h2', 'h3', 'h4', 'h5', 'h6', 'p'], true) ? $headingLevel : 'h3';

foreach ($entries as $entry) {
    if (!Entries::belongsTogether((string) ($entry['uuid'] ?? ''), (string) ($entry['filename'] ?? ''))) {
        continue;
    }

    $name  = Entries::downloadName($entry);
    $file  = $showMeta ? $storage->locate($entry['filename']) : null;
    $hidden = [
        'uuid'     => $entry['uuid'],
        'context'  => (string) $context,
        'item'     => $itemId,
        'field'    => $field->name,
        $formToken => 1,
    ];

    // One token for all the buttons of this field, for visitors without scripts.
    $token ??= $tokens->issue((string) $context, $itemId, $field->name);
    $title   = trim((string) ($entry['title'] ?? ''));
    $size  = $file !== null ? (int) filesize($file) : null;

    $downloads[] = (object) [
        'title'       => $title,
        'description' => trim((string) ($entry['description'] ?? '')),
        'button'      => trim((string) ($entry['button'] ?? '')),
        'icon'        => trim((string) ($entry['icon'] ?? '')),
        'class'       => trim((string) ($entry['class'] ?? '')) ?: 'btn-primary',
        'name'        => $name,
        'label'       => $title ?: $name,
        'extension'   => Entries::extension($name),
        'size'        => $size,
        'meta'        => $size !== null
            ? Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_META', strtoupper(Entries::extension($name)), HTMLHelper::_('number.bytes', $size))
            : '',
        // The download token is fetched fresh when the button is pressed (download.js),
        // since a cached page carries the tokens of whoever it was rendered for. The
        // one rendered here is only sent when scripts do not run; it comes last, so it
        // wins over the empty one.
        'hidden'      => implode('', array_map(
            static fn ($key, $value): string => '<input type="hidden" name="' . $escape($key) . '" value="' . $escape($value) . '">',
            array_keys($hidden),
            $hidden
        ))
            . '<input type="hidden" name="download_token" value="" data-ppd-token-url="' . $escape($tokenUrl) . '">'
            . '<noscript><input type="hidden" name="download_token" value="' . $escape($token) . '"></noscript>',
    ];
}

if ($downloads === []) {
    return;
}

$document = $app->getDocument();

if ($document instanceof HtmlDocument) {
    $wa = $document->getWebAssetManager();
    $wa->getRegistry()->addExtensionRegistryFile('plg_fields_prettyprotecteddownloads');
    $wa->useScript('plg_fields_prettyprotecteddownloads.download');
}

$display = (string) $fieldParams->get('display_mode', 'buttons');
$display = \in_array($display, ['buttons', 'cards', 'list'], true) ? $display : 'buttons';

require PluginHelper::getLayoutPath('fields', 'prettyprotecteddownloads', 'prettyprotecteddownloads/' . $display);
