<?php
require_once(__DIR__ . '/../../../../config.php');
use local_akademikmonitor\service\access_service;

access_service::require_manage();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}
require_sesskey();
global $DB;
$id = required_param('id', PARAM_INT);
$DB->delete_records('kartu_ujian_siswa', ['id_kartu_ujian' => $id]);
$DB->delete_records('kartu_ujian', ['id' => $id]);
redirect(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'), 'Kartu ujian berhasil dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
