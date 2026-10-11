# qtype_excelformula — Moodle Excel Formula Question Type

A Moodle question type where students answer by typing Excel formulas into
specific cells of a spreadsheet shown in the quiz. Each graded cell is marked
one at a time, with per-cell attempts, credit types and feedback.

---

## Contents

1. [How it works](#1-how-it-works)
2. [Teacher guide: setting up a question](#2-teacher-guide-setting-up-a-question)
3. [Student experience](#3-student-experience)
4. [Grading logic](#4-grading-logic)
5. [Installation](#5-installation)
6. [Testing locally (using a shared Moodle server)](#6-testing-locally-using-a-shared-moodle-server)
7. [Architecture and file structure](#7-architecture-and-file-structure)
8. [Customising the plugin](#8-customising-the-plugin)
9. [Troubleshooting](#9-troubleshooting)
10. [Version history](#10-version-history)

---

## 1. How it works

| Actor   | What they do |
|---------|--------------|
| Teacher | Uploads a **question spreadsheet** (what students see) and an **answer-key spreadsheet** whose cell Notes describe the accepted formulas. Reviews the detected answer cases, assigns marks, and saves. |
| Student | Sees the worksheet(s) as a grid and answers each graded cell in order. Each submission is graded immediately through an AJAX call. |
| Plugin  | Evaluates the student's formula against the sheet's cell values (PhpSpreadsheet), matches it to an answer case, awards that case's marks, and records the result on the live `question_attempt`. |

---

## 2. Teacher guide: setting up a question

### 2.1 Question spreadsheet

In **Question spreadsheet**, upload an `.xlsx` or `.xls` file. Every worksheet is
parsed (values, bold/italic, fill colour, alignment, merged cells, column
widths, row heights) and stored so students see each sheet as a tabbed grid.
Formula cells are shown as their calculated values, never as formulas.

### 2.2 Answer-key spreadsheet

In **Answer key file**, upload an `.xlsx` or `.xls` workbook in which each
**graded cell carries a classic Excel Note** (Review → Notes → New Note).
Each Note defines that cell's answer cases.

> Threaded comments are **not** supported. If one is found, detection fails and
> names the cell. Convert it to a Note first.

**Note syntax.** One `attribute: value` per line. A **blank line separates
answer cases** within the same Note.

| Attribute    | Required | Values |
|--------------|----------|--------|
| `formula`    | Yes      | One or more accepted formulas, separated by commas (see 2.4) |
| `feedback`   | No       | Text shown when this case is the best match |
| `credittype` | No       | `full`, `partial` or `hardcoded` (defaults to `full`) |

Rules enforced by the parser:

- Attribute names are case-insensitive. Lines that match no attribute are ignored.
- A case with no `formula` is dropped.
- An invalid `credittype` falls back to `full`.
- **Marks are not read from the Note.** You set them in step 2.3.
- Every worksheet is scanned. The sheet name is captured with each cell.

Example Note on cell `B6`:

```
formula: =SUM(B2:B4)
feedback: Correct - VAT-exclusive total.
credittype: full

formula: =B2+B3+B4
feedback: Accepted, but SUM is preferred.
credittype: partial
```

### 2.3 Detect and review answer cases

1. Click **Detect answer cases**. The summary reads
   `Detected N cell(s) with N answer case(s).`
2. Review the **Answer cases** accordion. Each graded cell is one **Answer N**
   group, and each case inside it is one **Answer Case N** row.
3. Set the marks. You can also add, edit or remove cells and cases by hand.

| Level | Field | Meaning |
|-------|-------|---------|
| Cell  | Cell reference | e.g. `B6` |
| Cell  | Worksheet | Required. The same cell reference can exist on several sheets |
| Cell  | Total marks | Maximum marks for this cell |
| Cell  | Default feedback | Shown when no case matches |
| Case  | Credit type | Full credit, Partial credit, or Hardcoded value |
| Case  | Marks | Marks awarded when this case is the best match |
| Case  | Accepted formulas | Comma-separated (see 2.4) |
| Case  | Feedback | Shown when this case matches |

### 2.4 Writing accepted formulas

- The leading `=` is optional. Case and whitespace are ignored.
- Separate several accepted formulas with commas. Commas inside parentheses
  are treated as function arguments, so `=ROUND(SUM(B2:B4),2), =B2+B3+B4`
  is two formulas.
- **`full` / `partial`:** the student's formula text must match the accepted
  formula (see 4.2 for what counts as a match).
- **`hardcoded`:** the accepted text is a number (e.g. `1250.5`). It matches
  when the *result* of the student's formula equals that number, whatever
  formula produced it.
- `$` markers are significant. `$B$6` and `B6` are different formulas, so you
  can mark them as separate full/partial cases.

### 2.5 Validation on save

The form is rejected unless:

- at least one graded cell exists;
- every cell has a cell reference, a worksheet name and at least one case;
- every case has at least one accepted formula;
- all marks are numbers of 0 or greater, and every credit type is valid.

---

## 3. Student experience

1. The question text and the worksheet grid(s) are shown. Multiple worksheets
   appear as tabs.
2. Cells are answered **one at a time, in the order defined by the teacher**.
   Only the active cell's input is enabled, and its grid cell is highlighted
   (the sheet tab switches automatically).
3. The student clicks **Check** (or presses Enter). The formula is sent to the
   server and graded immediately.
4. After each submission the student sees:
   - the mark (`Mark: x / y`);
   - the matched case's feedback, or the cell's default feedback, or a generic
     "try again" message if none is set;
   - for retries, the number of attempts remaining.
5. Each cell allows up to **3 attempts**. It locks when the student earns full
   marks or uses all attempts, and the next cell then unlocks. Only the best
   mark across attempts is kept.
6. Progress is saved as steps on the question attempt, so reloading the page or
   returning from the quiz summary does not lose it. On review, a cell that was
   not fully correct shows its correct formula (the first accepted formula of
   its first full-credit case).

The order is enforced **server-side** too. The grading service refuses a cell
that is not the next ungraded one.

---

## 4. Grading logic

### 4.1 Evaluating a formula

The formula is written into a throwaway in-memory worksheet pre-filled with that
sheet's cell values and calculated by **PhpSpreadsheet's Calculation engine**.
Any function supported by PhpSpreadsheet is therefore usable. If the formula is
empty, malformed, or does not produce a number, it evaluates to nothing and
earns no marks.

### 4.2 Matching a case

For each case, each accepted formula is tested:

| Credit type | Match rule |
|-------------|------------|
| `hardcoded` | Student's result equals the accepted number (tolerance 0.0001, to absorb floating-point rounding only) |
| `full` / `partial` | Student's formula equals the accepted formula after normalisation (strip `=`, whitespace, case), **or** after canonicalisation |

Canonicalisation makes operand order irrelevant for purely additive or purely
multiplicative groups: `D9*$B$6` equals `$B$6*D9`, and `A1+B1` equals `B1+A1`.
Subtraction and division stay order-significant, function arguments keep their
order, and `$` markers are never altered.

Formulas that are mathematically equivalent but structured differently
(e.g. `SUM(B2:B4)` vs `B2+B3+B4`) are **not** treated as equal. Add each as its
own case.

The **highest-mark** matching case is used.

### 4.3 Marks and state

- Cell mark = the matched case's marks (0 if no case matches). Maximum = the
  cell's total marks.
- Cell state: **right** if mark ≥ maximum, **partial** if mark > 0, otherwise
  **wrong**.
- Question fraction = sum of each cell's best mark ÷ sum of all cells' total
  marks.

---

## 5. Installation

1. Copy this folder to `<moodle root>/question/type/excelformula/`.
   The folder name must be exactly `excelformula` (no `qtype_` prefix).
2. Log in as admin. Moodle detects the plugin.
3. Click **Upgrade Moodle database now**.

**Requirements:** Moodle 4.0 or later (`$plugin->requires = 2022041900`) and the
PhpSpreadsheet library bundled in Moodle at
`lib/phpspreadsheet/vendor/autoload.php`.

**Deploying updates:** increment `$plugin->version` in `version.php`
(format `YYYYMMDDNN`) and run the upgrade again. Schema changes belong in
`db/upgrade.php`.

---

## 6. Testing locally (using a shared Moodle server)

Use this when someone has shared a complete Moodle server (Moodle code, web
server, PHP, database and `moodledata`) as a zip file, and you want to run it
on your own machine.

> The exact start-up step depends on how the server was packaged. The steps
> below assume a Windows-style bundle (as in the original development setup,
> e.g. `C:\Moodle\server\moodle\`). Ask the sender which stack it uses and the
> admin login.

### 6.1 Unzip

1. Copy the zip to a **short path** on a local drive (e.g. `C:\Moodle\`), not
   a synced or network folder.
2. Unzip it there (right-click → *Extract All…*). Do not rename the extracted
   folders, because `config.php` contains absolute paths.
3. Confirm the plugin is present at
   `...\server\moodle\question\type\excelformula\`. If it is missing, copy the
   plugin folder there.

### 6.2 Check `config.php`

Open `...\server\moodle\config.php` and check:

| Setting | What to verify |
|---------|----------------|
| `$CFG->wwwroot` | Matches the URL you will browse to, e.g. `http://localhost`, including the port if it is not 80 |
| `$CFG->dataroot` | Points to the **unzipped** `moodledata` folder on *your* machine |
| `$CFG->dbhost`, `dbname`, `dbuser`, `dbpass`, `dbtype` | Match the bundled database server's settings |

If you unzipped to a different drive or folder than the original machine, update
`dataroot` (and any other absolute paths) now.

For plugin development, also add (remove for production):

```php
$CFG->cachejs = false;
$CFG->jsrev   = -1;
```

### 6.3 Start the servers

Start the bundled web server and database. Packaged Windows bundles usually
provide a start script or executable in the server folder. Keep it running
while you test. If a port is already in use, stop the other program or change
the port in the web server config and update `$CFG->wwwroot` to match.

### 6.4 Log in and upgrade

1. Browse to `http://localhost/moodle` (or your `wwwroot`).
2. Log in with the admin credentials. username: yolanit password: Mbalentl3!
3. If the plugin version is newer than the installed one, Moodle shows the
   upgrade screen. Click **Upgrade Moodle database now**.
4. Purge caches: *Site administration → Development → Purge all caches*.

### 6.5 Run a test

1. Open a course, then a **Quiz** (or create one).
2. *Add question → Excel Formula*.
3. Upload a **question spreadsheet**, then an **answer-key spreadsheet** with
   Notes as described in [2.2](#22-answer-key-spreadsheet).
4. Click **Detect answer cases**, set the marks, and save.
5. Preview the quiz and check:
   - the grid and sheet tabs display correctly;
   - the first graded cell is active and highlighted;
   - a full-credit formula locks the cell and unlocks the next one;
   - a partial/wrong formula shows feedback and the attempts remaining;
   - a cell locks after the 3rd attempt;
   - reloading the page keeps your progress.

---

## 7. Architecture and file structure

The plugin follows Moodle's question-type conventions: model (`question.php`,
`questiontype.php`), view (`renderer.php`, AMD modules) and controller-style
external services.

```
excelformula/
├── version.php                    Plugin version and requirements
├── questiontype.php               Save/load options, answers and cases; build question instance
├── question.php                   Grading, case matching, formula evaluation, per-cell attempt state
├── edit_excelformula_form.php     Teacher form (filepickers, detect button, accordion container)
├── renderer.php                   Student view: grid, sheet tabs, per-cell answer blocks
├── styles.css                     Grid, answer-block and accordion styling
├── classes/
│   ├── spreadsheet_parser.php     parse() for the question grid; parse_answer_key() for Notes
│   └── external/
│       ├── detect_answercases.php Teacher AJAX: parse answer-key Notes (read-only)
│       └── grade_cell.php         Student AJAX: grade one cell and record an attempt step
├── amd/src/
│   ├── answer_cases.js            Nested cell → case accordion; state kept in a hidden JSON field
│   ├── answerkey_parser.js        Detect button; calls detect_answercases
│   ├── cell_answer.js             One-cell-at-a-time flow; calls grade_cell
│   └── sheet_tabs.js              Worksheet tab switching
├── db/
│   ├── install.xml                Table definitions
│   ├── services.php               External function registration
│   └── upgrade.php                Schema migrations
└── lang/en/qtype_excelformula.php All user-facing strings
```

### Database tables

| Table | Rows | Holds |
|-------|------|-------|
| `qtype_excelformula_options` | 1 per question | File item ids and `spreadsheetdata` (parsed grid JSON for all sheets) |
| `qtype_excelformula_answers` | 1 per graded cell | `cellref`, `sheetname`, `markallocation`, `defaultfeedback` |
| `qtype_excelformula_anscase` | 1 per answer case | `casemark`, `casetype`, `answertext`, `casefeedback` |

### Attempt data (per graded cell, stored on the question attempt)

| Key | Purpose |
|-----|---------|
| `attempts_{ansid}` | Submissions used so far |
| `bestmark_{ansid}` | Best mark across attempts |
| `answer_{ansid}` | Final formula, written **only when the cell locks**. Its presence marks the cell as done and advances the sequence |

`question.php`, `grade_cell.php` and `renderer.php` must agree on these key names.

---

## 8. Customising the plugin

- **Appearance:** edit `styles.css`. Class names are prefixed
  `qtype_excelformula_` (student view) and `excelformula-` (teacher form).
- **Text:** edit `lang/en/qtype_excelformula.php`. Change only the values,
  never the string keys.
- **Attempts per cell:** change `MAX_ATTEMPTS_PER_CELL` in `question.php`.
- **JavaScript:** with `$CFG->cachejs = false` and `$CFG->jsrev = -1`, Moodle
  serves `amd/src` directly. For production run `grunt amd` from the Moodle
  root to build `amd/build`.

---

## 9. Troubleshooting

| Problem | Solution |
|---------|----------|
| Plugin not in the question type list | Folder must be named exactly `excelformula`. Purge all caches |
| No upgrade screen after copying | Increase `$plugin->version`, then purge caches |
| Detect answer cases reports an error about a threaded comment | Replace the comment with a classic Note in Excel |
| Detect finds 0 cells | Notes must be classic Notes with at least one `formula:` line, and the answer-key file must be uploaded first |
| Student's correct formula scores 0 | Check the case's accepted formula and `$` markers, and the cell's worksheet name matches the sheet exactly |
| Equivalent formula not accepted | Add it as its own answer case (see 4.2) |
| Detect/Check buttons do nothing | Check the browser console. Purge caches and confirm `db/services.php` changes were upgraded |
| Blank page or path errors on a shared server | `dataroot` or `wwwroot` in `config.php` does not match this machine (see 6.2) |

---

## 10. Version history

| Version | Date       | Notes |
|---------|------------|-------|
| 1.0     | 2026-10-12 | Initial iteration of the artefact |