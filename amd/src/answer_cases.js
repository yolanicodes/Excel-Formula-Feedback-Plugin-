// =============================================================
// amd/src/answer_cases.js
//
// AMD module loaded by edit_excelformula_form.php.
//
// Builds a NESTED ACCORDION UI for answer cases:
//
//   Answers
//     -> one collapsible group per graded CELL
//          (e.g. "Cell B6 — Amount before VAT", with a total-marks
//           field and a case count)
//          -> one collapsible row per ANSWER CASE for that cell
//             (Answer 1, Answer 2 ... each with a credit-type
//              dropdown, marks, accepted formulas, feedback)
//
// WHY THIS EXISTS AS CUSTOM JS RATHER THAN mform repeat_elements:
//   Moodle's repeat_elements() API only supports a single flat
//   list of repeated field groups -- it has no concept of nesting
//   one repeating group inside another. To get a true nested
//   cell -> case accordion, this module renders the whole section
//   itself into a single static container (#excelformula-answercases)
//   and keeps the real data in a hidden field as JSON.
//
// DATA FLOW:
//   1. On init(), read the existing structure out of the hidden
//      field "answercasesdata" (PHP put it there from the database).
//   2. Render the accordion from that in-memory structure.
//   3. Every add/remove/edit updates the in-memory structure AND
//      immediately re-serialises it into the hidden field, so the
//      data is always submit-ready -- no separate "build on submit"
//      step is needed (simpler and avoids missing an edge case).
//
// HOW AMD WORKS IN MOODLE:
//   - This file lives at:  amd/src/answer_cases.js
//   - Compiled output at:  amd/build/answer_cases.min.js
//   - Production: run `grunt amd` from Moodle root to compile.
//   - Development shortcut: add these two lines to config.php
//       $CFG->cachejs = false;
//       $CFG->jsrev   = -1;
//     Moodle will then serve the source file directly, no grunt needed.
//   - PHP call: $PAGE->requires->js_call_amd(
//         'qtype_excelformula/answer_cases', 'init', [$strings]
//     );
// =============================================================

