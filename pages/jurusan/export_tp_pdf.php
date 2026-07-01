<?php

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $CFG;

$kmid = required_param('kmid', PARAM_INT);
$cpid = optional_param('cpid', 0, PARAM_INT);
$filtercourseid = optional_param('filtercourseid', 0, PARAM_INT);

$dompdfautoload = $CFG->dirroot . '/local/akademikmonitor/lib/dompdf/autoload.inc.php';
if (!file_exists($dompdfautoload)) {
    throw new moodle_exception('Dompdf belum tersedia di folder local/akademikmonitor/lib/dompdf.');
}
require_once($dompdfautoload);

use Dompdf\Dompdf;
use Dompdf\Options;

function local_akademikmonitor_export_pdf_safe_filename(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
    $safe = trim((string)$safe, '_');
    return $safe !== '' ? $safe : 'export_tp_pdf';
}

function local_akademikmonitor_export_pdf_first_field(?stdClass $record, array $fields, string $fallback = '-'): string {
    if (!$record) {
        return $fallback;
    }
    foreach ($fields as $field) {
        if (property_exists($record, $field) && trim((string)$record->{$field}) !== '') {
            return trim((string)$record->{$field});
        }
    }
    return $fallback;
}

function local_akademikmonitor_export_pdf_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function local_akademikmonitor_export_pdf_teacher_name(int $courseid): string {
    global $DB;
    if ($courseid <= 0) {
        return '-';
    }

    $ctx = context_course::instance($courseid, IGNORE_MISSING);
    if (!$ctx) {
        return '-';
    }

    $teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, ra.id AS raid
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.contextid = :ctxid
            AND r.shortname = :roleshortname
          ORDER BY ra.id DESC",
        ['ctxid' => $ctx->id, 'roleshortname' => 'editingteacher'],
        0,
        1
    );

    if (!$teachers) {
        return '-';
    }

    return fullname(reset($teachers));
}

$km = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);
$mapel = $DB->get_record('mata_pelajaran', ['id' => $km->id_mapel], '*', IGNORE_MISSING);
$nama_mapel_raw = $mapel ? (string)$mapel->nama_mapel : 'Mata Pelajaran';
$nama_mapel = trim(preg_replace('/^\[.*?\]\s*/', '', $nama_mapel_raw));
if ($nama_mapel === '') {
    $nama_mapel = $nama_mapel_raw ?: 'Mata Pelajaran';
}

$nama_sekolah = get_config('local_akademikmonitor', 'nama_sekolah') ?: 'SMK PGRI 2 Giri';
$nama_jurusan = '-';
$nama_kurikulum = '-';
$tahun_ajaran = '-';

$kj = $DB->get_record('kurikulum_jurusan', ['id' => $km->id_kurikulum_jurusan], '*', IGNORE_MISSING);
if ($kj) {
    $jur = $DB->get_record('jurusan', ['id' => $kj->id_jurusan], '*', IGNORE_MISSING);
    $nama_jurusan = local_akademikmonitor_export_pdf_first_field($jur, ['nama_jurusan', 'nama'], '-');

    $kur = $DB->get_record('kurikulum', ['id' => $kj->id_kurikulum], '*', IGNORE_MISSING);
    $nama_kurikulum = local_akademikmonitor_export_pdf_first_field($kur, ['nama', 'nama_kurikulum'], '-');

    $ta = $DB->get_record('tahun_ajaran', ['id' => $kj->id_tahun_ajaran], '*', IGNORE_MISSING);
    $tahun_ajaran = local_akademikmonitor_export_pdf_first_field($ta, ['tahun_ajaran', 'nama'], '-');
}

$tingkat = strtoupper(trim((string)($km->tingkat_kelas ?? 'X')));
$fase_map = ['X' => 'E', 'XI' => 'F', 'XII' => 'F', 'XIII' => 'F'];
$fase = ($fase_map[$tingkat] ?? 'E') . ' / Semester ' . (stripos($nama_mapel_raw, 'genap') !== false ? 'genap' : $tahun_ajaran);

$jp_per_konten = (int)($km->jam_pelajaran ?? 0);

$tpcols = $DB->get_columns('tujuan_pembelajaran');
$has_konten = isset($tpcols['konten']);
$has_id_course = isset($tpcols['id_course']);

