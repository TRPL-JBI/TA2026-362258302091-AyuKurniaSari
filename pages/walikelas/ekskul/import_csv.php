<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\common_service;
use local_akademikmonitor\service\walikelas\ekskul_service;

global $DB, $USER;

$redirectparams = period_filter_service::append_filter_params([]);

/**
 * Bersihkan BOM + spasi.
 */
function local_akademikmonitor_clean_csv_value(string $value): string {
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    return trim($value);
}

/**
 * Deteksi delimiter CSV (; atau ,)
 */
function local_akademikmonitor_detect_csv_delimiter(string $line): string {
    $semicoloncount = substr_count($line, ';');
    $commacount = substr_count($line, ',');

    return ($semicoloncount >= $commacount) ? ';' : ',';
}

/**
 * Ambil header asli (skip sep=;)
 */
function local_akademikmonitor_read_csv_header($handle, string $delimiter): ?array {
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

        if (count($row) === 1 && strtolower(trim((string)$row[0])) === 'sep=;') {
            continue;
        }

        $row = array_map(function($value) {
            return strtolower(local_akademikmonitor_clean_csv_value((string)$value));
        }, $row);

        if (!empty(array_filter($row))) {
            return $row;
        }
    }

    return null;
}

try {
    // $kelasid = required_param('kelasid', PARAM_INT);
    $groupid = required_param('kelasid', PARAM_INT);
    $semesterform = optional_param('semester', 0, PARAM_INT);
    $tahunajaranid = period_filter_service::get_selected_tahunajaranid();

    period_filter_service::require_editable_selected_period($tahunajaranid);

    $semesteraktif = in_array($semesterform, [1, 2], true)
        ? $semesterform
        : period_filter_service::get_selected_semester();

    if ($groupid <= 0) {
        throw new \Exception('Kelas tidak valid');
    }

    $groups = common_service::get_group_walikelas_by_tahunajaran(
        (int)$USER->id,
        (int)$tahunajaranid
    );

if (!isset($groups[(int)$groupid])) {
    throw new \Exception('Tidak ada akses ke kelas ini');
}

    if (!in_array($semesteraktif, [1, 2], true)) {
        throw new \Exception('Semester tidak valid');
    }

    if (empty($_FILES['csvfile']['tmp_name'])) {
        throw new \Exception('File CSV belum dipilih');
    }

    $tmpname = $_FILES['csvfile']['tmp_name'];
    $filename = $_FILES['csvfile']['name'] ?? '';

    $maxfilesize = 2 * 1024 * 1024;

    if ($_FILES['csvfile']['size'] > $maxfilesize) {
        throw new \Exception('Ukuran file maksimal 2 MB.');
    }

    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        throw new \Exception('File harus berformat CSV.');
    }
    // Validasi MIME type.
    $allowedmimes = [
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'text/plain',
    ];

    $filemime = function_exists('mime_content_type')
        ? mime_content_type($tmpname)
        : '';

    if ($filemime === false) {
        $filemime = '';
    }

    if ($filemime !== '' && !in_array($filemime, $allowedmimes, true)) {
        throw new \Exception('Tipe file CSV tidak valid.');
    }

    // ===== DETEKSI DELIMITER =====
    $samplehandle = fopen($tmpname, 'r');

    $firstline = '';
    while (($line = fgets($samplehandle)) !== false) {
        $line = trim($line);

        if ($line === '' || strtolower($line) === 'sep=;') {
            continue;
        }

        $firstline = $line;
        break;
    }
    fclose($samplehandle);

    if ($firstline === '') {
        throw new \Exception('File kosong');
    }

    $delimiter = local_akademikmonitor_detect_csv_delimiter($firstline);

    $handle = fopen($tmpname, 'r');

    $header = local_akademikmonitor_read_csv_header($handle, $delimiter);

    if (!$header) {
        throw new \Exception('Header tidak ditemukan');
    }

    $requiredcolumns = ['nisn', 'ekskul', 'predikat'];

    foreach ($requiredcolumns as $column) {
        if (!in_array($column, $header, true)) {
            throw new \Exception('Kolom wajib: nisn, ekskul, predikat');
        }
    }

    $nisnindex = array_search('nisn', $header, true);
    $ekskulindex = array_search('ekskul', $header, true);
    $predikatindex = array_search('predikat', $header, true);

    // ===== AMBIL FIELD NISN =====
    $field = $DB->get_record('user_info_field', ['shortname' => 'nisn'], 'id');
    

    if (!$field) {
        throw new \Exception('Field NISN tidak ditemukan');
    }
    $transaction = $DB->start_delegated_transaction();
    $imported = 0;
    $skipped = 0;
    $rownum = 1;
    $maxrows = 5000;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rownum++;
        if ($rownum > $maxrows) {
            throw new \Exception(
                'Jumlah baris maksimal ' . $maxrows . '.'
            );
        }

        if (empty(array_filter($row))) {
            continue;
        }

        $nisn = local_akademikmonitor_clean_csv_value((string)($row[$nisnindex] ?? ''));
        $namaekskul = local_akademikmonitor_clean_csv_value((string)($row[$ekskulindex] ?? ''));
        $predikat = strtoupper(local_akademikmonitor_clean_csv_value((string)($row[$predikatindex] ?? '')));

        // ===== VALIDASI DASAR =====
        if ($nisn === '' || $namaekskul === '' || $predikat === '') {
            $skipped++;
            continue;
        }

        if (!in_array($predikat, ['A', 'B', 'C', 'D'], true)) {
            $skipped++;
            continue;
        }

        // ===== CARI USER BERDASARKAN NISN =====
        $sql = "SELECT uid.userid
                  FROM {user_info_data} uid
                 WHERE uid.fieldid = :fieldid
                   AND " . $DB->sql_compare_text('uid.data') . " = " . $DB->sql_compare_text(':nisn');

        $userdata = $DB->get_record_sql($sql, [
            'fieldid' => $field->id,
            'nisn' => $nisn
        ]);

        if (!$userdata) {
            $skipped++;
            continue;
        }
        /*
            * Pastikan siswa benar-benar anggota kelas yang sedang diimport.
            */
            if (!$DB->record_exists('groups_members', [
                'groupid' => (int)$groupid,
                'userid'  => (int)$userdata->userid,
            ])) {
                $skipped++;
                continue;
            }

        // ===== CARI EKSKUL (FIX CASE INSENSITIVE) =====
        $ekskul = $DB->get_record_sql(
            "SELECT id FROM {ekskul} WHERE LOWER(TRIM(nama)) = LOWER(TRIM(:nama))",
            ['nama' => $namaekskul]
        );

        if (!$ekskul) {
            $skipped++;
            continue;
        }

        // ===== SIMPAN =====
        ekskul_service::save(
            (int)$userdata->userid,
            (int)$groupid,
            (int)$ekskul->id,
            (int)$semesteraktif,
            $predikat
        );

        $imported++;
    }

    fclose($handle);
    $transaction->allow_commit();
    redirect(
        new moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php', $redirectparams),
        "Import selesai. Berhasil: $imported, dilewati: $skipped",
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );

} catch (\Throwable $e) {
    if (isset($transaction)) {
        $transaction->rollback($e);
    }
    redirect(
        new moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php', $redirectparams),
        'Import gagal: ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}