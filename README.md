# qtype_excelformula — Moodle Excel Formula Question Type
### Complete Developer & Installation Guide

---

## What this plugin does

This plugin adds a new question type to Moodle quizzes called **"Excel Formula"**.

- The **teacher** defines a set of spreadsheet cell values (e.g. A1=10, A2=20) and a correct formula.
- The **student** types an Excel-style formula in the quiz (e.g. `=SUM(A1:A3)`).
- The plugin **evaluates** the student's formula against the cell data and checks if the result matches the expected answer.

---

## Supported Excel functions

| Function      | Example                    | Description                           |
|---------------|----------------------------|---------------------------------------|
| SUM           | `=SUM(A1:A5)`              | Add a range of cells                  |
| AVERAGE       | `=AVERAGE(A1:A5)`          | Average of a range                    |
| MIN           | `=MIN(A1:A5)`              | Smallest value in a range             |
| MAX           | `=MAX(A1:A5)`              | Largest value in a range              |
| COUNT         | `=COUNT(A1:A5)`            | Count cells in a range                |
| IF            | `=IF(A1>10, A1, 0)`        | Conditional logic                     |
| ROUND         | `=ROUND(A1, 2)`            | Round to N decimal places             |
| ROUNDUP       | `=ROUNDUP(A1, 0)`          | Always round up                       |
| ROUNDDOWN     | `=ROUNDDOWN(A1, 0)`        | Always round down                     |
| INT           | `=INT(A1)`                 | Remove decimal part                   |
| ABS           | `=ABS(A1)`                 | Absolute value (remove minus sign)    |
| SQRT          | `=SQRT(A1)`                | Square root                           |
| POWER         | `=POWER(A1, 2)`            | Raise to a power (A1 squared)         |
| MOD           | `=MOD(A1, 3)`              | Remainder after division              |
| PRODUCT       | `=PRODUCT(A1:A3)`          | Multiply all values in a range        |
| SUMPRODUCT    | `=SUMPRODUCT(A1:A3, B1:B3)`| Multiply pairs, then sum              |
| Basic math    | `=(A1+B1)*C1/2`            | Any arithmetic with +, -, *, /, ^    |

---

## File structure

```
qtype_excelformula/          ← This entire folder goes into Moodle
│
├── version.php              ← Plugin version info
├── questiontype.php         ← Database load/save logic
├── question.php             ← Grading logic + formula evaluator
├── edit_excelformula_form.php ← Teacher's question editing form
├── renderer.php             ← Student-facing display
├── styles.css               ← Visual styling
├── lang/
│   └── en/
│       └── qtype_excelformula.php  ← All text labels
└── db/
    └── install.xml          ← Database table definition
```

---

## STEP 1 — Install on your local Moodle

1. Copy the entire `qtype_excelformula` folder to:
   ```
   C:\Moodle\server\moodle\question\type\excelformula\
   ```
   ⚠️ The folder name must be exactly `excelformula` (no "qtype_" prefix in the folder name).

2. Open your browser and go to:
   ```
   http://localhost/moodle
   ```

3. Log in as admin.

4. Moodle will automatically detect the new plugin and show an **upgrade/install screen**.

5. Click **"Upgrade Moodle database now"**.

6. You're done. The plugin is now installed locally.

---

## STEP 2 — Test the plugin locally

1. In Moodle, go to a course and open (or create) a **Quiz** activity.

2. In the quiz, click **"Add question"** and look for **"Excel Formula"** in the question type list.

3. Fill in the form:
   - **Cell data** (JSON format):
     ```json
     {"A1": 10, "A2": 20, "A3": 30, "B1": 5}
     ```
   - **Correct formula**: `=SUM(A1:A3)`
   - **Expected result**: `60`
   - **Tolerance**: `0.01`

4. Save the question, then preview the quiz as a student.

5. Try entering `=SUM(A1:A3)` — it should be graded correct.
   Try entering `=A1+A2+A3` — it should also be correct (same result = 60).
   Try entering `=SUM(A1:A2)` — it should be graded wrong (result = 30, not 60).

---

## STEP 3 — Deploy to the live Moodle 4.5 server

When you're happy with how it works locally:

1. Copy the `qtype_excelformula` folder to the live server in the same location:
   ```
   /path/to/moodle/question/type/excelformula/
   ```
   (Ask your server admin or hosting provider for the exact Moodle root path.)

2. Log in to the live Moodle as admin.

3. Moodle will detect the new plugin and show the upgrade screen.

4. Click **"Upgrade Moodle database now"**.

5. Done — the question type is now available on the live server.

---

## How to customise the plugin

### Add a new Excel function

Open `question.php` and find the `switch ($funcname)` block inside `parse_function_call()`.
Add a new `case` like this:

```php
case 'MYFUNCTION':
    // Your logic here
    // $args[0], $args[1], etc. are the function arguments
    $value = $this->parse_expr(trim($args[0]), $cells);
    return $value * 2; // Example: double the value
```

### Change the appearance

Edit `styles.css`. All the class names are prefixed with `qtype_excelformula_` so they won't clash with anything else in Moodle.

### Change the form labels or messages

All text strings are in `lang/en/qtype_excelformula.php`.
Edit the values (the text in quotes on the right side of `=`).
Do NOT change the variable names (the part before `=`).

### Bump the version number

Every time you make changes and want to deploy an update to the live server,
increment the version number in `version.php`:
```php
$plugin->version = 2024010101;  // Increment the last two digits
```

---

## Cell data format reference

Cell data must be entered in **JSON format**. Here are examples:

```json
{"A1": 10, "A2": 20, "A3": 30}
```
```json
{"A1": 100, "B1": 200, "C1": 300, "A2": 50, "B2": 75, "C2": 25}
```
```json
{"Revenue": 50000, "Cost": 32000}
```

> **Note:** Cell names don't have to be traditional Excel references (A1, B2, etc.).
> You can use any labels, but make sure your formula uses the same labels you defined.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Plugin not showing in question type list | Check the folder is named exactly `excelformula` (not `qtype_excelformula`) |
| "Upgrade Moodle database" screen not appearing | Clear Moodle's cache: Site admin → Development → Purge all caches |
| Student's formula gives wrong result | Check the Expected result value matches what your formula actually produces |
| JSON error on cell data | Validate your JSON at https://jsonlint.com |
| Formula evaluates to null | Check that cell references in the formula match the keys in the cell data (case-insensitive) |

---

## Version history

| Version    | Date       | Notes                     |
|------------|------------|---------------------------|
| 1.0        | 2024-01-01 | Initial release           |
