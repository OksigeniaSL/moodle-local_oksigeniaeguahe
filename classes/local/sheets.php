<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_oksigeniaeguahe\local;

/**
 * Reads a file as it comes, every sheet of it: a spreadsheet (xlsx, ods) or a CSV. Each cell becomes a string: dates
 * as «YYYY-MM-DD HH:MM:SS», whole numbers without decimals, booleans as TRUE or FALSE. Nothing is kept: the rows live
 * only while the import reads them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sheets {
    /** @var int Rows read at most from each sheet. */
    const MAXROWS = 20000;

    /** @var int Columns read at most. */
    const MAXCOLS = 80;

    /** @var int Sheets read at most. */
    const MAXSHEETS = 40;

    /**
     * Reads a file.
     *
     * @param string $path
     * @param string $filename To know its kind.
     * @return array A list of sheets: name, rows (each a list of strings, without the empty ones).
     */
    public static function read(string $path, string $filename): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            return [['name' => pathinfo($filename, PATHINFO_FILENAME), 'rows' => self::csv((string) file_get_contents($path))]];
        }
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadEmptyCells(false);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            // Not a spreadsheet after all: perhaps a CSV with another name.
            return [['name' => pathinfo($filename, PATHINFO_FILENAME), 'rows' => self::csv((string) file_get_contents($path))]];
        }
        $out = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            if (count($out) >= self::MAXSHEETS) {
                break;
            }
            $out[] = ['name' => (string) $sheet->getTitle(), 'rows' => self::sheet($sheet)];
        }
        $book->disconnectWorksheets();
        return $out;
    }

    /**
     * The rows of one sheet.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @return array
     */
    protected static function sheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array {
        $rows = [];
        $maxrow = min(self::MAXROWS, (int) $sheet->getHighestDataRow());
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(self::MAXCOLS);
        foreach ($sheet->getRowIterator(1, max(1, $maxrow)) as $line) {
            // Only the cells that exist: a sheet of boxes to tick has thousands of empty ones.
            $cells = $line->getCellIterator('A', $last);
            $cells->setIterateOnlyExistingCells(true);
            $values = [];
            foreach ($cells as $cell) {
                $value = self::value($cell);
                if ($value !== '') {
                    $values[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn()) - 1] = $value;
                }
            }
            if ($values) {
                $row = array_fill(0, max(array_keys($values)) + 1, '');
                $rows[] = array_replace($row, $values);
            }
        }
        return $rows;
    }

    /**
     * A cell as a string.
     *
     * @param \PhpOffice\PhpSpreadsheet\Cell\Cell $cell
     * @return string
     */
    protected static function value(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string {
        // A formula: the value the spreadsheet saved with it, without working it out again (lookups are slow).
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        if ($value === null && $cell->isFormula()) {
            try {
                $value = $cell->getCalculatedValue();
            } catch (\Throwable $e) {
                $value = '';
            }
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    return '';
                }
            }
            return floor($value) == $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : (string) $value;
        }
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        return trim((string) $value);
    }

    /**
     * A CSV: UTF-8 (or Latin-1, as Excel saves it), with commas, semicolons or tabs.
     *
     * @param string $content
     * @return array
     */
    public static function csv(string $content): array {
        // The export of the forms of Meta ads comes in UTF-16 with tabs.
        if (strncmp($content, "\xFF\xFE", 2) === 0 || strncmp($content, "\xFE\xFF", 2) === 0) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', $content[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE');
        } else if (substr_count(substr($content, 0, 400), "\x00") > 100) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstline = (string) strtok($content, "\n");
        $counts = [',' => substr_count($firstline, ','), ';' => substr_count($firstline, ';'),
            "\t" => substr_count($firstline, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false && count($rows) < self::MAXROWS) {
            $line = array_map(fn($v) => trim((string) $v), array_slice($line, 0, self::MAXCOLS));
            if (implode('', $line) !== '') {
                $rows[] = self::trim($line);
            }
        }
        fclose($handle);
        return $rows;
    }

    /**
     * A row without its empty cells at the end.
     *
     * @param array $row
     * @return array
     */
    protected static function trim(array $row): array {
        while ($row && end($row) === '') {
            array_pop($row);
        }
        return $row;
    }
}
