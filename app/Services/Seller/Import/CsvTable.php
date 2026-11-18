<?php

namespace App\Services\Seller\Import;

use League\Csv\Reader;

/**
 * Reads a product spreadsheet saved as CSV/TSV/TXT from any platform:
 * - any text encoding (UTF-8 with or without BOM, UTF-16 "Unicode text" from Excel, Windows-1252)
 * - comma, semicolon, tab or pipe separated
 * - extra rows above the real header (Amazon templates, eBay "Info" lines) are skipped
 */
class CsvTable
{
    /** @var list<string> */
    public array $headers = [];

    /** @var list<array<string,string>> */
    public array $rows = [];

    /** File line number of the header row (1-based), for error messages. */
    public int $headerLine = 1;

    public static function fromFile(string $path): self
    {
        $t = new self;
        $raw = self::toUtf8((string) file_get_contents($path));

        $csv = Reader::createFromString($raw);
        $csv->setDelimiter(self::sniffDelimiter($raw));
        $all = [];
        foreach ($csv->getRecords() as $record) {
            $all[] = array_map(fn ($v) => (string) $v, $record);
        }
        if ($all === []) {
            return $t;
        }

        // The header is the row (within the first 15) with the most recognisable column names.
        $best = 0;
        $bestScore = -1;
        foreach (array_slice($all, 0, 15, true) as $i => $cells) {
            $score = ColumnMap::score($cells);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }
        if ($bestScore < 2) {
            $best = 0;
        }
        $t->headerLine = $best + 1;

        // Unique, trimmed header names (some exports repeat a column name).
        $seen = [];
        foreach ($all[$best] as $h) {
            $h = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $h));
            $name = $h === '' ? 'column' : $h;
            $n = $seen[strtolower($name)] = ($seen[strtolower($name)] ?? 0) + 1;
            $t->headers[] = $n > 1 ? "{$name} {$n}" : $name;
        }

        $width = count($t->headers);
        foreach (array_slice($all, $best + 1) as $cells) {
            if (! array_filter($cells, fn ($v) => trim($v) !== '')) {
                continue; // blank line
            }
            $cells = array_pad(array_slice($cells, 0, $width), $width, '');
            $t->rows[] = array_combine($t->headers, $cells);
        }

        return $t;
    }

    public static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        // UTF-16 without a BOM: every other byte is zero.
        $sample = substr($raw, 0, 400);
        if ($sample !== '' && substr_count($sample, "\0") > strlen($sample) / 4) {
            return (string) mb_convert_encoding($raw, 'UTF-8', $sample[0] === "\0" ? 'UTF-16BE' : 'UTF-16LE');
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    /** Pick the separator that splits the first few lines most consistently. */
    public static function sniffDelimiter(string $raw): string
    {
        // Header lines decide: descriptions further down are full of commas even in tab files.
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\r|\n/', substr($raw, 0, 20000)) ?: [], fn ($l) => trim($l) !== '')), 0, 3);
        foreach ($lines as $line) {
            if (substr_count($line, "\t") >= 2) {
                return "\t";
            }
        }
        $best = ',';
        $bestCount = 0;
        foreach ([',', "\t", ';', '|'] as $d) {
            $max = 0;
            foreach ($lines as $line) {
                $max = max($max, substr_count($line, $d));
            }
            if ($max > $bestCount) {
                [$best, $bestCount] = [$d, $max];
            }
        }

        return $best;
    }
}
