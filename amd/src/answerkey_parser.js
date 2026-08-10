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
 * Detects answer cases from an uploaded answer-key spreadsheet's cell
 * comments via the qtype_excelformula_detect_answercases external
 * function, then hands the resulting cell/case structure to
 * qtype_excelformula/answer_cases for rendering.
 *
 * DATA FLOW:
 *   1. Teacher clicks #excelformula-detect-answercases.
 *   2. This module reads the draft itemid off the answerkeyfile
 *      filepicker and calls the detect_answercases external function.
 *   3. On success, the decoded cell/case structure is pushed into
 *      qtype_excelformula/answer_cases via its setState() method, so
 *      the existing accordion renderer/hidden-field logic is reused
 *      rather than duplicated here.
 *   4. A summary message ("N cells / N cases detected") is shown in
 *      the .excelformula-detect-message container.
 *
 * @module     qtype_excelformula/answerkey_parser
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'qtype_excelformula/answer_cases'],
        function($, Ajax, Notification, AnswerCases) {

    // Populated from PHP-supplied language strings in init().
    var STR = {};

    /**
     * Read the current draft itemid off the answer-key filepicker.
     *
     * Moodle's filepicker form element stores its draft itemid in a
     * plain hidden input named after the element itself.
     *
     * @return {Number|null} The draft itemid, or null if no file is selected.
     */
    function getDraftItemId() {
        var $input = $('input[name="answerkeyfile"]');
        var value = $input.length ? parseInt($input.val(), 10) : NaN;

        return isNaN(value) || value <= 0 ? null : value;
    }

    /**
     * Display a status/result message under the detect button.
     *
     * @param {String} text Message to display.
     * @param {Boolean} isError True to style the message as an error.
     */
    function showMessage(text, isError) {
        $('.excelformula-detect-message')
            .text(text)
            .toggleClass('text-danger', !!isError)
            .toggleClass('text-success', !isError);
    }

    /**
     * Build the "N cells / N cases detected" summary string.
     *
     * Substitutes {cellcount} and {casecount} tokens in the
     * 'detectedanswercount' language string, since it is passed to
     * this module already resolved (no $a) by edit_excelformula_form.php.
     *
     * @param {Number} cellcount Number of graded cells detected.
     * @param {Number} casecount Number of answer cases detected.
     * @return {String}
     */
    function buildDetectedMessage(cellcount, casecount) {
        return STR.detectedcount
            .replace('{cellcount}', cellcount)
            .replace('{casecount}', casecount);
    }

    /**
     * Click handler for #excelformula-detect-answercases: sends the
     * answer-key draft file off for parsing and, on success, loads the
     * detected structure into the answer_cases accordion.
     */
    function detectAnswerCases() {
        var draftitemid = getDraftItemId();

        if (!draftitemid) {
            showMessage(STR.detecterror, true);
            return;
        }

        showMessage(STR.detecting, false);

        Ajax.call([{
            methodname: 'qtype_excelformula_detect_answercases',
            args: {draftitemid: draftitemid}
        }])[0].done(function(response) {
            if (!response.success) {
                showMessage(response.error || STR.detecterror, true);
                return;
            }

           var cells;
            try {
                cells = JSON.parse(response.cells);
            } catch (e) {
                // Temporary diagnostic: surface the raw payload so we can see
                // whether the backend returned malformed/empty cell data.
                window.console.error('qtype_excelformula: failed to parse response.cells', response, e);
                showMessage(STR.detecterror, true);
                return;
            }

            // Delegates rendering + hidden-field persistence to
            // answer_cases.js, which owns that state.
            AnswerCases.setState(cells);

            showMessage(buildDetectedMessage(response.cellcount, response.casecount), false);
        }).fail(function(ex) {
            Notification.exception(ex);
            showMessage(STR.detecterror, true);
        });
    }

    /**
     * Entry point called by PHP:
     *   $PAGE->requires->js_call_amd(
     *       'qtype_excelformula/answerkey_parser', 'init', [$strings]
     *   );
     *
     * @param {Object} strings Language strings keyed: detecting, detectedcount, detecterror.
     */
    function init(strings) {
        STR = strings || {};

        $('#excelformula-detect-answercases').on('click', detectAnswerCases);
    }

    return {init: init};
});