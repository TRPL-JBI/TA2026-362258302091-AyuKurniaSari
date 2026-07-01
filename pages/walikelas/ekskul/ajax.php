<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\common_service;
use local_akademikmonitor\service\walikelas\ekskul_service;

global $USER;

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHAEXT);

/**
 * Memastikan user login adalah wali kelas dari group/kelas yang dikirim.
 *
 * Kenapa perlu?
 * require_login() hanya memastikan user sudah login.
 * require_sesskey() hanya memastikan request berasal dari sesi yang valid.
 * Tapi keduanya belum memastikan user tersebut adalah wali kelas dari kelas yang diedit.
 */
function local_akademikmonitor_require_walikelas_group_access(int $groupid, int $tahunajaranid): void {
    global $USER;

    if ($groupid <= 0) {
        throw new \exception('Kelas tidak valid.');
    }

    $groups = common_service::get_group_walikelas_by_tahunajaran(
        (int)$USER->id,
        (int)$tahunajaranid
    );

    if (!isset($groups[$groupid])) {
        throw new \exception('Anda tidak memiliki akses ke kelas ini.');
    }
}

try {
    $tahunajaranid = period_filter_service::get_selected_tahunajaranid();

    period_filter_service::require_editable_selected_period($tahunajaranid);

    switch ($action) {
        case 'save':
            $userid = required_param('userid', PARAM_INT);
            $kelasid = required_param('kelasid', PARAM_INT);
            $ekskulid = required_param('ekskulid', PARAM_INT);
            $predikat = required_param('predikat', PARAM_TEXT);
            $semester = optional_param('semester', period_filter_service::get_selected_semester(), PARAM_INT);

            local_akademikmonitor_require_walikelas_group_access((int)$kelasid, (int)$tahunajaranid);

            ekskul_service::save(
                (int)$userid,
                (int)$kelasid,
                (int)$ekskulid,
                (int)$semester,
                (string)$predikat
            );

            echo json_encode([
                'ok' => true,
                'message' => 'Data ekstrakurikuler berhasil disimpan',
            ]);
            break;

/**
 * Hapus data ekstrakurikuler siswa.
 */
case 'delete':

    $userid = required_param('userid', PARAM_INT);

    $kelasid = required_param('kelasid', PARAM_INT);

    $ekskulid = required_param('ekskulid', PARAM_INT);

    $semester = required_param('semester', PARAM_INT);

    local_akademikmonitor_require_walikelas_group_access(
        (int)$kelasid,
        (int)$tahunajaranid
    );

    ekskul_service::delete(
        (int)$userid,
        (int)$kelasid,
        (int)$ekskulid,
        (int)$semester
    );

    echo json_encode([
        'ok' => true,
        'message' => 'Data ekstrakurikuler berhasil dihapus',
    ]);

    break;

default:
    echo json_encode([
        'ok' => false,
        'message' => 'Action tidak dikenal',
    ]);
    break;

    }
} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}

exit;