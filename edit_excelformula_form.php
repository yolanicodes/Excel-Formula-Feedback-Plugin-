<?php
// ============================================================
// edit_excelformula_form.php
//
// Renders the teacher-facing question editing form.
//
// ANSWER CASES STRUCTURE (nested accordion):
//   Answers
//     -> one group per graded CELL (e.g. "Cell B6 — Amount before VAT")
//          -> one row per ANSWER CASE for that cell (Answer 1, Answer 2 ...)
//
// Because Moodle's mform/repeat_elements API only supports a single
// flat list of repeated fields (no nested repeats), the nested
// accordion UI you see on screen is built entirely in JS
// (amd/src/answer_cases.js) on top of a hidden data field.
//
// HOW DATA FLOWS:
//   1. PHP outputs the existing saved structure as JSON into a
//      hidden field: answercasesdata
//   2. JS reads that JSON on page load and builds the accordion UI
//      from it (one block per cell, one sub-row per case).
//   3. As the teacher edits, adds, or removes cells/cases in the
//      UI, JS keeps an in-memory copy of the structure.
//   4. Immediately before the form submits, JS serialises that
//      in-memory structure back into the SAME hidden field as JSON.
//   5. PHP reads answercasesdata back out of $data in validation()
//      and get_data(), and decodes it -- no per-row PARAM_* fields
//      are needed because the whole structure travels as one blob.
// ============================================================

defined('MOODLE_INTERNAL') || die();


class qtype_excelformula_edit_form extends question_edit_form {

