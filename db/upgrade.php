
<?php
// This file is generated using Claude AI. It contains the upgrade logic for the Excel Formula question type plugin in Moodle. It handles database schema changes and data migrations when the plugin version is updated.
// Runs when Moodle detects the version number has increased.

defined('MOODLE_INTERNAL') || die();

function xmldb_qtype_excelformula_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026061904) {

        // --- 1. Drop the old single-options table ---
        $table = new xmldb_table('qtype_excelformula_options');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // --- 2. Re-create qtype_excelformula_options (new slim version) ---
        $table = new xmldb_table('qtype_excelformula_options');
        $table->add_field('id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('questionid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('celldataitemid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('answerkeyitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
         $table->add_field('spreadsheetdata', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'question', ['id']);
       
        $dbman->create_table($table);

        // --- 3. Create qtype_excelformula_answers ---
        $table = new xmldb_table('qtype_excelformula_answers');
        if (!$dbman->table_exists($table)) {
            $table->add_field('ansid',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('questionid',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cellref',        XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, '');
            $table->add_field('markallocation', XMLDB_TYPE_NUMBER,  '20',  XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
            $table->add_field('rules',          XMLDB_TYPE_TEXT,    null,  null, null);
            $table->add_field('feedback',       XMLDB_TYPE_TEXT,    null,  null, null);
            $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['ansid']);
            $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'question', ['id']);
            $dbman->create_table($table);
        }

        // --- 4. Create qtype_excelformula_anscase ---
        $table = new xmldb_table('qtype_excelformula_anscase');
        if (!$dbman->table_exists($table)) {
            $table->add_field('anscaseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('ansid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('casemark',     XMLDB_TYPE_NUMBER,  '20', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
            $table->add_field('casetype',     XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, 'formula');
            $table->add_field('answertext',   XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
            $table->add_field('casefeedback', XMLDB_TYPE_TEXT,    null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['anscaseid']);
            $table->add_key('ansid',   XMLDB_KEY_FOREIGN, ['ansid'], 'qtype_excelformula_answers', ['ansid']);
            $dbman->create_table($table);
        }

       upgrade_plugin_savepoint(true, 2026061904, 'qtype', 'excelformula');
    }

    if ($oldversion < 2026070601) {

        // Define field sheetname to be added to qtype_excelformula_answers.
        $table = new xmldb_table('qtype_excelformula_answers');
        $field = new xmldb_field('sheetname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'cellref');

        // Conditionally launch add field sheetname.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070601, 'qtype', 'excelformula');
    }
        if ($oldversion < 2026082300) {

        // Define field defaultfeedback to be added to qtype_excelformula_answers.
        $table = new xmldb_table('qtype_excelformula_answers');
        $field = new xmldb_field('defaultfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sheetname');

        // Conditionally launch add field defaultfeedback.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082300, 'qtype', 'excelformula');
    }

       if ($oldversion < 2026083000) {

        // Define field formulaweight to be dropped from qtype_excelformula_answers.
        $table = new xmldb_table('qtype_excelformula_answers');
        $field = new xmldb_field('formulaweight');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Define field answerweight to be dropped from qtype_excelformula_answers.
        $field = new xmldb_field('answerweight');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Define field caseweight to be dropped from qtype_excelformula_anscase.
        $table = new xmldb_table('qtype_excelformula_anscase');
        $field = new xmldb_field('caseweight');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026083000, 'qtype', 'excelformula');
    }

    return true;
}