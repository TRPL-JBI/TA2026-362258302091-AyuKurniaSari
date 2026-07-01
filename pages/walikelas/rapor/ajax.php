<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\rapor_service;
use local_akademikmonitor\service\walikelas\access_service;

header('Content-Type: application/json; charset=utf-8');

$userid = required_param('userid', PARAM_INT);
$groupid = required_param('groupid', PARAM_INT);
$kelasid = required_param('kelasid', PARAM_INT);
$kelasid = required_param('kelasid', PARAM_INT);
$action = required_param('action', PARAM_ALPHAEXT);

global $USER;

$waliid = (int)$USER->id;
$semester = optional_param('semester', period_filter_service::get_selected_semester(), PARAM_INT);
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();

try {
    /*
     * Semua action di rapor/ajax.php adalah proses simpan.
     * Maka semuanya wajib ditolak kalau tahun ajaran yang dipilih adalah arsip.
     */
    period_filter_service::require_editable_selected_period($tahunajaranid);
    access_service::require_rapor_access(
        $userid,
        $groupid,
        // $kelasid,
        $tahunajaranid,
        true
    );
    switch ($action) {
        case 'save_catatan':
            rapor_service::save_catatan(
                $userid,
                $kelasid,
                $semester,
                required_param('catatan', PARAM_TEXT),
                $waliid
            );
            break;

        case 'save_kokurikuler':
            rapor_service::save_kokurikuler(
                $userid,
                $kelasid,
                $semester,
                required_param('kokurikuler', PARAM_TEXT),
                $waliid
            );
            break;

        case 'save_ketidakhadiran':
            rapor_service::save_ketidakhadiran(
                $userid,
                $kelasid,
                $semester,
                required_param('sakit', PARAM_INT),
                required_param('izin', PARAM_INT),
                required_param('alfa', PARAM_INT),
                $waliid
            );
            break;

        case 'save_kenaikan':
            rapor_service::save_kenaikan_kelas(
                $userid,
                $kelasid,
                // $semester,
                required_param('keputusan', PARAM_TEXT),
                $waliid
            );
            break;

        default:
            throw new \Exception('Action tidak dikenal');
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Data rapor berhasil disimpan',
    ]);

} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}

exit;