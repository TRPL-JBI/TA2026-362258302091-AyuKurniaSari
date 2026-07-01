<?php
// Mendefinisikan bahwa file ini merupakan AJAX endpoint Moodle.
// AJAX endpoint adalah file yang dipanggil oleh JavaScript tanpa melakukan reload halaman.
define('AJAX_SCRIPT', true);

// Memuat file konfigurasi utama Moodle. // // File ini wajib dipanggil karena berisi: // - koneksi database // - session user // - permission Moodle // - library inti Moodle

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();
require_sesskey();

// Memberitahu browser bahwa output file ini // berupa JSON. // // Karena file ini digunakan oleh AJAX, // maka browser harus menerima response JSON.
header('Content-Type: application/json; charset=utf-8');

use local_akademikmonitor\service\ekskul_service;

$action = required_param('action', PARAM_ALPHAEXT);

try {
    // Menentukan proses yang dijalankan 
    // berdasarkan action yang dikirim dari frontend.
    switch ($action) {
        case 'create':
            // Mengambil nama ekstrakurikuler.
            $nama = required_param('nama', PARAM_TEXT);
            // Mengambil ID pembina yang dipilih.
            $pembinaid = required_param('pembinaid', PARAM_INT);

            if ($pembinaid <= 0) {
                throw new Exception('Pembina wajib dipilih');
            }

            $id = ekskul_service::create($nama, $pembinaid);

            echo json_encode([
                'ok' => true,
                'message' => 'Ekstrakurikuler ditambahkan',
                'data' => [
                    'id' => $id,
                    'nama' => $nama,
                    'pembina' => fullname(core_user::get_user($pembinaid)),
                    'id_pembina' => $pembinaid,
                    'is_active' => '1',
                ]
            ]);
            break;

        case 'update':
            $id = required_param('id', PARAM_INT);
            $nama = required_param('nama', PARAM_TEXT);
            $pembinaid = required_param('pembinaid', PARAM_INT);

            if ($pembinaid <= 0) {
                throw new Exception('Pembina wajib dipilih');
            }

            ekskul_service::update($id, $nama, $pembinaid);

            echo json_encode([
                'ok' => true,
                'message' => 'Ekstrakurikuler diupdate',
                'data' => [
                    'id' => $id,
                    'nama' => $nama,
                    'pembina' => fullname(core_user::get_user($pembinaid)),
                    'id_pembina' => $pembinaid,
                ]
            ]);
            break;

        case 'toggle':
            $id = required_param('id', PARAM_INT);
            ekskul_service::toggle($id);

            echo json_encode([
                'ok' => true,
                'message' => 'Status berubah'
            ]);
            break;

        default:
            echo json_encode([
                'ok' => false,
                'message' => 'Action tidak dikenal'
            ]);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage()
    ]);
}
exit;