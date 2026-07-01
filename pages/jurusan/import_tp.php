<?php
/**
 * Import Tujuan Pembelajaran via CSV (Admin, per CP dan per Course).
 *
 * Tidak membuat tabel baru. TP dikaitkan ke course melalui kolom {tujuan_pembelajaran}.id_course.
 */

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $PAGE, $OUTPUT;

$kmid = required_param('kmid', PARAM_INT);
$cpid = required_param('cpid', PARAM_INT);
$selectedcourseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/import_tp.php', ['kmid' => $kmid, 'cpid' => $cpid]));
$PAGE->set_context($context);
$PAGE->set_title('Import Tujuan Pembelajaran');
$PAGE->set_heading('Import Tujuan Pembelajaran');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_import_tp_admin_sidebar_urls(string $active): array {
    return [
        'is_dashboard' => $active === 'dashboard', 'is_tahun_ajaran' => $active === 'tahun_ajaran',
        'is_kurikulum' => $active === 'kurikulum', 'is_manajemen_jurusan' => $active === 'jurusan',
        'is_manajemen_kelas' => $active === 'kelas', 'is_mata_pelajaran' => $active === 'mata_pelajaran',
        'is_matpel' => $active === 'mata_pelajaran', 'is_kktp' => $active === 'kktp',
        'is_notif' => $active === 'notif', 'is_ekskul' => $active === 'ekskul', 'is_mitra' => $active === 'mitra',
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

function local_akademikmonitor_import_tp_admin_read_csv(string $filepath): array {
    $handle = fopen($filepath, 'r');
    if (!$handle) { return [[], ['Tidak dapat membuka file CSV.']]; }
    $header = fgetcsv($handle, 0, ';');
    if ($header === false) { fclose($handle); return [[], ['File CSV kosong atau formatnya tidak valid.']]; }
    if (isset($header[0])) { $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]); }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $rows = []; $errors = []; $line = 1;
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        $line++;
        if (count(array_filter($data, static fn($v) => trim((string)$v) !== '')) === 0) { continue; }
        $row = [];
        foreach ($header as $idx => $name) { if ($name !== '') { $row[$name] = trim((string)($data[$idx] ?? '')); } }
        $row['_line'] = $line;
        $rows[] = $row;
    }
    fclose($handle);
    return [$rows, $errors];
}

function local_akademikmonitor_import_tp_admin_teacher_names(int $courseid): string {
    global $DB;
    if ($courseid <= 0) { return '-'; }

    $ctx = context_course::instance($courseid, IGNORE_MISSING);
    if (!$ctx) { return '-'; }

    // Guru pengampu utama diambil dari role editingteacher hasil Generate Course.
    // Role teacher/non-editing teacher tidak ditampilkan agar wali kelas/viewer tidak ikut muncul.
    $teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, ra.id AS raid
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.contextid = :ctxid
            AND r.shortname = :roleshortname
          ORDER BY ra.id DESC",
        ['ctxid' => $ctx->id, 'roleshortname' => 'editingteacher'],
        0,
        1
    );

    if (!$teachers) { return '-'; }
    $teacher = reset($teachers);
    return fullname($teacher);
}

$km      = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);
$cp      = $DB->get_record('capaian_pembelajaran', ['id' => $cpid, 'id_kurikulum_mapel' => $kmid], '*', MUST_EXIST);
$mapel   = $DB->get_record('mata_pelajaran', ['id' => $km->id_mapel], '*', IGNORE_MISSING);
$kj      = $DB->get_record('kurikulum_jurusan', ['id' => $km->id_kurikulum_jurusan], '*', IGNORE_MISSING);
$jurusan = $kj ? $DB->get_record('jurusan', ['id' => $kj->id_jurusan], '*', IGNORE_MISSING) : null;

