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
 * Event: An AI agent asked the user to confirm an action (user-confirm-v1).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\event;

/**
 * An AI agent asked the user to confirm an action.
 *
 * other: confid, tool, status, clientid.
 */
class ai_action_requested extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_aiconnect_pending';
    }

    /**
     * Build the event from a pending-action row.
     *
     * @param \stdClass $action Row of local_aiconnect_pending.
     * @return self
     */
    public static function create_from_action(\stdClass $action): self {
        return self::create([
            'objectid'      => (int) $action->id,
            'context'       => \context_user::instance((int) $action->userid),
            'relateduserid' => (int) $action->userid,
            'other'         => [
                'confid'   => (string) $action->confid,
                'tool'     => (string) $action->tool,
                'status'   => (string) $action->status,
                'clientid' => (string) $action->clientid,
            ],
        ]);
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventaiactionrequested', 'local_aiconnect');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "User {$this->relateduserid}: AI action '" . s($this->other['tool'] ?? '') . "' ("
            . s($this->other['confid'] ?? '') . ") status '" . s($this->other['status'] ?? '') . "', client '"
            . s($this->other['clientid'] ?? '') . "'.";
    }

    /**
     * Link to the action.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/aiconnect/aiactions.php', ['id' => $this->other['confid'] ?? '']);
    }

    /**
     * Validate.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['confid'], $this->other['tool'])) {
            throw new \coding_exception('confid and tool are required in other');
        }
    }

    /**
     * Object id mapping (backup/restore: not restored).
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_aiconnect_pending', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Other mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
