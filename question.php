<?php
// ============================================================
// question.php
// This file defines how the question WORKS:
//   - What data it stores
//   - How it checks if a student's answer is correct
//   - The actual Excel formula evaluator
//
// For a beginner: think of this as the "brain" of the question.
// ============================================================

defined('MOODLE_INTERNAL') || die();


/**
 * The main question class.
 * "question_graded_automatically" means Moodle will grade it
 * without a teacher needing to manually mark each response.
 */
class qtype_excelformula_question extends question_graded_automatically {

    // --------------------------------------------------------
    // These properties are loaded from the database when
    // a student opens the quiz. They match the columns in
    // the db/install.xml table you defined.
    // --------------------------------------------------------
   public $correctformula;  // Legacy single-formula field; kept only for export_to_xml()/import_from_xml().
    public $celldata;        // Structured grid JSON from spreadsheet_parser.php.
    public $answers = [];    // Graded cells + their answer cases, populated by questiontype.php::initialise_question_instance()

    /** @var \PhpOffice\PhpSpreadsheet\Spreadsheet|null Cached workbook reused across evaluate_formula() calls within one grading pass, to avoid rebuilding the same cell values on every call. */
    private $evaluationworkbook = null;

    /** @var string|null Hash of the $cells array currently loaded into $evaluationworkbook, used to detect when the cache must be rebuilt for a different worksheet's values. */
    private $evaluationcellskey = null;
    
    

    const VALUE_COMPARISON_EPSILON = 0.0001;//fixed epsilon used only to absorb floating-point rounding when comparing a student's result to an accepted value. The plugin does not track a per-cell tolerance, so this is not teacher-configurable.
    const MAX_ATTEMPTS_PER_CELL = 3; // Maximum number of attempts allowed per graded cell before it is locked.


    // --------------------------------------------------------
    // Tell Moodle what form fields the student fills in.
    // "answer" is the name of the text box where students
    // type their formula.
    // --------------------------------------------------------
    public function get_expected_data() {
        // One field per graded cell: 'answer_' . ansid. Keep this key
        // format in sync with grade_cell.php (submitteddata) and
        // renderer.php (input names) -- all three must agree.
        $expected = [];
        foreach ($this->answers as $answer) {
            $expected['answer_' . $answer->ansid] = PARAM_RAW_TRIMMED;
            $expected['attempts_' . $answer->ansid]  = PARAM_INT;
            $expected['bestmark_' . $answer->ansid]  = PARAM_FLOAT;
        }
        return $expected;
    }


    // --------------------------------------------------------
    // Show the student's formula in reports/review screens.
    // --------------------------------------------------------
    public function summarise_response(array $response) {
        $parts = [];
        foreach ($this->answers as $answer) {
            $key = 'answer_' . $answer->ansid;
            if (!empty($response[$key])) {
                $parts[] = $answer->cellref . ': ' . $response[$key];
            }
        }
        return $parts ? implode('; ', $parts) : null;
    }


    // --------------------------------------------------------
    // Is the student's response "complete enough" to be graded?
    // Returns true if they typed something (even if wrong).
    // --------------------------------------------------------
    public function is_complete_response(array $response) {
        if (empty($this->answers)) {
            return false;
        }
        foreach ($this->answers as $answer) {
            $key = 'answer_' . $answer->ansid;
            if (!array_key_exists($key, $response) || $response[$key] === '') {
                return false;
            }
        }
        return true;
    }


    // --------------------------------------------------------
    // What message to show if the student tries to submit
    // without entering anything.
    // --------------------------------------------------------
    public function get_validation_error(array $response) {
        if (!$this->is_complete_response($response)) {
            return get_string('pleaseenteraformula', 'qtype_excelformula');
        }
        return '';
    }
/**
 * Number of formula submissions already recorded for one graded cell,
 * read from the attempt's own qt var state (written by grade_cell.php),
 * so retry counting survives reloads and stays authoritative on the
 * server, not just in cell_answer.js.
 *
 * @param question_attempt $qa
 * @param int $ansid
 * @return int
 */
public function get_cell_attempt_count(question_attempt $qa, int $ansid): int {
    return (int) $qa->get_last_qt_var('attempts_' . $ansid, 0);
}

/**
 * Highest mark achieved so far across a cell's attempts. Compared
 * against a newly-computed mark by grade_cell.php so only the best
 * of the (at most MAX_ATTEMPTS_PER_CELL) attempts is ever kept.
 *
 * @param question_attempt $qa
 * @param int $ansid
 * @return float
 */
public function get_cell_best_mark(question_attempt $qa, int $ansid): float {
    return (float) $qa->get_last_qt_var('bestmark_' . $ansid, 0);
}

    // --------------------------------------------------------
    // Has the student changed their answer since last time?
    // Used to avoid unnecessary database saves.
    // --------------------------------------------------------
       public function is_same_response(array $prevresponse, array $newresponse) {
        foreach ($this->answers as $answer) {
            $key = 'answer_' . $answer->ansid;
            if (!question_utils::arrays_same_at_key_missing_is_blank(
                    $prevresponse, $newresponse, $key)) {
                return false;
            }

            // attempts_/bestmark_ change on every retry even while
            // answer_ itself is withheld (grade_cell.php only writes
            // answer_ once the cell locks). Without checking these too,
            // the behaviour engine treats consecutive retries as "no
            // change", drops the pending step, and the attempt count
            // never actually persists.
            $attemptskey = 'attempts_' . $answer->ansid;
            if (!question_utils::arrays_same_at_key_missing_is_blank(
                    $prevresponse, $newresponse, $attemptskey)) {
                return false;
            }

            $bestmarkkey = 'bestmark_' . $answer->ansid;
            if (!question_utils::arrays_same_at_key_missing_is_blank(
                    $prevresponse, $newresponse, $bestmarkkey)) {
                return false;
            }
        }
        return true;
    }


