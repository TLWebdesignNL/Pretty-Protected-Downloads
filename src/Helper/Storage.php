<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper;

use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The folder the files are kept in.
 *
 * Two ways to keep it are offered. Outside the web root, the web server has no URL for
 * the files at all, whatever server it is. Inside the web root the folder is closed with
 * an .htaccess file, which only Apache and LiteSpeed read. On nginx or IIS the files
 * would be downloadable by anyone who guesses a name, which the status field says.
 *
 * The folder is created, and closed, the first time it is needed rather than when the
 * settings are saved: a fields plugin is not loaded while its own settings are saved, so
 * there is nothing to hook into there.
 */
final class Storage
{
    public const OUTSIDE = 'outside';

    public const WEBROOT = 'webroot';

    /**
     * The folder used inside the web root when no path is set.
     */
    public const DEFAULT_WEBROOT_FOLDER = 'files/prettyprotecteddownloads';

    /**
     * Joomla's own top-level folders. Closing one of these with .htaccess would take
     * the site, the administrator or every image on it offline, so none of them is
     * ever accepted as the storage folder.
     */
    private const JOOMLA_FOLDERS = [
        'administrator', 'api', 'cache', 'cli', 'components', 'images', 'includes', 'installation',
        'language', 'layouts', 'libraries', 'logs', 'media', 'modules', 'plugins', 'templates', 'tmp',
    ];

    /**
     * Closes the folder on Apache 2.4, and on 2.2 through the compatibility module.
     */
    private const HTACCESS = <<<'HTACCESS'
# Written by Pretty Protected Downloads. The files in this folder are only
# served through Joomla, after an access check; never directly.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

HTACCESS;

    /**
     * @param   string  $method    self::OUTSIDE or self::WEBROOT.
     * @param   string  $path      The configured path; relative paths are read from the site root.
     * @param   string  $siteRoot  The Joomla site root.
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string $siteRoot
    ) {
    }

    /**
     * Build from the plugin parameters.
     *
     * @param   Registry  $params  The plugin parameters.
     *
     * @return  self
     */
    public static function fromParams(Registry $params): self
    {
        $method = (string) $params->get('storage_method', self::OUTSIDE) === self::WEBROOT ? self::WEBROOT : self::OUTSIDE;
        $key    = $method === self::WEBROOT ? 'storage_path_webroot' : 'storage_path';

        return new self($method, trim((string) $params->get($key, '')), JPATH_ROOT);
    }

    /**
     * The storage folder as an absolute path without a trailing separator, or an empty
     * string when it is not configured.
     *
     * @return  string
     */
    public function path(): string
    {
        $path = rtrim($this->path, '/\\');

        if ($path === '' && $this->method === self::WEBROOT) {
            $path = self::DEFAULT_WEBROOT_FOLDER;
        }

        if ($path === '') {
            return '';
        }

        if (!self::isAbsolute($path)) {
            $path = rtrim($this->siteRoot, '/\\') . '/' . $path;
        }

        return $path;
    }

    /**
     * Whether the storage folder lies inside the site root, and so has a URL.
     *
     * @return  bool
     */
    public function isInsideWebroot(): bool
    {
        return $this->relativeToWebroot() !== null;
    }

    /**
     * The storage folder as a path relative to the site root, or null when it lies
     * outside it. The site root itself is the empty string.
     *
     * @return  ?string
     */
    public function relativeToWebroot(): ?string
    {
        $path = $this->path();

        if ($path === '') {
            return null;
        }

        $root = $this->realRoot();
        $real = rtrim($this->resolveExisting($path), '/\\');

        if ($real === $root) {
            return '';
        }

        return str_starts_with($real, $root . '/') ? substr($real, \strlen($root) + 1) : null;
    }

    /**
     * Whether the folder is one the site cannot do without: the site root, a folder
     * above it, or one of Joomla's own folders. Files are never written there, and
     * neither is the .htaccess that would close it.
     *
     * @return  bool
     */
    public function isForbidden(): bool
    {
        $path = $this->path();

        if ($path === '') {
            return false;
        }

        $root = $this->realRoot();
        $real = rtrim($this->resolveExisting($path), '/\\');

        if ($real === $root || str_starts_with($root . '/', $real . '/')) {
            return true;
        }

        return \in_array($this->relativeToWebroot(), self::JOOMLA_FOLDERS, true);
    }

