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
 * External function: grade a single answer cell mid-attempt.
 *
 * Called from amd/src/cell_answer.js (File 5, not yet written) once a
 * student submits the formula for the CURRENT active graded cell only.
 * Unlike detect_answercases.php (which is read-only, pre-save, teacher
 * side), this function operates on a LIVE question_attempt during a
 * student's quiz attempt and must record a genuine step against it,
 * so that:
 *   - a page reload/navigation does not lose progress;
 *   - attempt history, review, and regrade all see this response
 *     exactly as if it had been submitted through the normal question
 *     engine flow;
 *   - the final grade_response() call (question.php, File 3) can
 *     read back the same per-cell results rather than re-deriving
 *     them independently.
 *
 * DEPENDENCY NOTES (to verify once later files are written):
 *   - Expects qtype_excelformula_question (question.php, File 3) to
 *     expose a per-cell grading method, tentatively:
 *       grade_single_cell(int $ansid, string $formula): array
 *     returning ['mark' => float, 'maxmark' => float,
 *                'state' => question_state, 'feedback' => string].
 *     This external function must NOT duplicate best_matching_case()
 *     logic -- grading stays in the model layer (question.php).
 *   - Expects get_expected_data() (question.php, File 3) to expose one
 *     submitted-data key per graded cell, tentatively named
 *     'answer_' . $ansid, so process_action() submits data in the
 *     same shape the question already declares it accepts.
 *   - Expects renderer.php (File 4) to render inputs using that same
 *     'answer_' . $ansid naming so student-submitted keys line up.
 *
 * @package    qtype_excelformula
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_excelformula\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use question_engine;
use question_state;

/**
 * Grades one answer cell within an in-progress question_attempt and
 * persists the result as a real attempt step, without grading (or
 * unlocking) any other cell.
 */
class grade_cell extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'qubaid'  => new external_value(PARAM_INT, 'Question usage by activity id'),
            'slot'    => new external_value(PARAM_INT, 'Slot number of this question within the usage'),
            'ansid'   => new external_value(PARAM_INT, 'qtype_excelformula_answers.ansid of the cell being answered'),
            'formula' => new external_value(PARAM_RAW, 'The student-submitted formula for this cell'),
        ]);
    }

    /**
     * Grades a single cell and records the response as a step on the
     * live question_attempt, so the question engine's own state
     * (history, review, regrade) stays authoritative.
     *
     * @param int    $qubaid  Question usage by activity id.
     * @param int    $slot    Slot number within the usage.
     * @param int    $ansid   Which graded cell (qtype_excelformula_answers.ansid) this response is for.
     * @param string $formula Student's submitted formula for that cell.
     * @return array
     */
    public static function execute(int $qubaid, int $slot, int $ansid, string $formula): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'qubaid'  => $qubaid,
            'slot'    => $slot,
            'ansid'   => $ansid,
            'formula' => $formula,
        ]);

        // Load the live usage and locate this question's attempt within it.
        // Context/capability checks are delegated to the attempt's own
        // owning module (quiz, etc.) via require_login()/validate_context()
        // against the usage's context, mirroring how question_attempt
        // actions are normally validated in Moodle core.
        $quba = question_engine::load_questions_usage_by_activity($params['qubaid']);
        $qa   = $quba->get_question_attempt($params['slot']);

        $context = $quba->get_owning_context();
        self::validate_context($context);

        $question = $qa->get_question();

        if (!($question instanceof \qtype_excelformula_question)) {
            return [
                'success'  => false,
                'error'    => get_string('detecterror', 'qtype_excelformula'),
                'mark'     => 0,
                'maxmark'  => 0,
                'feedback' => '',
                'state'    => '',
            ];
        }

        // Refuse to grade a cell the student should not yet be able to
        // reach -- server-side enforcement of the "answer in order"
        // rule, not just a UI lock. Relies on qa->get_response_summary()
        // / question->get_graded_ansids($qa) (File 3) to know which
        // cells already have a recorded, graded step.
        $gradedansids = $question->get_graded_ansids($qa);
        $nextansid    = $question->get_next_ungraded_ansid($qa);

        if ($nextansid !== $params['ansid'] && !in_array($params['ansid'], $gradedansids, true)) {
            return [
                'success'  => false,
                'error'    => get_string('detecterror', 'qtype_excelformula'),
                'mark'     => 0,
                'maxmark'  => 0,
                'feedback' => '',
                'state'    => '',
            ];
        }

        // Grading itself stays in the model layer (question.php, File 3).
        $result = $question->grade_single_cell($params['ansid'], $params['formula']);

        // Record this as a genuine step on the question_attempt so the
        // question engine's own state (not a side table) is authoritative
        // for review, regrade, and attempt history.
        $submitteddata = [
            'answer_' . $params['ansid'] => $params['formula'],
        ];
        $quba->process_action($params['slot'], $submitteddata);
        question_engine::save_questions_usage_by_activity($quba);

        return [
            'success'  => true,
            'error'    => '',
            'mark'     => (float) $result['mark'],
            'maxmark'  => (float) $result['maxmark'],
            'feedback' => (string) ($result['feedback'] ?? ''),
            'state'    => (string) $result['state'],
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'  => new external_value(PARAM_BOOL, 'Whether this cell was graded'),
            'error'    => new external_value(PARAM_RAW, 'Error message if grading was refused/failed'),
            'mark'     => new external_value(PARAM_FLOAT, 'Mark awarded for this cell'),
            'maxmark'  => new external_value(PARAM_FLOAT, 'Marks available for this cell'),
            'feedback' => new external_value(PARAM_RAW, 'Case-specific feedback for this cell, if any'),
            'state'    => new external_value(PARAM_RAW, 'question_state name this cell response resolved to'),
        ]);
    }
}