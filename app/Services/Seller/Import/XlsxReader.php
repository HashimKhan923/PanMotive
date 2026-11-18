<?php

namespace App\Services\Seller\Import;

/**
 * Reads the first worksheet of an Excel workbook (.xlsx — also what Google Sheets and Numbers
 * export) into plain rows of strings, without any extra library: an .xlsx file is a zip of XML.
 * Shopify, Amazon and eBay templates are often opened in Excel and saved as .xlsx, sometimes
 * still named ".csv".
 */
class XlsxReader
{
    public static function isXlsx(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        $head = $fh ? (string) fread($fh, 4) : '';
        $fh && fclose($fh);

        return $head === "PK\x03\x04";
    }

    /**
     * @return list<list<string>>
     *
     * @throws \InvalidArgumentException when the zip is not a readable workbook
     */
    public static function rows(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Not a readable Excel file.');
        }
        try {
            $sheetPath = self::firstSheetPath($zip);
            $xml = $sheetPath ? $zip->getFromName($sheetPath) : false;
            if ($xml === false) {
                throw new \InvalidArgumentException('This Excel file has no worksheet we can read.');
            }
            $strings = self::sharedStrings($zip);
        } finally {
            $zip->close();
        }

        $rows = [];
        $reader = new \XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $row = null;
        $rowIndex = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'row') {
                if ($row !== null) {
                    $rows[$rowIndex] = $row;
                }
                $rowIndex = (int) ($reader->getAttribute('r') ?: $rowIndex + 1);
                $row = [];
                if ($reader->isEmptyElement) {
                    $rows[$rowIndex] = $row;
                    $row = null;
                }
            } elseif ($reader->localName === 'c' && $row !== null) {
                $ref = (string) $reader->getAttribute('r');
                $col = $ref !== '' ? self::columnIndex($ref) : count($row);
                $type = (string) $reader->getAttribute('t');
                $cell = new \SimpleXMLElement($reader->readOuterXml());
                $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $value = match ($type) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => self::text($cell->is),
                    'b' => ((string) $cell->v) === '1' ? 'TRUE' : 'FALSE',
                    default => self::number((string) $cell->v),
                };
                $row[$col] = $value;
            }
        }
        if ($row !== null) {
            $rows[$rowIndex] = $row;
        }
        $reader->close();

        // Fill gaps: empty cells and blank rows aren't stored in the file.
        ksort($rows);
        $out = [];
        $width = 0;
        foreach ($rows as $cells) {
            $width = max($width, $cells ? max(array_keys($cells)) + 1 : 0);
        }
        foreach ($rows as $cells) {
            $line = array_fill(0, $width, '');
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            $out[] = $line;
        }

        return $out;
    }

    private static function firstSheetPath(\ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook && $rels) {
            $wb = simplexml_load_string($workbook, options: LIBXML_NONET);
            $rel = simplexml_load_string($rels, options: LIBXML_NONET);
            $sheet = $wb?->sheets?->sheet[0] ?? null;
            if ($sheet && $rel) {
                $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rel->Relationship as $r) {
                    if ((string) $r['Id'] === $rid) {
                        $target = ltrim((string) $r['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }
        }

        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }

    /** @return list<string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $sst = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_PARSEHUGE);
        $out = [];
        foreach ($sst?->si ?? [] as $si) {
            $out[] = self::text($si);
        }

        return $out;
    }

    /** A string item: plain <t>, or rich text runs <r><t>. */
    private static function text(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r ?? [] as $run) {
            $s .= (string) $run->t;
        }

        return $s;
    }

    /** "12.5" stays; Excel float noise like "19.989999999999998" becomes "19.99". */
    private static function number(string $v): string
    {
        if ($v !== '' && is_numeric($v) && str_contains($v, '.') && strlen($v) > 12) {
            return rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
        }

        return $v;
    }

    /** "C12" → 2 (zero-based). */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/\d+/', '', $ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
