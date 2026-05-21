<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Config drift collector: settings whose current value differs from default.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\collectors;

/**
 * Walks the admin settings tree and reports every setting whose current
 * value differs from its declared default.
 *
 * Useful as a cross-site drift check — comparing two snapshots quickly
 * surfaces which of the ~hundreds of Moodle settings have been customised
 * away from the out-of-the-box state.
 *
 * Secrets (password-class settings and anything matching common secret
 * name patterns) are excluded entirely — name and value are both withheld
 * rather than redacted, so the data is safe to send through the push
 * pipeline to a central collector.
 */
class config_drift {
    /** Setting classes that always hold secrets. */
    private const SENSITIVE_CLASSES = [
        'admin_setting_configpasswordunmask',
        'admin_setting_configpasswordunmask_with_advanced',
    ];

    /** Display-only "settings" that never hold meaningful config values. */
    private const DISPLAY_ONLY_CLASSES = [
        'admin_setting_heading',
        'admin_setting_description',
        'admin_setting_button',
    ];

    /** Substring patterns in the setting name that indicate a secret. */
    private const SENSITIVE_NAME_PATTERNS = [
        'password',
        'secret',
        'token',
        'apikey',
        'api_key',
        'privatekey',
        'private_key',
        'salt',
        'passphrase',
        'clientkey',
        'client_secret',
    ];

    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        global $CFG;

        require_once($CFG->libdir . '/adminlib.php');

        // admin_get_root only fully populates the settings tree under an admin
        // session — without one, get_children() on category nodes returns empty.
        // Establish admin context for this collector run; downstream code
        // continues with the original session via the runtime context the
        // caller (WS / scheduled task) provides.
        $originaluser = self::elevate_to_admin();
        try {
            $root = admin_get_root(true, true);
            $entries = [];
            $skipped = ['sensitive' => 0, 'no_default' => 0];
            self::walk($root, $entries, $skipped);
        } finally {
            self::restore_user($originaluser);
        }

        usort($entries, function ($a, $b) {
            return strcmp($a['fullname'], $b['fullname']);
        });

        return [
            'count' => count($entries),
            'skipped' => $skipped,
            'entries' => $entries,
        ];
    }

    /**
     * Temporarily set the current user to a site admin so admin_get_root()
     * loads the full settings tree. Returns the previous user for restoration.
     *
     * @return \stdClass|null
     */
    protected static function elevate_to_admin(): ?\stdClass {
        global $USER;

        if (!empty($USER->id) && is_siteadmin($USER)) {
            return null;
        }
        $admins = get_admins();
        if (empty($admins)) {
            return null;
        }
        $previous = $USER;
        \core\session\manager::set_user(reset($admins));
        return $previous;
    }

    /**
     * Restore the session to the user active before elevate_to_admin().
     *
     * @param \stdClass|null $previous
     */
    protected static function restore_user(?\stdClass $previous): void {
        if ($previous === null) {
            return;
        }
        \core\session\manager::set_user($previous);
    }

    /**
     * Recursively walk admin_category / admin_settingpage nodes.
     *
     * @param mixed $node
     * @param array $entries
     * @param array $skipped
     */
    protected static function walk($node, array &$entries, array &$skipped): void {
        if ($node instanceof \admin_category) {
            foreach ($node->get_children() as $child) {
                self::walk($child, $entries, $skipped);
            }
            return;
        }
        if (!($node instanceof \admin_settingpage)) {
            return;
        }
        foreach ($node->settings as $setting) {
            self::check_setting($setting, $entries, $skipped);
        }
    }

    /**
     * Compare a single setting to its default, recording drift if any.
     *
     * @param \admin_setting $setting
     * @param array $entries
     * @param array $skipped
     */
    protected static function check_setting($setting, array &$entries, array &$skipped): void {
        if (!($setting instanceof \admin_setting)) {
            return;
        }

        foreach (self::DISPLAY_ONLY_CLASSES as $class) {
            if (is_a($setting, $class)) {
                return;
            }
        }

        if (self::is_sensitive($setting)) {
            $skipped['sensitive']++;
            return;
        }

        $default = $setting->get_defaultsetting();
        if ($default === null) {
            // No declared default — not really "drift", just an unset value.
            $skipped['no_default']++;
            return;
        }

        $current = $setting->get_setting();
        if (self::values_equal($current, $default)) {
            return;
        }

        $entries[] = [
            'plugin' => (string) ($setting->plugin ?? ''),
            'name' => (string) $setting->name,
            'fullname' => self::fullname($setting),
            'visible_name' => self::stringify_visible_name($setting),
            'class' => self::short_class_name($setting),
            'current' => self::stringify_value($current),
            'default' => self::stringify_value($default),
        ];
    }

    /**
     * True if the setting's class or name marks it as a secret.
     *
     * @param \admin_setting $setting
     * @return bool
     */
    protected static function is_sensitive($setting): bool {
        foreach (self::SENSITIVE_CLASSES as $class) {
            if (is_a($setting, $class)) {
                return true;
            }
        }
        $name = strtolower((string) $setting->name);
        foreach (self::SENSITIVE_NAME_PATTERNS as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compare two setting values, treating typical Moodle representations as equal
     * (string '0' vs int 0, equivalent arrays, etc.).
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected static function values_equal($a, $b): bool {
        return self::stringify_value($a) === self::stringify_value($b);
    }

    /**
     * Best-effort string representation for comparison and output.
     *
     * @param mixed $value
     * @return string
     */
    protected static function stringify_value($value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = (is_int($k) ? '' : $k . '=') . self::stringify_value($v);
            }
            sort($parts);
            return '[' . implode(',', $parts) . ']';
        }
        if (is_object($value)) {
            // lang_string and similar implement __toString; use that if present.
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return get_class($value);
        }
        return (string) $value;
    }

    /**
     * Setting identifier in "plugin/name" form.
     *
     * @param \admin_setting $setting
     * @return string
     */
    protected static function fullname($setting): string {
        $plugin = (string) ($setting->plugin ?? '');
        return ($plugin === '' ? '' : $plugin . '/') . $setting->name;
    }

    /**
     * Strip namespace from the setting class for compact reporting.
     *
     * @param \admin_setting $setting
     * @return string
     */
    protected static function short_class_name($setting): string {
        $class = get_class($setting);
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * The setting's display label (visible_name may be a lang_string).
     *
     * @param \admin_setting $setting
     * @return string
     */
    protected static function stringify_visible_name($setting): string {
        return is_object($setting->visiblename) ? (string) $setting->visiblename : (string) $setting->visiblename;
    }
}
