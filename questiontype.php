<?php
// ============================================================
// questiontype.php
// This file is the "glue" between your question and Moodle.
// It tells Moodle how to:
//   - Save and load question data from the database
//   - Create the correct question object when a quiz is opened
//
// As a beginner you mostly won't need to change this file.
// ============================================================

defined('MOODLE_INTERNAL') || die();


class qtype_excelformula extends question_type {

    // --------------------------------------------------------
    // Tell Moodle your question has its own extra database table
    // (the one defined in db/install.xml).
    // --------------------------------------------------------
    public function extra_question_fields() {
    return [
        'qtype_excelformula_options',
        'celldataitemid',
        'answerkeyitemid',
        'spreadsheetdata',
    ];
}


    // --------------------------------------------------------
    // When a teacher edits or creates a question, this method
    // copies the submitted form data into the database.
    // The parent class handles most of this — we just call it.
    // --------------------------------------------------------
    public function save_question_options($question) {
    global $CFG, $USER, $DB;

    // --------------------------------------------------------
    // Process the uploaded question spreadsheet if one was provided.
    // --------------------------------------------------------
    if (!empty($question->questionfile)) {

        // $question->questionfile holds the draft itemid from the
        // filepicker element. We move the draft file into a
        // permanent file area tied to this question.
        $context = context_system::instance(); // or context for the question category

        file_save_draft_area_files(
            $question->questionfile,      // draft itemid
            $context->id,
            'qtype_excelformula',         // component (your plugin)
            'questionfile',               // filearea name
            $question->id,                // itemid = question id
            ['subdirs' => 0, 'maxfiles' => 1]
        );

        // Retrieve the saved file record so we can get its path.
        $fs    = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'qtype_excelformula',
            'questionfile',
            $question->id,
            'id DESC',
            false   // exclude directories
        );

        if (!empty($files)) {
            $file     = reset($files); // the most recently uploaded file
            $temppath = make_temp_directory('qtype_excelformula') . '/' . $file->get_filename();

            // Copy from Moodle's file store to a real filesystem path
            // that PhpSpreadsheet can open.
            $file->copy_content_to($temppath);

            try {
                // Load the parser (Moodle auto-discovers classes/ directory).
                require_once($CFG->dirroot
                    . '/question/type/excelformula/classes/spreadsheet_parser.php');

               $parsed                    = qtype_excelformula_spreadsheet_parser::parse($temppath);
                $question->spreadsheetdata = json_encode($parsed);
                $question->celldataitemid  = $question->id;

            } catch (moodle_exception $e) {
                // If the file can't be parsed, preserve any existing
                // celldata and surface the error via debugging.
                debugging('qtype_excelformula: ' . $e->getMessage(), DEBUG_DEVELOPER);
            } finally {
                // Always clean up the temp file.
                if (file_exists($temppath)) {
                    unlink($temppath);
                }
            }
        }
    }

    // Sanitise tolerance (unchanged from your original).
   // if (!isset($question->tolerance) || $question->tolerance === '') {
       // $question->tolerance = 0;
    //}
    if (!isset($question->celldataitemid)) {
    $question->celldataitemid = 0;
    }
    if (!isset($question->answerkeyitemid)) {
        $question->answerkeyitemid = 0;
    }
    if (!isset($question->spreadsheetdata)) {
        $existing = $DB->get_record('qtype_excelformula_options', ['questionid' => $question->id]);
        $question->spreadsheetdata = $existing->spreadsheetdata ?? '';
    }

