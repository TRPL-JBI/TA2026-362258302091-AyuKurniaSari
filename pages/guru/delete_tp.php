<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

global $DB;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new \moodle_exception('invalidrequest');
}

$id       = required_param('id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$cpid     = required_param('cpid', PARAM_INT);

$coursecontext = context_course::instance($courseid, MUST_EXIST);
require_capability('moodle/course:manageactivities', $coursecontext);

$tp = $DB->get_record('tujuan_pembelajaran', [
    'id' => $id,
    'id_course' => $courseid,
    'id_capaian_pembelajaran' => $cpid
], '*', MUST_EXIST);

$hasgrades = false;
try {
    $hasgrades = \local_akademikmonitor\service\tp_gradebook_service::tp_has_grades((int)$tp->id);
} catch (\Throwable $e) {
    $hasgrades = false;
}

if ($hasgrades) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/guru/tp.php', [
            'courseid' => $courseid,
            'cpid' => $cpid
        ]),
        'TP tidak dapat dihapus karena sudah memiliki nilai di gradebook. Jika ada kesalahan penulisan, gunakan tombol Edit.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

try {
    \local_akademikmonitor\service\tp_gradebook_service::delete_grade_items_for_tp((int)$tp->id);
} catch (\Throwable $e) {
    // Jika pembersihan gradebook gagal, data TP tetap dihapus karena belum memiliki nilai.
}

$DB->delete_records('tujuan_pembelajaran', [
    'id' => $tp->id
]);

redirect(
    new moodle_url('/local/akademikmonitor/pages/guru/tp.php', [
        'courseid' => $courseid,
        'cpid' => $cpid
    ]),
    'Tujuan Pembelajaran berhasil dihapus.',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);