$cpcols = $DB->get_columns('capaian_pembelajaran');
$has_cp_elemen = isset($cpcols['elemen']);
$kmcols = $DB->get_columns('kurikulum_mapel');
$has_km_elemen = isset($kmcols['elemen']);

if ($cpid > 0) {
    $singlecp = $DB->get_record('capaian_pembelajaran', [
        'id' => $cpid,
        'id_kurikulum_mapel' => $kmid,
    ], '*', MUST_EXIST);
    $cps = [$singlecp->id => $singlecp];
} else {
    $cps = $DB->get_records('capaian_pembelajaran', ['id_kurikulum_mapel' => $kmid], 'id ASC');
}

$elemen_parts = [];
$cp_parts = [];
$rows_raw = [];

foreach ($cps as $cp) {
    if ($has_cp_elemen && trim((string)($cp->elemen ?? '')) !== '') {
        $elemen_parts[trim((string)$cp->elemen)] = true;
    }
    if (trim((string)($cp->deskripsi ?? '')) !== '') {
        $cp_parts[] = trim((string)$cp->deskripsi);
    }

    $where = ['id_capaian_pembelajaran' => $cp->id];
    if ($has_id_course && $filtercourseid > 0) {
        $where['id_course'] = $filtercourseid;
    }
    $tps = $DB->get_records('tujuan_pembelajaran', $where, 'id ASC');

    foreach ($tps as $tp) {
        $konten = $has_konten ? trim((string)($tp->konten ?? '')) : '';
        if ($konten === '') {
            $konten = '-';
        }
        $rows_raw[] = [
            'konten' => $konten,
            'kompetensi' => trim((string)($tp->kompetensi ?? '')),
            'dpl' => trim((string)($tp->dpl ?? '')),
            'atp' => trim((string)($tp->atp ?? '')),
            'deskripsi' => trim((string)($tp->deskripsi ?? '')),
            'jp' => $jp_per_konten > 0 ? $jp_per_konten : '',
        ];
    }
}

$elemen = !empty($elemen_parts) ? implode(' | ', array_keys($elemen_parts)) : '-';
if ($elemen === '-' && $has_km_elemen && trim((string)($km->elemen ?? '')) !== '') {
    $elemen = trim((string)$km->elemen);
}
$cp_header = !empty($cp_parts) ? implode(' ', $cp_parts) : '-';

$course_name = '';
$teacher_name = '';
if ($filtercourseid > 0 && $DB->record_exists('course', ['id' => $filtercourseid])) {
    $course_name = (string)$DB->get_field('course', 'fullname', ['id' => $filtercourseid]);
    $teacher_name = local_akademikmonitor_export_pdf_teacher_name($filtercourseid);
}

// Hitung rowspan berdasarkan konten berurutan, seperti contoh PDF analisis.
$blocks = [];
$current = null;
foreach ($rows_raw as $idx => $row) {
    if ($current === null || $current['konten'] !== $row['konten']) {
        if ($current !== null) {
            $blocks[] = $current;
        }
        $current = [
            'no' => count($blocks) + 1,
            'konten' => $row['konten'],
            'jp' => $row['jp'],
            'rows' => [],
        ];
    }
    $current['rows'][] = $row;
}
if ($current !== null) {
    $blocks[] = $current;
}

$total_jp = 0;
foreach ($blocks as $block) {
    if (is_numeric($block['jp'])) {
        $total_jp += (int)$block['jp'];
    }
}

$bodyrows = '';
foreach ($blocks as $block) {
    $rowspan = max(1, count($block['rows']));
    foreach ($block['rows'] as $i => $row) {
        $bodyrows .= '<tr>';
        if ($i === 0) {
            $bodyrows .= '<td class="center top" rowspan="' . $rowspan . '">' . (int)$block['no'] . '</td>';
            $bodyrows .= '<td class="top" rowspan="' . $rowspan . '">' . nl2br(local_akademikmonitor_export_pdf_e($block['konten'])) . '</td>';
        }
        $bodyrows .= '<td class="top">' . nl2br(local_akademikmonitor_export_pdf_e($row['kompetensi'])) . '</td>';
        $bodyrows .= '<td class="top">' . nl2br(local_akademikmonitor_export_pdf_e($row['dpl'])) . '</td>';
        $bodyrows .= '<td class="center top">' . nl2br(local_akademikmonitor_export_pdf_e($row['atp'])) . '</td>';
        $bodyrows .= '<td class="justify top">' . nl2br(local_akademikmonitor_export_pdf_e($row['deskripsi'])) . '</td>';
        if ($i === 0) {
            $bodyrows .= '<td class="center top" rowspan="' . $rowspan . '">' . local_akademikmonitor_export_pdf_e((string)$block['jp']) . '</td>';
        }
        $bodyrows .= '</tr>';
    }
}

