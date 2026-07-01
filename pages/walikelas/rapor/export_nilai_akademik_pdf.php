<?php
require_once(__DIR__ . '/../../../../../config.php');

global $CFG;

require_once($CFG->dirroot . '/local/akademikmonitor/lib/dompdf/autoload.inc.php');

use Dompdf\Dompdf;
use local_akademikmonitor\service\walikelas\rapor_service;
use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\access_service;

require_login();
require_sesskey();

$userid = required_param('userid', PARAM_INT);
$kelasid = required_param('kelasid', PARAM_INT);
$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();
access_service::require_rapor_access(
    $userid,
    $kelasid,
    $tahunajaranid,
    false
);
$data = rapor_service::get_export_data($userid, $kelasid, $semester, $tahunajaranid);

$user = $data['user'];
$profile = $data['profile'];
$course = $data['course'];
$jurusan = $data['jurusan'];
$nilaiakademik = $data['nilai_akademik'] ?? [];
$namakelas = $course->fullname ?? '-';

$logopath = $CFG->dirroot . '/local/akademikmonitor/pix/logo.jpg';
$logosrc = '';
if (file_exists($logopath)) {
    $logosrc = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logopath));
}

$semester = $data['semester_label'] ?? period_filter_service::get_semester_label($semester);
$tahunpelajaran = $data['tahunajaran_label'] ?? period_filter_service::get_tahunajaran_label($tahunajaranid);

$html = '
<style>
'
    . 'body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
'
    . '.page-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 6px; }
'
    . '.page-subtitle { text-align: center; font-size: 13px; margin-bottom: 20px; }
'
    . '.header { width: 100%; margin-bottom: 18px; }
'
    . '.header td { vertical-align: top; }
'
    . '.logo { width: 80px; }
'
    . '.identity-table, .nilai-table { width: 100%; border-collapse: collapse; }
'
    . '.identity-table td { padding: 4px 6px; }
'
    . '.nilai-table th, .nilai-table td { border: 1px solid #222; padding: 6px 8px; vertical-align: top; }
'
    . '.nilai-table th { text-align: center; font-weight: bold; }
'
    . '.section-title { font-size: 13px; font-weight: bold; margin: 10px 0 8px; }
'
    . '.muted { color: #666; }
'
    . '.footer-note { margin-top: 18px; font-size: 10px; color: #555; }
'
    . '</style>';

$html .= '<div class="page-title">LAPORAN NILAI AKADEMIK</div>';
$html .= '<div class="page-subtitle">SMKS PGRI 2 GIRI BANYUWANGI</div>';

$html .= '<table class="header">'
    . '<tr>'
    . '<td style="width:90px;">' . ($logosrc ? '<img class="logo" src="' . $logosrc . '">' : '') . '</td>'
    . '<td>'
    . '<table class="identity-table">'
    . '<tr><td style="width:180px;"><strong>Nama Peserta Didik</strong></td><td>: ' . s(fullname($user)) . '</td></tr>'
    . '<tr><td><strong>NISN</strong></td><td>: ' . s($profile->nisn ?? '-') . '</td></tr>'
    . '<tr><td><strong>NIS</strong></td><td>: ' . s($profile->nis ?? '-') . '</td></tr>'
    . '<tr><td><strong>Kelas</strong></td><td>: ' . s($namakelas) . '</td></tr>'
    . '<tr><td><strong>Program Keahlian</strong></td><td>: ' . s($jurusan) . '</td></tr>'
    . '<tr><td><strong>Semester</strong></td><td>: ' . s($semester) . '</td></tr>'
    . '<tr><td><strong>Tahun Pelajaran</strong></td><td>: ' . s($tahunpelajaran) . '</td></tr>'
    . '</table>'
    . '</td>'
    . '</tr>'
    . '</table>';

$html .= '<div class="section-title">Daftar Nilai Akademik</div>';
$html .= '<table class="nilai-table">';
$html .= '<thead><tr>'
    . '<th style="width:36px;">No</th>'
    . '<th style="width:180px;">Mata Pelajaran</th>'
    . '<th style="width:70px;">Nilai Akhir</th>'
    . '<th>Capaian Kompetensi</th>'
    . '</tr></thead><tbody>';

if (!empty($nilaiakademik)) {
    foreach ($nilaiakademik as $row) {
        $html .= '<tr>'
            . '<td style="text-align:center;">' . (int)($row['no'] ?? 0) . '</td>'
            . '<td>' . s($row['mata_pelajaran'] ?? '-') . '</td>'
            . '<td style="text-align:center;">' . s($row['nilai_akhir'] ?? '-') . '</td>'
            . '<td>'
            . '<div><strong>Capaian tertinggi:</strong> ' . s($row['capaian_atas'] ?? '-') . '</div>'
            . '<div style="margin-top:6px;"><strong>Perlu ditingkatkan:</strong> ' . s($row['capaian_bawah'] ?? '-') . '</div>'
            . '</td>'
            . '</tr>';
    }
} else {
    $html .= '<tr><td colspan="4" style="text-align:center;">Belum ada data nilai akademik.</td></tr>';
}

$html .= '</tbody></table>';
$html .= '<div class="footer-note">Dokumen ini digenerate dari halaman Nilai Akademik pada plugin Akademik Monitor.</div>';

$dompdf = new Dompdf();
$dompdf->set_option('isHtml5ParserEnabled', true);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

while (ob_get_level()) {
    ob_end_clean();
}

$dompdf->stream('nilai_akademik_' . $userid . '.pdf', ['Attachment' => true]);
exit;
