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
                c.expanded = false;  // cases start collapsed, since they are nested
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
    // Replace the entire render() function with:
function render() {
    $container.empty();

    state.forEach(function(cell, cellIndex) {
        $container.append(renderCell(cell, cellIndex));
    });

    // "Add graded cell" lives outside all Answer groups — one global control.
    var $addCellBtn = $('<button type="button" class="btn btn-secondary btn-sm excelformula-add-cell">')
        .text(STR.addcell)
        .on('click', function() {
            state.push({cellref: '', sheetname: '', totalmarks: 0, defaultfeedback: '',expanded: true, cases: []});
            persist();
            render();
        });

    $container.append(
        $('<div class="excelformula-global-add">').append($addCellBtn)
    );

    persist();
}

 // ----------------------------------------------------------
    // renderCell(cell, cellIndex)
    //   Builds one top-level "Answer N" accordion group.
    //   The header is a clickable row (chevron + title + summary +
    //   remove button). The body — shown only when expanded —
    //   contains the cell-reference, worksheet, and total-marks
    //   fields, followed by the nested answer-case sub-accordions.
    // ----------------------------------------------------------
    function renderCell(cell, cellIndex) {
        var $wrap = $('<div class="excelformula-cell-group">');

        // ---- Accordion header: "Answer N" ----
        var $chevron = $('<span class="excelformula-chevron">')
            .html(cell.expanded
                ? '<i class="fa fa-chevron-down fa-fw"></i>'
                : '<i class="fa fa-chevron-right fa-fw"></i>'
            );

        var $title = $('<span class="excelformula-cell-title">')
            .text('Answer ' + (cellIndex + 1));

        var $summary = $('<span class="excelformula-cell-summary">');

        var $removeCellBtn = $('<button type="button" class="btn btn-link text-danger btn-sm excelformula-remove-cell">')
            .text(STR.removecell)
            .on('click', function(e) {
                e.stopPropagation();
                state.splice(cellIndex, 1);
                persist();
                render();
            });

        var $header = $('<div class="excelformula-cell-header">')
            .append($chevron, $title, $summary, $removeCellBtn)
            .on('click', function(e) {
                // Ignore clicks on the remove button itself.
                if ($(e.target).closest('.excelformula-remove-cell').length) {
                    return;
                }
                cell.expanded = !cell.expanded;
                render();
            });

        updateSummary($header, cell);
        $wrap.append($header);

        // ---- Accordion body: fields + nested case sub-accordions ----
        if (cell.expanded) {
            var $body = $('<div class="excelformula-cell-body">');

            // Cell reference field.
            var $cellrefLabel = $('<label class="excelformula-field-label">').text(STR.cellreference);
            var $cellrefInput = $('<input type="text" class="form-control excelformula-cellref-input">')
                .attr('placeholder', STR.newcellplaceholder)
                .val(cell.cellref)
                .on('input', function() {
                    cell.cellref = $(this).val();
                    persist();
                    updateSummary($header, cell);
                });

            // Worksheet field.
            var $sheetnameLabel = $('<label class="excelformula-field-label">').text(STR.sheetname);
            var $sheetnameInput = $('<input type="text" class="form-control excelformula-sheetname-input">')
                .attr('placeholder', STR.sheetnameplaceholder)
                .val(cell.sheetname)
                .on('input', function() {
                    cell.sheetname = $(this).val();
                    persist();
                });

            // Total marks field.
            var $totalmarksLabel = $('<label class="excelformula-field-label">').text(STR.totalmarks);
            var $totalmarksInput = $('<input type="number" min="0" step="any" class="form-control excelformula-totalmarks-input">')
                .val(cell.totalmarks)
                .on('input', function() {
                    cell.totalmarks = parseFloat($(this).val()) || 0;
                    persist();
                    updateSummary($header, cell);
                });
                 var $defaultfeedbackLabel = $('<label class="excelformula-field-label">').text(STR.defaultfeedback);
            var $defaultfeedbackInput = $('<textarea class="form-control excelformula-defaultfeedback-input" rows="2">')
                .val(cell.defaultfeedback)
                .on('input', function() {
                    cell.defaultfeedback = $(this).val();
                    persist();
                });
                
            $body.append(
                $('<div class="excelformula-field-row">').append($cellrefLabel, $cellrefInput),
                $('<div class="excelformula-field-row">').append($sheetnameLabel, $sheetnameInput),
                $('<div class="excelformula-field-row">').append($totalmarksLabel, $totalmarksInput),
                $('<div class="excelformula-field-row">').append($defaultfeedbackLabel, $defaultfeedbackInput)
            );

            // Nested answer-case sub-accordions.
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
                        expanded: false
                    });
                    persist();
                    render();
                });

          

            $caseList.append(
                $('<div class="excelformula-add-case-wrap">').append($addCaseBtn)
            );

            $body.append($caseList);
            $wrap.append($body);
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
    //   Builds one "Answer Case N" nested accordion row inside a
    //   cell group. The header shows the case number and is
    //   clickable to expand/collapse. The body — shown only when
    //   expanded — contains the credittype, marks, accepted
    //   formulas, and feedback fields.
    // ----------------------------------------------------------
    function renderCase(cell, answercase, cellIndex, caseIndex) {
        var $wrap = $('<div class="excelformula-case-row">');

        // ---- Case header: "Answer Case N" ----
        var $chevron = $('<span class="excelformula-chevron">')
            .html(answercase.expanded
                ? '<i class="fa fa-chevron-down fa-fw"></i>'
                : '<i class="fa fa-chevron-right fa-fw"></i>'
            );

        var $caseTitle = $('<span class="excelformula-case-title">')
            .text('Answer Case ' + (caseIndex + 1));

        var $removeCaseBtn = $('<button type="button" class="btn btn-link text-danger btn-sm excelformula-remove-case">')
            .text(STR.removecase)
            .on('click', function(e) {
                e.stopPropagation();
                cell.cases.splice(caseIndex, 1);
                persist();
                render();
            });

        var $caseHeader = $('<div class="excelformula-case-header">')
            .append($chevron, $caseTitle, $removeCaseBtn)
            .on('click', function(e) {
                // Ignore clicks on the remove button itself.
                if ($(e.target).closest('.excelformula-remove-case').length) {
                    return;
                }
                answercase.expanded = !answercase.expanded;
                render();
            });

        $wrap.append($caseHeader);

        // ---- Case body (visible when expanded) ----
        if (answercase.expanded) {
            var $body = $('<div class="excelformula-case-body">');

            // Credit type.
            var $credittypeLabel = $('<label class="excelformula-field-label">').text(STR.credittype);
            var $select = $('<select class="form-control excelformula-credittype-select">');
            [
                ['full',       STR.credittype_full],
                ['partial',    STR.credittype_partial],
                ['hardcoded',  STR.credittype_hardcoded]
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

            // Marks.
            var $marksLabel = $('<label class="excelformula-field-label">').text(STR.marks);
            var $marksInput = $('<input type="number" min="0" step="any" class="form-control">')
                .val(answercase.marks)
                .on('input', function() {
                    answercase.marks = parseFloat($(this).val()) || 0;
                    persist();
                });

            // Accepted formulas.
            var $formulasLabel = $('<label class="excelformula-field-label">').text(STR.formulas);
            var $formulasInput = $('<input type="text" class="form-control">')
                .attr('placeholder', STR.formulasplaceholder)
                .val(answercase.formulas)
                .on('input', function() {
                    answercase.formulas = $(this).val();
                    persist();
                });

            // Feedback.
            var $feedbackLabel = $('<label class="excelformula-field-label">').text(STR.feedback);
            var $feedbackInput = $('<textarea class="form-control" rows="3">')
                .val(answercase.feedback)
                .on('input', function() {
                    answercase.feedback = $(this).val();
                    persist();
                });

            $body.append(
                $('<div class="excelformula-field-row">').append($credittypeLabel, $select),
                $('<div class="excelformula-field-row">').append($marksLabel, $marksInput),
                $('<div class="excelformula-field-row">').append($formulasLabel, $formulasInput),
                $('<div class="excelformula-field-row">').append($feedbackLabel, $feedbackInput)
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
        // Moodle renders static elements in a col-md-3 (label) + col-md-9
        // (content) grid. Hiding the empty label column and widening the
        // content column to col-md-12 aligns the accordion flush-left with
        // the section headers above it and fills the full form width.
        var $fitem = $container.closest('.fitem');
        if ($fitem.length) {
            $fitem.children('.col-md-3').hide();
            $fitem.children('.col-md-9')
                .removeClass('col-md-9')
                .addClass('col-md-12')
                .css('display', 'block');
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
                defaultfeedback: '',
                expanded: true,
                cases: [{
                    credittype: 'full',
                    marks: 0,
                    formulas: '',
                    feedback: '',
                    expanded: false
                }]
            });
        }

        render();
    }

    // Expose init() so PHP can call it
    return { init: init, setState: setState };
});
