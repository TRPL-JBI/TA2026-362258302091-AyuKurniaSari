<?php

require_once(__DIR__.'/../../../../../config.php');

require_login();

$filename = "template_pkl.csv";

// HEADER CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

// OPEN OUTPUT
$output = fopen('php://output', 'w');

/*
=====================================
HEADER KOLOM (WAJIB KONSISTEN)
=====================================
*/

fputcsv($output, [
    'nisn',
    'mitra',
    'waktu_mulai',
    'waktu_selesai',
    'nilai'
]);

/*
=====================================
CONTOH DATA (BIAR USER PAHAM FORMAT)
=====================================
*/

fputcsv($output, [
    '360000000112',     // NISN (string biar tidak hilang 0)
    'PT Contoh',        // nama mitra (HARUS sama dengan DB)              
    '2026-03-01',       // format YYYY-MM-DD
    '2026-06-01',       // format YYYY-MM-DD
    '85'                // nilai
]);

fclose($output);
exit;