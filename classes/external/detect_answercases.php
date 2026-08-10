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
 * External function: detect answer cases from an uploaded answer-key
 * spreadsheet's cell comments.
 *
 * @package    qtype_excelformula
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_excelformula\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

/**
 * Parses the draft answer-key file's cell comments into the nested
 * cell -> case structure consumed by amd/src/answer_cases.js, without
 * persisting anything -- the teacher still reviews/saves via the form.
 */
class detect_answercases extends external_api {

    /**
     * Parameter definition: the draft itemid of the uploaded answer-key file.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft file area itemid for the answer-key file'),
        ]);
    }

    /**
     * Reads the draft file, parses its cell comments, and returns the
     * detected cell/case structure plus summary counts.
     *
     * @param int $draftitemid Draft file area itemid.
     * @return array
     */
    public static function execute(int $draftitemid): array {
        global $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['draftitemid' => $draftitemid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/question:add', $context);

        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $usercontext->id, 'user', 'draft', $params['draftitemid'], 'id DESC', false
        );

        if (empty($files)) {
            return [
                'success'    => false,
                'error'      => get_string('detecterror_nofile', 'qtype_excelformula'),
                'cells'      => [],
                'cellcount'  => 0,
                'casecount'  => 0,
            ];
        }

        $file = reset($files);
        $temppath = make_temp_directory('qtype_excelformula') . '/' . $file->get_filename();
        $file->copy_content_to($temppath);

        require_once($CFG->dirroot . '/question/type/excelformula/classes/spreadsheet_parser.php');

        try {
            // Added in spreadsheet_parser.php (step 2): reads cell comments
            // and groups attribute:value pairs into cells[]/cases[].
            $cells = \qtype_excelformula_spreadsheet_parser::parse_answer_key($temppath);
        } catch (\moodle_exception $e) {
            return [
                'success'    => false,
                'error'      => $e->getMessage(),
                'cells'      => [],
                'cellcount'  => 0,
                'casecount'  => 0,
            ];
        } finally {
            if (file_exists($temppath)) {
                unlink($temppath);
            }
        }

        $casecount = 0;
        foreach ($cells as $cell) {
            $casecount += count($cell['cases'] ?? []);
        }

        return [
            'success'   => true,
            'error'     => '',
            'cells'     => json_encode($cells),
            'cellcount' => count($cells),
            'casecount' => $casecount,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'   => new external_value(PARAM_BOOL, 'Whether parsing succeeded'),
            'error'     => new external_value(PARAM_RAW, 'Error message if parsing failed'),
            'cells'     => new external_value(PARAM_RAW, 'JSON-encoded cell/case structure'),
            'cellcount' => new external_value(PARAM_INT, 'Number of graded cells detected'),
            'casecount' => new external_value(PARAM_INT, 'Total answer cases detected'),
        ]);
    }
}