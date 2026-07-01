<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);
require_sesskey();

use local_akademikmonitor\service\mitra_service;

if (empty($_FILES['fileimport']['tmp_name'])) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
        'File tidak ditemukan',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$filename = $_FILES['fileimport']['name'] ?? '';
$maxfilesize = 2 * 1024 * 1024;

if ($_FILES['fileimport']['size'] > $maxfilesize) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
        'Ukuran file maksimal 2 MB.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
        'File harus berformat CSV.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$file = $_FILES['fileimport']['tmp_name'];
$data = [];

/**
 * Membersihkan header CSV.
 *
 * Kenapa perlu?
 * - Kadang file CSV dari Excel menyimpan BOM UTF-8 di kolom pertama.
 * - Misalnya header terbaca menjadi "\uFEFFnama", bukan "nama".
 */
function local_akademikmonitor_mitra_clean_csv_value(string $value): string {
    $value = trim($value);

    // Hapus BOM UTF-8 jika ada.
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    return trim($value);
}
/**
 * Deteksi delimiter CSV otomatis.
 *
 * Kenapa perlu?
 * - Ada file CSV yang memakai koma (,)
 * - Ada file CSV yang memakai titik koma (;)
 * - Supaya import fleksibel dari Excel / Spreadsheet.
 */
function local_akademikmonitor_detect_csv_delimiter(string $line): string {
    $delimiters = [',', ';'];

    $bestdelimiter = ',';
    $maxcount = 0;

    foreach ($delimiters as $delimiter) {
        $count = substr_count($line, $delimiter);

        if ($count > $maxcount) {
            $maxcount = $count;
            $bestdelimiter = $delimiter;
        }
    }

    return $bestdelimiter;
}
try {
    $handle = fopen($file, 'r');
if (!$handle) {
    throw new \exception('File tidak bisa dibaca.');
}
$firstline = fgets($handle);
if ($firstline === false) {
    fclose($handle);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
        'File CSV kosong.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

    // Deteksi delimiter otomatis.
    $delimiter = local_akademikmonitor_detect_csv_delimiter($firstline);

    // Kembalikan pointer file ke awal.
    rewind($handle);

    // Ambil baris pertama CSV.
    $firstrow = fgetcsv($handle, 0, $delimiter);

    if ($firstrow === false) {
        fclose($handle);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
            'File CSV kosong.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $firstrow = array_map(
        'local_akademikmonitor_mitra_clean_csv_value',
        $firstrow
    );

    /*
     * Support:
     * sep=;
     * sep=,
     */
    if (
        count($firstrow) === 1
        && (
            strtolower(str_replace(' ', '', $firstrow[0])) === 'sep=;'
            || strtolower(str_replace(' ', '', $firstrow[0])) === 'sep=,'
        )
    ) {
        $header = fgetcsv($handle, 0, $delimiter);
    } else {
        $header = $firstrow;
    }

    if ($header === false) {
        fclose($handle);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
            'Header CSV tidak ditemukan.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $header = array_map(
        'local_akademikmonitor_mitra_clean_csv_value',
        $header
    );

    // Validasi header.
    if ($header !== ['nama', 'alamat', 'kontak']) {
        fclose($handle);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
            'Format header salah. Harus: nama,alamat,kontak atau nama;alamat;kontak',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Baca isi CSV.
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

        $row = array_map(static function($value) {
            return local_akademikmonitor_mitra_clean_csv_value((string)$value);
        }, $row);

        $nama = trim($row[0] ?? '');
        $alamat = trim($row[1] ?? '');
        $kontak = trim($row[2] ?? '');

        // Lewati baris kosong.
        if ($nama === '' && $alamat === '' && $kontak === '') {
            continue;
        }

        $data[] = [
            'nama' => $nama,
            'alamat' => $alamat,
            'kontak' => $kontak,
        ];

    }

    fclose($handle);
        $maxrows = 5000;

        if (count($data) > $maxrows) {
            redirect(
                new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
                'Jumlah baris maksimal ' . $maxrows . '.',
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

$transaction = $DB->start_delegated_transaction();

$result = mitra_service::import($data);

$transaction->allow_commit();
$message = "Import selesai. Berhasil: {$result['success']}, Gagal: {$result['failed']}";

redirect(
    new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
    $message,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
} catch (\Throwable $e) {
    if (isset($transaction)) {
        $transaction->rollback($e);
    }
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
        'Import gagal: ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}