       // Returns one correct formula per graded cell, keyed to match
    // get_expected_data()/renderer.php's 'answer_' . ansid field names,
    // so each cell can show its own correct answer instead of one
    // formula for the whole question.
    public function get_correct_response() {
        $correct = [];
        foreach ($this->answers as $answer) {
            $correct['answer_' . $answer->ansid] = $this->get_correct_formula_for_answer($answer);
        }
        return $correct;
    }

    // Picks the accepted formula to show as "the correct answer" for
    // one graded cell: the first formula on the first "full credit"
    // case, falling back to the first case of any type if no full
    // credit case exists.
    private function get_correct_formula_for_answer($answer): string {
        foreach ($answer->answercases as $case) {
            if (($case->casetype ?? 'full') === 'full') {
                $formulas = $this->split_accepted_formulas($case->answertext);
                if (!empty($formulas[0])) {
                    return $formulas[0];
                }
            }
        }
        if (!empty($answer->answercases)) {
            $case     = reset($answer->answercases);
            $formulas = $this->split_accepted_formulas($case->answertext);
            return $formulas[0] ?? '';
        }
        return '';
    }

    // --------------------------------------------------------
    // THE MAIN GRADING FUNCTION
    // This is called when Moodle needs to grade the student's
    // answer. It returns:
    //   - A score between 0 (wrong) and 1 (correct)
    //   - A state (gradedright, gradedwrong, gradedpartial)
    // --------------------------------------------------------
   public function grade_response(array $response) {
   // NEW
    // Cells are now grouped per worksheet, since the same cellref
    // (e.g. B3) can exist on more than one sheet. Build a lookup
    // keyed by sheetname => [cellref => value].
    $cellsbysheet = [];
    if (!empty($this->celldata)) {
        $decoded = json_decode($this->celldata, true);
        if (is_array($decoded)) {
            if (isset($decoded['sheets']) && is_array($decoded['sheets'])) {
                foreach ($decoded['sheets'] as $sheetname => $sheetdata) {
                    $cells = [];
                    foreach ($sheetdata['cells'] ?? [] as $ref => $celldata) {
                        $cells[strtoupper(trim($ref))] = floatval($celldata['raw'] ?? 0);
                    }
                    $cellsbysheet[$sheetname] = $cells;
                }
            } else if (isset($decoded['cells']) && is_array($decoded['cells'])) {
                // Legacy single-sheet grid format.
                $cells = [];
                foreach ($decoded['cells'] as $ref => $celldata) {
                    $cells[strtoupper(trim($ref))] = floatval($celldata['raw'] ?? 0);
                }
                $cellsbysheet[''] = $cells;
            } else {
                // Legacy flat {"A1": 10} format.
                $cells = [];
                foreach ($decoded as $key => $val) {
                    $cells[strtoupper(trim($key))] = floatval($val);
                }
                $cellsbysheet[''] = $cells;
            }
        }
    }

    if (empty($this->answers)) {
        return [0, question_state::$gradedwrong];
    }

    $totalmarks  = 0.0;
    $earnedmarks = 0.0;

        foreach ($this->answers as $answer) {
        $cellmarks   = (float) $answer->markallocation;
        $totalmarks += $cellmarks;

        // Prefer the running best mark recorded across attempts
        // (bestmark_{ansid}, kept by grade_cell.php) so a strong early
        // attempt isn't lost to a weaker final one. Falls back to a
        // fresh compute only if no bestmark_ was ever recorded.
        if (isset($response['bestmark_' . $answer->ansid]) && $response['bestmark_' . $answer->ansid] !== '') {
            $earnedmarks += (float) $response['bestmark_' . $answer->ansid];
        } else {
            $cellformula = trim($response['answer_' . $answer->ansid] ?? '');
            $sheetcells  = $cellsbysheet[$answer->sheetname ?? ''] ?? [];
            $result       = $this->compute_cell_result($answer, $cellformula, $sheetcells);
            $earnedmarks += $result['mark'];
        }
    }

    if ($totalmarks <= 0) {
        return [0, question_state::$gradedwrong];
    }

    $fraction = min(1.0, max(0.0, $earnedmarks / $totalmarks));

    if ($fraction >= 1.0) {
        return [1, question_state::$gradedright];
    } else if ($fraction > 0.0) {
        return [$fraction, question_state::$gradedpartial];
    }
    return [0, question_state::$gradedwrong];
}
// ----------------------------------------------------------
// best_matching_case()
//   Finds the highest-mark case (for one graded cell) that
//   the student's formula/result satisfies. "hardcoded" cases
//   match on value only; "full"/"partial" cases match on
//   formula text, or on the evaluated value as a fallback.
// ----------------------------------------------------------
private function best_matching_case(array $cases, string $studentformula, ?float $studentresult, array $cells) {
    $best = null;

    foreach ($cases as $case) {
        $accepted   = $this->split_accepted_formulas($case->answertext);
        $credittype = $case->casetype ?? 'full';

        foreach ($accepted as $acceptedtext) {
            if ($acceptedtext === '') {
                continue;
            }

           if ($credittype === 'hardcoded') {
                $ismatch = $this->values_match($studentresult, $acceptedtext);
           } else {
                $ismatch = $this->formulas_match($studentformula, $acceptedtext);
            }

            if ($ismatch && ($best === null || $case->casemark > $best->casemark)) {
                $best = $case;
            }
        }
    }

    return $best;
}

// ----------------------------------------------------------
// compute_cell_result()
//   Single source of truth for grading ONE cell against ONE
//   submitted formula. Used by grade_response() (whole-question,
//   on final submit) and grade_single_cell() (mid-attempt, called
//   from classes/external/grade_cell.php) so the two never diverge.
//
// @param stdClass   $answer      Row from qtype_excelformula_answers, with ->answercases attached.
// @param string     $formula     Student's submitted formula for this cell.
// @param array      $sheetcells  Cell ref => value, for this answer's own worksheet.
// @return array{mark: float, maxmark: float, feedback: string, state: question_state}
// ----------------------------------------------------------
private function compute_cell_result($answer, string $formula, array $sheetcells): array {
    $maxmark = (float) $answer->markallocation;
    $result  = $this->evaluate_formula($formula, $sheetcells);
    $best    = $this->best_matching_case($answer->answercases, $formula, $result, $sheetcells);

    $mark     = $best !== null ? (float) $best->casemark : 0.0;
    $feedback = $best !== null 
    ? (string) ($best->casefeedback ?? '') 
    : (string) ($answer->defaultfeedback ?? '');

    if ($mark >= $maxmark && $maxmark > 0) {
        $state = question_state::$gradedright;
    } else if ($mark > 0) {
        $state = question_state::$gradedpartial;
    } else {
        $state = question_state::$gradedwrong;
    }

    return ['mark' => $mark, 'maxmark' => $maxmark, 'feedback' => $feedback, 'state' => $state];
}

// ----------------------------------------------------------
// grade_single_cell()
//   Public entry point for mid-attempt per-cell grading, called
//   from classes/external/grade_cell.php. Looks up the answer row
//   by ansid, resolves its own worksheet's cells, and delegates to
//   compute_cell_result(). Read-only with respect to $this->answers;
//   persistence of the resulting response is the caller's job
//   (recorded as a question_attempt step, not stored here).
//
// @param int    $ansid   qtype_excelformula_answers.ansid to grade.
// @param string $formula Student's submitted formula for this cell.
// @return array Same shape as compute_cell_result(), with 'state' as a string for external API transport.
// ----------------------------------------------------------
public function grade_single_cell(int $ansid, string $formula): array {
    $cellsbysheet = $this->get_cells_by_sheet();

    foreach ($this->answers as $answer) {
        if ((int) $answer->ansid !== $ansid) {
            continue;
        }
        $sheetcells = $cellsbysheet[$answer->sheetname ?? ''] ?? [];
        $result     = $this->compute_cell_result($answer, trim($formula), $sheetcells);
        $result['state'] = $result['state']->__toString();
        return $result;
    }

    return ['mark' => 0.0, 'maxmark' => 0.0, 'feedback' => '', 'state' => question_state::$gradedwrong->__toString()];
}

// ----------------------------------------------------------
// get_graded_ansids() / get_next_ungraded_ansid()
//   Server-side sequencing state, read from the question_attempt's
//   own recorded step data (NOT a separate plugin table) so that
//   "answer cells in order" is enforced by the question engine's
//   authoritative state, not just the client-side UI lock in
//   amd/src/cell_answer.js. A cell counts as graded once a non-empty
//   'answer_{ansid}' value has been recorded against the attempt --
//   grade_cell.php only records that step after grading succeeds.
//
// @param question_attempt $qa
// @return int[] ansids already answered+graded, in $this->answers order.
// ----------------------------------------------------------
public function get_graded_ansids(question_attempt $qa): array {
    $graded = [];
    foreach ($this->answers as $answer) {
        $value = $qa->get_last_qt_var('answer_' . $answer->ansid, '');
        if ($value !== '') {
            $graded[] = (int) $answer->ansid;
        }
    }
    return $graded;
}

/**
 * @param question_attempt $qa
 * @return int|null ansid of the next cell the student is allowed to answer, or null if all are done.
 */
public function get_next_ungraded_ansid(question_attempt $qa): ?int {
    $graded = $this->get_graded_ansids($qa);
    foreach ($this->answers as $answer) {
        if (!in_array((int) $answer->ansid, $graded, true)) {
            return (int) $answer->ansid;
        }
    }
    return null;
}

/**
 * Shared cellsbysheet decoding, extracted from grade_response() so
 * grade_single_cell() (mid-attempt) can build the same lookup
 * without duplicating the sheet-format-detection logic.
 *
 * @return array sheetname => [cellref => float value]
 */
private function get_cells_by_sheet(): array {
    $cellsbysheet = [];
    if (empty($this->celldata)) {
        return $cellsbysheet;
    }
    $decoded = json_decode($this->celldata, true);
    if (!is_array($decoded)) {
        return $cellsbysheet;
    }
    if (isset($decoded['sheets']) && is_array($decoded['sheets'])) {
        foreach ($decoded['sheets'] as $sheetname => $sheetdata) {
            $cells = [];
            foreach ($sheetdata['cells'] ?? [] as $ref => $celldata) {
                $cells[strtoupper(trim($ref))] = floatval($celldata['raw'] ?? 0);
            }
            $cellsbysheet[$sheetname] = $cells;
        }
    } else if (isset($decoded['cells']) && is_array($decoded['cells'])) {
        $cells = [];
        foreach ($decoded['cells'] as $ref => $celldata) {
            $cells[strtoupper(trim($ref))] = floatval($celldata['raw'] ?? 0);
        }
        $cellsbysheet[''] = $cells;
    } else {
        $cells = [];
        foreach ($decoded as $key => $val) {
            $cells[strtoupper(trim($key))] = floatval($val);
        }
        $cellsbysheet[''] = $cells;
    }
    return $cellsbysheet;
}

// ----------------------------------------------------------
// formulas_match() / values_match()
//   Case/whitespace-insensitive formula comparison, and a
//   numeric comparison within the cell's tolerance.
// ----------------------------------------------------------
private function formulas_match(string $a, string $b): bool {
        if ($this->normalise_formula($a) === $this->normalise_formula($b)) {
            return true;
        }
        return $this->canonicalise_formula($a) === $this->canonicalise_formula($b);
    }

/**
     * Strips '=', case, and whitespace only -- the fast-path exact
     * comparison formulas_match() tries first.
     */
    private function normalise_formula(string $formula): string {
        $formula = trim($formula);
        if ($formula !== '' && $formula[0] === '=') {
            $formula = substr($formula, 1);
        }
        return strtolower(preg_replace('/\s+/', '', $formula));
    }

