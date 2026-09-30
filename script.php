<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

/**
 * Script file of the Pretty Protected Downloads fields plugin.
 */
return new class () implements InstallerScriptInterface {
    /**
     * Joomla 5.0 brought the event classes the plugin subscribes with.
     *
     * @var string
     */
    private string $minimumJoomla = '5.0';

    /**
     * @var string
     */
    private string $minimumPhp = '8.1';

    /**
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  bool
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  bool
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * The stored files are left where they are: they are the site's documents, not the
     * plugin's, and a folder outside the web root may not even be the plugin's to empty.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  bool
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        echo Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_INSTALLERSCRIPT_UNINSTALL');

        return true;
    }

    /**
     * Refuse to install on a PHP or Joomla version the plugin does not run on.
     *
     * @param   string            $type     install, update, discover_install or uninstall.
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  bool
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', $this->minimumPhp), Log::WARNING, 'jerror');

            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomla), Log::WARNING, 'jerror');

            return false;
        }

        return true;
    }

    /**
     * Switch the plugin on after a first install, and say what happened. A discovered
     * install never calls install(), so this is done here rather than there.
     *
     * @param   string            $type     install, update, discover_install or uninstall.
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  bool
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install' || $type === 'discover_install') {
            // A field type that is installed but switched off simply is not offered, so
            // switch it on: the storage settings are the step that needs a decision.
            $db        = Factory::getContainer()->get(DatabaseInterface::class);
            $extension = 'plugin';
            $folder    = 'fields';
            $element   = 'prettyprotecteddownloads';
            $query     = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':type', $extension)
                ->bind(':folder', $folder)
                ->bind(':element', $element);
            $db->setQuery($query)->execute();

            echo Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_INSTALLERSCRIPT_INSTALL');
        } elseif ($type === 'update') {
            echo Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_INSTALLERSCRIPT_UPDATE');
        }

        return true;
    }
};
