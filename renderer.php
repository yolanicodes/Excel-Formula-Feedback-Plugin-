<?php
// ============================================================
// renderer.php
// This file controls what the STUDENT sees:
//   - The cell data table (showing them the spreadsheet values)
//   - The formula input box
//   - Feedback after they submit
//
// Think of this as the "view" of your plugin.
// ============================================================

defined('MOODLE_INTERNAL') || die();


class qtype_excelformula_renderer extends qtype_renderer {

    /**
     * Render the question for a student to answer.
     * Called by Moodle when it's time to display the question.
     *
     * $qa      = the current quiz attempt
     * $options = display settings (e.g. show feedback or not)
     */
    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {

        $question = $qa->get_question();

        $output = '';
        $output .= html_writer::tag('div', $question->format_questiontext($qa),
            ['class' => 'qtext']);

        // --------------------------------------------------------
        // 1. Show the cell data table (if cell data exists)
        // --------------------------------------------------------
        if (!empty($question->celldata)) {
            $data = json_decode($question->celldata, true);
            if (is_array($data)) {
                // Detect multi-sheet grid format {"sheets": {...}} vs legacy
                // single-sheet {"dimensions":...} vs old flat {"A1": 10} table.
                if (isset($data['sheets']) && is_array($data['sheets'])) {
                    $output .= $this->render_sheet_tabs($data['sheets']);
                } else if (isset($data['dimensions'])) {
                    $output .= $this->render_spreadsheet_grid($data);
                } else {
                    // Fallback: render the old flat table so existing questions
                    // don't break before teachers re-upload their spreadsheets.
                    $output .= $this->render_cell_table($data);
                }
            }
        }

        // --------------------------------------------------------
        // 2. One answer field per graded cell, grouped by worksheet,
        //    answered in the server-enforced order (see
        //    question->get_next_ungraded_ansid()). Each cell shows
        //    its own mark/feedback inline once graded via the
        //    qtype_excelformula_grade_cell AJAX step, so there is no
        //    separate whole-question feedback block any more.
        // --------------------------------------------------------
        $output .= $this->render_cell_answers($qa, $question, $options);

        return $output;
    }

    /**
     * Render one answer input per graded cell, grouped by worksheet,
     * in the order the student is required to answer them.
     *
     * Emits the DOM contract amd/src/cell_answer.js binds to:
     *   #qtype_excelformula_cellanswers_{qubaid}_{slot}
     *     .qtype_excelformula_sheet_group[data-sheetname]
     *       .qtype_excelformula_cell_answer[data-ansid][data-cellref]
     *                                       [data-sheetname][data-graded][data-active]
     *
     * @param question_attempt $qa
     * @param qtype_excelformula_question $question
     * @param question_display_options $options
     * @return string HTML
     */
    private function render_cell_answers(question_attempt $qa, $question, question_display_options $options): string {
        global $PAGE;

        if (empty($question->answers)) {
            return '';
        }

        $qubaid = $qa->get_usage_id();
        $slot   = $qa->get_slot();

        $gradedansids = $question->get_graded_ansids($qa);
        $nextansid    = $question->get_next_ungraded_ansid($qa);

        // Group answers by sheetname, preserving definition order, so
        // the on-screen sequence matches get_next_ungraded_ansid().
        $bysheet = [];
        foreach ($question->answers as $answer) {
            $bysheet[$answer->sheetname ?? ''][] = $answer;
        }

        $groupshtml = '';
        foreach ($bysheet as $sheetname => $answers) {
            $cellshtml = '';
            foreach ($answers as $answer) {
                $ansid = (int) $answer->ansid;
                $cellshtml .= $this->render_cell_answer(
                    $qa, $question, $answer, $options,
                    in_array($ansid, $gradedansids, true),
                    $ansid === $nextansid
                );
            }
            $groupshtml .= html_writer::div(
                html_writer::tag('h5', s($sheetname), ['class' => 'qtype_excelformula_sheet_group_title']) .
                $cellshtml,
                'qtype_excelformula_sheet_group',
                ['data-sheetname' => $sheetname]
            );
        }

        $wrap = html_writer::div($groupshtml, 'qtype_excelformula_cell_answers', [
            'id'          => "qtype_excelformula_cellanswers_{$qubaid}_{$slot}",
            'data-qubaid' => $qubaid,
            'data-slot'   => $slot,
        ]);

        // NOTE: 'checkingcell', 'markawarded' and 'checkcell' are new
        // language strings this feature needs; not yet added to
        // lang/en/qtype_excelformula.php pending that file's turn.
        $PAGE->requires->js_call_amd('qtype_excelformula/cell_answer', 'init', [
            $qubaid,
            $slot,
            [
                'checking'    => get_string('checkingcell', 'qtype_excelformula'),
                'markawarded' => get_string('markawardedtemplate', 'qtype_excelformula'),
                'detecterror' => get_string('detecterror', 'qtype_excelformula'),
            ],
        ]);

        return $wrap;
    }

