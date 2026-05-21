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
 * Web-UI form that wraps the Sentinel WS-access bootstrap.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Two-section form: setup options + an optional regenerate-token toggle.
 *
 * Defaults mirror cli/setup.php so click-through-defaults yields the same
 * outcome as the canonical CLI invocation.
 */
class setup_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'identityheader', get_string('setup_identity_heading', 'local_sentinel'));

        $mform->addElement('text', 'username', get_string('setup_username', 'local_sentinel'));
        $mform->setType('username', PARAM_USERNAME);
        $mform->setDefault('username', 'sentinel');
        $mform->addHelpButton('username', 'setup_username', 'local_sentinel');

        $mform->addElement('text', 'rolename', get_string('setup_rolename', 'local_sentinel'));
        $mform->setType('rolename', PARAM_TEXT);
        $mform->setDefault('rolename', 'Sentinel');

        $mform->addElement('text', 'roleshortname', get_string('setup_roleshortname', 'local_sentinel'));
        $mform->setType('roleshortname', PARAM_ALPHANUMEXT);
        $mform->setDefault('roleshortname', 'sentinel');

        if (!empty($this->_customdata['has_existing_token'])) {
            $mform->addElement('header', 'regenheader', get_string('setup_regen_heading', 'local_sentinel'));
            $mform->addElement(
                'advcheckbox',
                'regenerate',
                get_string('setup_regenerate', 'local_sentinel'),
                get_string('setup_regenerate_desc', 'local_sentinel')
            );
            $mform->setDefault('regenerate', 0);
        }

        $this->add_action_buttons(false, get_string('setup_run', 'local_sentinel'));
    }
}
