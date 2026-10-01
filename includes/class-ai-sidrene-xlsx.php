<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Minimalni XLSX čitač/pisač za kontrolirani predložak dodatka. */
final class AI_Sidrene_XLSX
{
    public static function posalji($naziv, $zaglavlje, $retci)
    {
        $privremena = wp_tempnam($naziv);
        $rezultat = self::izradi($privremena, $zaglavlje, $retci);
        if (is_wp_error($rezultat)) {
            wp_delete_file($privremena);
            return $rezultat;
        }
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($naziv) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($privremena));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the generated workbook and avoids duplicating it in memory.
        readfile($privremena);
        wp_delete_file($privremena);
        exit;
    }

    /** Izrađuje XLSX na zadanoj putanji; izdvojeno radi pouzdanog testiranja i ponovne uporabe. */
    public static function izradi($putanja, $zaglavlje, $retci)
    {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('ai_xlsx_zip', 'PHP ZipArchive nije dostupan.');
        }
        $zip = new ZipArchive();
        if ($zip->open($putanja, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('ai_xlsx_open', 'Excel datoteku nije moguće izraditi.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sidrene cijene" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sheet_path = tempnam(sys_get_temp_dir(), 'ai-xlsx-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The worksheet is streamed to keep memory usage bounded on large stores.
        $sheet = fopen($sheet_path, 'wb');
        if (!$sheet) {
            $zip->close();
            wp_delete_file($putanja);
            return new WP_Error('ai_xlsx_sheet', 'Privremeni Excel list nije moguće otvoriti.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to the private temporary worksheet stream.
        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $broj = 1;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to the private temporary worksheet stream.
        fwrite($sheet, self::redak($broj++, $zaglavlje));
        foreach ($retci as $redak) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to the private temporary worksheet stream.
            fwrite($sheet, self::redak($broj++, $redak));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Completes the private temporary worksheet stream.
        fwrite($sheet, '</sheetData></worksheet>');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the private worksheet stream opened above.
        fclose($sheet);
        $dodano = $zip->addFile($sheet_path, 'xl/worksheets/sheet1.xml');
        $zatvoreno = $zip->close();
        wp_delete_file($sheet_path);
        if (!$dodano || !$zatvoreno || !is_file($putanja) || filesize($putanja) === 0) {
            wp_delete_file($putanja);
            return new WP_Error('ai_xlsx_write', 'Excel datoteku nije moguće dovršiti.');
        }
        return true;
    }

    private static function redak($broj, $vrijednosti)
    {
        $xml = '<row r="' . (int) $broj . '">';
        foreach (array_values($vrijednosti) as $indeks => $vrijednost) {
            $referenca = self::stupac($indeks + 1) . $broj;
            $sigurno = htmlspecialchars((string) $vrijednost, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xml .= '<c r="' . $referenca . '" t="inlineStr"><is><t xml:space="preserve">' . $sigurno . '</t></is></c>';
        }
        return $xml . '</row>';
    }

    private static function stupac($broj)
    {
        $slova = '';
        while ($broj > 0) {
            $broj--;
            $slova = chr(65 + ($broj % 26)) . $slova;
            $broj = intdiv($broj, 26);
        }
        return $slova;
    }

    public static function procitaj($putanja)
    {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('ai_xlsx_zip', 'PHP ZipArchive nije dostupan.');
        }
        $zip = new ZipArchive();
        if ($zip->open($putanja) !== true) {
            return new WP_Error('ai_xlsx_open', 'Excel datoteku nije moguće otvoriti.');
        }
        $sheet_stat = $zip->statName('xl/worksheets/sheet1.xml');
        $shared_stat = $zip->statName('xl/sharedStrings.xml');
        if (($sheet_stat && $sheet_stat['size'] > 25 * 1024 * 1024) || ($shared_stat && $shared_stat['size'] > 25 * 1024 * 1024)) {
            $zip->close();
            return new WP_Error('ai_xlsx_size', 'Raspakirana Excel datoteka prelazi dopuštenu veličinu.');
        }
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        if ($sheet_xml === false) {
            return new WP_Error('ai_xlsx_sheet', 'Prvi Excel list nije pronađen.');
        }
        $prethodne_libxml_pogreske = libxml_use_internal_errors(true);
        $shared = array();
        if ($shared_xml) {
            $doc = simplexml_load_string($shared_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($doc) {
                foreach ($doc->si as $si) {
                    $shared[] = (string) $si->t;
                }
            }
        }
        $doc = simplexml_load_string($sheet_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (!$doc) {
            libxml_clear_errors();
            libxml_use_internal_errors($prethodne_libxml_pogreske);
            return new WP_Error('ai_xlsx_xml', 'Excel XML nije valjan.');
        }
        $retci = array();
        foreach ($doc->sheetData->row as $row) {
            $vrijednosti = array();
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                preg_match('/^[A-Z]+/', $ref, $m);
                $index = self::indeks_stupca($m[0] ?? 'A');
                $tip = (string) $cell['t'];
                if ($tip === 'inlineStr') {
                    $value = (string) $cell->is->t;
                } elseif ($tip === 's') {
                    $value = $shared[(int) $cell->v] ?? '';
                } else {
                    $value = (string) $cell->v;
                }
                $vrijednosti[$index] = $value;
            }
            if ($vrijednosti) {
                ksort($vrijednosti);
                $retci[] = array_replace(array_fill(0, max(array_keys($vrijednosti)) + 1, ''), $vrijednosti);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prethodne_libxml_pogreske);
        return $retci;
    }

    private static function indeks_stupca($slova)
    {
        $broj = 0;
        foreach (str_split($slova) as $slovo) {
            $broj = $broj * 26 + ord($slovo) - 64;
        }
        return max(0, $broj - 1);
    }
}
