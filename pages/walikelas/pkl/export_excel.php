<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

global $CFG, $DB, $USER;

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

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\common_service;
use local_akademikmonitor\service\walikelas\pkl_service;

$kelasid = required_param('kelasid', PARAM_INT);
$semester = optional_param('semester', period_filter_service::get_selected_semester(), PARAM_INT);
$tahunajaranid = optional_param('tahunajaranid', period_filter_service::get_selected_tahunajaranid(), PARAM_INT);

if ($kelasid <= 0) {
    throw new \exception('Kelas tidak valid');
}
$groups = common_service::get_group_walikelas_by_tahunajaran(
    (int)$USER->id,
    (int)$tahunajaranid
);

if (!isset($groups[(int)$kelasid])) {
    throw new \exception('Anda tidak memiliki akses ke kelas ini.');
}

if (!common_service::is_group_kelas_xii($kelasid)) {
    throw new \exception('Export PKL hanya tersedia untuk kelas XII');
}

if (!in_array((int)$semester, [1, 2], true)) {
    $semester = period_filter_service::get_selected_semester();
}

$group = $DB->get_record('groups', ['id' => $kelasid], 'id, name', MUST_EXIST);

$siswas = common_service::get_siswa_group($kelasid, (int)$USER->id);
$userids = array_map('intval', array_keys($siswas));
$nisnmap = common_service::get_nisn_map_by_userids($userids);

$filename = 'pkl_' .
    preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$group->name) .
    '_' . strtolower(period_filter_service::get_semester_label((int)$semester)) . '.xls';

$workbook = new MoodleExcelWorkbook('-');
$workbook->send($filename);

$worksheet = $workbook->add_worksheet('PKL');

$row = 0;
$worksheet->write($row, 0, 'LAPORAN PKL SISWA');
$row += 2;

$worksheet->write($row, 0, 'Kelas');
// $worksheet->write($row, 1, $group->name ?? '-');
$worksheet->write(
    $row,
    1,
    local_akademikmonitor_safe_excel_text((string)($group->name ?? '-'))
);
$row++;

$worksheet->write($row, 0, 'Semester');
$worksheet->write($row, 1, period_filter_service::get_semester_label((int)$semester));
$row++;

$worksheet->write($row, 0, 'Tahun Ajaran');
$worksheet->write($row, 1, period_filter_service::get_tahunajaran_label((int)$tahunajaranid));
$row += 2;

$worksheet->write($row, 0, 'No');
$worksheet->write($row, 1, 'Nama');
$worksheet->write($row, 2, 'NISN');
$worksheet->write($row, 3, 'Mitra');
$worksheet->write($row, 4, 'Alamat');
$worksheet->write($row, 5, 'Kontak');
$worksheet->write($row, 6, 'Waktu Mulai');
$worksheet->write($row, 7, 'Waktu Selesai');
$worksheet->write($row, 8, 'Nilai');
$row++;

$no = 1;

foreach ($siswas as $siswa) {
    $userid = (int)$siswa->id;
    $nama = fullname($siswa);
    $nisn = !empty($nisnmap[$userid]) ? (string)$nisnmap[$userid] : '-';

    $pkls = pkl_service::get_pkl_siswa($userid, $kelasid, (int)$semester);

    if (empty($pkls)) {
        $worksheet->write($row, 0, $no);
        // $worksheet->write($row, 1, $nama);
        // $worksheet->write($row, 2, $nisn);
        $worksheet->write($row, 1, local_akademikmonitor_safe_excel_text($nama));
        $worksheet->write($row, 2, local_akademikmonitor_safe_excel_text($nisn));
        $worksheet->write($row, 3, '-');
        $worksheet->write($row, 4, '-');
        $worksheet->write($row, 5, '-');
        $worksheet->write($row, 6, '-');
        $worksheet->write($row, 7, '-');
        $worksheet->write($row, 8, '-');
        $row++;
        $no++;
        continue;
    }

    foreach ($pkls as $index => $pkl) {
        $worksheet->write($row, 0, ($index === 0 ? $no : ''));
        // $worksheet->write($row, 1, ($index === 0 ? $nama : ''));
        // $worksheet->write($row, 2, ($index === 0 ? $nisn : ''));
        // $worksheet->write($row, 3, $pkl->nama ?? '-');
        // $worksheet->write($row, 4, $pkl->alamat ?? '-');
        // $worksheet->write($row, 5, $pkl->kontak ?? '-');
        $worksheet->write(
    $row,
    1,
    ($index === 0
        ? local_akademikmonitor_safe_excel_text($nama)
        : '')
);

$worksheet->write(
    $row,
    2,
    ($index === 0
        ? local_akademikmonitor_safe_excel_text($nisn)
        : '')
);

$worksheet->write(
    $row,
    3,
    local_akademikmonitor_safe_excel_text((string)($pkl->nama ?? '-'))
);

$worksheet->write(
    $row,
    4,
    local_akademikmonitor_safe_excel_text((string)($pkl->alamat ?? '-'))
);

$worksheet->write(
    $row,
    5,
    local_akademikmonitor_safe_excel_text((string)($pkl->kontak ?? '-'))
);
        $worksheet->write($row, 6, $pkl->waktu_mulai ?? '-');
        $worksheet->write($row, 7, $pkl->waktu_selesai ?? '-');
        $worksheet->write($row, 8, $pkl->nilai ?? '-');
        $row++;
    }

    $no++;
}

$workbook->close();
exit;