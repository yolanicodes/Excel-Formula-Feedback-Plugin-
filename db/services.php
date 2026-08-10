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
 * External service (AJAX) definitions for qtype_excelformula.
 *
 * Registers qtype_excelformula_detect_answercases so it can be called
 * from amd/src/answerkey_parser.js via core/ajax, and
 * qtype_excelformula_grade_cell so it can be called from
 * amd/src/cell_answer.js to grade one cell mid-attempt.
 *
 * @package    qtype_excelformula
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'qtype_excelformula_detect_answercases' => [
        'classname'    => \qtype_excelformula\external\detect_answercases::class,
        'methodname'   => 'execute',
        'description'  => 'Parses an uploaded answer-key spreadsheet\'s cell comments into a nested cell/case structure for the answer cases accordion.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'moodle/question:add',
    ],
    'qtype_excelformula_grade_cell' => [
        'classname'    => \qtype_excelformula\external\grade_cell::class,
        'methodname'   => 'execute',
        'description'  => 'Grades one answer cell mid-attempt and records the response as a step on the live question_attempt.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/quiz:attempt',
    ],
];