    /**
     * Builds a canonical form of a formula so operand order stops
     * mattering for purely commutative +/* groupings (e.g. "D9*$B$6"
     * and "$B$6*D9" canonicalise identically), while '-' and '/'
     * remain order-significant (not commutative) and '$' absolute/
     * relative markers remain untouched -- so different $ referencing
     * styles still count as genuinely different formulas, letting a
     * teacher grade them as separate full/partial cases.
     */
    private function canonicalise_formula(string $formula): string {
        return $this->canonicalise_additive($this->normalise_formula($formula));
    }

    private function canonicalise_additive(string $expr): string {
        $expr = trim($expr);

        if (strlen($expr) > 1 && $expr[0] === '(' && $expr[strlen($expr) - 1] === ')'
                && $this->matching_close_paren($expr, 0) === strlen($expr) - 1) {
            return '(' . $this->canonicalise_additive(substr($expr, 1, strlen($expr) - 2)) . ')';
        }

        $tokens = $this->split_by_operators($expr, ['+', '-']);
        if (count($tokens) === 1) {
            return $this->canonicalise_multiplicative($expr);
        }

        $pureadd = true;
        foreach ($tokens as $i => $t) {
            if ($i > 0 && $t['op'] !== '+') {
                $pureadd = false;
                break;
            }
        }

        if ($pureadd) {
            $parts = array_map(function($t) {
                return $this->canonicalise_multiplicative($t['value']);
            }, $tokens);
            sort($parts);
            return implode('+', $parts);
        }

        $rebuilt = $this->canonicalise_multiplicative($tokens[0]['value']);
        for ($i = 1; $i < count($tokens); $i++) {
            $rebuilt .= $tokens[$i]['op'] . $this->canonicalise_multiplicative($tokens[$i]['value']);
        }
        return $rebuilt;
    }

