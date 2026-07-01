<?php
/**
 * Generate kartu ujian untuk siswa yang memenuhi persyaratan.
 *
 * Syarat:
 * 1. Jumlah alpa <= 10% dari total pertemuan Attendance semester tersebut.
 * 2. Semua nilai akhir course >= KKTP mapel masing-masing.
 */
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();
require_sesskey();

global $DB;

use local_akademikmonitor\service\kartu_ujian_service;

$id = required_param('id', PARAM_INT);
$ku = $DB->get_record('kartu_ujian', ['id' => $id], '*', MUST_EXIST);

$semester = kartu_ujian_service::normalize_semester($ku->semester ?? '');
$kelasid = (int)$ku->id_kelas;
$tahunajaranid = (int)$ku->id_tahun_ajaran;

$namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$pesertasql = "SELECT pk.id_user, {$namefields}, u.idnumber
                 FROM {peserta_kelas} pk
                 JOIN {user} u ON u.id = pk.id_user
                WHERE pk.id_kelas = :kelasid
                  AND u.deleted = 0
                  AND (pk.id_role IS NULL OR pk.id_role NOT IN (
                       SELECT r.id FROM {role} r WHERE r.shortname IN ('editingteacher','teacher')
                  ))";
$peserta = $DB->get_records_sql($pesertasql, ['kelasid' => $kelasid]);

$generated = 0;
$already = 0;
$removed = 0;
$skipped = 0;

foreach ($peserta as $p) {
    $userid = (int)$p->id_user;
    $eligibility = kartu_ujian_service::get_eligibility($userid, $kelasid, $tahunajaranid, $semester);
    $exists = $DB->get_record('kartu_ujian_siswa', [
        'id_kartu_ujian' => $id,
        'id_user' => $userid,
    ], '*', IGNORE_MISSING);

if (empty($eligibility['eligible'])) {
    /*
     * Kalau siswa tidak memenuhi KKTP, generate otomatis tidak membuat kartu baru.
     *
     * Tapi kalau siswa sudah pernah diloloskan manual oleh admin,
     * record di {kartu_ujian_siswa} JANGAN dihapus.
     *
     * Kenapa?
     * Karena record itu sekarang juga dipakai sebagai tanda override/lolos manual.
     */
    $skipped++;
    continue;
}

    if ($exists) {
        $already++;
        continue;
    }

    $rec = new stdClass();
    $rec->id_kartu_ujian = $id;
    $rec->id_user = $userid;
    $rec->timecreated = time();
    $DB->insert_record('kartu_ujian_siswa', $rec);
    $generated++;
}

$msg = "Generate selesai. $generated kartu baru dibuat.";
if ($already > 0) {
    $msg .= " $already siswa sudah punya kartu.";
}
if ($skipped > 0) {
    $msg .= " $skipped siswa belum memenuhi syarat.";
}
if ($removed > 0) {
    $msg .= " $removed kartu lama dihapus karena siswa sudah tidak memenuhi syarat.";
}

redirect(
    new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
    $msg,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
