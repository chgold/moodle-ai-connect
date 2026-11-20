<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/aiconnect:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => ['user' => CAP_ALLOW, 'student' => CAP_ALLOW, 'teacher' => CAP_ALLOW],
    ],
    'local/aiconnect:use' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'student' => CAP_ALLOW, 'teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW,
        ],
    ],
    'local/aiconnect:usepro' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
];