    private function canonicalise_multiplicative(string $expr): string {
        $expr = trim($expr);

        $tokens = $this->split_by_operators($expr, ['*', '/']);
        if (count($tokens) > 1) {
            $puremul = true;
            foreach ($tokens as $i => $t) {
                if ($i > 0 && $t['op'] !== '*') {
                    $puremul = false;
                    break;
                }
            }

            if ($puremul) {
                $parts = array_map(function($t) {
                    return $this->canonicalise_additive($t['value']);
                }, $tokens);
                sort($parts);
                return implode('*', $parts);
            }

            $rebuilt = $this->canonicalise_additive($tokens[0]['value']);
            for ($i = 1; $i < count($tokens); $i++) {
                $rebuilt .= $tokens[$i]['op'] . $this->canonicalise_additive($tokens[$i]['value']);
            }
            return $rebuilt;
        }

        // Function calls: arguments are positional, not commutative --
        // canonicalise each argument's own contents but keep their order.
        if (preg_match('/^([a-z_][a-z0-9_]*)\((.*)\)$/s', $expr, $m)) {
            $args = $this->split_function_args($m[2]);
            $canonargs = array_map([$this, 'canonicalise_additive'], $args);
            return $m[1] . '(' . implode(',', $canonargs) . ')';
        }

        return $expr;
    }
/**
     * For 'full'/'partial' cases where the accepted text is a formula
     * (not a bare number), evaluate it against the same cell data and
     * compare its computed result to the student's result. Lets a
     * differently-written but equivalent formula still earn credit.
     */
    private function accepted_formula_value_matches(string $acceptedtext, ?float $studentresult, array $cells): bool {
        if ($studentresult === null) {
            return false;
        }
        $acceptedresult = $this->evaluate_formula($acceptedtext, $cells);
        return $acceptedresult !== null
            && abs($studentresult - $acceptedresult) <= self::VALUE_COMPARISON_EPSILON;
    }

