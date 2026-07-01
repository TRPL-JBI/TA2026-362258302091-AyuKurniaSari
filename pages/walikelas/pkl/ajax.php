<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\common_service;
use local_akademikmonitor\service\walikelas\pkl_service;

global $USER;

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHAEXT);

/**
 * Memastikan user login adalah wali kelas dari group/kelas yang dikirim.
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
        case 'save_pkl':
            $pklid = optional_param('pklid', 0, PARAM_INT);
            $userid = required_param('userid', PARAM_INT);
            // $kelasid = required_param('kelasid', PARAM_INT);
            $groupid = required_param('kelasid', PARAM_INT);
            $mitraid = required_param('mitraid', PARAM_INT);
            $semester = optional_param('semester', period_filter_service::get_selected_semester(), PARAM_INT);
            $waktu_mulai = required_param('waktu_mulai', PARAM_TEXT);
            $waktu_selesai = required_param('waktu_selesai', PARAM_TEXT);
            $nilai = required_param('nilai', PARAM_TEXT);

            local_akademikmonitor_require_walikelas_group_access((int)$groupid, (int)$tahunajaranid);
            global $DB;

            if (!$DB->record_exists('groups_members', [
                'groupid' => (int)$groupid,
                'userid'  => (int)$userid,
            ])) {
                throw new moodle_exception(
                    'studentnotingroup',
                    'local_akademikmonitor'
                );
            }

            if (!common_service::is_group_kelas_xii((int)$groupid)) {
                throw new \exception('PKL hanya tersedia untuk kelas XII.');
            }

            pkl_service::save(
                (int)$userid,
                (int)$groupid,
                (int)$mitraid,
                (int)$semester,
                (string)$waktu_mulai,
                (string)$waktu_selesai,
                (string)$nilai,
                (int)$pklid
            );

            echo json_encode([
                'ok' => true,
                'message' => 'Data PKL berhasil disimpan',
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