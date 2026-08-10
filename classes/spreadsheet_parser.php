<?php
// ============================================================
// classes/spreadsheet_parser.php
//
// Parses an uploaded .xlsx file using PhpSpreadsheet and
// returns a structured array suitable for JSON storage and
// grid rendering.
//
// Usage:
//   $data = qtype_excelformula_spreadsheet_parser::parse($filepath);
//   $json = json_encode($data);
// ============================================================

defined('MOODLE_INTERNAL') || die();

class qtype_excelformula_spreadsheet_parser {



    /**
     * Parse a .xlsx file and return a structured array.
     *
     * @param  string $filepath  Absolute path to the .xlsx file on disk.
     * @return array             Structured cell data ready for json_encode().
     * @throws moodle_exception  On read failure.
     */
    public static function parse(string $filepath): array {
        global $CFG;

        // Load PhpSpreadsheet via its Composer autoloader.
        require_once($CFG->dirroot . '/lib/phpspreadsheet/vendor/autoload.php');

        // --------------------------------------------------------
        // 1. Load the spreadsheet and select the first sheet.
        //    IOFactory auto-detects .xlsx vs .xls vs .ods.
        // --------------------------------------------------------
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
        } catch (\Exception $e) {
            throw new moodle_exception(
                'errorparsespreadsheet',
                'qtype_excelformula',
                '',
                $e->getMessage()
            );
        }

        $sheets = [];

        // Process every worksheet, keyed by its name, so the grid
        // and the grading data both stay sheet-aware.
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetname = $sheet->getTitle();

        // --------------------------------------------------------
        // 2. Determine the bounding box of used cells.
        //    e.g. "A1:E10" => minRow=1, maxRow=10, minCol=1, maxCol=5
        // --------------------------------------------------------
           $highestRow    = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $highestColNum = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

             $dimensions = [
            'minRow' => 1,
            'maxRow' => (int) $highestRow,
            'minCol' => 1,
            'maxCol' => (int) $highestColNum,
         ];