    /**
     * Make sure the folder exists, and that it is closed when it has a URL.
     *
     * @return  string  The folder, with a trailing separator.
     *
     * @throws  \RuntimeException  When the folder is not configured or cannot be created.
     */
    public function prepare(): string
    {
        $path = $this->path();

        if ($path === '') {
            throw new \RuntimeException(Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_STORAGE_NOT_CONFIGURED'));
        }

        if ($this->isForbidden()) {
            throw new \RuntimeException(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_STORAGE_FORBIDDEN', $path));
        }

        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new \RuntimeException(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_ERROR_STORAGE_NOT_CREATED', $path));
        }

        // Written in both modes: outside the web root it costs nothing, and it still
        // protects a folder that was meant to be outside but turns out not to be.
        if (!is_file($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', self::HTACCESS);
        }

        if (!is_file($path . '/index.html')) {
            @file_put_contents($path . '/index.html', '<!DOCTYPE html><title></title>' . "\n");
        }

        return $path . '/';
    }

    /**
     * The absolute path of a stored file, or null when the name is not one this plugin
     * wrote or the file is not there.
     *
     * @param   string  $filename  The stored filename.
     *
     * @return  ?string
     */
    public function locate(string $filename): ?string
    {
        $path = $this->path();

        if ($path === '' || !preg_match(Entries::OWN_FILE, $filename)) {
            return null;
        }

        $file = $path . '/' . $filename;

        return is_file($file) ? $file : null;
    }

    /**
     * Delete a stored file. Names this plugin did not write are ignored.
     *
     * @param   string  $filename  The stored filename.
     *
     * @return  bool  Whether a file was deleted.
     */
    public function delete(string $filename): bool
    {
        $file = $this->locate($filename);

        return $file !== null && @unlink($file);
    }

    /**
     * The files this plugin wrote to the folder, by name, with their size and
     * modification time.
     *
     * Anything else in the folder, such as the protection files it is prepared with or
     * the files of whatever the folder is shared with, is not listed, so a clean-up can
     * never remove it.
     *
     * @return  array<string, array{size: int, mtime: int}>
     */
    public function files(): array
    {
        $path = $this->path();

        if ($path === '' || !is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (scandir($path) ?: [] as $name) {
            if (!preg_match(Entries::OWN_FILE, $name) || !is_file($path . '/' . $name)) {
                continue;
            }

            $files[$name] = [
                'size'  => (int) filesize($path . '/' . $name),
                'mtime' => (int) filemtime($path . '/' . $name),
            ];
        }

        return $files;
    }

    /**
     * The stored files no field names any more.
     *
     * A file is only counted once it is older than the grace period, because an upload
     * belongs to no field until the article it was made for is saved.
     *
     * @param   array<string, true>  $referenced  The filenames still in use, as keys.
     * @param   int                  $olderThan   Only files last changed before this time.
     *
     * @return  array<string, array{size: int, mtime: int}>
     */
    public function unused(array $referenced, int $olderThan): array
    {
        return array_filter(
            array_diff_key($this->files(), $referenced),
            static fn (array $file): bool => $file['mtime'] < $olderThan
        );
    }

    /**
     * What the settings screen reports about the folder.
     *
     * @return  array{configured: bool, path: string, forbidden: bool, exists: bool, writable: bool, creatable: bool, insideWebroot: bool, closed: bool}
     */
    public function status(): array
    {
        $path   = $this->path();
        $exists = $path !== '' && is_dir($path);

        return [
            'configured'    => $path !== '',
            'path'          => $path,
            'forbidden'     => $this->isForbidden(),
            'exists'        => $exists,
            'writable'      => $exists && is_writable($path),
            'creatable'     => !$exists && $path !== '' && is_writable($this->nearestExisting($path)),
            'insideWebroot' => $this->isInsideWebroot(),
            'closed'        => $exists && is_file($path . '/.htaccess'),
        ];
    }

    /**
     * The site root, resolved, without a trailing separator.
     *
     * @return  string
     */
    private function realRoot(): string
    {
        return rtrim(realpath($this->siteRoot) ?: $this->siteRoot, '/\\');
    }

    /**
     * A path with its existing part resolved, so a folder that has yet to be created
     * can still be placed inside or outside the site root.
     *
     * @param   string  $path  The path.
     *
     * @return  string
     */
    private function resolveExisting(string $path): string
    {
        $existing = $this->nearestExisting($path);

        return (realpath($existing) ?: $existing) . substr($path, \strlen($existing));
    }

    /**
     * The nearest folder of a path, itself included, that exists.
     *
     * @param   string  $path  The path.
     *
     * @return  string
     */
    private function nearestExisting(string $path): string
    {
        while (!file_exists($path)) {
            $parent = \dirname($path);

            if ($parent === $path) {
                break;
            }

            $path = $parent;
        }

        return $path;
    }

    /**
     * Whether a path is absolute, on either kind of system.
     *
     * @param   string  $path  The path.
     *
     * @return  bool
     */
    private static function isAbsolute(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || preg_match('/^[a-z]:[\\\\\/]/i', $path) === 1;
    }
}
