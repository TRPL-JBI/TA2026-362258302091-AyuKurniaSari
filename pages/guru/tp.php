<?php
/**
 * Halaman Tujuan Pembelajaran untuk Guru.
 *
 * File BARU — tidak mengubah file apapun yang sudah ada.
 *
 * Konsep sesuai catatan Kepala Sekolah:
 * - CP dibuat oleh Admin (sama untuk semua guru dalam satu mapel).
 * - TP dibuat sendiri oleh masing-masing Guru (bisa berbeda meski mapel sama).
 * - TP yang disimpan guru tersinkron ke tabel {tujuan_pembelajaran} yang sama
 *   sehingga langsung terlihat di sisi Admin.
 *
 * URL: /local/akademikmonitor/pages/guru/tp.php?courseid=X
 *
 * Guru masuk lewat context course mereka sendiri. Plugin mendeteksi
 * kurikulum_mapel (kmid) yang terhubung ke course tersebut via tabel
 * {course_mapel_mapping} (atau nama alias yang ada di mapping_service).
 *
 * Fitur:
 * 1. Lihat daftar CP untuk mapel course ini (hanya baca, CP dibuat admin).
 * 2. Pilih CP → tampil form tambah TP manual (+ baris).
 * 3. Import TP via CSV untuk CP yang dipilih.
 * 4. Semua TP yang disimpan/diimport langsung tersinkron ke gradebook.
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();

global $DB, $PAGE, $OUTPUT, $USER;

$courseid = required_param('courseid', PARAM_INT);
$cpid     = optional_param('cpid', 0, PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA); // 'import'

// ── Pastikan user punya akses mengajar di course ini ─────────────────────────
$coursecontext = context_course::instance($courseid, MUST_EXIST);
require_capability('moodle/course:manageactivities', $coursecontext);
$roles = get_user_roles($coursecontext, $USER->id, false);

$iseditingteacher = false;

foreach ($roles as $role) {
    if ($role->shortname === 'editingteacher') {
        $iseditingteacher = true;
        break;
    }
}

if (!$iseditingteacher && !is_siteadmin()) {
    print_error(
        'nopermissions',
        'error',
        '',
        'mengelola TP course ini'
    );
}

$course = get_course($courseid);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/guru/tp.php', [
    'courseid' => $courseid,
    'cpid'     => $cpid,
    'action'   => $action,
]));
$PAGE->set_context($coursecontext);
$PAGE->set_title('Tujuan Pembelajaran — ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));

// ── Cari kurikulum_mapel yang terhubung ke course ini ────────────────────────
// Tabel mapping: {course_mapel_mapping} field: id_course, id_kurikulum_mapel
// Jika nama tabel berbeda, sesuaikan di sini.
$mapping = null;

if ($DB->get_manager()->table_exists('course_mapel')) {
    $mapping = $DB->get_record('course_mapel', ['id_course' => $courseid], '*', IGNORE_MISSING);
}

if (!$mapping && $DB->get_manager()->table_exists('course_mapel_mapping')) {
    $mapping = $DB->get_record('course_mapel_mapping', ['id_course' => $courseid], '*', IGNORE_MISSING);
}

if (!$mapping && $DB->get_manager()->table_exists('mapping_course_mapel')) {
    $mapping = $DB->get_record('mapping_course_mapel', ['id_course' => $courseid], '*', IGNORE_MISSING);
}

if (!$mapping) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'Course ini belum dipetakan ke Mata Pelajaran. Hubungi Admin untuk melakukan mapping terlebih dahulu melalui menu <strong>Mapping Course</strong>.',
        'warning'
    );
    echo $OUTPUT->footer();
    die();
}

$kmid = (int)($mapping->id_kurikulum_mapel ?? $mapping->kmid ?? 0);
if ($kmid <= 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Data mapping tidak valid. Hubungi Admin.', 'error');
    echo $OUTPUT->footer();
    die();
}

$km    = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);
$mapel = $DB->get_record('mata_pelajaran', ['id' => $km->id_mapel], '*', IGNORE_MISSING);
$kj    = $DB->get_record('kurikulum_jurusan', ['id' => $km->id_kurikulum_jurusan], '*', IGNORE_MISSING);
$jurusan = $kj ? $DB->get_record('jurusan', ['id' => $kj->id_jurusan], '*', IGNORE_MISSING) : null;

$nama_mapel_raw = $mapel ? (string)$mapel->nama_mapel : 'Mata Pelajaran';
$nama_mapel     = trim(preg_replace('/^\[.*?\]\s*/', '', $nama_mapel_raw)) ?: $nama_mapel_raw;
$nama_jurusan   = $jurusan ? format_string((string)$jurusan->nama_jurusan) : '-';


