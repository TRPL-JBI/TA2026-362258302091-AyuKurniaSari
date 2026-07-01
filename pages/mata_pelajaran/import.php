<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB, $PAGE, $OUTPUT;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/import.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Import Mata Pelajaran');
$PAGE->set_heading('Import Mata Pelajaran');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_import_sidebar_urls(string $active): array {
    return [
        'is_dashboard' => $active === 'dashboard',
        'is_tahun_ajaran' => $active === 'tahun_ajaran',
        'is_kurikulum' => $active === 'kurikulum',
        'is_manajemen_jurusan' => $active === 'jurusan',
        'is_manajemen_kelas' => $active === 'kelas',
        'is_mata_pelajaran' => $active === 'mata_pelajaran',
        'is_matpel' => $active === 'mata_pelajaran',
        'is_kktp' => $active === 'kktp',
        'is_notif' => $active === 'notif',
        'is_ekskul' => $active === 'ekskul',
        'is_mitra' => $active === 'mitra',
        'dashboard_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
    ];
}

function local_akademikmonitor_read_csv_rows(string $filepath): array {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return [[], ['Tidak dapat membaca file CSV.']];
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return [[], ['CSV kosong atau formatnya tidak valid.']];
    }

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

    $rows = [];
    $errors = [];
    $line = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $line++;
        if (count(array_filter($data, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($header as $idx => $name) {
            if ($name !== '') {
                $row[$name] = trim((string)($data[$idx] ?? ''));
            }
        }
        $row['_line'] = $line;
        $rows[] = $row;
    }
    fclose($handle);

    return [$rows, $errors];
}

$hasresult = false;
$issuccess = false;
$iserror = false;
$resultmsg = '';
$rowerrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $hasresult = true;
    $dupmode = optional_param('dupmode', 'skip', PARAM_ALPHA);
    $dupmode = in_array($dupmode, ['skip', 'update'], true) ? $dupmode : 'skip';

    if (empty($_FILES['csvfile']) || !empty($_FILES['csvfile']['error'])) {
        $iserror = true;
        $resultmsg = 'Upload gagal. Pastikan file CSV sudah dipilih.';
} else {
    $maxfilesize = 2 * 1024 * 1024;

    if ($_FILES['csvfile']['size'] > $maxfilesize) {
        throw new moodle_exception(
            'Ukuran file maksimal 2 MB.'
        );
    }
    $filename = $_FILES['csvfile']['name'] ?? '';

    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {

        $iserror = true;
        $resultmsg = 'File harus berformat CSV.';

    } else {


        [$rows, $readerrors] = local_akademikmonitor_read_csv_rows(
            $_FILES['csvfile']['tmp_name']
        );
        $maxrows = 5000;

        if (count($rows) > $maxrows) {
            throw new moodle_exception(
                'Jumlah baris maksimal ' . $maxrows . '.'
            );
        }

        foreach ($readerrors as $err) {
            $rowerrors[] = ['msg' => $err];
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
$transaction = $DB->start_delegated_transaction();
try {
        foreach ($rows as $row) {
            $line = (int)($row['_line'] ?? 0);
            $nama = trim((string)($row['nama_mapel'] ?? $row['mapel'] ?? $row['nama'] ?? ''));
            $kategori = trim((string)($row['kategori'] ?? ''));

            if ($nama === '') {
                $skipped++;
                $rowerrors[] = ['msg' => "Baris {$line}: nama_mapel kosong, dilewati."];
                continue;
            }

            if ($kategori !== '' && !preg_match('/^\[.*?\]\s*/', $nama)) {
                $nama = '[' . core_text::strtolower($kategori) . '] ' . $nama;
            }

            $nama = core_text::substr($nama, 0, 255);
            $existing = $DB->get_record('mata_pelajaran', ['nama_mapel' => $nama], '*', IGNORE_MISSING);

            if ($existing) {
                if ($dupmode === 'update') {
                    $existing->nama_mapel = $nama;
                    $DB->update_record('mata_pelajaran', $existing);
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $record = (object)['nama_mapel' => $nama];
            $DB->insert_record('mata_pelajaran', $record);
            $inserted++;
        }
$transaction->allow_commit();
        $issuccess = true;
        $iserror = false;
        $resultmsg = "Import selesai. {$inserted} mata pelajaran ditambahkan, {$updated} diperbarui, {$skipped} dilewati.";
        } catch (Throwable $e) {

    $transaction->rollback($e);

    throw $e;
}
    }
}
}

$templatecontext = array_merge(local_akademikmonitor_import_sidebar_urls('mata_pelajaran'), [
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/import.php'))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
    'sesskey' => sesskey(),
    'has_result' => $hasresult,
    'is_success' => $issuccess,
    'is_error' => $iserror,
    'result_msg' => $resultmsg,
    'row_errors' => $rowerrors,
    'has_row_errors' => !empty($rowerrors),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/import_mapel', $templatecontext);
echo $OUTPUT->footer();
