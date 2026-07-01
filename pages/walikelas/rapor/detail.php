<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

global $CFG, $PAGE, $OUTPUT, $USER, $DB;

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\common_service;
use local_akademikmonitor\service\walikelas\rapor_service;

require_once($CFG->libdir . '/accesslib.php');

$userid = required_param('userid', PARAM_INT);
$kelasidparam = optional_param('kelasid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();

$detail = rapor_service::get_detail_page_data($userid, $semester, $tahunajaranid, $kelasidparam);
$course = $detail['course'];
$group = $detail['group'];
$groupid = (int)$group->id;
$kelasid = (int)$detail['kelasid'];
$template = $detail['template'];

$systemcontext = context_system::instance();
$coursecontext = context_course::instance((int)$course->id);

// Halaman detail rapor adalah halaman local plugin, jadi context halaman dibuat system.
// Jangan memakai context_course sebagai PAGE context, karena wali kelas belum tentu bisa membuka
// semua course mapel hasil generate. Kalau PAGE context course dipakai, Moodle akan melempar
// requireloginerror: Course or activity not accessible.
$PAGE->set_url('/local/akademikmonitor/pages/walikelas/rapor/detail.php', [
    'userid' => $userid,
    'kelasid' => $kelasid,
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]);

$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Detail Raport');
$PAGE->set_heading('Detail Raport');

// $groupid = (int)$group->id;

$studentingroup = $DB->record_exists('groups_members', [
    'groupid' => $groupid,
    'userid' => $userid,
]);

/*
 * Detail rapor boleh dibuka oleh:
 * 1. User yang punya capability viewrapor.
 * 2. Wali kelas yang memang memegang kelas tersebut pada tahun ajaran yang dipilih.
 *
 * Kenapa tidak cukup capability?
 * Karena konsep wali kelas di plugin ini disimpan di tabel {kelas}.id_user,
 * bukan selalu role Moodle khusus. Jadi saat tahun ajaran berubah, akses harus
 * mengikuti data wali kelas di plugin.
 */
$groupswali = common_service::get_group_walikelas_by_tahunajaran(
    (int)$USER->id,
    (int)$tahunajaranid
);

$iswalikelas = isset($groupswali[(int)$groupid]);

$canview = $iswalikelas
    || has_capability('local/akademikmonitor:viewrapor', $systemcontext)
    || has_capability('local/akademikmonitor:viewrapor', $coursecontext);

if (!$studentingroup || !$canview) {
    throw new moodle_exception('nopermissions', 'error');
}

/*
 * Action reset ketidakhadiran manual.
 *
 * Fungsi ini hanya menghapus data manual dari tabel rapor_ketidakhadiran.
 * Setelah data manual dihapus, halaman detail rapor akan kembali membaca
 * data otomatis dari Attendance Moodle melalui rapor_service::get_ketidakhadiran().
 *
 * Filter semester dan tahun ajaran tetap aman karena:
 * - $semester tetap berasal dari period_filter_service::get_selected_semester()
 * - $tahunajaranid tetap berasal dari period_filter_service::get_selected_tahunajaranid()
 * - redirect tetap memakai period_filter_service::append_filter_params()
 */
if ($action === 'resetketidakhadiran') {
    require_sesskey();

    rapor_service::reset_ketidakhadiran_manual(
        $userid,
        $kelasid,
        $semester
    );

    redirect(
        new moodle_url(
            '/local/akademikmonitor/pages/walikelas/rapor/detail.php',
            period_filter_service::append_filter_params([
                'userid' => $userid,
                'kelasid' => $kelasid,
            ])
        ),
        'Data ketidakhadiran berhasil dikembalikan ke data Attendance.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$template += period_filter_service::build_filter_data();

$PAGE->requires->css('/local/akademikmonitor/css/walikelasstyles.css');
$PAGE->requires->css('/local/akademikmonitor/css/styles.css');

$PAGE->requires->js_call_amd('local_akademikmonitor/tabrapor', 'init');
$PAGE->requires->js_call_amd('local_akademikmonitor/catatan', 'init', [$userid, $groupid, $kelasid, $semester]);
$PAGE->requires->js_call_amd('local_akademikmonitor/kenaikan_kelas', 'init', [$userid,$groupid, $kelasid]);
$PAGE->requires->js_call_amd('local_akademikmonitor/ketidakhadiran', 'init', [$userid,$groupid, $kelasid, $semester]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/detail_raport', $template);
echo $OUTPUT->footer();