    /**
     * Re-runs the same case-matching used in grade_response(), returning
     * the matched case per graded cell so the renderer can show its
     * case-specific feedback. Read-only — does not affect the stored grade.
     *
     * @param string     $studentformula The formula the student submitted.
     * @param array      $cells          Cell ref => numeric value.
     * @param float|null $studentresult  Pass the already-evaluated result to avoid re-computing it.
     * @return array Keyed by ansid; value is the matched case object or null.
     */
   // NEW (question.php)
     public function get_matched_cases(string $studentformula, array $cellsbysheet, ?float $studentresult = null): array {
        $matches = [];
        foreach ($this->answers as $ansid => $answer) {
            // Cell references are only unique per-sheet, so resolve this
            // answer's cells using its own sheetname before matching.
            $sheetcells = $cellsbysheet[$answer->sheetname ?? ''] ?? [];
            $result     = $studentresult ?? $this->evaluate_formula($studentformula, $sheetcells);
            $matches[$ansid] = $this->best_matching_case(
                $answer->answercases, $studentformula, $result, $sheetcells
            );
        }
        return $matches;
    }
private function values_match(?float $studentresult, string $acceptedtext): bool {
    if ($studentresult === null) {
        return false;
    }

    $acceptedtext = trim($acceptedtext);
    if ($acceptedtext !== '' && $acceptedtext[0] === '=') {
        $acceptedtext = substr($acceptedtext, 1);
    }

    if (!is_numeric($acceptedtext)) {
        return false;
    }

    return abs($studentresult - floatval($acceptedtext)) <= self::VALUE_COMPARISON_EPSILON;
}
    /**
     * Convert a column letter to a number.
     * A=1, B=2, Z=26, AA=27, AB=28, etc.
     */
    private function col_to_num($col) {
        $col = strtoupper($col);
        $num = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $num = $num * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $num;
    }


    /**
     * Convert a column number back to letters.
     * 1=A, 2=B, 26=Z, 27=AA, etc.
     */
    private function num_to_col($num) {
        $result = '';
        while ($num > 0) {
            $num--;
            $result = chr(ord('A') + ($num % 26)) . $result;
            $num    = intval($num / 26);
        }
        return $result;
    }
// ============================================================
    // EXCEL FORMULA EVALUATOR
    // ============================================================
    // This section handles parsing and evaluating Excel-style
    // formulas. It supports:
    //   - Basic arithmetic:    A1+B1, A1*2, (A1+B1)/C1
    //   - Cell references:     A1, B2, AA10 (with optional $ absolute/
    //                           relative markers: A1, $A1, A$1, $A$1)
    //   - Ranges:              A1:A5 (single-column or single-row)
    //   - Functions: SUM, AVERAGE, MIN, MAX, COUNT,
    //                IF, ROUND, ABS, SQRT, POWER, INT, MOD
    //
    // To add a new function: add a new 'case' in the switch
    // statement inside parse_function_call().
    // ============================================================

    /**
     * Evaluate a formula string against a set of cell values.
     *
     * Delegates parsing and calculation to PhpSpreadsheet's Calculation
     * engine rather than a hand-rolled parser, so parenthesis matching,
     * nested function calls, and the wider Excel function set are all
     * handled by the same library already used to parse question/answer
     * spreadsheets (see classes/spreadsheet_parser.php). The supplied
     * $cells are written into a throwaway in-memory worksheet purely so
     * that cell references in the formula resolve against them; nothing
     * here is persisted.
     *
     * @param  string $formula The formula string, with or without a leading '='.
     * @param  array  $cells   Cell ref (e.g. 'A1') => numeric value, for this worksheet only.
     * @return float|null      The calculated numeric result, or null if the
     *                         formula is empty, malformed, or does not
     *                         resolve to a number.
     */
   public function evaluate_formula($formula, $cells) {
        global $CFG;

        $formula = trim($formula);
        if ($formula === '') {
            return null;
        }
        if ($formula[0] !== '=') {
            $formula = '=' . $formula;
        }

        require_once($CFG->dirroot . '/lib/phpspreadsheet/vendor/autoload.php');

        try {
            $sheet = $this->get_evaluation_sheet($cells);

            // Scratch cell reference, chosen well outside any plausible
            // graded-cell range so it can never collide with $cells.
            $scratchref = 'ZZ9999';
            $sheet->setCellValue($scratchref, $formula);
            $result = $sheet->getCell($scratchref)->getCalculatedValue();

            return is_numeric($result) ? floatval($result) : null;

        } catch (\Throwable $e) {
            // \Throwable (not just \Exception) so a malformed formula
            // always degrades to "could not be evaluated" instead of a
            // raw PHP fatal/Error escaping to the student.
            return null;
        }
    }

    /**
     * Returns a worksheet pre-loaded with the given cell values, reusing
     * the previous call's workbook when $cells is unchanged so repeated
     * evaluate_formula() calls against the same worksheet (e.g. testing
     * several accepted-answer texts for one graded cell in
     * best_matching_case()) don't rebuild the whole cell collection each
     * time. Rebuilds from scratch whenever $cells differs from what is
     * currently loaded.
     *
     * @param  array $cells Cell ref => numeric value.
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    private function get_evaluation_sheet(array $cells): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet {
        $key = md5(serialize($cells));

        if ($this->evaluationworkbook !== null && $this->evaluationcellskey === $key) {
            return $this->evaluationworkbook->getActiveSheet();
        }

        if ($this->evaluationworkbook !== null) {
            // Release the previous worksheet's cell collection before
            // discarding it; PhpSpreadsheet objects are not garbage
            // collected automatically.
            $this->evaluationworkbook->disconnectWorksheets();
        }

        $this->evaluationworkbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $this->evaluationworkbook->getActiveSheet();

        foreach ($cells as $ref => $value) {
            $sheet->setCellValue($ref, $value);
        }

        $this->evaluationcellskey = $key;

        return $sheet;
    }

    /**
     * Releases the cached evaluation workbook's cell collection, if one
     * was built, so PhpSpreadsheet's internal cell cache does not linger
     * beyond this question instance's lifetime.
     */
    public function __destruct() {
        if ($this->evaluationworkbook !== null) {
            $this->evaluationworkbook->disconnectWorksheets();
        }
    }