    protected function definition_inner($mform) {
        global $PAGE;

        // ============================================================
        // SECTION 1 — Question spreadsheet
        // ============================================================
        $mform->addElement(
            'header',
            'questionfilehdr',
            get_string('questionfilehdr', 'qtype_excelformula')
        );
        $mform->setExpanded('questionfilehdr', true);

        $mform->addElement(
            'filepicker',
            'questionfile',
            get_string('questionfile', 'qtype_excelformula'),
            null,
            [
                'maxbytes'       => 10 * 1024 * 1024,
                'accepted_types' => ['.xlsx', '.xls'],
            ]
        );
        $mform->addHelpButton('questionfile', 'questionfile', 'qtype_excelformula');

        // Hidden field holds parsed cell data as JSON.
        // Populated server-side when the file is processed.
        $mform->addElement('hidden', 'celldata', '');
        $mform->setType('celldata', PARAM_RAW);


        // ============================================================
        // SECTION 2 — Answer key file
        // ============================================================
        $mform->addElement(
            'header',
            'answerkeyfilehdr',
            get_string('answerkeyfilehdr', 'qtype_excelformula')
        );
        $mform->setExpanded('answerkeyfilehdr', true);

        $mform->addElement(
            'filepicker',
            'answerkeyfile',
            get_string('answerkeyfile', 'qtype_excelformula'),
            null,
            [
                'maxbytes'       => 5 * 1024 * 1024,
                'accepted_types' => ['.xlsx', '.xls', '.json'],
            ]
        );
        $mform->addHelpButton('answerkeyfile', 'answerkeyfile', 'qtype_excelformula');

 $mform->addElement(
            'static',
            'answerkeydetectcontainer',
            '',
            html_writer::div(
                html_writer::tag(
                    'button',
                    get_string('detectanswercases', 'qtype_excelformula'),
                    [
                        'type'  => 'button',
                        'id'    => 'excelformula-detect-answercases',
                        'class' => 'btn btn-secondary',
                    ]
                ) .
                html_writer::div('', 'excelformula-detect-message'),
                'excelformula-answerkey-detect'
            )
        );
        // ============================================================
        // SECTION 3 — Answer cases (nested accordion: cell -> cases)
        //
        // Unlike the old flat repeat_elements list, this is rendered
        // as a single static HTML block. All the actual structure
        // (which cells exist, which cases belong to which cell, the
        // value of every field) lives in JS memory and is written
        // into the hidden "answercasesdata" field as JSON right
        // before submit.
        // ============================================================
        $mform->addElement(
            'header',
            'answercaseshdr',
            get_string('answercaseshdr', 'qtype_excelformula')
        );
        $mform->setExpanded('answercaseshdr', true);

        // Hidden field carrying the whole nested structure as JSON.
        // PHP populates this on load (data_preprocessing) and reads
        // it back on submit (validation / get_data).
        $mform->addElement('hidden', 'answercasesdata', '[]');
        $mform->setType('answercasesdata', PARAM_RAW);

        // Static container the JS module renders the accordion into.
        // Kept empty here -- amd/src/answer_cases.js builds everything
        // inside #excelformula-answercases on init().
        $mform->addElement(
            'static',
            'answercasescontainer',
            '',
            html_writer::div('', '', ['id' => 'excelformula-answercases'])
        );


        // ============================================================
        // Load the AMD module that builds and manages the
        // nested cell -> case accordion UI.
        //
        // 'qtype_excelformula/answer_cases' maps to:
        //   question/type/excelformula/amd/src/answer_cases.js
        //
        // For production:  run `grunt amd` from the Moodle root.
        // For development: add to config.php:
        //   $CFG->cachejs = false;
        //   $CFG->jsrev   = -1;
        //
        // Strings are passed through because AMD JS files cannot
        // call Moodle's get_string() directly -- they must be
        // supplied from PHP and given to the JS init() call.
        // ============================================================
        $jsstrings = [
            'addcell'              => get_string('addcell', 'qtype_excelformula'),
            'addcase'              => get_string('addanswercase', 'qtype_excelformula'),
            'removecell'           => get_string('removecell', 'qtype_excelformula'),
            'removecase'           => get_string('removecase', 'qtype_excelformula'),
            'cellreference'        => get_string('case_cell', 'qtype_excelformula'),
            'totalmarks'           => get_string('cell_totalmarks', 'qtype_excelformula'),
            'defaultfeedback'      => get_string('cell_defaultfeedback', 'qtype_excelformula'),
            'cellsummary'          => get_string('cellsummary', 'qtype_excelformula'),
            'answernumber'         => get_string('answernumber', 'qtype_excelformula'),
            'credittype'           => get_string('case_credittype', 'qtype_excelformula'),
            'credittype_full'      => get_string('credittype_full', 'qtype_excelformula'),
            'credittype_partial'   => get_string('credittype_partial', 'qtype_excelformula'),
            'credittype_hardcoded' => get_string('credittype_hardcoded', 'qtype_excelformula'),
            'marks'                => get_string('case_marks', 'qtype_excelformula'),
            'formulas'             => get_string('case_formulas', 'qtype_excelformula'),
            'formulasplaceholder'  => get_string('case_formulas_placeholder', 'qtype_excelformula'),
            'feedback'             => get_string('case_feedback', 'qtype_excelformula'),
          'newcellplaceholder'   => get_string('newcellplaceholder', 'qtype_excelformula'),
            'sheetname'            => get_string('sheetname', 'qtype_excelformula'),
            'sheetnameplaceholder' => get_string('sheetnameplaceholder', 'qtype_excelformula'),
        ];

       $PAGE->requires->js_call_amd(
            'qtype_excelformula/answer_cases',
            'init',
            [$jsstrings]
        );

       $PAGE->requires->js_call_amd(
            'qtype_excelformula/answerkey_parser',
            'init',
            [[
                'detecting'         => get_string('detectinganswercases', 'qtype_excelformula'),
                'detectedcount'     => get_string('detectedanswercount', 'qtype_excelformula'),
                'detecterror'       => get_string('detecterror', 'qtype_excelformula'),
            ]]
        );
    }


