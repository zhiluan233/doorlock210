<?php
/*

轻量级 Excel xlsx 读取模块
Ver 1.0.0.0 20260723
Code by Jason / Codex

*/
namespace anim210System;

class SimpleXlsxReader {

    public static function readFirstSheet($filePath)
    {
        if (!class_exists('\\ZipArchive')) {
            throw new \RuntimeException('服务器未启用 ZipArchive，无法读取 xlsx 文件');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('无法打开 xlsx 文件');
        }

        try {
            $sharedStrings = self::readSharedStrings($zip);
            $dateStyles = self::readDateStyles($zip);
            $sheetPath = self::firstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new \RuntimeException('未找到工作表内容');
            }
            return self::readSheetRows($sheetXml, $sharedStrings, $dateStyles);
        } finally {
            $zip->close();
        }
    }

    private static function readSharedStrings($zip)
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            throw new \RuntimeException('共享字符串解析失败');
        }
        $strings = [];
        foreach ($doc->si as $si) {
            $texts = $si->xpath('.//*[local-name()="t"]');
            $value = '';
            if (is_array($texts)) {
                foreach ($texts as $text) {
                    $value .= (string)$text;
                }
            }
            $strings[] = $value;
        }
        return $strings;
    }

    private static function readDateStyles($zip)
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            throw new \RuntimeException('样式表解析失败');
        }

        $customFormats = [];
        $numFmts = $doc->xpath('//*[local-name()="numFmt"]');
        if (is_array($numFmts)) {
            foreach ($numFmts as $fmt) {
                $id = intval($fmt['numFmtId']);
                $customFormats[$id] = (string)$fmt['formatCode'];
            }
        }

        $dateStyles = [];
        $cellXfs = $doc->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]');
        if (is_array($cellXfs)) {
            foreach ($cellXfs as $index => $xf) {
                $numFmtId = intval($xf['numFmtId']);
                if (self::isDateNumFmt($numFmtId, $customFormats[$numFmtId] ?? '')) {
                    $dateStyles[$index] = true;
                }
            }
        }
        return $dateStyles;
    }

    private static function isDateNumFmt($numFmtId, $formatCode)
    {
        if (in_array($numFmtId, [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57], true)) {
            return true;
        }
        $formatCode = strtolower((string)$formatCode);
        $formatCode = preg_replace('/"[^"]*"|\\[[^\\]]*\\]/', '', $formatCode);
        return preg_match('/[ymdhis]/', $formatCode) === 1;
    }

    private static function firstSheetPath($zip)
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = simplexml_load_string($workbookXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $rels = simplexml_load_string($relsXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $sheetNodes = $workbook->xpath('//*[local-name()="sheet"]');
        if (!is_array($sheetNodes) || count($sheetNodes) === 0) {
            return 'xl/worksheets/sheet1.xml';
        }
        $attrs = $sheetNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)($attrs['id'] ?? '');
        if ($rid === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] !== $rid) {
                continue;
            }
            $target = str_replace('\\', '/', (string)$rel['Target']);
            if (strpos($target, '/') === 0) {
                return ltrim($target, '/');
            }
            return 'xl/' . ltrim($target, '/');
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function readSheetRows($sheetXml, $sharedStrings, $dateStyles)
    {
        $doc = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            throw new \RuntimeException('工作表解析失败');
        }

        $rows = [];
        $rowNodes = $doc->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if (!is_array($rowNodes)) {
            return [];
        }

        foreach ($rowNodes as $rowNode) {
            $rowIndex = intval($rowNode['r']);
            if ($rowIndex <= 0) {
                $rowIndex = count($rows) + 1;
            }
            $rows[$rowIndex] = [];
            $cells = $rowNode->xpath('./*[local-name()="c"]');
            if (!is_array($cells)) {
                continue;
            }
            foreach ($cells as $cell) {
                $ref = (string)$cell['r'];
                $colIndex = self::columnIndexFromRef($ref);
                if ($colIndex <= 0) {
                    continue;
                }
                $rows[$rowIndex][$colIndex] = self::cellValue($cell, $sharedStrings, $dateStyles);
            }
        }
        ksort($rows);
        return $rows;
    }

    private static function cellValue($cell, $sharedStrings, $dateStyles)
    {
        $type = (string)($cell['t'] ?? '');
        $styleIndex = intval($cell['s'] ?? 0);
        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//*[local-name()="t"]');
            $value = '';
            if (is_array($texts)) {
                foreach ($texts as $text) {
                    $value .= (string)$text;
                }
            }
            return trim($value);
        }

        $values = $cell->xpath('./*[local-name()="v"]');
        $value = is_array($values) && count($values) > 0 ? (string)$values[0] : '';
        if ($type === 's') {
            return trim((string)($sharedStrings[intval($value)] ?? ''));
        }
        if ($type === 'b') {
            return $value === '1' ? 'true' : 'false';
        }
        if ($value !== '' && isset($dateStyles[$styleIndex]) && is_numeric($value)) {
            return self::excelSerialToDateText(floatval($value));
        }
        return trim((string)$value);
    }

    private static function columnIndexFromRef($ref)
    {
        if (!preg_match('/^([A-Z]+)/i', (string)$ref, $matches)) {
            return 0;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }
        return $index;
    }

    private static function excelSerialToDateText($serial)
    {
        if ($serial <= 0) {
            return '';
        }
        $seconds = ($serial - 25569) * 86400;
        $rounded = intval(round($seconds));
        if (abs($serial - floor($serial)) > 0.000001) {
            return gmdate('Y-m-d H:i:s', $rounded);
        }
        return gmdate('Y-m-d', $rounded);
    }
}