    /**
     * Parse and evaluate a single expression. Recursive — calls
     * itself for sub-expressions (e.g. each function argument).
     */
    private function parse_expr($expr, $cells) {
        $expr = trim($expr);

        if ($this->is_leaf_token($expr)) {
            return $this->parse_leaf($expr, $cells);
        }

        return $this->parse_additive($expr, $cells);
    }

    /**
     * True if $expr is one of the three atomic forms this evaluator
     * resolves directly: a number, a single cell reference (optionally
     * with $ absolute/relative markers), or a function call. Used to
     * decide whether to hand $expr to parse_leaf() or keep splitting it
     * via parse_additive().
     */
    private function is_leaf_token($expr) {
        return is_numeric($expr)
            || preg_match('/^\$?[A-Za-z]+\$?\d+$/', $expr)
            || preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*\(.*\)$/s', $expr);
    }

    /**
     * Resolve an already-atomic token (see is_leaf_token()) to its
     * numeric value.
     *
     * Called from two places: parse_expr(), for the top-level expression
     * or a parenthesised sub-expression once it has been reduced to a
     * single leaf; and parse_unary(), for an operator-free token handed
     * down through parse_additive() -> parse_multiplicative() ->
     * parse_power() -> parse_unary(). Deliberately does NOT call
     * parse_expr()/parse_additive() again -- looping a string that has
     * already been through that whole chain back into the start of the
     * same chain is what previously caused unbounded recursion (and a
     * PHP function-nesting-level fatal) on malformed input such as a
     * stray operand or a mistyped cell reference. Any token that fails
     * all three checks here is genuinely invalid, not just "not reduced
     * far enough yet", so it throws rather than recursing further.
     */
    private function parse_leaf($expr, $cells) {
        if (is_numeric($expr)) {
            return floatval($expr);
        }

        // Accept Excel-style absolute/relative reference markers -- $A$2,
        // $A2, A$2, and A2 all resolve to the same cell. The $ has no
        // effect on evaluation here (there is no drag-fill/copy step to
        // anchor against), so it is simply stripped before lookup.
        if (preg_match('/^\$?[A-Za-z]+\$?\d+$/', $expr)) {
            $ref = strtoupper(str_replace('$', '', $expr));
            return isset($cells[$ref]) ? floatval($cells[$ref]) : 0.0;
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s', $expr, $m)) {
            return $this->parse_function_call(strtoupper($m[1]), $m[2], $cells);
        }

        throw new \Exception('Unrecognised formula token: ' . $expr);
    }

    /**
     * Handle addition and subtraction (lowest precedence).
     */
    private function parse_additive($expr, $cells) {
        $tokens = $this->split_by_operators($expr, ['+', '-']);

        if (count($tokens) === 1) {
            return $this->parse_multiplicative($expr, $cells);
        }

        $result = $this->parse_multiplicative($tokens[0]['value'], $cells);
        for ($i = 1; $i < count($tokens); $i++) {
            $val = $this->parse_multiplicative($tokens[$i]['value'], $cells);
            if ($tokens[$i]['op'] === '+') {
                $result += $val;
            } else {
                $result -= $val;
            }
        }
        return $result;
    }

    /**
     * Handle multiplication and division.
     */
    private function parse_multiplicative($expr, $cells) {
        $tokens = $this->split_by_operators($expr, ['*', '/']);

        if (count($tokens) === 1) {
            return $this->parse_power($expr, $cells);
        }

        $result = $this->parse_power($tokens[0]['value'], $cells);
        for ($i = 1; $i < count($tokens); $i++) {
            $val = $this->parse_power($tokens[$i]['value'], $cells);
            if ($tokens[$i]['op'] === '*') {
                $result *= $val;
            } else {
                $result = ($val != 0) ? $result / $val : 0;
            }
        }
        return $result;
    }

    /**
     * Handle exponentiation (^).
     */
    private function parse_power($expr, $cells) {
        $tokens = $this->split_by_operators($expr, ['^']);

        if (count($tokens) === 1) {
            return $this->parse_unary($expr, $cells);
        }

        $result = $this->parse_unary($tokens[0]['value'], $cells);
        for ($i = 1; $i < count($tokens); $i++) {
            $val = $this->parse_unary($tokens[$i]['value'], $cells);
            $result = pow($result, $val);
        }
        return $result;
    }

    /**
     * Handle unary minus and parentheses.
     */
    private function parse_unary($expr, $cells) {
        $expr = trim($expr);

        if (strlen($expr) > 0 && $expr[0] === '-') {
            return -1 * $this->parse_unary(substr($expr, 1), $cells);
        }

        if (strlen($expr) > 1 && $expr[0] === '(' && $expr[strlen($expr)-1] === ')') {
            if ($this->matching_close_paren($expr, 0) === strlen($expr) - 1) {
                return $this->parse_expr(substr($expr, 1, strlen($expr)-2), $cells);
            }
        }

        // Anything still unreduced at this point (no top-level +, -, *,
        // /, or ^; no leading unary minus; not a fully-parenthesised
        // sub-expression) is either an ordinary leaf token -- a number,
        // cell reference, or function call -- being classified for the
        // first time (split_by_operators() peels these off without ever
        // running them past parse_expr()'s own checks), or it is
        // genuinely malformed input. parse_leaf() decides which and
        // throws in the latter case; it deliberately does NOT call back
        // into parse_expr()/parse_additive(), since re-entering that
        // chain with an unchanged string is what previously caused
        // unbounded recursion (and a PHP function-nesting-level fatal)
        // on malformed input such as a stray operand or mistyped cell
        // reference.
        return $this->parse_leaf($expr, $cells);
    }