    // ============================================================
    // data_preprocessing
    // Re-hydrates the form when a teacher edits an existing question.
    //
    // Builds the nested structure:
    //   [
    //     ['cellref' => 'B6', 'totalmarks' => 4, 'cases' => [
    //         ['credittype' => 'full', 'marks' => 4,
    //          'formulas' => '=SUM(B2:B4)', 'feedback' => '...'],
    //         ...
    //     ]],
    //     ...
    //   ]
    //
    // and JSON-encodes it into answercasesdata, which JS reads on
    // page load to build the accordion UI.
    // ============================================================
    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);

        // ---- Pre-populate the question spreadsheet filepicker ----
    // Moodle filepickers work with draft file areas. We create a
    // fresh draft, copy the existing permanent file into it, and
    // give the draft itemid to the form so the picker shows the file.
    if (!empty($question->id)) {
        $draftitemid = file_get_submitted_draft_itemid('questionfile');
        $context     = context_system::instance();

        file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'qtype_excelformula',
            'questionfile',
            $question->id,
            ['subdirs' => 0, 'maxfiles' => 1]
        );

        $question->questionfile = $draftitemid;
    }

        if (!empty($question->options)) {
            $question->celldata = $question->options->spreadsheetdata ?? '';

            $cells = [];

            // $question->options->answers is keyed by ansid and was
            // populated by questiontype.php::get_question_options(),
            // each with its own ->answercases sub-array already
            // attached.
            if (!empty($question->options->answers)) {
                foreach ($question->options->answers as $answer) {
                   $cell = [
                        'cellref'    => $answer->cellref ?? '',
                        'sheetname'  => $answer->sheetname ?? '',
                        'totalmarks' => isset($answer->markallocation)
                            ? (float) $answer->markallocation : 0,
                        'defaultfeedback' => $answer->defaultfeedback ?? '',
                        'cases'      => [],
                    ];

                    if (!empty($answer->answercases)) {
                        foreach ($answer->answercases as $case) {
                            $cell['cases'][] = [
                                'credittype' => $case->casetype ?? 'full',
                                'marks'      => isset($case->casemark)
                                    ? (float) $case->casemark : 0,
                                'formulas'   => $case->answertext ?? '',
                                'feedback'   => $case->casefeedback ?? '',
                            ];
                        }
                    }

                    $cells[] = $cell;
                }
            }

            $question->answercasesdata = json_encode($cells);
        }

        return $question;
    }


    // ============================================================
    // validation
    // Reads the nested JSON structure back out of answercasesdata
    // (written there by JS right before submit) and validates it
    // server-side. This is the authoritative validation step --
    // never trust that the client-side JS already caught everything.
    // ============================================================
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $cells = [];
        if (!empty($data['answercasesdata'])) {
            $decoded = json_decode($data['answercasesdata'], true);
            if (is_array($decoded)) {
                $cells = $decoded;
            }
        }

        if (empty($cells)) {
            $errors['answercasescontainer'] =
                get_string('atleastonecellrequired', 'qtype_excelformula');
            return $errors;
        }

       // NEW
        foreach ($cells as $i => $cell) {
            $cellref   = trim($cell['cellref'] ?? '');
            $sheetname = trim($cell['sheetname'] ?? '');

            if ($cellref === '') {
                $errors['answercasescontainer'] =
                    get_string('casecellrequired', 'qtype_excelformula');
                continue;
            }

            if ($sheetname === '') {
                $errors['answercasescontainer'] =
                    get_string('casesheetnamerequired', 'qtype_excelformula');
                continue;
            }

            if (empty($cell['cases'])) {
                $errors['answercasescontainer'] =
                    get_string('atleastonecaserequired', 'qtype_excelformula');
                continue;
            }

            $totalmarks = isset($cell['totalmarks']) ? floatval($cell['totalmarks']) : 0;
            if ($totalmarks < 0) {
                $errors['answercasescontainer'] =
                    get_string('casemarksinvalid', 'qtype_excelformula');
            }

            foreach ($cell['cases'] as $case) {
                if (empty(trim($case['formulas'] ?? ''))) {
                    $errors['answercasescontainer'] =
                        get_string('caseformulasrequired', 'qtype_excelformula');
                }

                $marks = $case['marks'] ?? '';
                if ($marks !== '' && (!is_numeric($marks) || floatval($marks) < 0)) {
                    $errors['answercasescontainer'] =
                        get_string('casemarksinvalid', 'qtype_excelformula');
                }

                $validtypes = ['full', 'partial', 'hardcoded'];
                if (!in_array($case['credittype'] ?? '', $validtypes, true)) {
                    $errors['answercasescontainer'] =
                        get_string('casemarksinvalid', 'qtype_excelformula');
                }
            }
        }

        return $errors;
    }


    public function qtype() {
        return 'excelformula';
    }

} // End of class qtype_excelformula_edit_form