$nama_mapel = $mapel ? format_string((string)$mapel->nama_mapel) : '-';
$nama_jurusan = $jurusan ? format_string((string)$jurusan->nama_jurusan) : '-';
$selectedcourseid = optional_param('courseid', 0, PARAM_INT);
$courseoptions = [];
$courses = $DB->get_records_sql("SELECT c.id, c.fullname, c.shortname
       FROM {course} c
       JOIN {course_mapel} cm ON cm.id_course = c.id
      WHERE cm.id_kurikulum_mapel = :kmid
      ORDER BY c.fullname ASC", ['kmid' => $kmid]);
      
foreach ($courses as $c) {
    $teachers = local_akademikmonitor_import_tp_admin_teacher_names((int)$c->id);
    $courseoptions[] = [
        'id' => (int)$c->id,
        'label' => format_string($c->fullname) . ' — Guru: ' . $teachers,
        'selected' => (int)$c->id === $selectedcourseid,
    ];
}

$hasresult = false; $issuccess = false; $iserror = false; $resultmsg = ''; $rowerrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $hasresult = true;
    $selectedcourseid = optional_param('courseid', 0, PARAM_INT);
    $dupmode = optional_param('dupmode', 'skip', PARAM_ALPHA);
    $dupmode = in_array($dupmode, ['skip', 'update'], true) ? $dupmode : 'skip';

    if (!$DB->record_exists('course_mapel', ['id_course' => $selectedcourseid, 'id_kurikulum_mapel' => $kmid])) {
        $iserror = true;
        $resultmsg = 'Course tidak valid untuk mata pelajaran/kelas ini.';
    } else if (empty($_FILES['csvfile']) || !empty($_FILES['csvfile']['error'])) {
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
        [$rows, $readerrors] = local_akademikmonitor_import_tp_admin_read_csv($_FILES['csvfile']['tmp_name']);
        $maxrows = 5000;

        if (count($rows) > $maxrows) {
            throw new moodle_exception(
                'Jumlah baris maksimal ' . $maxrows . '.'
            );
        }
        foreach ($readerrors as $err) { $rowerrors[] = ['msg' => $err]; }
        }
        
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        if (!$iserror) {

            $transaction = $DB->start_delegated_transaction();

            try {

                $comparetext = $DB->sql_compare_text('deskripsi');

                foreach ($rows as $row) {

                    $line = (int)($row['_line'] ?? 0);

                    $deskripsi = trim((string)($row['deskripsi'] ?? ''));

                    $konten = core_text::substr(trim((string)($row['konten'] ?? '')), 0, 100);
                    $kompetensi = core_text::substr(trim((string)($row['kompetensi'] ?? '')), 0, 255);
                    $dpl = core_text::substr(trim((string)($row['dpl'] ?? '')), 0, 255);
                    $atp = core_text::substr(trim((string)($row['atp'] ?? '')), 0, 255);

                    $status = strtolower(trim((string)($row['status'] ?? 'aktif')));
                    $status = in_array($status, ['aktif', 'nonaktif'], true)
                        ? $status
                        : 'aktif';

                    if ($deskripsi === '') {
                        $skipped++;
                        $rowerrors[] = [
                            'msg' => "Baris {$line}: kolom deskripsi kosong, dilewati."
                        ];
                        continue;
                    }

                    $existing = $DB->get_record_sql("
                        SELECT *
                        FROM {tujuan_pembelajaran}
                        WHERE id_capaian_pembelajaran = ?
                        AND id_course = ?
                        AND LOWER(TRIM({$comparetext})) = LOWER(TRIM(?))
                    ", [
                        $cpid,
                        $selectedcourseid,
                        core_text::substr($deskripsi, 0, 255)
                    ]);

                    if ($existing) {

                        if ($dupmode === 'update') {

                            $existing->konten = $konten;
                            $existing->kompetensi = $kompetensi;
                            $existing->dpl = $dpl;
                            $existing->atp = $atp;
                            $existing->deskripsi = core_text::substr($deskripsi, 0, 255);
                            $existing->status = $status;

                            $DB->update_record('tujuan_pembelajaran', $existing);

                            $updated++;

                            try {
                                \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp_course(
                                    (int)$existing->id,
                                    $selectedcourseid
                                );
                            } catch (\Throwable $e) {
                                // Sinkron gradebook gagal tidak membatalkan import.
                            }

                        } else {

                            $skipped++;

                            $rowerrors[] = [
                                'msg' => "Baris {$line}: TP sudah ada pada course ini, dilewati."
                            ];
                        }

                        continue;
                    }

                    $record = new stdClass();
                    $record->deskripsi = core_text::substr($deskripsi, 0, 255);
                    $record->konten = $konten;
                    $record->kompetensi = $kompetensi;
                    $record->dpl = $dpl;
                    $record->atp = $atp;
                    $record->id_capaian_pembelajaran = $cpid;
                    $record->id_course = $selectedcourseid;
                    $record->status = $status;

                    $newid = $DB->insert_record('tujuan_pembelajaran', $record);

                    $inserted++;

                    if ($newid > 0) {

                        try {

                            \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp_course(
                                (int)$newid,
                                $selectedcourseid
                            );

                        } catch (\Throwable $e) {

                            $rowerrors[] = [
                                'msg' => "Baris {$line}: TP ditambahkan tetapi sinkronisasi gradebook gagal - " . $e->getMessage()
                            ];

                        }
                    }
                }

                $transaction->allow_commit();

                $issuccess = true;
                $iserror = false;

                $resultmsg = "Import selesai untuk course terpilih. {$inserted} TP ditambahkan, {$updated} diperbarui, {$skipped} dilewati.";

            } catch (\Throwable $e) {

                $transaction->rollback($e);

                $issuccess = false;
                $iserror = true;

                $resultmsg = 'Import gagal: ' . $e->getMessage();

            }

        }
    }
}

$templatecontext = array_merge(local_akademikmonitor_import_tp_admin_sidebar_urls('jurusan'), [
    'kmid' => $kmid, 'cpid' => $cpid, 'cp_deskripsi' => format_text($cp->deskripsi, FORMAT_PLAIN),
    'nama_mapel' => $nama_mapel, 'nama_jurusan' => $nama_jurusan, 'tingkat' => s((string)($km->tingkat_kelas ?? '-')),
    'course_options' => $courseoptions, 'has_courses' => !empty($courseoptions),
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/import_tp.php', ['kmid' => $kmid, 'cpid' => $cpid]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid]))->out(false),
    'sesskey' => sesskey(), 'has_result' => $hasresult, 'is_success' => $issuccess, 'is_error' => $iserror,
    'result_msg' => $resultmsg, 'row_errors' => $rowerrors, 'has_row_errors' => !empty($rowerrors),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/import_tp_admin', $templatecontext);
echo $OUTPUT->footer();
