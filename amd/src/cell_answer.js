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
 * Drives the student-facing "one cell at a time" answer flow.
 *
 * Renders no markup itself -- it operates on the DOM contract emitted
 * by qtype_excelformula_renderer::formulation_and_controls() (renderer.php):
 *
 *   <div class="qtype_excelformula_cell_answers"
 *        id="qtype_excelformula_cellanswers_{qubaid}_{slot}"
 *        data-qubaid="{qubaid}" data-slot="{slot}">
 *     <div class="qtype_excelformula_sheet_group" data-sheetname="Sheet1">
 *       <div class="qtype_excelformula_cell_answer"
 *            data-ansid="12" data-cellref="B6" data-sheetname="Sheet1"
 *            data-graded="0|1" data-active="0|1">
 *         <input class="qtype_excelformula_cell_input" ...>
 *         <button type="button" class="qtype_excelformula_submit_cell">...</button>
 *         <div class="qtype_excelformula_cell_feedback"></div>
 *       </div>
 *       ...
 *     </div>
 *     ...
 *   </div>
 *
 * and the spreadsheet grid emitted by render_spreadsheet_grid(), where
 * each <td> carries data-cellref="B6" and each sheet panel carries
 * id="qtype_excelformula_sheet_{md5(sheetname)}" (see sheet_tabs.js).
 *
 * SEQUENCING:
 *   Only one .qtype_excelformula_cell_answer may be data-active="1" at a
 *   time (server-rendered based on question->get_next_ungraded_ansid()).
 *   Submitting that cell calls the qtype_excelformula_grade_cell external
 *   function (services.php / classes/external/grade_cell.php), which
 *   records a genuine step on the live question_attempt. The server
 *   response is the single source of truth for the mark/feedback shown;
 *   this module never grades client-side.
 *
 * @module     qtype_excelformula/cell_answer
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    // Populated from PHP-supplied language strings in init().
    var STR = {};
    var ALERT_CLASS = {
        gradedright: 'alert alert-success',
        gradedpartial: 'alert alert-warning',
        gradedwrong: 'alert alert-danger'
    };

    /**
     * Look up the grid's sheet panel id for a given worksheet name.
     *
     * renderer.php's render_sheet_tabs() keys each panel by
     * md5(sheetname), but no MD5 implementation is available
     * client-side, so the id is read back off the matching tab
     * button's data-target attribute instead of recomputed here.
     *
     * @param {String} sheetname Worksheet name.
     * @return {String|null} Panel element id, or null if no matching tab exists.
     */
    function sheetPanelId(sheetname) {
        var panelid = null;
        $('.qtype_excelformula_sheet_tab').each(function() {
            if ($(this).text() === sheetname) {
                panelid = $(this).data('target');
            }
        });
        return panelid;
    }

    /**
     * Remove any previous highlight and highlight the grid cell for the
     * given sheet/cellref, switching sheet tabs first if needed.
     *
     * @param {String} sheetname Worksheet name the active cell belongs to.
     * @param {String} cellref   Cell reference, e.g. "B6".
     */
    function highlightCell(sheetname, cellref) {
        $('.qtype_excelformula_grid_cell').removeClass('qtype_excelformula_active_cell');

        var panelid = sheetPanelId(sheetname);
        var $scope;

        if (panelid) {
            // Reuses sheet_tabs.js's own delegated click handler so the
            // tab bar's active state stays in sync -- no duplicated logic.
            $('.qtype_excelformula_sheet_tab[data-target="' + panelid + '"]').trigger('click');
            // Scope the lookup to this sheet's panel -- the same cellref
            // (e.g. B3) can exist on more than one sheet, so an unscoped
            // selector could highlight the wrong sheet's cell.
            $scope = $('#' + panelid);
        } else {
            // Legacy single-sheet grid: no tab panel wrapper exists, so
            // there is only one grid on the page to search.
            $scope = $(document);
        }

        $scope.find('[data-cellref="' + cellref + '"].qtype_excelformula_grid_cell')
            .addClass('qtype_excelformula_active_cell');
    }

    /**
     * Apply the server-returned grading state to one cell block: lock
     * the input, show mark/feedback, colour it, and clear the active
     * highlight.
     *
     * @param {jQuery} $cell  The .qtype_excelformula_cell_answer block.
     * @param {Object} result Response from qtype_excelformula_grade_cell.
     */
    function applyGradedState($cell, result) {
        var $input = $cell.find('.qtype_excelformula_cell_input');
        var $feedback = $cell.find('.qtype_excelformula_cell_feedback');
        

        $input.prop('readonly', true);
        $cell.attr('data-graded', '1').attr('data-active', '0');
        $cell.find('.qtype_excelformula_submit_cell').prop('disabled', true).hide();

        $cell.removeClass('qtype_excelformula_state_gradedright ' +
            'qtype_excelformula_state_gradedpartial qtype_excelformula_state_gradedwrong');
        $cell.addClass('qtype_excelformula_state_' + result.state);
        $cell.addClass(ALERT_CLASS[result.state] || '');

        var markline = STR.markawarded
            .replace('{mark}', result.mark)
            .replace('{maxmark}', result.maxmark);

        var $text = $('<p class="qtype_excelformula_cell_mark">').text(markline);
        $feedback.empty().append($text);

                if (result.feedback) {
            $feedback.append($('<p class="qtype_excelformula_casefeedback">').text(result.feedback));
        } else if (result.state !== 'gradedright') {
            $feedback.append($('<p class="qtype_excelformula_casefeedback qtype_excelformula_default_feedback">').text(STR.defaultfeedback));
        }
    }

    /**
     * Activate the next unanswered cell block: enable its input, show
     * its submit control, mark it active, focus it, and highlight the
     * matching grid cell.
     *
     * @param {jQuery} $container The question's cell-answers wrapper.
     */
    function activateNextCell($container) {
        var $next = $container
            .find('.qtype_excelformula_cell_answer[data-graded="0"]')
            .first();

        if (!$next.length) {
            return;
        }

        $next.attr('data-active', '1');
        $next.find('.qtype_excelformula_cell_input').prop('disabled', false).focus();
        $next.find('.qtype_excelformula_submit_cell').prop('disabled', false).show();

        highlightCell($next.data('sheetname'), $next.data('cellref'));
    }

       /**
     * Show feedback for a non-locking attempt: cell stays active and
     * editable, but the student sees their mark and remaining attempts
     * before trying again.
     *
     * @param {jQuery} $cell  The .qtype_excelformula_cell_answer block.
     * @param {Object} result Response from qtype_excelformula_grade_cell.
     */
    function applyRetryState($cell, result) {
        var $input = $cell.find('.qtype_excelformula_cell_input');
        var $feedback = $cell.find('.qtype_excelformula_cell_feedback');

        $input.prop('disabled', false).val('').focus();
        $cell.find('.qtype_excelformula_submit_cell').prop('disabled', false);

        var markline = STR.markawarded
            .replace('{mark}', result.mark)
            .replace('{maxmark}', result.maxmark);

        var $text = $('<p class="qtype_excelformula_cell_mark">').text(markline);
        var remaining = result.attemptsmax - result.attemptsused;
        var $attemptsline = $('<p class="qtype_excelformula_attempts_remaining">')
            .text(STR.attemptsremaining.replace('{remaining}', remaining));

                $feedback.empty().append($text, $attemptsline);

        if (result.feedback) {
            $feedback.append($('<p class="qtype_excelformula_casefeedback">').text(result.feedback));
        } else {
            $feedback.append($('<p class="qtype_excelformula_casefeedback qtype_excelformula_default_feedback">').text(STR.defaultfeedback));
        }
    }

    /**
     * Submit the active cell's formula to qtype_excelformula_grade_cell
     * and update the UI once the server responds.
     *
     * @param {jQuery} $container The question's cell-answers wrapper.
     * @param {jQuery} $cell      The active .qtype_excelformula_cell_answer block.
     */
    function submitCell($container, $cell) {
        var $input = $cell.find('.qtype_excelformula_cell_input');
        var formula = $input.val();

        if (formula === '') {
            return;
        }

        $input.prop('disabled', true);
        $cell.find('.qtype_excelformula_submit_cell').prop('disabled', true);
        $cell.find('.qtype_excelformula_cell_feedback').text(STR.checking);

        Ajax.call([{
            methodname: 'qtype_excelformula_grade_cell',
            args: {
                qubaid: $container.data('qubaid'),
                slot: $container.data('slot'),
                ansid: parseInt($cell.data('ansid'), 10),
                formula: formula
            }
                }])[0].done(function(result) {
            if (!result.success) {
                $input.prop('disabled', false);
                $cell.find('.qtype_excelformula_submit_cell').prop('disabled', false);
                $cell.find('.qtype_excelformula_cell_feedback').text(result.error || STR.detecterror);
                return;
            }

            if (result.locked) {
                applyGradedState($cell, result);
                activateNextCell($container);
            } else {
                applyRetryState($cell, result);
            }
        }).fail(function(ex) {
            $input.prop('disabled', false);
            $cell.find('.qtype_excelformula_submit_cell').prop('disabled', false);
            Notification.exception(ex);
        });
    }

    /**
     * Entry point called by PHP once per question instance:
     *   $PAGE->requires->js_call_amd('qtype_excelformula/cell_answer',
     *       'init', [$qubaid, $slot, $strings]);
     *
     * @param {Number} qubaid  Question usage by activity id.
     * @param {Number} slot    Slot number within the usage.
     * @param {Object} strings Language strings: checking, markawarded, detecterror.
     */
    function init(qubaid, slot, strings) {
        STR = strings || {};

        var $container = $('#qtype_excelformula_cellanswers_' + qubaid + '_' + slot);
        if (!$container.length) {
            return;
        }

        // Event delegation: cell blocks are static per page load (no
        // add/remove at runtime), but delegation keeps this consistent
        // with the rest of the plugin's AMD modules.
        $container.on('click', '.qtype_excelformula_submit_cell', function(e) {
            e.preventDefault();
            submitCell($container, $(this).closest('.qtype_excelformula_cell_answer'));
        });

        $container.on('keydown', '.qtype_excelformula_cell_input', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                submitCell($container, $(this).closest('.qtype_excelformula_cell_answer'));
            }
        });

        var $active = $container.find('.qtype_excelformula_cell_answer[data-active="1"]').first();
        if ($active.length) {
            highlightCell($active.data('sheetname'), $active.data('cellref'));
        }
    }

    return {init: init};
});