<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}

require_sesskey();

global $DB;

$id = required_param('id', PARAM_INT);

// Pastikan data tahun ajaran memang ada.
$record = $DB->get_record('tahun_ajaran', ['id' => $id], '*', MUST_EXIST);

/**
 * Tahun ajaran aktif disimpan di config plugin.
 *
 * Kenapa tidak update tabel atau hapus-insert ulang?
 * Karena tabel tahun_ajaran kemungkinan sudah dipakai oleh fitur lain,
 * misalnya filter semester/tahun ajaran, ekskul, PKL, rapor, atau data lain.
 *
 * Dengan menyimpan id aktif ke config, kita tidak mengubah struktur database
 * dan tidak merusak relasi data yang sudah ada.
 */
set_config('active_tahunajaranid', $record->id, 'local_akademikmonitor');

redirect(
    new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'),
    'Tahun ajaran berhasil diaktifkan.',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);