<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;
use local_akademikmonitor\service\mitra_service;

access_service::require_manage();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

// use local_akademikmonitor\service\mitra_service;

$action = required_param('action', PARAM_ALPHAEXT);

try {
    switch ($action) {
        case 'create':
            $nama   = required_param('nama', PARAM_TEXT);
            $alamat = optional_param('alamat', '', PARAM_TEXT);
            $kontak = optional_param('kontak', '', PARAM_TEXT);

            $newid = mitra_service::create($nama, $alamat, $kontak);

            echo json_encode([
                'ok' => true,
                'message' => 'Mitra ditambahkan',
                'id' => $newid,
                'data' => [
                    'id' => $newid,
                    'nama' => $nama,
                    'alamat' => $alamat,
                    'kontak' => $kontak,
                    'is_active' => 1,
                    'badge_text' => 'Aktif',
                    'badge_class' => 'am-badge-success',
                    'toggle_text' => 'Arsipkan',
                ],
            ]);
            break;

        case 'update':
            $id     = required_param('id', PARAM_INT);
            $nama   = required_param('nama', PARAM_TEXT);
            $alamat = optional_param('alamat', '', PARAM_TEXT);
            $kontak = optional_param('kontak', '', PARAM_TEXT);

            mitra_service::update($id, $nama, $alamat, $kontak);

            echo json_encode([
                'ok' => true,
                'message' => 'Mitra diupdate',
                'data' => [
                    'id' => $id,
                    'nama' => $nama,
                    'alamat' => $alamat,
                    'kontak' => $kontak,
                ],
            ]);
            break;

        case 'toggle':
            $id = required_param('id', PARAM_INT);
            $new = mitra_service::toggle($id);

            echo json_encode([
                'ok' => true,
                'message' => 'Status berubah',
                'data' => [
                    'id' => $id,
                    'is_active' => $new,
                    'badge_text' => $new ? 'Aktif' : 'Diarsipkan',
                    'badge_class' => $new ? 'am-badge-success' : 'am-badge-muted',
                    'toggle_text' => $new ? 'Arsipkan' : 'Aktifkan',
                ],
            ]);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'Action tidak dikenal']);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
exit;