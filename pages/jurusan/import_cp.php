<?php

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $PAGE, $OUTPUT;

$kmid = required_param('kmid', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/import_cp.php', ['kmid' => $kmid]));
$PAGE->set_context($context);
$PAGE->set_title('Import Capaian Pembelajaran');
$PAGE->set_heading('Import Capaian Pembelajaran');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

// ── Helper: sidebar URLs (sama seperti file lain di jurusan/) ────────────────
function local_akademikmonitor_import_cp_sidebar_urls(string $active): array {
    return [
        'is_dashboard'        => $active === 'dashboard',
        'is_tahun_ajaran'     => $active === 'tahun_ajaran',
        'is_kurikulum'        => $active === 'kurikulum',
        'is_manajemen_jurusan'=> $active === 'jurusan',
        'is_manajemen_kelas'  => $active === 'kelas',
        'is_mata_pelajaran'   => $active === 'mata_pelajaran',
        'is_matpel'           => $active === 'mata_pelajaran',
        'is_kktp'             => $active === 'kktp',
        'is_notif'            => $active === 'notif',
        'is_ekskul'           => $active === 'ekskul',
        'is_mitra'            => $active === 'mitra',
        'dashboard_url'       => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url'    => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url'       => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url'  => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url'          => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url'            => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url'           => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url'          => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url'           => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
    ];
}

// ── Helper: baca CSV ─────────────────────────────────────────────────────────
function local_akademikmonitor_import_cp_read_csv(string $filepath): array {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return [[], ['Tidak dapat membuka file CSV.']];
    }

$header = fgetcsv($handle, 0, ';');

if ($header === false) {
    fclose($handle);
    return [[], ['File CSV kosong atau formatnya tidak valid.']];
}

$header = array_map('trim', $header);

    // Hapus BOM UTF-8 jika ada.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

    $rows   = [];
    $errors = [];
    $line   = 1;

    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        $line++;
        // Lewati baris kosong.
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

// ── Ambil data konteks ───────────────────────────────────────────────────────
$km    = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);
$mapel = $DB->get_record('mata_pelajaran', ['id' => $km->id_mapel], '*', IGNORE_MISSING);
$kj    = $DB->get_record('kurikulum_jurusan', ['id' => $km->id_kurikulum_jurusan], '*', IGNORE_MISSING);
$jurusan = $kj ? $DB->get_record('jurusan', ['id' => $kj->id_jurusan], '*', IGNORE_MISSING) : null;

$nama_mapel  = $mapel  ? format_string((string)$mapel->nama_mapel)   : '-';
$nama_jurusan = $jurusan ? format_string((string)$jurusan->nama_jurusan) : '-';

// ── Proses POST ──────────────────────────────────────────────────────────────
$hasresult  = false;
$issuccess  = false;
$iserror    = false;
$resultmsg  = '';
$rowerrors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_sesskey();

    $hasresult = true;

    $dupmode = optional_param('dupmode', 'skip', PARAM_ALPHA);
    $dupmode = in_array($dupmode, ['skip', 'update'], true)
        ? $dupmode
        : 'skip';

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

        $rows = [];
        $readerrors = [];

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {

            $iserror = true;
            $resultmsg = 'File harus berformat CSV.';

        } else {

            [$rows, $readerrors] = local_akademikmonitor_import_cp_read_csv(
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
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        if (!$iserror) {

            $transaction = $DB->start_delegated_transaction();

            try {

                $comparetext = $DB->sql_compare_text('deskripsi');

                foreach ($rows as $row) {

                    $line = (int)($row['_line'] ?? 0);

                    $deskripsi = trim(
                        (string)($row['deskripsi_cp'] ?? $row['deskripsi'] ?? '')
                    );

                    if ($deskripsi === '') {

                        $skipped++;

                        $rowerrors[] = [
                            'msg' => "Baris {$line}: deskripsi_cp kosong, dilewati."
                        ];

                        continue;
                    }

                    $existing = $DB->get_record_sql("
                        SELECT *
                          FROM {capaian_pembelajaran}
                         WHERE id_kurikulum_mapel = ?
                           AND LOWER(TRIM({$comparetext})) = LOWER(TRIM(?))
                    ", [
                        $kmid,
                        $deskripsi
                    ], IGNORE_MISSING);

                    if ($existing) {

                        if ($dupmode === 'update') {

                            $existing->deskripsi = $deskripsi;

                            $DB->update_record(
                                'capaian_pembelajaran',
                                $existing
                            );

                            $updated++;

                        } else {

                            $skipped++;

                            $rowerrors[] = [
                                'msg' => "Baris {$line}: CP sudah ada, dilewati."
                            ];
                        }

                        continue;
                    }

                    $record = new stdClass();
                    $record->deskripsi = $deskripsi;
                    $record->id_kurikulum_mapel = $kmid;

                    $DB->insert_record(
                        'capaian_pembelajaran',
                        $record
                    );

                    $inserted++;
                }

                $transaction->allow_commit();

                $issuccess = true;
                $iserror = false;

                $resultmsg =
                    "Import selesai. {$inserted} CP ditambahkan, {$updated} diperbarui, {$skipped} dilewati.";

            } catch (Throwable $e) {

                $transaction->rollback($e);

                $issuccess = false;
                $iserror = true;

                $resultmsg = 'Import gagal: ' . $e->getMessage();
            }
        }
    }
}

// ── Template context ─────────────────────────────────────────────────────────
$templatecontext = array_merge(local_akademikmonitor_import_cp_sidebar_urls('jurusan'), [
    'kmid'         => $kmid,
    'nama_mapel'   => $nama_mapel,
    'nama_jurusan' => $nama_jurusan,
    'tingkat'      => s((string)($km->tingkat_kelas ?? '-')),
    'action_url'   => (new moodle_url('/local/akademikmonitor/pages/jurusan/import_cp.php', ['kmid' => $kmid]))->out(false),
    'back_url'     => (new moodle_url('/local/akademikmonitor/pages/jurusan/capaian_pembelajaran.php', ['kmid' => $kmid]))->out(false),
    'sesskey'      => sesskey(),
    'has_result'   => $hasresult,
    'is_success'   => $issuccess,
    'is_error'     => $iserror,
    'result_msg'   => $resultmsg,
    'row_errors'   => $rowerrors,
    'has_row_errors' => !empty($rowerrors),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/import_cp', $templatecontext);
echo $OUTPUT->footer();
