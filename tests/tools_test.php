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
 * Tests for the tools dispatcher.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\controller\tools_controller;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_aiconnect\controller\tools_controller
 */
final class tools_test extends \advanced_testcase {

    /**
     * Unknown tool returns a structured `tool_not_found` error.
     */
    public function test_unknown_tool_returns_error(): void {
        $this->resetAfterTest();

        $result = (new tools_controller())->execute('moodle.bogus', [], 0, 'read');
        $this->assertSame('tool_not_found', $result['error']);
    }

    /**
     * Pro tools require write scope.
     */
    public function test_pro_tool_blocked_without_write_scope(): void {
        $this->resetAfterTest();
        set_config('edition', 'pro', 'local_aiconnect');

        $result = (new tools_controller())->execute('moodle.enrollUser',
            ['userid' => 1, 'courseid' => 1], 0, 'read');

        $this->assertSame('insufficient_scope', $result['error']);
    }

    /**
     * moodle.getUserProfile returns the caller's record.
     */
    public function test_get_user_profile(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'username' => 'alice', 'firstname' => 'Alice', 'lastname' => 'Test',
            'email' => 'alice@test',
        ]);

        $result = (new tools_controller())->execute('moodle.getUserProfile', [], (int) $user->id, 'read');

        $this->assertSame('alice', $result['username']);
        $this->assertSame('alice@test', $result['email']);
    }

    /**
     * moodle.getCourses returns courses the user is enrolled in.
     */
    public function test_get_courses_lists_enrolled(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course(['fullname' => 'Algebra 101', 'shortname' => 'ALG101']);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, 'student');

        $result = (new tools_controller())->execute('moodle.getCourses', ['limit' => 10], (int) $user->id, 'read');

        $this->assertNotEmpty($result);
        $shortnames = array_column($result, 'shortname');
        $this->assertContains('ALG101', $shortnames);
    }

    /**
     * moodle.getAssignments lists assignments in a course.
     */
    public function test_get_assignments(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $assigngen = $gen->get_plugin_generator('mod_assign');
        $assigngen->create_instance(['course' => $course->id, 'name' => 'Task 1']);
        $assigngen->create_instance(['course' => $course->id, 'name' => 'Task 2']);

        $result = (new tools_controller())->execute('moodle.getAssignments',
            ['courseid' => $course->id], 0, 'read');

        $this->assertCount(2, $result);
        $this->assertSame('Task 1', $result[0]['name']);
    }

    /**
     * moodle.enrollUser actually enrols when write scope is granted.
     */
    public function test_enroll_user_with_write_scope(): void {
        $this->resetAfterTest();
        set_config('edition', 'pro', 'local_aiconnect');
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $target = $gen->create_user();

        // Pro tools check the caller's native Moodle permissions (SEC-00): run as the admin.
        $this->setAdminUser();
        global $USER;
        $result = (new tools_controller())->execute('moodle.enrollUser',
            ['userid' => $target->id, 'courseid' => $course->id], (int) $USER->id, 'read write');

        $this->assertTrue($result['enrolled'] ?? false, json_encode($result));
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $target->id));
    }

    /**
     * Missing required parameter is reported as a tool error.
     */
    public function test_missing_required_param(): void {
        $this->resetAfterTest();

        $result = (new tools_controller())->execute('moodle.getCourse', [], 0, 'read');

        $this->assertSame('tool_error', $result['error']);
        $this->assertStringContainsString('courseid', $result['message']);
    }
}
