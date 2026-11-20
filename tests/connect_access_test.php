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
 * Tests for who may connect an AI agent (2.2.9).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\local\connect_access;

/**
 * @covers \local_aiconnect\local\connect_access
 */
final class connect_access_test extends \advanced_testcase {
    /**
     * Teachers and students hold the role in a course — they may connect.
     */
    public function test_course_roles_may_connect(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $ntteacher = $gen->create_and_enrol($course, 'teacher');
        $student = $gen->create_and_enrol($course, 'student');

        $this->assertTrue(connect_access::can_connect((int) $teacher->id));
        $this->assertTrue(connect_access::can_connect((int) $ntteacher->id));
        $this->assertTrue(connect_access::can_connect((int) $student->id));
    }

    /**
     * A user with no course role, a guest, and a suspended user may not connect; admin may.
     */
    public function test_others_refused(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plain = $gen->create_user();
        $this->assertFalse(connect_access::can_connect((int) $plain->id));
        $this->assertFalse(connect_access::can_connect((int) guest_user()->id));
        $this->assertFalse(connect_access::can_connect(0));
        $this->assertTrue(connect_access::can_connect((int) get_admin()->id));

        $course = $gen->create_course();
        $suspended = $gen->create_and_enrol($course, 'student');
        $suspended->suspended = 1;
        user_update_user($suspended, false, false);
        $this->assertFalse(connect_access::can_connect((int) $suspended->id));
    }

    /**
     * An admin who removes the capability from the student role is respected.
     */
    public function test_admin_can_remove_students(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(connect_access::CAPABILITY, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(connect_access::can_connect((int) $student->id));
    }
}