    /**
     * Evaluate a function call like SUM(A1:A3) or IF(A1>5,A1,0).
     */
    private function parse_function_call($funcname, $args_str, $cells) {
        $args = $this->split_function_args($args_str);

        switch ($funcname) {

            case 'SUM':
                $total = 0.0;
                foreach ($args as $arg) {
                    foreach ($this->resolve_arg($arg, $cells) as $v) {
                        $total += $v;
                    }
                }
                return $total;

            case 'AVERAGE':
                $values = [];
                foreach ($args as $arg) {
                    foreach ($this->resolve_arg($arg, $cells) as $v) {
                        $values[] = $v;
                    }
                }
                return count($values) > 0 ? array_sum($values) / count($values) : 0.0;

            case 'MIN':
                $values = [];
                foreach ($args as $arg) {
                    foreach ($this->resolve_arg($arg, $cells) as $v) {
                        $values[] = $v;
                    }
                }
                return count($values) > 0 ? min($values) : 0.0;

            case 'MAX':
                $values = [];
                foreach ($args as $arg) {
                    foreach ($this->resolve_arg($arg, $cells) as $v) {
                        $values[] = $v;
                    }
                }
                return count($values) > 0 ? max($values) : 0.0;

            case 'COUNT':
                $count = 0;
                foreach ($args as $arg) {
                    $count += count($this->resolve_arg($arg, $cells));
                }
                return floatval($count);

            case 'IF':
                if (count($args) >= 3) {
                    $condition = $this->evaluate_condition(trim($args[0]), $cells);
                    return $condition
                        ? $this->parse_expr(trim($args[1]), $cells)
                        : $this->parse_expr(trim($args[2]), $cells);
                }
                return 0.0;

            case 'ROUND':
                if (count($args) >= 2) {
                    $num      = $this->parse_expr(trim($args[0]), $cells);
                    $decimals = intval($this->parse_expr(trim($args[1]), $cells));
                    return round($num, $decimals);
                }
                return $this->parse_expr(trim($args[0]), $cells);

            case 'ROUNDUP':
                if (count($args) >= 2) {
                    $num      = $this->parse_expr(trim($args[0]), $cells);
                    $decimals = intval($this->parse_expr(trim($args[1]), $cells));
                    $factor   = pow(10, $decimals);
                    return ceil($num * $factor) / $factor;
                }
                return $this->parse_expr(trim($args[0]), $cells);

            case 'ROUNDDOWN':
                if (count($args) >= 2) {
                    $num      = $this->parse_expr(trim($args[0]), $cells);
                    $decimals = intval($this->parse_expr(trim($args[1]), $cells));
                    $factor   = pow(10, $decimals);
                    return floor($num * $factor) / $factor;
                }
                return $this->parse_expr(trim($args[0]), $cells);

            case 'INT':
                return floatval(intval($this->parse_expr(trim($args[0]), $cells)));

            case 'ABS':
                return abs($this->parse_expr(trim($args[0]), $cells));

            case 'SQRT':
                $v = $this->parse_expr(trim($args[0]), $cells);
                return $v >= 0 ? sqrt($v) : 0.0;

            case 'POWER':
                if (count($args) >= 2) {
                    return pow(
                        $this->parse_expr(trim($args[0]), $cells),
                        $this->parse_expr(trim($args[1]), $cells)
                    );
                }
                return 0.0;

            case 'MOD':
                if (count($args) >= 2) {
                    $n = $this->parse_expr(trim($args[0]), $cells);
                    $d = $this->parse_expr(trim($args[1]), $cells);
                    return $d != 0 ? fmod($n, $d) : 0.0;
                }
                return 0.0;

            case 'PRODUCT':
                $result = 1.0;
                foreach ($args as $arg) {
                    foreach ($this->resolve_arg($arg, $cells) as $v) {
                        $result *= $v;
                    }
                }
                return $result;

            case 'SUMPRODUCT':
                if (count($args) >= 2) {
                    $range1 = $this->resolve_arg(trim($args[0]), $cells);
                    $range2 = $this->resolve_arg(trim($args[1]), $cells);
                    $total  = 0.0;
                    $len    = min(count($range1), count($range2));
                    for ($i = 0; $i < $len; $i++) {
                        $total += $range1[$i] * $range2[$i];
                    }
                    return $total;
                }
                return 0.0;

            default:
                return 0.0;
        }
    }

    /**
     * Evaluate a comparison condition like "A1>5" or "B1<=C1".
     * Used inside IF() formulas.
     */
    private function evaluate_condition($condition, $cells) {
        $operators = ['>=', '<=', '<>', '!=', '>', '<', '='];
        foreach ($operators as $op) {
            $pos = strpos($condition, $op);
            if ($pos !== false) {
                $left  = $this->parse_expr(substr($condition, 0, $pos), $cells);
                $right = $this->parse_expr(substr($condition, $pos + strlen($op)), $cells);
                switch ($op) {
                    case '>':  return $left >  $right;
                    case '<':  return $left <  $right;
                    case '>=': return $left >= $right;
                    case '<=': return $left <= $right;
                    case '<>':
                    case '!=': return $left != $right;
                    case '=':  return $left == $right;
                }
            }
        }
        return $this->parse_expr($condition, $cells) != 0;
    }

