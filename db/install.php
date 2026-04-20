<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_aiconnect_install() {
    global $DB;

    $dbman = $DB->get_manager();

    $tables = [
        'local_aiconnect_clients' => [
            ['client_id', XMLDB_TYPE_CHAR, 100, null, XMLDB_NOTNULL, null, null],
            ['client_name', XMLDB_TYPE_CHAR, 200, null, XMLDB_NOTNULL, null, null],
            ['redirect_uris', XMLDB_TYPE_TEXT, null, null, null, null, null],
            ['scopes', XMLDB_TYPE_CHAR, 500, null, null, null, 'read write'],
            ['timecreated', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
        ],
        'local_aiconnect_codes' => [
            ['code', XMLDB_TYPE_CHAR, 200, null, XMLDB_NOTNULL, null, null],
            ['client_id', XMLDB_TYPE_CHAR, 100, null, XMLDB_NOTNULL, null, null],
            ['userid', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
            ['scope', XMLDB_TYPE_CHAR, 500, null, null, null, null],
            ['code_challenge', XMLDB_TYPE_CHAR, 200, null, null, null, null],
            ['redirect_uri', XMLDB_TYPE_CHAR, 500, null, null, null, null],
            ['expiry', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
            ['used', XMLDB_TYPE_INTEGER, 1, null, XMLDB_NOTNULL, null, 0],
        ],
        'local_aiconnect_tokens' => [
            ['token', XMLDB_TYPE_CHAR, 200, null, XMLDB_NOTNULL, null, null],
            ['tokentype', XMLDB_TYPE_CHAR, 10, null, XMLDB_NOTNULL, null, null],
            ['client_id', XMLDB_TYPE_CHAR, 100, null, null, null, null],
            ['userid', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
            ['scope', XMLDB_TYPE_CHAR, 500, null, null, null, null],
            ['parenttoken', XMLDB_TYPE_CHAR, 200, null, null, null, null],
            ['expiry', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
            ['revoked', XMLDB_TYPE_INTEGER, 1, null, XMLDB_NOTNULL, null, 0],
            ['timecreated', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
        ],
        'local_aiconnect_ratelimits' => [
            ['token', XMLDB_TYPE_CHAR, 200, null, XMLDB_NOTNULL, null, null],
            ['windowtype', XMLDB_TYPE_CHAR, 10, null, XMLDB_NOTNULL, null, null],
            ['windowstart', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, null],
            ['requestcount', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, null, 1],
        ],
    ];

    foreach ($tables as $tablename => $fields) {
        if ($dbman->table_exists($tablename)) {
            continue;
        }
        $table = new xmldb_table($tablename);
        $table->add_field('id', XMLDB_TYPE_INTEGER, 10, null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        foreach ($fields as $f) {
            $table->add_field($f[0], $f[1], $f[2] ?? null, null, $f[4] ?? null, null, $f[6] ?? null);
        }
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
    }

    $agents = ['claude','chatgpt','gemini','grok','copilot','perplexity','metaai','deepseek','ai-agent-default'];
    foreach ($agents as $agent) {
        if (!$DB->record_exists('local_aiconnect_clients', ['client_id' => $agent])) {
            $DB->insert_record('local_aiconnect_clients', [
                'client_id' => $agent, 'client_name' => $agent,
                'redirect_uris' => 'urn:ietf:wg:oauth:2.0:oob',
                'scopes' => 'read write delete', 'timecreated' => time(),
            ]);
        }
    }
    return true;
}
