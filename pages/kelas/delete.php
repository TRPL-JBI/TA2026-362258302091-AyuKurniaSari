<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}
$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB;

$id = required_param('id', PARAM_INT);
$kelas = $DB->get_record('kelas', ['id' => $id], '*', MUST_EXIST);

$DB->delete_records('peserta_kelas', ['id_kelas' => $id]);
$DB->delete_records('kelas', ['id' => $id]);

redirect(new moodle_url('/local/akademikmonitor/pages/kelas/index.php'), 'Kelas berhasil dihapus. Course Moodle yang sudah dibuat tidak ikut dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