    $this->save_answer_cases($question);
    return parent::save_question_options($question);
}
// ----------------------------------------------------------
    // save_answer_cases($question)
    //   Persists the nested cell -> case structure submitted via
    //   answer_cases.js (as JSON in $question->answercasesdata)
    //   into qtype_excelformula_answers / qtype_excelformula_anscase.
    //
    //   extra_question_fields() only covers the single flat
    //   qtype_excelformula_options row, so these child tables are
    //   written here by hand. Existing rows for this question are
    //   cleared first, then re-inserted from the submitted
    //   structure -- simplest way to keep edited/removed cells and
    //   cases in sync without diffing old vs new rows.
    // ----------------------------------------------------------
    private function save_answer_cases($question) {
        global $DB;

        $cells = [];
        if (!empty($question->answercasesdata)) {
            $decoded = json_decode($question->answercasesdata, true);
            if (is_array($decoded)) {
                $cells = $decoded;
            }
        }

        $oldanswerids = $DB->get_fieldset_select(
            'qtype_excelformula_answers', 'ansid', 'questionid = ?', [$question->id]
        );
        if (!empty($oldanswerids)) {
            $DB->delete_records_list('qtype_excelformula_anscase', 'ansid', $oldanswerids);
        }
        $DB->delete_records('qtype_excelformula_answers', ['questionid' => $question->id]);

        foreach ($cells as $cell) {
            $answer = new stdClass();
            $answer->questionid     = $question->id;
            $answer->cellref        = $cell['cellref'] ?? '';
            $answer->sheetname      = $cell['sheetname'] ?? '';
            $answer->formulaweight  = 0;
            $answer->answerweight   = 0;
            $answer->markallocation = isset($cell['totalmarks']) ? floatval($cell['totalmarks']) : 0;
            $answer->rules          = json_encode([]);
            $answer->feedback       = '';
            $answer->defaultfeedback = $cell['defaultfeedback'] ?? '';

            $answer->ansid = $DB->insert_record('qtype_excelformula_answers', $answer);

            foreach ($cell['cases'] ?? [] as $case) {
                $anscase = new stdClass();
                $anscase->ansid        = $answer->ansid;
                $anscase->casemark     = isset($case['marks']) ? floatval($case['marks']) : 0;
                $anscase->casetype     = $case['credittype'] ?? 'full';
                $anscase->caseweight   = 0;
                $anscase->answertext   = $case['formulas'] ?? '';
                $anscase->casefeedback = $case['feedback'] ?? '';

                $DB->insert_record('qtype_excelformula_anscase', $anscase);
            }
        }
    }


    // --------------------------------------------------------
    // Load the question data from the database and put it
    // into the question object that gets used in the quiz.
    // --------------------------------------------------------
 public function get_question_options($question) {
    global $DB;

    parent::get_question_options($question);

    $answers = $DB->get_records(
        'qtype_excelformula_answers',
        ['questionid' => $question->id],
        'ansid ASC'
    );

    foreach ($answers as $ansid => $answer) {
        $answer->answercases = $DB->get_records(
            'qtype_excelformula_anscase',
            ['ansid' => $ansid],
            'anscaseid ASC'
        );
    }

    $question->options->answers = $answers;

    return true;
}


    // --------------------------------------------------------
    // Populate the question object that will be handed to the
    // student (question.php). Copy the options into properties.
    // --------------------------------------------------------
    public function initialise_question_instance(question_definition $question, $questiondata) {
    global $DB;

    parent::initialise_question_instance($question, $questiondata);

    // --------------------------------------------------------
    // FILE REFERENCE IDs
    // These integers are the Moodle File API item IDs for the
    // two uploaded spreadsheets. They are not used during
    // grading itself, but renderer.php needs celldataitemid
    // to display a "Download question data" link, and any
    // future re-parse logic needs answerkeyitemid to re-read
    // the answer key without hitting the DB.
    // --------------------------------------------------------
    $question->celldataitemid  = (int) $questiondata->options->celldataitemid;
    $question->answerkeyitemid = (int) $questiondata->options->answerkeyitemid;

   // Pass the structured grid JSON straight through as-is. renderer.php
    // and question.php each json_decode() it themselves and know how to
    // read the nested 'cells' map for their own purposes (display vs grading).
    $question->celldata = $questiondata->options->spreadsheetdata ?? '';

    // --------------------------------------------------------
    // ANSWERS  (one entry per graded cell)
    //
    // $question->answers is an array keyed by ansid.
    // Each entry is an object with these properties:
    //
    //   ->ansid           int     primary key from qtype_excelformula_answers
    //   ->cellref         string  cell being graded, e.g. "B3"
    //   ->formulaweight   float   weight for formula-structure matching
    //   ->answerweight    float   weight for numeric-result matching
    //   ->markallocation  float   marks this cell contributes to the total
    //   ->feedback        string  cell-level feedback (shown regardless of case)
    //   ->rules           array   decoded from JSON, e.g. ['tolerance' => 0.01]
    //   ->answercases     array   see ANSWER CASES section below
    // --------------------------------------------------------
    $question->answers = [];

    if (!empty($questiondata->options->answers)) {
        foreach ($questiondata->options->answers as $ansid => $answer) {

            // --------------------------------------------------
            // RULES
            // Stored as a JSON blob in the DB so new grading
            // parameters can be added without schema changes.
            // Decode once here so grading code reads plain PHP:
            //   $answer->rules['tolerance']  instead of
            //   json_decode($answer->rules)['tolerance']
            //
            // Defaults to an empty array if the column is NULL
            // or contains malformed JSON, so grading never
            // crashes on a missing rules value.
            // --------------------------------------------------
            if (!empty($answer->rules)) {
                $decoded = json_decode($answer->rules, true);
                $answer->rules = is_array($decoded) ? $decoded : [];
            } else {
                $answer->rules = [];
            }

            // --------------------------------------------------
            // ANSWER CASES
            // Each case is one acceptable answer for this cell.
            // Multiple cases let a teacher mark several different
            // formulas (or a formula and a hardcoded value) as
            // equally correct for the same cell.
            //
            // Each case object has:
            //   ->anscaseid    int     primary key
            //   ->ansid        int     parent answer id
            //   ->casemark     float   mark awarded when this case matches
            //   ->casetype     string  "formula" or "value"
            //   ->caseweight   float   relative weight vs other cases
            //   ->answertext   string  the accepted formula/value
            //   ->casefeedback string  feedback shown when THIS case matches
            // --------------------------------------------------
            // answercases was already attached by get_question_options().
            // Reindex to 0-based so grading loops work with foreach
            // without worrying about the DB-keyed offset.
            $answer->answercases = array_values((array) $answer->answercases);

            $question->answers[$ansid] = $answer;
        }
    }
}


    // --------------------------------------------------------
    // When the teacher exports a quiz to a file (e.g. XML),
    // this method specifies what extra data to include.
    // --------------------------------------------------------
   public function export_to_xml($question, qformat_xml $format, $extra = null) {
        $output  = '';
        $output .= '    <celldata>' . htmlspecialchars($question->options->spreadsheetdata ?? '') . "</celldata>\n";
        return $output;
    }


    // --------------------------------------------------------
    // When the teacher imports questions from an XML file,
    // this method reads back the values saved by export_to_xml.
    // --------------------------------------------------------
    public function import_from_xml($data, $question, qformat_xml $format, $extra = null) {
        if ($data['#']['@']['type'] !== 'excelformula') {
            return false;
        }

        $question = $format->import_headers($data);
        $question->qtype = 'excelformula';

       $question->spreadsheetdata = $format->getpath($data, ['#', 'celldata', 0, '#'], '{}');

        return $question;
    }

} // End of class qtype_excelformula
