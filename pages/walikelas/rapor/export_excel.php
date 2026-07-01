<?php
while (ob_get_level()) {
    ob_end_clean();
}

require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

global $CFG;

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\rapor_service;
use local_akademikmonitor\service\walikelas\access_service;

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

$showpkl = !empty($data['show_pkl']);

$user = $data['user'];
$profile = $data['profile'];

$pkl = $showpkl ? ($data['pkl'] ?? []) : [];
$ekskul = $data['ekskul'] ?? [];
$absen = $data['absen'] ?? null;
$catatan = $data['catatan'] ?? null;
$keputusan = $data['keputusan'] ?? null;

require_once($CFG->libdir . '/excellib.class.php');

/**
 * Mencegah Formula Injection pada file Excel.
 *
 * Nilai yang diawali =, +, - atau @ akan diperlakukan sebagai teks,
 * bukan sebagai formula ketika file dibuka di Excel.
 */
function local_akademikmonitor_safe_excel_text(string $value): string {
    $value = trim($value);

    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

\core\session\manager::write_close();

header('Content-Type: application/vnd.ms-excel');
header('Content-Transfer-Encoding: binary');

$filename = 'rapor_' . $userid . '.xls';

$workbook = new MoodleExcelWorkbook('-');
$workbook->send($filename);

while (ob_get_level()) {
    ob_end_clean();
}

/*
 * Sheet 1: Data Diri.
 */
$sheet1 = $workbook->add_worksheet('Data Diri');

$row = 0;

$sheet1->write($row, 0, 'Semester');
$sheet1->write($row++, 1, $data['semester_label'] ?? '-');

$sheet1->write($row, 0, 'Tahun Ajaran');
$sheet1->write($row++, 1, $data['tahunajaran_label'] ?? '-');

$sheet1->write($row, 0, 'Nama Peserta Didik');
// $sheet1->write($row++, 1, fullname($user));
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text(fullname($user))
);

$sheet1->write($row, 0, 'Nomor Induk Siswa Nasional');
// $sheet1->write($row++, 1, $profile->nisn ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->nisn ?? '-'))
);

$sheet1->write($row, 0, 'Jenis Kelamin');
$sheet1->write($row++, 1, $profile->jenis_kelamin ?? '-');

$sheet1->write($row, 0, 'Agama');
$sheet1->write($row++, 1, $profile->agama ?? '-');

$sheet1->write($row, 0, 'Status Dalam Keluarga');
$sheet1->write($row++, 1, $profile->status_dalam_keluarga ?? '-');

$sheet1->write($row, 0, 'Anak Ke');
$sheet1->write($row++, 1, $profile->anak_ke ?? '-');

$sheet1->write($row, 0, 'Alamat Peserta Didik');
// $sheet1->write($row++, 1, $profile->alamat_peserta_didik ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->alamat_peserta_didik ?? '-'))
);

$sheet1->write($row, 0, 'Nomor Telepon Rumah');
$sheet1->write($row++, 1, $profile->telepon_rumah ?? '-');

$sheet1->write($row, 0, 'Sekolah Asal');
// $sheet1->write($row++, 1, $profile->sekolah_asal ?? ($profile->sekolah_Asal ?? '-'));
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text(
        (string)($profile->sekolah_asal ?? ($profile->sekolah_Asal ?? '-'))
    )
);

$sheet1->write($row, 0, 'Diterima di Kelas');
$sheet1->write($row++, 1, $profile->diterima_di_kelas ?? '-');

$sheet1->write($row, 0, 'Tanggal Diterima');
$sheet1->write($row++, 1, $profile->tanggal_diterima ?? '-');

$sheet1->write($row, 0, 'Nama Ayah');
// $sheet1->write($row++, 1, $profile->nama_ayah ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->nama_ayah ?? '-'))
);

$sheet1->write($row, 0, 'Nama Ibu');
// $sheet1->write($row++, 1, $profile->nama_ibu ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->nama_ibu ?? '-'))
);

$sheet1->write($row, 0, 'Alamat Orang Tua');
// $sheet1->write($row++, 1, $profile->alamat_orang_tua ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->alamat_orang_tua ?? '-'))
);

$sheet1->write($row, 0, 'Telepon Orang Tua');
$sheet1->write($row++, 1, $profile->telepon_orang_tua ?? '-');

$sheet1->write($row, 0, 'Pekerjaan Ayah');
// $sheet1->write($row++, 1, $profile->pekerjaan_ayah ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->pekerjaan_ayah ?? '-'))
);

