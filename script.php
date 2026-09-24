<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access to this file
\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

/**
 * Script file of the Pretty Protected Downloads fields plugin.
 */
class plgFieldsPrettyprotecteddownloadsInstallerScript
{
    /**
     * Joomla 5.0 brought the event classes the plugin subscribes with.
     *
     * @var string
     */
    protected string $minimumJoomla = '5.0';

    /**
     * @var string
     */
    protected string $minimumPhp = '8.1';

    /**
     * Function called before extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update or discover_install, not uninstall)
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    public function preflight(string $type, InstallerAdapter $parent): bool
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
     * Function called after extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update or discover_install, not uninstall)
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        if ($type === 'install' || $type === 'discover_install') {
            // A field type that is installed but switched off simply is not offered, so
            // switch it on: the storage settings are the step that needs a decision.
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('fields'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('prettyprotecteddownloads'));
            $db->setQuery($query)->execute();

            echo Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_INSTALLERSCRIPT_INSTALL');
        }

        if ($type === 'update') {
            echo Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_INSTALLERSCRIPT_UPDATE');
        }

        return true;
    }

    /**
     * Method to uninstall the extension
     *
     * The stored files are left where they are: they are the site's documents, not the
     * plugin's, and a folder outside the web root may not even be the plugin's to empty.
     *
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    public function uninstall(InstallerAdapter $parent): bool
    {
        echo Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_INSTALLERSCRIPT_UNINSTALL');

        return true;
    }
}