$tptablecolumns = $DB->get_columns('tujuan_pembelajaran');
$hasidcourse = isset($tptablecolumns['id_course']);
$hasstatus = isset($tptablecolumns['status']);

// ── Helper: baca CSV ─────────────────────────────────────────────────────────
function local_akademikmonitor_guru_tp_read_csv(string $filepath): array {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return [[], ['Tidak dapat membuka file CSV.']];
    }

    $header = fgetcsv($handle, 0, ';');
    if ($header === false) {
        fclose($handle);
        return [[], ['File CSV kosong atau formatnya tidak valid.']];
    }

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

    $rows   = [];
    $errors = [];
    $line   = 1;

    while (($data = fgetcsv($handle, 0, ';')) !== false) {
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

// ── Daftar CP untuk mapel ini (hanya baca) ───────────────────────────────────
$allcp = $DB->get_records('capaian_pembelajaran', ['id_kurikulum_mapel' => $kmid], 'id ASC');

// ── Proses POST: simpan TP manual ─────────────────────────────────────────────
$hasresult = false;
$issuccess = false;
$iserror   = false;
$resultmsg = '';
$rowerrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $postcpid  = required_param('cpid', PARAM_INT);
    $postaction = optional_param('postaction', 'manual', PARAM_ALPHA);

    // Validasi cpid milik kmid ini.
    $cp = $DB->get_record('capaian_pembelajaran', ['id' => $postcpid, 'id_kurikulum_mapel' => $kmid], '*', IGNORE_MISSING);
    if (!$cp) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid]),
            'CP tidak valid.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // ── POST: Edit TP ────────────────────────────────────────────────────────
    if ($postaction === 'update') {
        $tpid = required_param('tpid', PARAM_INT);
        $conditions = ['id' => $tpid, 'id_capaian_pembelajaran' => $postcpid];
        if ($hasidcourse) { $conditions['id_course'] = $courseid; }
        $tp = $DB->get_record('tujuan_pembelajaran', $conditions, '*', MUST_EXIST);
        $tp->konten = core_text::substr(optional_param('konten', '', PARAM_TEXT), 0, 100);
        $tp->kompetensi = core_text::substr(optional_param('kompetensi', '', PARAM_TEXT), 0, 255);
        $tp->dpl = core_text::substr(optional_param('dpl', '', PARAM_TEXT), 0, 255);
        $tp->atp = core_text::substr(optional_param('atp', '', PARAM_TEXT), 0, 255);
        $tp->deskripsi = core_text::substr(optional_param('deskripsi', '', PARAM_RAW_TRIMMED), 0, 255);
        if ($hasstatus) { $tp->status = optional_param('status', 'aktif', PARAM_ALPHA) === 'nonaktif' ? 'nonaktif' : 'aktif'; }
        if (trim((string)$tp->deskripsi) === '') {
            redirect(new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $postcpid]), 'Tujuan pembelajaran tidak boleh kosong.', null, \core\output\notification::NOTIFY_ERROR);
        }
        $DB->update_record('tujuan_pembelajaran', $tp);
        try { \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp_course((int)$tp->id, $courseid); } catch (\Throwable $e) {}
        redirect(new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $postcpid]), 'TP berhasil diperbarui.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // ── POST: Hapus TP ───────────────────────────────────────────────────────
    if ($postaction === 'delete') {
        $tpid = required_param('tpid', PARAM_INT);
        $conditions = ['id' => $tpid, 'id_capaian_pembelajaran' => $postcpid];
        if ($hasidcourse) { $conditions['id_course'] = $courseid; }
        $tp = $DB->get_record('tujuan_pembelajaran', $conditions, '*', MUST_EXIST);
        $hasgrades = false;
        try { $hasgrades = \local_akademikmonitor\service\tp_gradebook_service::tp_has_grades((int)$tp->id); } catch (\Throwable $e) { $hasgrades = false; }
        if ($hasgrades) {
            redirect(new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $postcpid]), 'TP tidak bisa dihapus karena sudah memiliki nilai.', null, \core\output\notification::NOTIFY_ERROR);
        }
        $DB->delete_records('tujuan_pembelajaran', ['id' => $tp->id]);
        redirect(new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $postcpid]), 'TP berhasil dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // ── POST: Import CSV ──────────────────────────────────────────────────────
    if ($postaction === 'import') {
        $hasresult = true;
        $dupmode   = optional_param('dupmode', 'skip', PARAM_ALPHA);
        $dupmode   = in_array($dupmode, ['skip', 'update'], true) ? $dupmode : 'skip';

        if (empty($_FILES['csvfile']) || !empty($_FILES['csvfile']['error'])) {
            $iserror   = true;
            $resultmsg = 'Upload gagal. Pastikan file CSV sudah dipilih.';
        } else {
            [$rows, $readerrors] = local_akademikmonitor_guru_tp_read_csv($_FILES['csvfile']['tmp_name']);
            foreach ($readerrors as $err) {
                $rowerrors[] = ['msg' => $err];
            }

            $inserted = 0; $updated = 0; $skipped = 0;

            foreach ($rows as $row) {
                $line       = (int)($row['_line'] ?? 0);
                $deskripsi  = trim((string)($row['deskripsi'] ?? ''));
                $konten     = core_text::substr(trim((string)($row['konten']     ?? '')), 0, 100);
                $kompetensi = core_text::substr(trim((string)($row['kompetensi'] ?? '')), 0, 255);
                $dpl        = core_text::substr(trim((string)($row['dpl']        ?? '')), 0, 255);
                $atp        = core_text::substr(trim((string)($row['atp']        ?? '')), 0, 255);
                $rowstatus  = strtolower(trim((string)($row['status'] ?? 'aktif')));
                $rowstatus  = in_array($rowstatus, ['aktif', 'nonaktif'], true) ? $rowstatus : 'aktif';

                if ($deskripsi === '') {
                    $skipped++;
                    $rowerrors[] = ['msg' => "Baris {$line}: kolom deskripsi kosong, dilewati."];
                    continue;
                }

                $comparetext = $DB->sql_compare_text('deskripsi');

                $existing = $DB->get_record_sql("
                    SELECT *
                    FROM {tujuan_pembelajaran}
                    WHERE id_capaian_pembelajaran = ?
                    AND id_course = ?
                    AND LOWER(TRIM({$comparetext})) = LOWER(TRIM(?))
                ", [$postcpid, $courseid, $deskripsi]);

                if ($existing) {
                    if ($dupmode === 'update') {
                        $existing->konten     = $konten;
                        $existing->kompetensi = $kompetensi;
                        $existing->dpl        = $dpl;
                        $existing->atp        = $atp;
                        $existing->deskripsi  = core_text::substr($deskripsi, 0, 255);
                        if ($hasidcourse) { $existing->id_course = $courseid; }
                        if ($hasstatus) { $existing->status = $rowstatus; }
                        $DB->update_record('tujuan_pembelajaran', $existing);
                        $updated++;
                    } else {
                        $skipped++;
                        $rowerrors[] = ['msg' => "Baris {$line}: TP sudah ada, dilewati."];
                    }
                    continue;
                }

                $record                          = new stdClass();
                $record->deskripsi               = core_text::substr($deskripsi, 0, 255);
                $record->konten                  = $konten;
                $record->kompetensi              = $kompetensi;
                $record->dpl                     = $dpl;
                $record->atp                     = $atp;
                $record->id_capaian_pembelajaran = $postcpid;
                $record->id_course = $courseid;
                if ($hasidcourse) { $record->id_course = $courseid; }
                if ($hasstatus) { $record->status = $rowstatus; }
                $newid = $DB->insert_record('tujuan_pembelajaran', $record);
                $inserted++;

                if ($newid > 0) {
                    try {
                        \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp_course((int)$newid, $courseid);
                    } catch (\Throwable $e) {
                        $rowerrors[] = ['msg' => "Baris {$line}: TP ditambahkan tapi sinkron gradebook gagal — " . $e->getMessage()];
                    }
                }
            }

            $issuccess = true;
            $resultmsg = "Import selesai. {$inserted} TP ditambahkan, {$updated} diperbarui, {$skipped} dilewati.";
        }

        // Setelah import, kembali ke tab CP yang sama
        $cpid = $postcpid;

    } else {
        // ── POST: Simpan TP Manual ────────────────────────────────────────────
        $deskripsilist  = optional_param_array('deskripsi',  [], PARAM_RAW_TRIMMED);
        $kontenlist     = optional_param_array('konten',     [], PARAM_TEXT);
        $kompetensilist = optional_param_array('kompetensi', [], PARAM_TEXT);
        $dpllist        = optional_param_array('dpl',        [], PARAM_TEXT);
        $atplist        = optional_param_array('atp',        [], PARAM_TEXT);

        $saved   = 0;
        $skipped = 0;

        foreach ($deskripsilist as $i => $deskripsi) {
            $deskripsi = trim((string)$deskripsi);
            if ($deskripsi === '') {
                $skipped++;
                continue;
            }

            $record                          = new stdClass();
            $record->deskripsi               = core_text::substr($deskripsi, 0, 255);
            $record->konten                  = core_text::substr(trim((string)($kontenlist[$i]     ?? '')), 0, 100);
            $record->kompetensi              = core_text::substr(trim((string)($kompetensilist[$i] ?? '')), 0, 255);
            $record->dpl                     = core_text::substr(trim((string)($dpllist[$i]        ?? '')), 0, 255);
            $record->atp                     = core_text::substr(trim((string)($atplist[$i]        ?? '')), 0, 255);
            $record->id_capaian_pembelajaran = $postcpid;
            $record->id_course = $courseid;
            if ($hasidcourse) { $record->id_course = $courseid; }
            if ($hasstatus) { $record->status = 'aktif'; }
            $newid = $DB->insert_record('tujuan_pembelajaran', $record);
            $saved++;

            if ($newid > 0) {
                try {
                    \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp_course((int)$newid, $courseid);
                } catch (\Throwable $e) {
                    // Gagal gradebook tidak menghentikan proses.
                }
            }
        }

        redirect(
            new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $postcpid]),
            "{$saved} TP berhasil disimpan" . ($skipped > 0 ? ", {$skipped} baris kosong dilewati." : "."),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// ── Ambil TP yang sudah ada untuk CP yang dipilih ────────────────────────────
$existing_tp = [];
if ($cpid > 0) {
    $tp_records = $DB->get_records('tujuan_pembelajaran', [
        'id_capaian_pembelajaran' => $cpid,
        'id_course' => $courseid
    ], 'id ASC');

    $no = 1;
    foreach ($tp_records as $tp) {
        $tp_bernilai = false;
        try {
            $tp_bernilai = \local_akademikmonitor\service\tp_gradebook_service::tp_has_grades((int)$tp->id);
        } catch (\Throwable $e) {
            $tp_bernilai = false;
        }

        // Hapus hanya dikunci kalau TP sudah memiliki nilai.
        // Kalau baru terhubung ke gradebook/course tetapi belum ada nilai, guru tetap boleh menghapus.
        $can_delete = !$tp_bernilai;

        $existing_tp[] = [
            'no'         => $no++,
            'id'         => (int)$tp->id,
            'konten'     => format_string((string)($tp->konten ?? '')),
            'kompetensi' => format_string((string)($tp->kompetensi ?? '')),
            'dpl'        => format_string((string)($tp->dpl ?? '')),
            'atp'        => format_string((string)($tp->atp ?? '')),
            'deskripsi'  => format_string((string)$tp->deskripsi),
            'edit_url'   => (new moodle_url('/local/akademikmonitor/pages/guru/edit_tp.php', [
                'id' => $tp->id,
                'courseid' => $courseid,
                'cpid' => $cpid
            ]))->out(false),
           'delete_url' => (new moodle_url(
                '/local/akademikmonitor/pages/guru/delete_tp.php'
            ))->out(false),

            'courseid'   => $courseid,
            'cpid'       => $cpid,
            'sesskey'    => sesskey(),

            'can_delete' => $can_delete,
            'tp_bernilai'=> $tp_bernilai,
        ];
    }
}

// ── Susun daftar CP untuk dropdown/pilihan ───────────────────────────────────
$cp_list = [];
foreach ($allcp as $cp_rec) {
    $cp_list[] = [
        'id'       => (int)$cp_rec->id,
        'deskripsi'=> format_text((string)$cp_rec->deskripsi, FORMAT_PLAIN),
        'selected' => $cpid > 0 && (int)$cp_rec->id === $cpid,
        'tp_url'   => (new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $cp_rec->id]))->out(false),
        'import_url' => (new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid, 'cpid' => $cp_rec->id, 'action' => 'import']))->out(false),
    ];
}

