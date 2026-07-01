<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}

require_sesskey();

$id = required_param('id', PARAM_INT);
global $DB;



// Pastikan data kurikulum memang ada.
$record = $DB->get_record('kurikulum', ['id' => $id], '*', MUST_EXIST);

/**
 * Kurikulum aktif disimpan di config plugin.
 *
 * Kenapa tidak update/hapus data di tabel kurikulum?
 * Karena data kurikulum bisa dipakai oleh jurusan, mata pelajaran,
 * capaian pembelajaran, tujuan pembelajaran, atau fitur akademik lain.
 *
 * Dengan config, kita hanya menyimpan id kurikulum aktif tanpa mengubah tabel.
 */
set_config('active_kurikulumid', $record->id, 'local_akademikmonitor');

redirect(
    new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'),
    'Kurikulum berhasil diaktifkan.',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);