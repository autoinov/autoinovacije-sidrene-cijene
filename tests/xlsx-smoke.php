<?php

define('ABSPATH', __DIR__);

final class WP_Error {
    private $poruka;
    public function __construct($kod, $poruka) {
        $this->poruka = $poruka;
    }
    public function get_error_message() {
        return $this->poruka;
    }
}

function is_wp_error($vrijednost) {
    return $vrijednost instanceof WP_Error;
}

function wp_delete_file($putanja) {
    return !file_exists($putanja) || unlink($putanja);
}

require dirname(__DIR__) . '/includes/class-ai-sidrene-xlsx.php';

$putanja = tempnam(sys_get_temp_dir(), 'ai-xlsx-test-') . '.xlsx';
$zaglavlje = array('product_id', 'sku', 'anchor_date', 'anchor_price');
$retci = array(
    array('42', '=OPASNO', '10.09.2026.', '19.99'),
    array('43', 'ČĆŽŠĐ', '02.05.2025.', '29.50'),
);

$rezultat = AI_Sidrene_XLSX::izradi($putanja, $zaglavlje, $retci);
if (is_wp_error($rezultat)) {
    fwrite(STDERR, $rezultat->get_error_message() . PHP_EOL);
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($putanja) !== true || $zip->locateName('xl/worksheets/sheet1.xml') === false) {
    fwrite(STDERR, "XLSX ZIP struktura nije valjana.\n");
    exit(1);
}
$zip->close();

$procitano = AI_Sidrene_XLSX::procitaj($putanja);
@unlink($putanja);
if (is_wp_error($procitano) || $procitano !== array_merge(array($zaglavlje), $retci)) {
    fwrite(STDERR, "XLSX povratno čitanje nije zadržalo podatke.\n");
    exit(1);
}

fwrite(STDOUT, "XLSX smoke test: OK\n");