$sheet1->write($row, 0, 'Pekerjaan Ibu');
// $sheet1->write($row++, 1, $profile->pekerjaan_ibu ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->pekerjaan_ibu ?? '-'))
);

$sheet1->write($row, 0, 'Nama Wali');
// $sheet1->write($row++, 1, $profile->nama_wali ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->nama_wali ?? '-'))
);

$sheet1->write($row, 0, 'Alamat Wali');
// $sheet1->write($row++, 1, $profile->alamat_wali ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->alamat_wali ?? '-'))
);

$sheet1->write($row, 0, 'Telepon Wali');
$sheet1->write($row++, 1, $profile->telepon_wali ?? '-');

$sheet1->write($row, 0, 'Pekerjaan Wali');
// $sheet1->write($row++, 1, $profile->pekerjaan_wali ?? '-');
$sheet1->write(
    $row++,
    1,
    local_akademikmonitor_safe_excel_text((string)($profile->pekerjaan_wali ?? '-'))
);

/*
 * Sheet 2: PKL.
 *
 * Sheet ini hanya dibuat kalau siswa berada di kelas XII.
 * Jadi untuk kelas X dan XI, file Excel tidak akan punya sheet PKL.
 */
if ($showpkl) {
    $sheet2 = $workbook->add_worksheet('PKL');

    $sheet2->write(0, 0, 'No');
    $sheet2->write(0, 1, 'Mitra');
    $sheet2->write(0, 2, 'Alamat');
    $sheet2->write(0, 3, 'Mulai');
    $sheet2->write(0, 4, 'Selesai');
    $sheet2->write(0, 5, 'Nilai');

    $row = 1;
    $no = 1;

    foreach ($pkl as $item) {
        $sheet2->write($row, 0, $no++);
        // $sheet2->write($row, 1, $item->nama ?? '-');
        $sheet2->write(
            $row,
            1,
            local_akademikmonitor_safe_excel_text((string)($item->nama ?? '-'))
        );
        // $sheet2->write($row, 2, $item->alamat ?? '-');
        $sheet2->write(
            $row,
            2,
            local_akademikmonitor_safe_excel_text((string)($item->alamat ?? '-'))
        );
        $sheet2->write($row, 3, $item->waktu_mulai ?? '-');
        $sheet2->write($row, 4, $item->waktu_selesai ?? '-');
        $sheet2->write($row, 5, $item->nilai ?? '-');
        $row++;
    }
}

/*
 * Sheet Ekskul.
 */
$sheet3 = $workbook->add_worksheet('Ekskul');

$sheet3->write(0, 0, 'No');
$sheet3->write(0, 1, 'Kegiatan');
$sheet3->write(0, 2, 'Predikat');

$row = 1;
$no = 1;

foreach ($ekskul as $item) {
    $sheet3->write($row, 0, $no++);
    // $sheet3->write($row, 1, $item->nama ?? '-');
    $sheet3->write(
        $row,
        1,
        local_akademikmonitor_safe_excel_text((string)($item->nama ?? '-'))
    );
    $sheet3->write($row, 2, $item->predikat ?? '-');
    $row++;
}

/*
 * Sheet Ketidakhadiran.
 */
$sheet4 = $workbook->add_worksheet('Ketidakhadiran');

$sheet4->write(0, 0, 'Sakit');
$sheet4->write(0, 1, 'Izin');
$sheet4->write(0, 2, 'Alfa');

$sheet4->write(1, 0, $absen->sakit ?? 0);
$sheet4->write(1, 1, $absen->izin ?? 0);
$sheet4->write(1, 2, $absen->alfa ?? 0);

/*
 * Sheet Catatan.
 */
$sheet5 = $workbook->add_worksheet('Catatan');

$sheet5->write(0, 0, 'Catatan Akademik');
// $sheet5->write(1, 0, $catatan->catatan ?? '-');
$sheet5->write(
    1,
    0,
    local_akademikmonitor_safe_excel_text((string)($catatan->catatan ?? '-'))
);

/*
 * Sheet Kenaikan.
 */
$sheet6 = $workbook->add_worksheet('Kenaikan');

$sheet6->write(0, 0, 'Keputusan');
// $sheet6->write(1, 0, $keputusan->keputusan ?? '-');
$sheet6->write(
    1,
    0,
    local_akademikmonitor_safe_excel_text((string)($keputusan->keputusan ?? '-'))
);

$workbook->close();
exit;