$selected_cp = null;
if ($cpid > 0 && isset($allcp[$cpid])) {
    $selected_cp = $allcp[$cpid];
}

// ── Template context ─────────────────────────────────────────────────────────
$show_import_form = $action === 'import' && $cpid > 0;
$show_tp_form     = $cpid > 0 && !$show_import_form;

$templatecontext = [
    'courseid'         => $courseid,
    'course_name'      => format_string($course->fullname),
    'nama_mapel'       => $nama_mapel,
    'nama_jurusan'     => $nama_jurusan,
    'tingkat'          => s((string)($km->tingkat_kelas ?? '-')),
    'cpid'             => $cpid,
    'cp_list'          => $cp_list,
    'has_cp'           => !empty($cp_list),
    'selected_cp_deskripsi' => $selected_cp ? format_text((string)$selected_cp->deskripsi, FORMAT_PLAIN) : '',
    'existing_tp'      => $existing_tp,
    'has_existing_tp'  => !empty($existing_tp),
    'show_tp_form'     => $show_tp_form,
    'show_import_form' => $show_import_form,
    'action_url'       => (new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $courseid]))->out(false),
    'back_url'         => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
    'sesskey'          => sesskey(),
    'has_result'       => $hasresult,
    'is_success'       => $issuccess,
    'is_error'         => $iserror,
    'result_msg'       => $resultmsg,
    'row_errors'       => $rowerrors,
    'has_row_errors'   => !empty($rowerrors),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/guru_tp', $templatecontext);
echo $OUTPUT->footer();