        // --------------------------------------------------------
        // 3. Walk every cell in the bounding box.
        //    For each occupied cell, record:
        //      - value      : the display value (strings, numbers)
        //      - type       : 'string' | 'numeric' | 'formula' | 'empty'
        //      - style      : minimal formatting for rendering hints
        //    Cells with no value are still recorded as 'empty' so
        //    the renderer knows to draw a blank grid cell.
        // --------------------------------------------------------
        $cells = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($colNum = 1; $colNum <= $highestColNum; $colNum++) {

                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate
                    ::stringFromColumnIndex($colNum);
                $cellRef   = $colLetter . $row;
                $cell      = $sheet->getCell($cellRef);

                // getCalculatedValue() resolves formula cells to their result.
                // Use getFormattedValue() to respect number formats (e.g. currency).
                $rawValue  = $cell->getCalculatedValue();
                $formatted = $cell->getFormattedValue();

                // Determine a simple type flag for the renderer.
                $dataType  = $cell->getDataType();   // PhpSpreadsheet type constant
                $type = 'empty';
                if ($dataType === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    || $dataType === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING2
                    || $dataType === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_INLINE) {
                    $type = 'string';
                } elseif ($dataType === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC) {
                    $type = 'numeric';
                } elseif ($dataType === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                    // Store the calculated result, not the formula itself,
                    // because students should only see values, not formulas.
                    $type = 'numeric';
                } elseif ($rawValue !== null && $rawValue !== '') {
                    $type = 'string';
                }

                // Skip truly blank cells to keep the JSON small.
                if ($type === 'empty' && ($rawValue === null || $rawValue === '')) {
                    $cells[$cellRef] = ['type' => 'empty', 'value' => ''];
                    continue;
                }

                // ---- Extract minimal style information ----
                $style      = $sheet->getStyle($cellRef);
                $fill       = $style->getFill();
                $font       = $style->getFont();
                $alignment  = $style->getAlignment();

                $bgColor  = null;
                if ($fill->getFillType() !== \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE) {
                    $argb    = $fill->getStartColor()->getARGB();
                    // ARGB e.g. "FFFFFF00" — strip the alpha byte to get RGB hex.
                    $bgColor = '#' . substr($argb, 2);
                }

                $cells[$cellRef] = [
                    'value'  => $formatted !== '' ? $formatted : (string) $rawValue,
                    'raw'    => $rawValue,      // kept for the formula evaluator
                    'type'   => $type,
                    'style'  => [
                        'bold'      => (bool) $font->getBold(),
                        'italic'    => (bool) $font->getItalic(),
                        'align'     => $alignment->getHorizontal() ?: 'general',
                        'bgColor'   => $bgColor,
                    ],
                ];
            }
        }

        // --------------------------------------------------------
        // 4. Read merged cell ranges.
        //    A merged cell like "A1:C1" means the HTML <td> for A1
        //    should get colspan=3 and the cells B1 and C1 should
        //    be skipped when rendering.
        // --------------------------------------------------------
        $mergedCells = [];
        foreach ($sheet->getMergeCells() as $mergeRange) {
            // Decode "A1:C3" into numeric coordinates.
            [$startRef, $endRef] = explode(':', $mergeRange);

            [$startCol, $startRow] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate
                ::coordinateFromString($startRef);
            [$endCol, $endRow] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate
                ::coordinateFromString($endRef);

            $mergedCells[] = [
                'range'    => $mergeRange,
                'startCol' => \PhpOffice\PhpSpreadsheet\Cell\Coordinate
                    ::columnIndexFromString($startCol),
                'startRow' => (int) $startRow,
                'endCol'   => \PhpOffice\PhpSpreadsheet\Cell\Coordinate
                    ::columnIndexFromString($endCol),
                'endRow'   => (int) $endRow,
            ];
        }

        // --------------------------------------------------------
        // 5. Record column widths (in Excel "character" units).
        //    These are converted to approximate pixel values in the
        //    renderer (1 Excel unit ≈ 7 px is a reasonable default).
        //    A value of null means "use the default width".
        // --------------------------------------------------------
        $columnWidths = [];
        for ($colNum = 1; $colNum <= $highestColNum; $colNum++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate
                ::stringFromColumnIndex($colNum);
            $dimension = $sheet->getColumnDimension($colLetter);
            $width     = $dimension->getWidth(); // -1 means "auto"
            $columnWidths[$colLetter] = $width > 0 ? (int) round($width * 7) : null;
        }

        // --------------------------------------------------------
        // 6. Record row heights (in points; 1pt ≈ 1.33px).
        // --------------------------------------------------------
        $rowHeights = [];
        for ($row = 1; $row <= $highestRow; $row++) {
            $dimension = $sheet->getRowDimension($row);
            $height    = $dimension->getRowHeight(); // -1 means "auto"
            $rowHeights[$row] = $height > 0 ? (int) round($height * 1.33) : null;
        }

       // NEW
            $sheets[$sheetname] = [
                'dimensions'   => $dimensions,
                'cells'        => $cells,
                'mergedCells'  => $mergedCells,
                'columnWidths' => $columnWidths,
                'rowHeights'   => $rowHeights,
            ];
        }

        return [
            'sheets' => $sheets,
        ];
    }

      /**
     * Parse an answer-key .xlsx file's cell comments into the nested
     * cell -> case structure consumed by amd/src/answer_cases.js.
     *
     * Each commented cell becomes one graded answer (cellref). Multiple
     * answer cases for that cell are written as separate blank-line
     * separated blocks within the SAME comment, each block containing
     * one or more "attribute: value" lines:
     *
     *   formula: =SUM(B2:B4)
     *   feedback: Correct - VAT-exclusive total.
     *   credittype: full
     *
     *   formula: =B2+B3+B4
     *   feedback: Also accepted - equivalent expansion.
     *   credittype: partial
     *
     * Recognised attributes: formula, feedback, credittype
     * (credittype must be one of full|partial|hardcoded, defaults to
     * full). marks is intentionally not read from the comment -- the
     * teacher sets marks per case after review in the edit form.
     *
     * @param  string $filepath  Absolute path to the answer-key .xlsx file on disk.
     * @return array             Cells in the shape:
     *                           [ ['cellref' => 'B6', 'totalmarks' => 0,
     *                              'cases' => [
     *                                  ['credittype' => 'full', 'marks' => 0,
     *                                   'formulas' => '=SUM(B2:B4)', 'feedback' => '...'],
     *                                  ...
     *                              ]], ... ]
     * @throws moodle_exception  On read failure.
     */
    public static function parse_answer_key(string $filepath): array {
        global $CFG;

        require_once($CFG->dirroot . '/lib/phpspreadsheet/vendor/autoload.php');

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
        } catch (\Exception $e) {
            throw new moodle_exception(
                'errorparsespreadsheet',
                'qtype_excelformula',
                '',
                $e->getMessage()
            );
        }

        $cells = [];

        // Walk every worksheet so a graded cell's comment is detected
        // regardless of which sheet the teacher placed it on.
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetname = $sheet->getTitle();

        // getComments() returns Comment objects keyed by cell coordinate,
        // e.g. "B6" -- only cells that actually have a comment appear here.
        foreach ($sheet->getComments() as $cellref => $comment) {
            $cellref     = strtoupper(trim($cellref));
            $commenttext = trim($comment->getText()->getPlainText());

            if ($commenttext === '') {
                continue;
            }

            if (stripos($commenttext, 'threaded comment') !== false) {
    throw new moodle_exception(
        'errorparsespreadsheet',
        'qtype_excelformula',
        '',
        "Cell {$cellref} uses a threaded comment. Convert it to a classic Note (Review tab → Notes → New Note in Excel) instead."
    );
}

            $cases = self::parse_comment_cases($commenttext);
            if (empty($cases)) {
                continue;
            }

           $cells[] = [
                'cellref'    => $cellref,
                'sheetname'  => $sheetname,
                'totalmarks' => 0,
                'cases'      => $cases,
            ];
        }
        }

        return $cells;
    }

    /**
     * Split a single cell comment's text into one or more answer cases
     * and extract the formula/feedback/credittype attributes from each.
     *
     * @param  string $commenttext Raw plain-text comment for one cell.
     * @return array               List of case arrays (credittype, marks,
     *                             formulas, feedback). Cases with no
     *                             formula attribute are dropped, since an
     *                             empty accepted-formula case is not
     *                             usable and would just be manual
     *                             clutter for the teacher to delete.
     */
    private static function parse_comment_cases(string $commenttext): array {
        $cases = [];

        // Blank lines separate multiple answer cases within one comment.
        $blocks = preg_split('/\R\s*\R/', $commenttext);

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $case = [
                'credittype' => 'full',
                'marks'      => 0,
                'formulas'   => '',
                'feedback'   => '',
            ];

            foreach (preg_split('/\R/', $block) as $line) {
                if (!preg_match('/^\s*(formula|feedback|credittype)\s*:\s*(.*)$/i', $line, $m)) {
                    continue;
                }

                $attribute = strtolower($m[1]);
                $value     = trim($m[2]);

                switch ($attribute) {
                    case 'formula':
                        $case['formulas'] = $value;
                        break;
                    case 'feedback':
                        $case['feedback'] = $value;
                        break;
                    case 'credittype':
                        $value = strtolower($value);
                        $case['credittype'] = in_array($value, ['full', 'partial', 'hardcoded'], true)
                            ? $value : 'full';
                        break;
                }
            }

            if ($case['formulas'] !== '') {
                $cases[] = $case;
            }
        }

        return $cases;
    }
}