    /**
     * Render a single graded-cell answer block: label, input, a
     * "check" control, and — once graded — its mark and case feedback.
     *
     * @param question_attempt $qa
     * @param qtype_excelformula_question $question
     * @param stdClass $answer   Row from qtype_excelformula_answers, with ->answercases attached.
     * @param question_display_options $options
     * @param bool $isgraded     Whether a response has already been recorded for this cell.
     * @param bool $isactive     Whether this is the next cell the student may answer.
     * @return string HTML
     */
    private function render_cell_answer(question_attempt $qa, $question, $answer,
            question_display_options $options, bool $isgraded, bool $isactive): string {

        $ansid      = (int) $answer->ansid;
        $fieldname  = $qa->get_qt_field_name('answer_' . $ansid);
        $currentval = $qa->get_last_qt_var('answer_' . $ansid, '');

        $inputattributes = [
            'type'         => 'text',
            'name'         => $fieldname,
            'id'           => $fieldname,
            'class'        => 'form-control qtype_excelformula_input qtype_excelformula_cell_input',
            'size'         => 40,
            'placeholder'  => get_string('formulaplaceholder', 'qtype_excelformula'),
            'value'        => s($currentval),
            'spellcheck'   => 'false',
            'autocomplete' => 'off',
        ];

        if ($isgraded) {
            // Readonly (not disabled) so the value still submits with
            // the rest of the quiz form.
            $inputattributes['readonly'] = 'readonly';
        } else if ($options->readonly || !$isactive) {
            $inputattributes['disabled'] = 'disabled';
        }

        $feedbackhtml = '';
        $stateclass   = '';
        $alertclass   = '';
        if ($isgraded) {
            $result     = $question->grade_single_cell($ansid, $currentval);
            $stateclass = 'qtype_excelformula_state_' . $result['state'];
            $alertmap = [
              'gradedright'   => 'alert alert-success',
              'gradedpartial' => 'alert alert-warning',
              'gradedwrong'   => 'alert alert-danger',
            ];
            $alertclass = $alertmap[$result['state']] ?? '';

            // Show this cell's mark/feedback whenever it has already been
            // graded via qtype_excelformula_grade_cell (grade_cell.php),
            // regardless of $options->feedback. That flag reflects the
            // *whole-question* behaviour state, which only becomes true
            // once the entire attempt is finished -- gating on it here
            // caused this block to disappear on every full page reload
            // during the attempt (e.g. visiting the quiz summary and
            // returning), even though the mark/feedback had already been
            // legitimately awarded and persisted as a qt var.
            $feedbackhtml .= html_writer::tag('p',
                get_string('markawarded', 'qtype_excelformula',
                    (object) ['mark' => $result['mark'], 'maxmark' => $result['maxmark']]),
                ['class' => 'qtype_excelformula_cell_mark']
            );
                       if (trim($result['feedback']) !== '') {
                $feedbackhtml .= html_writer::tag('p', s($result['feedback']),
                    ['class' => 'qtype_excelformula_casefeedback']);
            }

            // Show this cell's own correct formula (first "full credit"
            // case) whenever it wasn't fully correct, instead of one
            // formula summary for the whole question.
            if ($result['state'] !== 'gradedright') {
                $correctformula = $question->get_correct_response()['answer_' . $ansid] ?? '';
                if ($correctformula !== '') {
                    $feedbackhtml .= html_writer::tag('p',
                        get_string('correctansweris', 'qtype_excelformula', s($correctformula)),
                        ['class' => 'qtype_excelformula_correctanswer']);
                }
            }
        }

        $submitbutton = html_writer::tag('button', get_string('checkcell', 'qtype_excelformula'), [
            'type'  => 'button',
            'class' => 'btn btn-secondary btn-sm qtype_excelformula_submit_cell',
            'style' => ($isactive && !$isgraded && !$options->readonly) ? '' : 'display:none',
        ]);

        return html_writer::div(
            html_writer::tag('label', get_string('cellanswerlabel', 'qtype_excelformula', $answer->cellref),
                ['for' => $fieldname, 'class' => 'qtype_excelformula_label']) .
            html_writer::empty_tag('input', $inputattributes) .
            $submitbutton .
            html_writer::div($feedbackhtml, 'qtype_excelformula_cell_feedback'),
            trim('qtype_excelformula_cell_answer ' . $stateclass),
            [
                'data-ansid'     => $ansid,
                'data-cellref'   => $answer->cellref,
                'data-sheetname' => $answer->sheetname,
                'data-graded'    => $isgraded ? '1' : '0',
                'data-active'    => $isactive ? '1' : '0',
            ]
        );
    }


