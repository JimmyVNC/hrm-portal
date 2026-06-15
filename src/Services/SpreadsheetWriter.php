<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\FileCrypto;

/**
 * SpreadsheetWriter — Ghi dữ liệu 2D array ra file .xlsx
 * Dùng ZipArchive + XML thuần, không cần thư viện bên ngoài.
 *
 * Sử dụng:
 *   SpreadsheetWriter::toXlsx($rows, '/path/to/output.xlsx');
 *
 * $rows = [
 *   ['MÃ NV', 'HỌ TÊN', 'MẬT KHẨU', 'BỘ PHẬN'],
 *   ['001',   'Nguyễn A', 'pass123', 'KD'],
 *   ...
 * ];
 */
class SpreadsheetWriter
{
    private function __construct() {}

    /**
     * Ghi $rows thành file XLSX tại $outputPath.
     * @param array  $rows       Mảng 2D (dòng × cột), dòng đầu là header.
     * @param string $outputPath Đường dẫn tuyệt đối đến file output.
     * @return bool  true nếu thành công.
     */
    public static function toXlsx(array $rows, string $outputPath): bool
    {
        if (!class_exists('ZipArchive')) {
            error_log('[SpreadsheetWriter] ZipArchive extension is not available.');
            return false;
        }

        // Thu thập shared strings
        $sharedStrings = [];
        $sharedIndex   = [];
        $sheetXmlRows  = [];

        foreach ($rows as $rowIdx => $row) {
            $rowNum  = $rowIdx + 1;
            $cellXml = '';
            foreach ((array) $row as $colIdx => $value) {
                $colLetter = self::colLetter($colIdx);
                $cellRef   = $colLetter . $rowNum;
                $value     = (string) $value;

                // Luôn lưu là shared string để giữ nguyên leading zeros, ký tự đặc biệt
                if (!isset($sharedIndex[$value])) {
                    $sharedIndex[$value] = count($sharedStrings);
                    $sharedStrings[]     = $value;
                }
                $si = $sharedIndex[$value];

                $cellXml .= '<c r="' . $cellRef . '" t="s"><v>' . $si . '</v></c>';
            }
            $sheetXmlRows[] = '<row r="' . $rowNum . '">' . $cellXml . '</row>';
        }

        // Build shared strings XML
        $ssCount  = count($sharedStrings);
        $ssXmlItems = '';
        foreach ($sharedStrings as $s) {
            $ssXmlItems .= '<si><t xml:space="preserve">' . self::xmlEsc($s) . '</t></si>';
        }

        $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . $ssCount . '" uniqueCount="' . $ssCount . '">'
            . $ssXmlItems
            . '</sst>';

        // Build sheet XML
        $sheetRows   = implode('', $sheetXmlRows);
        $totalRows   = count($rows);
        $totalCols   = 0;
        foreach ($rows as $r) {
            $totalCols = max($totalCols, count((array) $r));
        }
        $dimRef = 'A1:' . self::colLetter($totalCols - 1) . $totalRows;

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . $dimRef . '"/>'
            . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '</worksheet>';

        // Styles (minimal)
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';

        // workbook.xml
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        // workbook rels
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        // root rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        // Write zip
        $zip = new \ZipArchive();
        $tmpPath = $outputPath . '.tmp_' . bin2hex(random_bytes(4));
        if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            error_log('[SpreadsheetWriter] Cannot create zip at: ' . $tmpPath);
            return false;
        }

        $zip->addFromString('[Content_Types].xml',                $contentTypes);
        $zip->addFromString('_rels/.rels',                        $rootRels);
        $zip->addFromString('xl/workbook.xml',                    $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels',         $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml',           $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml',               $sharedStringsXml);
        $zip->addFromString('xl/styles.xml',                      $stylesXml);
        $zip->close();

        if (!@rename($tmpPath, $outputPath)) {
            @unlink($tmpPath);
            return false;
        }
        @chmod($outputPath, 0600);

        return FileCrypto::encryptFileInPlace($outputPath);
    }

    /**
     * Chuyển index cột (0-based) sang chữ cái: 0→A, 25→Z, 26→AA...
     */
    private static function colLetter(int $index): string
    {
        $letter = '';
        $n = $index;
        while ($n >= 0) {
            $letter = chr(65 + ($n % 26)) . $letter;
            $n      = intdiv($n, 26) - 1;
        }
        return $letter;
    }

    /**
     * Escape ký tự XML.
     */
    private static function xmlEsc(string $s): string
    {
        return str_replace(
            ['&',    '<',   '>',   '"',    "'",    "\x00", "\x0B", "\x0C", "\x0E", "\x0F",
             "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17",
             "\x18", "\x19", "\x1A", "\x1B", "\x1C", "\x1D", "\x1E", "\x1F"],
            ['&amp;','&lt;','&gt;','&quot;','&apos;', '', '', '', '', '',
             '', '', '', '', '', '', '', '',
             '', '', '', '', '', '', '', ''],
            $s
        );
    }
}
