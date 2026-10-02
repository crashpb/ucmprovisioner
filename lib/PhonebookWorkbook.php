<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Literal-text XLSX export with optional publication metadata, using the existing ZIP extension. */
final class PhonebookWorkbook
{
    public static function create(array $rows, array $columns, array $labels, array $metadata = []): string
    {
        if (!class_exists(\ZipArchive::class)) { throw new \RuntimeException('PHP ZIP is required for Excel export.'); }
        if (!$columns || count($columns) > count(WebPhonebook::FIELDS) || count($rows) > WebPhonebook::MAX_CONTACTS) {
            throw new \InvalidArgumentException('Unsupported workbook size.');
        }
        foreach ($columns as $column) { if (!is_string($column) || !isset($labels[$column])) { throw new \InvalidArgumentException('Invalid workbook column.'); } }
        $xml = static fn(string $value): string => htmlspecialchars(WebPhonebook::text($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $last = chr(64 + count($columns)) . (count($rows) + 1);
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $last . '"/><sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/><cols>';
        foreach ($columns as $index => $column) {
            $width = match ($column) { 'extension','employee'=>16, 'email'=>34, 'name'=>26, default=>24 };
            $sheet .= '<col min="' . ($index+1) . '" max="' . ($index+1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData>';
        $header = array_intersect_key($labels, array_fill_keys($columns, true));
        foreach ([$header,...$rows] as $rowIndex => $row) {
            $sheet .= '<row r="' . ($rowIndex+1) . '">';
            foreach ($columns as $columnIndex => $column) {
                // Explicit inline strings preserve leading zeroes and + signs and never
                // evaluate contact text as a formula, hyperlink or external workbook link.
                $sheet .= '<c r="' . chr(65+$columnIndex) . ($rowIndex+1) . '" t="inlineStr" s="' . ($rowIndex === 0 ? '1' : '0')
                    . '"><is><t xml:space="preserve">' . $xml((string)($row[$column] ?? '')) . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData><autoFilter ref="A1:' . $last . '"/>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="landscape"/></worksheet>';
        $parts = [
            '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="Contacts" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml'=>$sheet,
        ];
        if ($metadata) {
            $meta = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            $rowNumber = 0;
            foreach ($metadata as $key => $value) {
                $rowNumber++;
                $meta .= '<row r="'.$rowNumber.'"><c r="A'.$rowNumber.'" t="inlineStr"><is><t>'.$xml((string)$key).'</t></is></c><c r="B'.$rowNumber.'" t="inlineStr"><is><t>'.$xml((string)$value).'</t></is></c></row>';
            }
            $parts['xl/worksheets/sheet2.xml'] = $meta . '</sheetData></worksheet>';
            $parts['[Content_Types].xml'] = str_replace('</Types>', '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>', $parts['[Content_Types].xml']);
            $parts['xl/workbook.xml'] = str_replace('</sheets>', '<sheet name="Publication" sheetId="2" r:id="rId3"/></sheets>', $parts['xl/workbook.xml']);
            $parts['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>', $parts['xl/_rels/workbook.xml.rels']);
        }
        $path = tempnam(sys_get_temp_dir(), 'ucmp-webbook-');
        if ($path === false) { throw new \RuntimeException('Could not create the workbook.'); }
        $zip = new \ZipArchive();
        $open = false;
        try {
            if (!@chmod($path, 0600) || $zip->open($path, \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Could not open the workbook.'); }
            $open = true;
            foreach ($parts as $name => $content) { if (!$zip->addFromString($name, $content)) { throw new \RuntimeException('Could not write the workbook.'); } }
            $closed = $zip->close(); $open = false;
            if (!$closed) { throw new \RuntimeException('Could not finish the workbook.'); }
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) { throw new \RuntimeException('Could not read the workbook.'); }
            return $bytes;
        } finally {
            if ($open) { $zip->close(); }
            @unlink($path);
        }
    }
}