    /**
     * Build an HTML table showing the cell values.
     * e.g. {"A1":10,"A2":20} becomes a table with A1=10, A2=20
     */
    private function render_cell_table($cells) {
        $rows = '';
        foreach ($cells as $ref => $value) {
            $rows .= html_writer::tag('tr',
                html_writer::tag('td', html_writer::tag('strong', strtoupper($ref)),
                    ['class' => 'qtype_excelformula_cellref']) .
                html_writer::tag('td', s($value),
                    ['class' => 'qtype_excelformula_cellval'])
            );
        }

        $table = html_writer::tag('table',
            html_writer::tag('thead',
                html_writer::tag('tr',
                    html_writer::tag('th', 'Cell') .
                    html_writer::tag('th', 'Value')
                )
            ) .
            html_writer::tag('tbody', $rows),
            ['class' => 'table table-sm table-bordered qtype_excelformula_table']
        );

        return html_writer::div(
            html_writer::tag('p',
                html_writer::tag('strong', get_string('celldataheading', 'qtype_excelformula'))
            ) . $table,
            'qtype_excelformula_celldata'
        );
    }

/**
 * Render a toggle-able set of worksheet grids.
 *
 * Builds one tab button per worksheet (keyed by sheet name) and one
 * grid panel per worksheet, reusing render_spreadsheet_grid() for
 * each panel. Only the first tab/panel is visible by default; the
 * rest are hidden via inline style and toggled by a small inline
 * script, since this markup has no server round-trip once rendered.
 *
 * @param array $sheets Sheet name => grid data, as produced by
 *                       qtype_excelformula_spreadsheet_parser::parse().
 * @return string HTML for the tab bar plus all sheet panels.
 */
// NEW
private function render_sheet_tabs(array $sheets): string {

    global $PAGE;
    $PAGE->requires->js_call_amd('qtype_excelformula/sheet_tabs', 'init');

    $tabs   = '';
    $panels = '';
    $first  = true;

    foreach ($sheets as $sheetname => $sheetdata) {

        $panelid = 'qtype_excelformula_sheet_' . md5($sheetname);

       $tabs .= html_writer::tag(
            'button',
            s($sheetname),
            [
                'type'        => 'button',
                'class'       => 'qtype_excelformula_sheet_tab' . ($first ? ' active' : ''),
                'data-target' => $panelid,
            ]
        );

        $panels .= html_writer::div(
            $this->render_spreadsheet_grid($sheetdata),
            'qtype_excelformula_sheet_panel',
            ['id' => $panelid, 'style' => $first ? '' : 'display:none']
        );

        $first = false;
    }

    return html_writer::div($tabs, 'qtype_excelformula_sheet_tabs') . $panels;
}

