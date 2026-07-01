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
$DB->get_record('jurusan', ['id' => $id], '*', MUST_EXIST);

if ($DB->record_exists('kelas', ['id_jurusan' => $id])) {
    redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'), 'Jurusan tidak bisa dihapus karena masih dipakai oleh kelas.', null, \core\output\notification::NOTIFY_ERROR);
}

$transaction = $DB->start_delegated_transaction();

try {

    $kjs = $DB->get_records('kurikulum_jurusan', ['id_jurusan' => $id], '', 'id');

    foreach ($kjs as $kj) {

        $kms = $DB->get_records('kurikulum_mapel', [
            'id_kurikulum_jurusan' => $kj->id
        ], '', 'id');

        foreach ($kms as $km) {

            $cps = $DB->get_records('capaian_pembelajaran', [
                'id_kurikulum_mapel' => $km->id
            ], '', 'id');

            foreach ($cps as $cp) {

                $tps = $DB->get_records('tujuan_pembelajaran', [
                    'id_capaian_pembelajaran' => $cp->id
                ], '', 'id');

                foreach ($tps as $tp) {

                    $DB->delete_records('grade_items_tp', [
                        'id_tp' => $tp->id
                    ]);

                    $DB->delete_records('assignment_tp', [
                        'id_tp' => $tp->id
                    ]);

                    $DB->delete_records('quiz_tp', [
                        'id_tp' => $tp->id
                    ]);
                }

                $DB->delete_records('tujuan_pembelajaran', [
                    'id_capaian_pembelajaran' => $cp->id
                ]);
            }

            $DB->delete_records('capaian_pembelajaran', [
                'id_kurikulum_mapel' => $km->id
            ]);

            $DB->delete_records('course_mapel', [
                'id_kurikulum_mapel' => $km->id
            ]);
        }

        $DB->delete_records('kurikulum_mapel', [
            'id_kurikulum_jurusan' => $kj->id
        ]);
    }

    $DB->delete_records('kurikulum_jurusan', [
        'id_jurusan' => $id
    ]);

    $DB->delete_records('jurusan', [
        'id' => $id
    ]);

    $transaction->allow_commit();

    redirect(
        new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'),
        'Jurusan berhasil dihapus.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );

} catch (\Throwable $e) {

    $transaction->rollback($e);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'),
        'Gagal menghapus jurusan: ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'), 'Jurusan berhasil dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