    /**
     * Resolve an argument to an array of float values (range, single
     * cell, number, or expression).
     */
    private function resolve_arg($arg, $cells) {
        $arg = trim($arg);

        // Same $-marker tolerance as parse_expr()'s single-cell match --
        // the capture groups already exclude the optional $ signs, so
        // $m[1..4] come out as plain column/row pieces with nothing
        // further to strip.
        if (preg_match('/^\$?([A-Za-z]+)\$?(\d+)\s*:\s*\$?([A-Za-z]+)\$?(\d+)$/', $arg, $m)) {
            return $this->expand_range(
                strtoupper($m[1]), intval($m[2]),
                strtoupper($m[3]), intval($m[4]),
                $cells
            );
        }

        return [$this->parse_expr($arg, $cells)];
    }

    /**
     * Expand a cell range like A1:A5, A1:C1, or A1:C3 into values.
     */
    private function expand_range($startCol, $startRow, $endCol, $endRow, $cells) {
        $values = [];

        $startColNum = $this->col_to_num($startCol);
        $endColNum   = $this->col_to_num($endCol);

        $minCol = min($startColNum, $endColNum);
        $maxCol = max($startColNum, $endColNum);
        $minRow = min($startRow, $endRow);
        $maxRow = max($startRow, $endRow);

        for ($col = $minCol; $col <= $maxCol; $col++) {
            for ($row = $minRow; $row <= $maxRow; $row++) {
                $ref = $this->num_to_col($col) . $row;
                $values[] = isset($cells[$ref]) ? floatval($cells[$ref]) : 0.0;
            }
        }

        return $values;
    }

    /**
     * Split an expression by top-level operators (+, -, *, /, ^).
     * "Top-level" means NOT inside parentheses or function calls.
     *
     * Returns an array of ['op' => '+', 'value' => '...'] items.
     * The first item has no 'op' (or op is '').
     */
    private function split_by_operators($expr, $operators) {
        $expr   = trim($expr);
        $depth  = 0; // Parenthesis depth
        $tokens = [];
        $current = '';
        $current_op = '';

        for ($i = 0; $i < strlen($expr); $i++) {
            $ch = $expr[$i];

            if ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $current .= $ch;
            } elseif ($depth === 0 && in_array($ch, $operators)) {
                // Ignore the minus sign if it is a unary minus at the start
                // (i.e. nothing useful is in $current yet)
                if ($ch === '-' && trim($current) === '' && empty($tokens)) {
                    $current .= $ch;
                } else {
                    $tokens[]   = ['op' => $current_op, 'value' => trim($current)];
                    $current    = '';
                    $current_op = $ch;
                }
            } else {
                $current .= $ch;
            }
        }

        // Always emit a trailing token, even when $expr was empty to
        // begin with (e.g. a stray leading '+' or a trailing operator
        // with nothing after it). Callers -- parse_additive(),
        // parse_multiplicative(), parse_power() -- all index $tokens[0]
        // unconditionally once count($tokens) !== 1, so this must never
        // return an empty array. An empty trailing token still reaches
        // parse_leaf() further down the chain and is correctly rejected
        // there as invalid input, rather than surfacing as an undefined-
        // array-key warning here.
        $tokens[] = ['op' => $current_op, 'value' => trim($current)];

        return $tokens;
    }


    /**
     * Split function arguments by commas, but only at the top level
     * (not inside nested parentheses).
     *
     * e.g. "A1:A3, IF(B1>0, B1, 0), C1" → ["A1:A3", "IF(B1>0, B1, 0)", "C1"]
     */
    private function split_function_args($args_str) {
        $args  = [];
        $depth = 0;
        $current = '';

        for ($i = 0; $i < strlen($args_str); $i++) {
            $ch = $args_str[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $args[]  = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if (trim($current) !== '') {
            $args[] = trim($current);
        }

        return $args;
    }


    /**
     * Find the position of the closing parenthesis that matches
     * the opening parenthesis at position $open_pos.
     */
    private function matching_close_paren($expr, $open_pos) {
        $depth = 0;
        for ($i = $open_pos; $i < strlen($expr); $i++) {
            if ($expr[$i] === '(') $depth++;
            if ($expr[$i] === ')') {
                $depth--;
                if ($depth === 0) return $i;
            }
        }
        return -1; // Not found
    }

    /**
     * Split a case's answertext into individual accepted formulas,
     * splitting only on commas OUTSIDE parentheses -- a single accepted
     * formula (e.g. "=ROUND(SUM(B2:B4),2)") can itself contain commas
     * as function-argument separators, which a plain explode(',', ...)
     * would incorrectly slice into unmatched fragments.
     *
     * @param string $answertext Raw stored value of casetext.
     * @return string[] Trimmed, individual accepted formulas.
     */
    private function split_accepted_formulas(string $answertext): array {
        $formulas = [];
        $depth    = 0;
        $current  = '';

        for ($i = 0; $i < strlen($answertext); $i++) {
            $ch = $answertext[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $formulas[] = trim($current);
                $current    = '';
            } else {
                $current .= $ch;
            }
        }

        if (trim($current) !== '') {
            $formulas[] = trim($current);
        }

        return $formulas;
    }

} // End of class qtype_excelformula_question