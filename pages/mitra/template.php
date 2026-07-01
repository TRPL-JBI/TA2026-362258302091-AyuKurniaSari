<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$filename = 'template_mitra.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

/*
 * Baris sep=; membuat Excel langsung membaca pemisah kolom sebagai titik koma.
 * Jadi ketika dibuka di Excel, kolom langsung terpisah:
 * nama | alamat | kontak
 */
fwrite($output, "sep=;\r\n");

/*
 * Delimiter titik koma dipakai supaya cocok dengan Excel regional Indonesia.
 */
fputcsv($output, ['nama', 'alamat', 'kontak'], ';');
fputcsv($output, ['PT Contoh Sejahtera', 'Banyuwangi', '08123456789'], ';');
fputcsv($output, ['CV Mitra Mandiri', 'Rogojampi', '08123456780'], ';');

fclose($output);
exit;