    /**
 * Render the parsed spreadsheet data as an HTML grid.
 *
 * $data is the array produced by SpreadsheetParser::parse() and
 * stored as JSON in $question->celldata. It contains:
 *   - dimensions   : minRow, maxRow, minCol, maxCol
 *   - cells        : keyed by "A1", "B3", etc.
 *   - mergedCells  : array of merge descriptors
 *   - columnWidths : col letter => pixel width (or null)
 *   - rowHeights   : row number => pixel height (or null)
 */
private function render_spreadsheet_grid(array $data): string {

    $dims         = $data['dimensions'];
    $cells        = $data['cells']        ?? [];
    $mergedCells  = $data['mergedCells']  ?? [];
    $columnWidths = $data['columnWidths'] ?? [];
    $rowHeights   = $data['rowHeights']   ?? [];

    $minRow = (int) $dims['minRow'];
    $maxRow = (int) $dims['maxRow'];
    $minCol = (int) $dims['minCol'];
    $maxCol = (int) $dims['maxCol'];

    // --------------------------------------------------------
    // Build a "skip map" from merged cell ranges.
    // Any cell that is NOT the top-left corner of a merge should
    // be skipped entirely when iterating — the corner cell will
    // already carry the colspan/rowspan.
    //
    // Format: $skipMap["col_row"] = true
    // Format: $spanMap["col_row"] = ['colspan' => N, 'rowspan' => M]
    // --------------------------------------------------------
    $skipMap = [];
    $spanMap = [];

    foreach ($mergedCells as $merge) {
        $sc = (int) $merge['startCol'];
        $sr = (int) $merge['startRow'];
        $ec = (int) $merge['endCol'];
        $er = (int) $merge['endRow'];

        $colspan = $ec - $sc + 1;
        $rowspan = $er - $sr + 1;

        // Mark the top-left corner as having a span.
        $spanMap[$sc . '_' . $sr] = [
            'colspan' => $colspan,
            'rowspan' => $rowspan,
        ];

        // Mark all other cells in the merge as skipped.
        for ($c = $sc; $c <= $ec; $c++) {
            for ($r = $sr; $r <= $er; $r++) {
                if ($c === $sc && $r === $sr) {
                    continue; // corner cell is rendered, not skipped
                }
                $skipMap[$c . '_' . $r] = true;
            }
        }
    }

    // --------------------------------------------------------
    // Build column letter lookup: colNum (int) => letter (string)
    // --------------------------------------------------------
    $colLetters = [];
    for ($colNum = $minCol; $colNum <= $maxCol; $colNum++) {
        // PhpSpreadsheet's autoloader is only loaded at save time
        // (questiontype.php::save_question_options()), not on this
        // student-facing render path, so use the local converter instead.
        $colLetters[$colNum] = $this->num_to_col_letter($colNum);
        // If PhpSpreadsheet is not available in renderer context, use this fallback:
        // $colLetters[$colNum] = $this->num_to_col_letter($colNum);
    }

    // --------------------------------------------------------
    // <colgroup> — sets pixel widths per column.
    // The first <col> is for the row-number header column.
    // --------------------------------------------------------
    $colgroup  = html_writer::empty_tag('col', ['style' => 'width:32px']); // row header
    for ($colNum = $minCol; $colNum <= $maxCol; $colNum++) {
        $letter = $colLetters[$colNum];
        $w      = $columnWidths[$letter] ?? 90; // default 90px
        $colgroup .= html_writer::empty_tag('col', ['style' => "width:{$w}px"]);
    }
    $colgroup = html_writer::tag('colgroup', $colgroup);

    // --------------------------------------------------------
    // Header row: blank corner + column letters (A, B, C…)
    // --------------------------------------------------------
    $headerCells = html_writer::tag('th', '', ['class' => 'qtype_excelformula_corner']);
    for ($colNum = $minCol; $colNum <= $maxCol; $colNum++) {
        $headerCells .= html_writer::tag(
            'th',
            $colLetters[$colNum],
            ['class' => 'qtype_excelformula_col_header']
        );
    }
    $thead = html_writer::tag('thead',
        html_writer::tag('tr', $headerCells)
    );

    // --------------------------------------------------------
    // Body rows
    // --------------------------------------------------------
    $tbodyRows = '';

    for ($row = $minRow; $row <= $maxRow; $row++) {

        $rowStyle = '';
        if (!empty($rowHeights[$row])) {
            $rowStyle = 'height:' . $rowHeights[$row] . 'px';
        }

        $rowCells = html_writer::tag(
            'td',
            (string) $row,
            ['class' => 'qtype_excelformula_row_header']
        );

        for ($colNum = $minCol; $colNum <= $maxCol; $colNum++) {

            $key = $colNum . '_' . $row;

            // This cell is absorbed by a merge — do not render it.
            if (!empty($skipMap[$key])) {
                continue;
            }

            $letter  = $colLetters[$colNum];
            $cellRef = $letter . $row;

            $cellData = $cells[$cellRef] ?? ['value' => '', 'type' => 'empty', 'style' => []];
            $value    = s($cellData['value'] ?? '');
            $type     = $cellData['type']  ?? 'empty';
            $style    = $cellData['style'] ?? [];

            // ---- Build inline CSS from stored style data ----
            $cssparts = [];
            if (!empty($style['bold'])) {
                $cssparts[] = 'font-weight:bold';
            }
            if (!empty($style['italic'])) {
                $cssparts[] = 'font-style:italic';
            }
            if (!empty($style['bgColor']) && $style['bgColor'] !== '#00000000') {
                $cssparts[] = 'background-color:' . s($style['bgColor']);
            }
            if (!empty($style['align']) && $style['align'] !== 'general') {
                $cssparts[] = 'text-align:' . s($style['align']);
            } elseif ($type === 'numeric') {
                $cssparts[] = 'text-align:right'; // numbers align right by default
            }

            $tdAttrs = ['class' => 'qtype_excelformula_grid_cell', 'data-cellref' => $cellRef];
            if (!empty($cssparts)) {
                $tdAttrs['style'] = implode(';', $cssparts);
            }

            // ---- Apply colspan/rowspan from merge map ----
            if (!empty($spanMap[$key])) {
                $tdAttrs['colspan'] = $spanMap[$key]['colspan'];
                $tdAttrs['rowspan'] = $spanMap[$key]['rowspan'];
            }

            $rowCells .= html_writer::tag('td', $value, $tdAttrs);
        }

        $trAttrs = ['class' => 'qtype_excelformula_grid_row'];
        if ($rowStyle) {
            $trAttrs['style'] = $rowStyle;
        }
        $tbodyRows .= html_writer::tag('tr', $rowCells, $trAttrs);
    }

    $tbody = html_writer::tag('tbody', $tbodyRows);

    $table = html_writer::tag(
        'table',
        $colgroup . $thead . $tbody,
        ['class' => 'qtype_excelformula_grid']
    );

    return html_writer::div(
        $table,
        'qtype_excelformula_grid_wrap'
    );
}

/**
 * Fallback column-number-to-letter converter for use in the renderer
 * when PhpSpreadsheet's static method is not in scope.
 * Mirrors the logic already in question.php::num_to_col().
 */
private function num_to_col_letter(int $num): string {
    $result = '';
    while ($num > 0) {
        $num--;
        $result = chr(ord('A') + ($num % 26)) . $result;
        $num    = intval($num / 26);
    }
    return $result;
}
    /**
     * Show the correct answer on the review screen when the teacher
     * has enabled "Show correct answer".
     */
        public function correct_response(question_attempt $qa) {
        return '';
    }

} // End of class qtype_excelformula_renderer