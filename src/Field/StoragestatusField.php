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
use Joomla\Database\DatabaseInterface;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Extension\Prettyprotecteddownloads;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Repository;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Settings;
use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Storage;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The state of the storage folder, on the plugin's settings screen, as it was last
 * saved: where the files go, whether that works, whether the folder is really closed
 * to the web, and how many stored files no field uses any more.
 */
class StoragestatusField extends FormField
{
    /**
     * @var  string
     */
    protected $type = 'Storagestatus';

    /**
     * @return  string
     */
    protected function getInput()
    {
        $params  = Settings::params();
        $storage = Storage::fromParams($params);
        $status  = $storage->status();
        $rows    = [];

        if (!$status['configured']) {
            return $this->alert('danger', Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_NOT_CONFIGURED'));
        }

        $rows[] = [Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_PATH'), '<code>' . htmlspecialchars($status['path'], ENT_QUOTES, 'UTF-8') . '</code>'];

        if ($status['exists']) {
            $rows[] = [
                Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_WRITABLE'),
                $this->badge($status['writable'], $status['writable'] ? 'JYES' : 'JNO'),
            ];
        } else {
            $rows[] = [
                Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_FOLDER'),
                $this->badge($status['creatable'], $status['creatable'] ? 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_CREATED_ON_UPLOAD' : 'PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_CANNOT_CREATE'),
            ];
        }

        $rows[] = [
            Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_LOCATION'),
            $status['insideWebroot']
                ? '<span class="badge bg-warning text-dark">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_INSIDE') . '</span>'
                : '<span class="badge bg-success">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_OUTSIDE') . '</span>',
        ];

        $maxBytes = Settings::maxBytes($params);
        $rows[]   = [Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_MAX_UPLOAD'), $maxBytes > 0 ? HTMLHelper::_('number.bytes', $maxBytes) : '-'];

        $html = ['<table class="table table-sm w-auto mb-2"><tbody>'];

        foreach ($rows as [$label, $value]) {
            $html[] = '<tr><th scope="row" class="pe-4">' . $label . '</th><td>' . $value . '</td></tr>';
        }

        $html[] = '</tbody></table>';

        if ($status['insideWebroot']) {
            $html[] = $this->alert('warning', Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_STATUS_INSIDE_WARNING'));
        }

        $html[] = $this->cleanup($storage);

        return implode("\n", $html);
    }

    /**
     * The unused files, and the button that deletes them.
     *
     * @param   Storage  $storage  The storage.
     *
     * @return  string
     */
    private function cleanup(Storage $storage): string
    {
        $repository = new Repository(Factory::getContainer()->get(DatabaseInterface::class));
        $unused     = $storage->unused($repository->referencedFilenames(), time() - Prettyprotecteddownloads::CLEANUP_GRACE);

        if ($unused === []) {
            return '<p class="text-muted small mb-0">' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_NONE') . '</p>';
        }

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_fields_prettyprotecteddownloads');
        $wa->useScript('plg_fields_prettyprotecteddownloads.admin');
        Text::script('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_CONFIRM');
        Text::script('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_JS_UPLOAD_FAILED');

        $url   = Uri::base() . 'index.php?option=com_ajax&group=fields&plugin=prettyprotecteddownloads&format=json&task=cleanup';
        $bytes = array_sum(array_column($unused, 'size'));

        return '<div class="ppd-cleanup">'
            . '<p class="mb-2">' . Text::plural('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_FOUND_N', \count($unused), HTMLHelper::_('number.bytes', $bytes)) . '</p>'
            . '<button type="button" class="btn btn-outline-danger btn-sm ppd-cleanup-button" data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-token="' . htmlspecialchars(Session::getFormToken(), ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="icon-trash me-1" aria-hidden="true"></span>' . Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_CLEANUP_BUTTON') . '</button>'
            . '<div class="ppd-cleanup-result small mt-2" role="status" aria-live="polite"></div>'
            . '</div>';
    }

    /**
     * @param   bool    $ok   Good or bad.
     * @param   string  $key  The language key of the text.
     *
     * @return  string
     */
    private function badge(bool $ok, string $key): string
    {
        return '<span class="badge ' . ($ok ? 'bg-success' : 'bg-danger') . '">' . Text::_($key) . '</span>';
    }

    /**
     * @param   string  $type  The alert type.
     * @param   string  $text  The text.
     *
     * @return  string
     */
    private function alert(string $type, string $text): string
    {
        return '<div class="alert alert-' . $type . ' mb-2">' . $text . '</div>';
    }
}
