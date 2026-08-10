<?php
// ============================================================
// lang/en/qtype_excelformula.php
// This file contains all the text labels and messages used
// throughout your plugin. Keeping text here (instead of
// hardcoding it in the PHP files) makes it easy to translate
// into other languages later.
// ============================================================

defined('MOODLE_INTERNAL') || die();

// --- Plugin identity ---
$string['pluginname']        = 'Excel Formula';
$string['pluginnamesummary'] = 'Students enter an Excel formula. It is evaluated against provided cell data and compared to the expected result.';
$string['pluginnameadding']  = 'Adding an Excel Formula question';
$string['pluginnameediting'] = 'Editing an Excel Formula question';

// --- Question editing form labels (what the teacher sees) ---
$string['correctformula']        = 'Correct formula';
$string['correctformula_help']   = 'Enter the correct Excel formula, e.g. =SUM(A1:A3) or =A1*B1+C1. The leading = is optional.';

$string['celldata']              = 'Cell data';
$string['celldata_help']         = 'Define the values of the spreadsheet cells the student will work with. Use JSON format, for example: {"A1": 10, "B1": 20, "C1": 30, "A2": 5, "B2": 15}';

$string['expectedresult']        = 'Expected result';
$string['expectedresult_help']   = 'The numeric answer your correct formula should produce when evaluated against the cell data above. Example: if cells A1=10, A2=20, A3=30 and formula is =SUM(A1:A3), the expected result is 60.';

//$string['tolerance']             = 'Tolerance (±)';
//$string['tolerance_help']        = 'How close the student\'s result needs to be to the expected result. Use 0 for exact match. Use 0.01 to allow rounding differences. Use 0.5 to accept results within half a unit.';

$string['gradeformula']          = 'Grading approach';
$string['gradeformula_help']     = 'Choose whether to grade based on the numeric result of the formula, or require the exact formula text.';

// --- Student-facing labels ---
$string['answertheformula']      = 'Enter your Excel formula:';
$string['formulaplaceholder']    = 'e.g. =SUM(A1:A3)';
$string['celldataheading']       = 'Cell data for this question:';
$string['youranswer']            = 'Your formula';

// --- Feedback messages ---
$string['correctansweris']       = 'The correct formula is: {$a}';
$string['yourformulaproduced']   = 'Your formula produced the result: {$a}';
$string['formulaerror']          = 'Your formula could not be evaluated. Please check the syntax.';
$string['formulaerror_detail']   = 'Error evaluating formula "{$a->formula}": {$a->error}';

// --- Validation errors ---
$string['pleaseenteraformula']   = 'Please enter a formula.';
$string['invalidcelldatajson']   = 'Cell data must be valid JSON format. Example: {"A1": 10, "B1": 20}';
$string['expectedresultrequired']= 'Please enter the expected numeric result.';
$string['correctformularequired']= 'Please enter the correct formula.';

// --- Privacy (required by Moodle) ---
$string['privacy:metadata'] = 'The Excel Formula question type plugin does not store any personal data beyond what the Moodle quiz engine itself stores.';

// Created by Yolani Tutani 
$string['celldatafile']           = 'Cell data spreadsheet';
$string['celldatafile_help']      = 'Upload an Excel file (.xls or .xlsx) containing the cell values the student will see. The first sheet is used. Each row should have a cell reference in column A and its value in column B.';
$string['celldatafilerequired']   = 'Please upload a cell data spreadsheet.';

$string['answerkeyfile']          = 'Answer key spreadsheet';
$string['answerkeyfile_help']     = 'Upload an Excel file (.xls or .xlsx) containing the correct formula(s) and answer cases. Expected columns: CellRef | FormulaWeight | AnswerWeight | MarkAllocation | Rules | Feedback | CaseMark | CaseType | CaseWeight | AnswerText | CaseFeedback';
$string['answerkeyfilerequired']  = 'Please upload an answer key spreadsheet.';

// --- Question editing form: section headers and file pickers ---
$string['questionfilehdr']        = 'Question spreadsheet';
$string['questionfile']           = 'Question data file';
$string['questionfile_help']      = 'Upload an Excel file (.xls or .xlsx) containing the cell values the student will see. The first sheet is used. Each row should have a cell reference in column A and its value in column B.';

$string['answerkeyfilehdr']       = 'Answer key file';

// --- Answer cases section (repeated rows in the edit form) ---
$string['answercaseshdr']         = 'Answer cases';
$string['case_cell']              = 'Cell reference';
$string['case_credittype']        = 'Credit type';
$string['case_marks']             = 'Marks';
$string['case_formulas']          = 'Accepted formulas';
$string['case_feedback']          = 'Feedback for this case';
$string['addanswercase']          = 'Add another answer case';

$string['credittype_full']        = 'Full credit';
$string['credittype_partial']     = 'Partial credit';
$string['credittype_hardcoded']   = 'Hardcoded value';

// --- Answer case validation errors ---
// NEW
$string['casecellrequired']       = 'Please enter a cell reference for this answer case.';
$string['casesheetnamerequired']  = 'Please enter the worksheet name for this answer case.';
$string['caseformulasrequired']   = 'Please enter at least one accepted formula for this answer case.';
$string['casemarksinvalid']       = 'Marks must be a number of 0 or greater.';

// --- Answer cases accordion (JS UI strings) ---
$string['addcell']                 = 'Add graded cell';
$string['removecell']              = 'Remove cell';
$string['removecase']              = 'Remove';
$string['cell_totalmarks']         = 'Total marks';
$string['cellsummary']             = 'answer cases';
$string['answernumber']            = 'Answer';
$string['case_formulas_placeholder'] = 'e.g. =SUM(B2:B4), =B2+B3+B4';
$string['newcellplaceholder']      = 'e.g. B6';
$string['sheetname']               = 'Worksheet';
$string['sheetnameplaceholder']    = 'e.g. Sheet1';

// --- Validation errors (used in edit_excelformula_form.php::validation) ---
$string['atleastonecellrequired']  = 'Please add at least one graded cell.';
$string['atleastonecaserequired']  = 'Each cell must have at least one answer case.';
$string['errorparsespreadsheet'] = 'Could not parse the uploaded spreadsheet: {$a}';

// --- Answer key detection (AJAX: qtype_excelformula_detect_answercases) ---
$string['detectanswercases']      = 'Detect answer cases';
$string['detectinganswercases']   = 'Detecting answer cases…';
$string['detectedanswercount']    = 'Detected {cellcount} cell(s) with {casecount} answer case(s).';
$string['detecterror']            = 'Could not detect answer cases from the uploaded answer key. Please check the file and try again.';
$string['detecterror_nofile']     = 'Please upload an answer key file before detecting answer cases.';
// --- Per-cell answer UI (renderer.php: render_cell_answers()/
//     render_cell_answer(); amd/src/cell_answer.js) ---
$string['cellanswerlabel']       = 'Cell {$a}';
$string['checkcell']             = 'Check';
$string['checkingcell']          = 'Checking…';
$string['markawarded']           = 'Mark: {$a->mark} / {$a->maxmark}';
$string['markawardedtemplate']   = 'Mark: {mark} / {maxmark}';