if ($bodyrows === '') {
    $bodyrows = '<tr><td colspan="7" class="center">Belum ada Tujuan Pembelajaran.</td></tr>';
}

$coursemeta = '';
if ($course_name !== '') {
    $coursemeta .= '<tr><td class="label">Course / Kelas</td><td class="colon">:</td><td>' . local_akademikmonitor_export_pdf_e($course_name) . '</td></tr>';
}
if ($teacher_name !== '') {
    $coursemeta .= '<tr><td class="label">Guru Pengampu</td><td class="colon">:</td><td>' . local_akademikmonitor_export_pdf_e($teacher_name) . '</td></tr>';
}

$html = '<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { size: A4 landscape; margin: 24mm 16mm 18mm 16mm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; }
    h1 { text-align: center; font-size: 13px; margin: 0 0 22px 0; font-weight: normal; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .meta td { border: none; padding: 3px 4px; vertical-align: top; font-size: 11px; }
    .meta .label { width: 145px; }
    .meta .colon { width: 10px; text-align: center; }
    .meta .cp { text-align: justify; line-height: 1.35; }
    table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.data th, table.data td { border: 1px solid #000; padding: 5px 6px; vertical-align: top; line-height: 1.25; }
    table.data th { text-align: left; font-weight: bold; }
    .center { text-align: center; }
    .justify { text-align: justify; }
    .top { vertical-align: top; }
    .total td { font-weight: bold; }
</style>
</head>
<body>
    <h1>ANALISIS CAPAIAN PEMBELAJARAN DAN ALUR TUJUAN PEMBELAJARAN</h1>
    <table class="meta">
        <tr><td class="label">Nama Sekolah</td><td class="colon">:</td><td>' . local_akademikmonitor_export_pdf_e($nama_sekolah) . '</td></tr>
        <tr><td class="label">Mata pelajaran</td><td class="colon">:</td><td>' . local_akademikmonitor_export_pdf_e($nama_mapel) . '</td></tr>
        <tr><td class="label">Fase</td><td class="colon">:</td><td>' . local_akademikmonitor_export_pdf_e($fase) . '</td></tr>
        <tr><td class="label">Elemen</td><td class="colon">:</td><td>' . local_akademikmonitor_export_pdf_e($elemen) . '</td></tr>
        <tr><td class="label">CP</td><td class="colon">:</td><td class="cp">' . local_akademikmonitor_export_pdf_e($cp_header) . '</td></tr>
        ' . $coursemeta . '
    </table>
    <table class="data">
        <thead>
            <tr>
                <th style="width:4%;">No</th>
                <th style="width:14%;">Konten</th>
                <th style="width:18%;">Kompetensi</th>
                <th style="width:14%;">DPL</th>
                <th style="width:6%;">ATP</th>
                <th style="width:39%;">Tujuan Pembelajaran</th>
                <th style="width:5%;">JP</th>
            </tr>
        </thead>
        <tbody>
            ' . $bodyrows . '
            <tr class="total"><td colspan="6" class="right" style="text-align:right;">Jumlah JP</td><td class="center">' . ($total_jp > 0 ? (int)$total_jp : '') . '</td></tr>
        </tbody>
    </table>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$safe = local_akademikmonitor_export_pdf_safe_filename($nama_mapel);
$coursesafe = $course_name !== '' ? '_' . local_akademikmonitor_export_pdf_safe_filename($course_name) : '';
$filename = 'Analisis_CP_ATP_' . $safe . '_Kelas' . local_akademikmonitor_export_pdf_safe_filename($tingkat) . $coursesafe . '_' . date('Ymd') . '.pdf';

while (ob_get_level()) {
    ob_end_clean();
}

$dompdf->stream($filename, ['Attachment' => true]);
exit;
