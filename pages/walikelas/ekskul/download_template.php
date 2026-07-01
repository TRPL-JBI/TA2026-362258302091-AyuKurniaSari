<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

$filename = 'template_ekskul.csv';

/*
 * Template CSV dibuat dengan delimiter titik koma (;).
 *
 * Kenapa bukan koma?
 * Karena di banyak setting regional Indonesia, Microsoft Excel membaca CSV
 * menggunakan separator titik koma (;). Kalau pakai koma, Excel sering
 * menampilkan semua data dalam 1 kolom.
 */
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

if (!$output) {
    exit;
}

/*
 * BOM UTF-8.
 *
 * Kenapa perlu?
 * Supaya Excel membaca file sebagai UTF-8, sehingga teks seperti nama
 * ekstrakurikuler, huruf Indonesia, dan karakter khusus tidak rusak.
 */
fwrite($output, "\xEF\xBB\xBF");

/*
 * Baris sep=; adalah instruksi khusus yang dikenali Excel.
 *
 * Kenapa perlu?
 * Supaya Excel langsung memecah CSV menjadi kolom berdasarkan titik koma (;),
 * bukan menjadikannya 1 kolom.
 *
 * Catatan:
 * Baris ini nanti akan di-skip oleh file import.
 */
fwrite($output, "sep=;\r\n");

$delimiter = ';';

fputcsv($output, ['nisn', 'ekskul', 'predikat'], $delimiter);
fputcsv($output, ['1234567890', 'Pramuka', 'A'], $delimiter);
fputcsv($output, ['1234567891', 'Futsal', 'B'], $delimiter);

fclose($output);
exit;