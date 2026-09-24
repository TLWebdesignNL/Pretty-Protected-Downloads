<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Test bootstrap.
 *
 * The plugin is a Joomla extension with no Composer dependencies, so rather than
 * pulling in a test framework these tests stand up just enough of the Joomla classes
 * the helpers touch: the language and the parameter registry. Each test file runs in
 * its own process with its own temporary site root, so the fixtures of one cannot
 * reach another.
 *
 * What is covered is everything that decides which file a request may reach -- the
 * stored value, the storage folder and the download tokens. The parts that need a
 * running Joomla (the database lookups, the events, the layouts) are not.
 *
 * Run them all with: php tests/run.php
 */

namespace {
    \define('_JEXEC', 1);
    \define('JPATH_ROOT', getenv('TEST_ROOT') ?: sys_get_temp_dir() . '/prettyprotecteddownloads-test');

    if (!is_dir(JPATH_ROOT)) {
        mkdir(JPATH_ROOT, 0777, true);
    }

    require_once __DIR__ . '/../src/Helper/Entries.php';
    require_once __DIR__ . '/../src/Helper/Storage.php';
    require_once __DIR__ . '/../src/Helper/DownloadTokens.php';
    require_once __DIR__ . '/../src/Helper/Settings.php';

    /**
     * Assertion counters.
     */
    $GLOBALS['tests_passed'] = 0;
    $GLOBALS['tests_failed'] = 0;

    /**
     * Record one assertion.
     *
     * @param   string  $label  What is being asserted.
     * @param   bool    $ok     Whether it holds.
     *
     * @return  void
     */
    function check(string $label, bool $ok): void
    {
        if ($ok) {
            $GLOBALS['tests_passed']++;
            echo "  ok   $label\n";

            return;
        }

        $GLOBALS['tests_failed']++;
        echo "  FAIL $label\n";
    }

    /**
     * Announce a group of assertions.
     *
     * @param   string  $name  Group name.
     *
     * @return  void
     */
    function group(string $name): void
    {
        echo "$name\n";
    }

    /**
     * Report the totals and exit with a status the runner can act on.
     *
     * @return  void
     */
    function finish(): void
    {
        echo "\n{$GLOBALS['tests_passed']} passed, {$GLOBALS['tests_failed']} failed\n";
        exit($GLOBALS['tests_failed'] === 0 ? 0 : 1);
    }

    /**
     * Whether a callable throws.
     *
     * @param   callable  $fn  The callable.
     *
     * @return  bool
     */
    function throws(callable $fn): bool
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return true;
        }

        return false;
    }

    /**
     * An in-memory session.
     */
    final class TestSession
    {
        public array $data = [];

        public function get(string $name, $default = null)
        {
            return $this->data[$name] ?? $default;
        }

        public function set(string $name, $value): void
        {
            $this->data[$name] = $value;
        }
    }
}

namespace Joomla\CMS\Language {
    /**
     * Returns the key itself, with the arguments appended, so a test can see which
     * message was chosen.
     */
    class Text
    {
        public static function _($string)
        {
            return $string;
        }

        public static function sprintf($string, ...$args)
        {
            return $string . ':' . implode(',', $args);
        }
    }
}

namespace Joomla\CMS\Plugin {
    class PluginHelper
    {
        public static function getPlugin($type, $name = null)
        {
            return null;
        }
    }
}

namespace Joomla\Registry {
    class Registry
    {
        private array $data;

        public function __construct($data = [])
        {
            $this->data = \is_string($data) ? (json_decode($data, true) ?: []) : (array) $data;
        }

        public function get($path, $default = null)
        {
            return $this->data[$path] ?? $default;
        }
    }
}
