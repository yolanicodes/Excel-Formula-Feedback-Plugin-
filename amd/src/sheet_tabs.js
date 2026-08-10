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
 * Toggles between worksheet grid panels on the student-facing question
 * display. Uses event delegation on a single container so it works for
 * any number of dynamically-rendered tabs without per-tab listeners.
 *
 * @module     qtype_excelformula/sheet_tabs
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {

    /**
     * Handle a click on any .qtype_excelformula_sheet_tab button: show its
     * matching panel, hide the others, and mark it active.
     *
     * @param {Event} e Click event.
     */
    function handleTabClick(e) {
        var $tab = $(e.currentTarget);
        var targetid = $tab.data('target');

        $tab.closest('.qtype_excelformula_sheet_tabs')
            .find('.qtype_excelformula_sheet_tab')
            .removeClass('active');
        $tab.addClass('active');

        $('.qtype_excelformula_sheet_panel').hide();
        $('#' + targetid).show();
    }

    /**
     * Entry point called by PHP:
     *   $PAGE->requires->js_call_amd('qtype_excelformula/sheet_tabs', 'init');
     */
    function init() {
        $(document).on('click', '.qtype_excelformula_sheet_tab', handleTabClick);
    }

    return {init: init};
});