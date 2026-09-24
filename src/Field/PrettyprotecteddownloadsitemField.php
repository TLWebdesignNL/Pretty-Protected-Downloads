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
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The upload control of one row: the current file, and a file input that uploads as
 * soon as a file is chosen.
 */
class PrettyprotecteddownloadsitemField extends FormField
{
    /**
     * @var  string
     */
    protected $type = 'Prettyprotecteddownloadsitem';

    /**
     * @return  string
     */
    protected function getInput()
    {
        $itemId   = (int) ($this->element['itemid'] ?? 0);
        $field    = (string) ($this->element['targetfield'] ?? '');
        $uuid     = (string) $this->form->getValue('uuid', null, '');
        $filename = (string) $this->form->getValue('filename', null, '');
        $original = (string) $this->form->getValue('original', null, '');
        $params   = Settings::params();
        $maxBytes = Settings::maxBytes($params);
        $allowed  = Settings::allowedExtensions($params);
        $endpoint = Uri::base() . 'index.php?option=com_ajax&group=fields&plugin=prettyprotecteddownloads'
            . '&item=' . $itemId . '&field=' . rawurlencode($field);

        $this->loadAssets();

        $escape  = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $current = $uuid !== '' && $filename !== ''
            ? Entries::downloadName(['uuid' => $uuid, 'filename' => $filename, 'original' => $original])
            : '';
        $enabled = $itemId > 0 ? '' : ' disabled';

        $html   = [];
        $html[] = '<div class="ppd-control"'
            . ' data-upload-url="' . $escape($endpoint . '&format=json&task=upload') . '"'
            . ' data-token="' . $escape(Session::getFormToken()) . '"'
            . ' data-preview-url="' . $escape($endpoint . '&format=raw&task=preview&uuid=__UUID__&filename=__FILE__') . '"'
            . ' data-max-bytes="' . $maxBytes . '"'
            . ' data-max-label="' . $escape($maxBytes > 0 ? HTMLHelper::_('number.bytes', $maxBytes) : '') . '"'
            . ' data-extensions="' . $escape(implode(',', $allowed)) . '">';
        $html[] = '<div class="ppd-current mb-2">';
        $html[] = '<span class="ppd-empty text-muted small"' . ($current !== '' ? ' hidden' : '') . '>'
            . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_NO_FILE') . '</span>';
        $html[] = '<a class="ppd-link link-primary text-decoration-none" target="_blank" rel="noopener"'
            . ($current !== '' ? '' : ' hidden')
            . ' href="' . $escape($current !== '' ? str_replace(['__UUID__', '__FILE__'], [rawurlencode($uuid), rawurlencode($filename)], $endpoint . '&format=raw&task=preview&uuid=__UUID__&filename=__FILE__') : '#') . '">'
            . '<span class="icon-file-alt me-1" aria-hidden="true"></span><span class="ppd-name">' . $escape($current) . '</span></a>';
        $html[] = '</div>';
        $html[] = '<input type="file" class="form-control ppd-input" accept="' . $escape('.' . implode(',.', $allowed)) . '"'
            . ' aria-label="' . $escape(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ENTRY_FILE_LABEL')) . '"' . $enabled . '>';
        $html[] = '<div class="form-text">' . $escape(Text::sprintf(
            'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_UPLOAD_HINT',
            implode(', ', $allowed),
            $maxBytes > 0 ? HTMLHelper::_('number.bytes', $maxBytes) : '-'
        )) . '</div>';
        $html[] = '<div class="ppd-progress progress mt-2" hidden role="progressbar" aria-valuemin="0" aria-valuemax="100">'
            . '<div class="progress-bar progress-bar-striped progress-bar-animated"></div></div>';
        $html[] = '<div class="ppd-status mt-2 small" role="status" aria-live="polite"></div>';

        if ($itemId <= 0) {
            $html[] = '<div class="alert alert-info mt-2 mb-0 py-2 px-3">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_SAVE_FIRST') . '</div>';
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * The script, once per page, and the texts it shows.
     *
     * @return  void
     */
    private function loadAssets(): void
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_fields_prettyprotecteddownloads');
        $wa->useScript('plg_fields_prettyprotecteddownloads.admin');

        foreach (['UPLOADING', 'UPLOADED', 'UPLOAD_FAILED', 'ERROR_TOO_LARGE', 'ERROR_EXTENSION_SHORT', 'PENDING_UPLOAD'] as $key) {
            Text::script('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_JS_' . $key);
        }
    }
}