define(['jquery'], function($) {

    // ----------------------------------------------------------
    // Credit-type colour map. Applied to the <select> itself
    // (border + background) rather than to a separate badge,
    // since the dropdown is the real editable control.
    // ----------------------------------------------------------
    var CREDIT = {
        full: {
            border: '#2E7D32',   // Moodle green
            bg: '#F1F8F1'
        },
        partial: {
            border: '#E65100',   // Moodle orange
            bg: '#FFF8F3'
        },
        hardcoded: {
            border: '#0073A8',   // Moodle blue
            bg: '#F0F7FB'
        }
    };

    // Populated from PHP-supplied language strings in init().
    var STR = {};

    // In-memory structure, the single source of truth while editing:
    //   [ { cellref, totalmarks, expanded, cases: [
    //         { credittype, marks, formulas, feedback, expanded }
    //   ] } ]
    var state = [];

    var $container = null;
    var $hiddenfield = null;

    // ----------------------------------------------------------
    // persist()
    //   Writes the current in-memory state into the hidden field
    //   as JSON, so the form has up-to-date data at all times
    //   (not just right before submit -- this is simpler and more
    //   robust against the form being submitted from any state).
    // ----------------------------------------------------------
    function persist() {
        if ($hiddenfield) {
            $hiddenfield.val(JSON.stringify(state));
        }
    }

    // ----------------------------------------------------------
    // applySelectColour($select)
    //   Colours a credit-type <select> based on its current value.
    // ----------------------------------------------------------
    function applySelectColour($select) {
        var val = $select.val() || 'full';
        var style = CREDIT[val] || CREDIT.full;

        $select.css({
            'border-left': '4px solid ' + style.border,
            'background': style.bg
        });
    }


    // ----------------------------------------------------------
    // expandAll(cells)
    //   Default every cell/case in a loaded structure to
    //   expanded=true so it displays fully on first render,
    //   whether it came from the hidden field (init) or from an
    //   external detector (setState).
    // ----------------------------------------------------------
    function expandAll(cells) {
        return (cells || []).map(function(cell) {
            cell.expanded = true;
            (cell.cases || []).forEach(function(c) {
                c.expanded = true;
            });
            return cell;
        });
    }

    // ----------------------------------------------------------
    // setState(cells)
    //   Replaces the current state wholesale with an externally
    //   supplied cell/case structure (e.g. detected from an
    //   answer-key spreadsheet by
    //   qtype_excelformula/answerkey_parser), then re-renders and
    //   persists it into the hidden field like any other change.
    //
    //   @param {Array} cells Nested cell/case structure, same
    //                        shape as the hidden-field payload.
    // ----------------------------------------------------------
    function setState(cells) {
        state = expandAll(cells);
        render();
    }
    // ----------------------------------------------------------
    // render()
    //   Rebuilds the entire accordion DOM from `state`.
    //   Simpler and more reliable than trying to patch the DOM
    //   incrementally; the number of cells/cases is small enough
    //   that a full re-render on every change is not a problem.
    // ----------------------------------------------------------
    function render() {
        $container.empty();

        state.forEach(function(cell, cellIndex) {
            $container.append(renderCell(cell, cellIndex));
        });

        persist();
    }

    // ----------------------------------------------------------
    // renderCell(cell, cellIndex)
    //   Builds the outer accordion row for one cell, including
    //   its header (cellref input, total marks input, case count,
    //   expand/collapse chevron) and -- if expanded -- the nested
    //   case rows beneath it.
    // ----------------------------------------------------------
    function renderCell(cell, cellIndex) {
        var $wrap = $('<div class="excelformula-cell-group">');

        // ---- Header row ----
        var $header = $('<div class="excelformula-cell-header">');

        var $chevron = $('<span class="excelformula-chevron">')
            .html(cell.expanded ? '<i class="fa fa-chevron-down fa-fw"></i>' : '<i class="fa fa-chevron-right fa-fw"></i>')
            .on('click', function() {
                cell.expanded = !cell.expanded;
                render();
            });

        // NEW
        var $cellrefInput = $('<input type="text" class="form-control excelformula-cellref-input">')
            .attr('placeholder', STR.newcellplaceholder)
            .val(cell.cellref)
            .on('input', function() {
                cell.cellref = $(this).val();
                persist();
                updateSummary($header, cell);
            });

        // Worksheet this cell belongs to, since the same cellref (e.g. B3)
        // can exist on more than one sheet.
        var $sheetnameInput = $('<input type="text" class="form-control excelformula-sheetname-input">')
            .attr('placeholder', STR.sheetnameplaceholder)
            .val(cell.sheetname)
            .on('input', function() {
                cell.sheetname = $(this).val();
                persist();
            });

        var $totalmarksInput = $('<input type="number" min="0" step="any" class="form-control excelformula-totalmarks-input">')
            .val(cell.totalmarks)
            .on('input', function() {
                cell.totalmarks = parseFloat($(this).val()) || 0;
                persist();
                updateSummary($header, cell);
            });

       // NEW
       var $cellrefLabel = $('<label class="excelformula-inline-label">').text(STR.cellreference).css('text-align', 'left');
        var $sheetnameLabel = $('<label class="excelformula-inline-label">').text(STR.sheetname).css('text-align', 'left');
        var $marksLabel = $('<label class="excelformula-inline-label">').text(STR.totalmarks).css('text-align', 'left');
        var $summary = $('<div class="excelformula-cell-summary">');

        var $removeCellBtn = $('<button type="button" class="btn btn-link text-danger excelformula-remove-cell">')
            .text(STR.removecell)
            .on('click', function(e) {
                e.stopPropagation();
                state.splice(cellIndex, 1);
                persist();
                render();
            });

        $header.append(
            $chevron,
           // NEW
            $('<div class="excelformula-cell-fields">').append(
                $('<div class="excelformula-field-pair">').append($cellrefLabel, $cellrefInput),
                $('<div class="excelformula-field-pair">').append($sheetnameLabel, $sheetnameInput),
                $('<div class="excelformula-field-pair">').append($marksLabel, $totalmarksInput)
            ),
            $summary,
            $removeCellBtn
        );

        updateSummary($header, cell);

        $wrap.append($header);

        // ---- Nested case rows (only rendered if expanded) ----
        if (cell.expanded) {
            var $caseList = $('<div class="excelformula-case-list">');

            cell.cases.forEach(function(answercase, caseIndex) {
                $caseList.append(renderCase(cell, answercase, cellIndex, caseIndex));
            });

            var $addCaseBtn = $('<button type="button" class="btn btn-secondary btn-sm excelformula-add-case">')
                .text(STR.addcase)
                .on('click', function() {
                    cell.cases.push({
                        credittype: 'full',
                        marks: 0,
                        formulas: '',
                        feedback: '',
                        expanded: true
                    });
                    persist();
                    render();
                });

           var $addCellBtn = $('<button type="button" class="btn btn-secondary btn-sm excelformula-add-cell">')
    .text(STR.addcell)
    .on('click', function() {
       state.push({ cellref: '', sheetname: '', totalmarks: 0, expanded: true, cases: [] });
        persist();
        render();
    });

$caseList.append(
    $('<div class="excelformula-add-case-wrap" style="display:flex; gap:0.5rem;">')
        .append($addCaseBtn, $addCellBtn)
);
            $wrap.append($caseList);
        }

        return $wrap;
    }

    // ----------------------------------------------------------
    // updateSummary($header, cell)
    //   Refreshes the "N answer cases | N marks allocated" text
    //   shown in a cell's header. No weighting % is shown.
    // ----------------------------------------------------------
    function updateSummary($header, cell) {
        var text = cell.cases.length + ' ' + STR.cellsummary + ' | ' +
            cell.totalmarks + ' ' + STR.totalmarks.toLowerCase();
        $header.find('.excelformula-cell-summary').text(text);
    }

    // ----------------------------------------------------------
    // renderCase(cell, answercase, cellIndex, caseIndex)
    //   Builds one nested case row: a header (Answer N + credit
    //   type dropdown + chevron) and, if expanded, the marks,
    //   formulas, and feedback fields.
    // ----------------------------------------------------------
    function renderCase(cell, answercase, cellIndex, caseIndex) {
        var $wrap = $('<div class="excelformula-case-row">');

        var $header = $('<div class="excelformula-case-header">');

        var $chevron = $('<span class="excelformula-chevron">')
            .html(answercase.expanded ? '<i class="fa fa-chevron-down fa-fw"></i>' : '<i class="fa fa-chevron-right fa-fw"></i>')
            .on('click', function() {
                answercase.expanded = !answercase.expanded;
                render();
            });

        var $label = $('<span class="excelformula-case-label">')
            .text(STR.answernumber + ' ' + (caseIndex + 1));

        var $select = $('<select class="form-control excelformula-credittype-select">');
        [
            ['full', STR.credittype_full],
            ['partial', STR.credittype_partial],
            ['hardcoded', STR.credittype_hardcoded]
        ].forEach(function(opt) {
            $select.append($('<option>').val(opt[0]).text(opt[1]));
        });
        $select.val(answercase.credittype);
        applySelectColour($select);

        $select.on('change', function() {
            answercase.credittype = $(this).val();
            applySelectColour($select);
            persist();
        });

        var $removeCaseBtn = $('<button type="button" class="btn btn-link text-danger btn-sm excelformula-remove-case">')
            .text(STR.removecase)
            .on('click', function(e) {
                e.stopPropagation();
                cell.cases.splice(caseIndex, 1);
                persist();
                render();
            });
        
    var $cellTitle = $('<h4 class="excelformula-cell-title" style="margin:0 0.5rem 0 0.25rem; font-size:1rem; font-weight:600;">')
    .text(cell.cellref ? (STR.cellreference + ': ' + cell.cellref) : STR.newcellplaceholder);

        $header.append($cellTitle, $chevron, $label);
        $wrap.append($header);

        if (answercase.expanded) {
            
            var $body = $('<div class="excelformula-case-body" style="text-align:left;">');

            var $marksLabel = $('<label style="text-align:left;">').text(STR.marks);
            var $marksInput = $('<input type="number" min="0" step="any" class="form-control">')
                .val(answercase.marks)
                .on('input', function() {
                    answercase.marks = parseFloat($(this).val()) || 0;
                    persist();
                });

            $body.append($select, $removeCaseBtn, $marksLabel, $marksInput);

            var $formulasLabel = $('<label style="text-align:left;">').text(STR.formulas);
            var $formulasInput = $('<input type="text" class="form-control">')
                .attr('placeholder', STR.formulasplaceholder)
                .val(answercase.formulas)
                .on('input', function() {
                    answercase.formulas = $(this).val();
                    persist();
                });

            var $feedbackLabel = $('<label style="text-align:left;">').text(STR.feedback);
            var $feedbackInput = $('<textarea class="form-control" rows="3">')
                .val(answercase.feedback)
                .on('input', function() {
                    answercase.feedback = $(this).val();
                    persist();
                });
          
            // var $caseTitle = $('<h5 style="margin:0 0 0.75rem 0; font-size:0.9rem; font-weight:600; color:#495057;">')
            // .text(STR.answernumber + ' ' + (caseIndex + 1));
            // $body.prepend($caseTitle);
            $body.append(
                $('<div class="excelformula-field-block" style="display:block; margin-bottom:0.75rem; padding-left:2em;">')
    .append(
        $marksLabel.css({ display: 'block', marginBottom: '0.2rem' }),
        $marksInput
    ).append($marksLabel, $marksInput),
               $('<div class="excelformula-field-block" style="display:block; margin-bottom:0.75rem; padding-left:2em;">')
    .append(
        $formulasLabel.css({ display: 'block', marginBottom: '0.2rem' }),
        $formulasInput
    ).append($formulasLabel, $formulasInput),
                $('<div class="excelformula-field-block" style="display:block; margin-bottom:0.75rem; padding-left:2em;">')
    .append(
        $feedbackLabel.css({ display: 'block', marginBottom: '0.2rem' }),
        $feedbackInput
    ).append($feedbackLabel, $feedbackInput)
            );

            $wrap.append($body);
        }

        return $wrap;
    }

    // ----------------------------------------------------------
    // init(strings)
    //   Entry point called by PHP:
    //     $PAGE->requires->js_call_amd(
    //         'qtype_excelformula/answer_cases', 'init', [$strings]
    //     );
    //
    //   strings = language-string lookup table built in PHP,
    //   since AMD JS cannot call Moodle's get_string() itself.
    // ----------------------------------------------------------
    function init(strings) {
        STR = strings || {};

        $container = $('#excelformula-answercases');
        $hiddenfield = $('[name="answercasesdata"]');

        if (!$container.length || !$hiddenfield.length) {
            return;
        }

        // Load existing data (placed there server-side by
        // data_preprocessing() in edit_excelformula_form.php).
        var initial = [];
        try {
            var raw = $hiddenfield.val();
            if (raw) {
                var parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    initial = parsed;
                }
            }
        } catch (e) {
            initial = [];
        }

        // Default every cell/case to expanded=true on first load
        // so the teacher sees the full structure immediately,
        // matching how the question already looks.
        state = expandAll(initial);
        // Start with one empty cell group if this is a brand new
        // question with no saved answer cases yet.
        if (state.length === 0) {
           state.push({
                cellref: '',
                sheetname: '',
                totalmarks: 0,
                expanded: true,
                cases: [{
                    credittype: 'full',
                    marks: 0,
                    formulas: '',
                    feedback: '',
                    expanded: true
                }]
            });
        }

        render();
    }

    // Expose init() so PHP can call it
    return { init: init, setState: setState };
});
