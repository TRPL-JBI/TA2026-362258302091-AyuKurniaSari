<?php

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $PAGE, $OUTPUT;

$kmid = required_param('kmid', PARAM_INT);
$cpid = required_param('cpid', PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$filtercourseid = optional_param('filtercourseid', 0, PARAM_INT);
// $searchtp = optional_param('searchtp', '', PARAM_RAW_TRIMMED);
// $searchguru = optional_param('searchguru', '', PARAM_RAW_TRIMMED);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid]));
$PAGE->set_context($context);
$PAGE->set_title('Tujuan Pembelajaran');
$PAGE->set_heading('Tujuan Pembelajaran');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_backend_admin_urls(string $active): array {
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

function local_akademikmonitor_backend_tp_teacher_names(int $courseid): string {
    global $DB;
    if ($courseid <= 0) { return '-'; }

    $ctx = context_course::instance($courseid, IGNORE_MISSING);
    if (!$ctx) { return '-'; }

    // Guru pengampu utama adalah role editingteacher yang dipilih saat Generate Course.
    // Role teacher/non-editing teacher tidak dipakai di sini karena biasanya dipakai wali kelas/viewer.
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

function local_akademikmonitor_backend_tahun_label(?stdClass $tahun): string {
    if (!$tahun) { return '-'; }
    if (property_exists($tahun, 'tahun_ajaran')) { return (string)$tahun->tahun_ajaran; }
    if (property_exists($tahun, 'nama')) { return (string)$tahun->nama; }
    return '-';
}
function local_akademikmonitor_backend_kurikulum_namefield(): string {
    global $DB;
    $columns = $DB->get_columns('kurikulum');
    return isset($columns['nama']) ? 'nama' : 'nama_kurikulum';
}
function local_akademikmonitor_backend_active_tahun(): ?stdClass {
    global $DB;
    $activeid = (int)get_config('local_akademikmonitor', 'active_tahunajaranid');
    if ($activeid > 0) {
        $record = $DB->get_record('tahun_ajaran', ['id' => $activeid], '*', IGNORE_MISSING);
        if ($record) { return $record; }
    }
    $columns = $DB->get_columns('tahun_ajaran');
    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('tahun_ajaran', [$field => 1], '*', IGNORE_MULTIPLE);
            if ($record) { return $record; }
        }
    }
    $records = $DB->get_records('tahun_ajaran', null, 'id DESC', '*', 0, 1);
    return $records ? reset($records) : null;
}
function local_akademikmonitor_backend_active_kurikulum(): ?stdClass {
    global $DB;
    $activeid = (int)get_config('local_akademikmonitor', 'active_kurikulumid');
    if ($activeid > 0) {
        $record = $DB->get_record('kurikulum', ['id' => $activeid], '*', IGNORE_MISSING);
        if ($record) { return $record; }
    }
    $columns = $DB->get_columns('kurikulum');
    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('kurikulum', [$field => 1], '*', IGNORE_MULTIPLE);
            if ($record) { return $record; }
        }
    }
    $records = $DB->get_records('kurikulum', null, 'id DESC', '*', 0, 1);
    return $records ? reset($records) : null;
}
function local_akademikmonitor_backend_ensure_kurikulum_jurusan(int $jurusanid, ?int $kurikulumid = null, ?int $tahunajaranid = null): ?stdClass {
    global $DB;
    if ($jurusanid <= 0) { return null; }
    if (empty($kurikulumid)) { $kurikulum = local_akademikmonitor_backend_active_kurikulum(); $kurikulumid = $kurikulum ? (int)$kurikulum->id : 0; }
    if (empty($tahunajaranid)) { $tahun = local_akademikmonitor_backend_active_tahun(); $tahunajaranid = $tahun ? (int)$tahun->id : 0; }
    if ($kurikulumid <= 0 || $tahunajaranid <= 0) { return null; }
    $existing = $DB->get_record('kurikulum_jurusan', ['id_jurusan'=>$jurusanid, 'id_kurikulum'=>$kurikulumid, 'id_tahun_ajaran'=>$tahunajaranid], '*', IGNORE_MULTIPLE);
    if ($existing) { return $existing; }
    $record = new stdClass();
    $record->id_jurusan = $jurusanid;
    $record->id_kurikulum = $kurikulumid;
    $record->id_tahun_ajaran = $tahunajaranid;
    $record->id = $DB->insert_record('kurikulum_jurusan', $record);
    return $record;
}
function local_akademikmonitor_backend_get_kurikulum_jurusan(int $jurusanid, ?int $tahunajaranid = null): ?stdClass {
    global $DB;
    if ($jurusanid <= 0) { return null; }
    $activekurikulum = local_akademikmonitor_backend_active_kurikulum();
    $activetahun = local_akademikmonitor_backend_active_tahun();
    $kurikulumid = $activekurikulum ? (int)$activekurikulum->id : 0;
    $tahunid = $tahunajaranid ?: ($activetahun ? (int)$activetahun->id : 0);
    if ($kurikulumid > 0 && $tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', ['id_jurusan'=>$jurusanid, 'id_kurikulum'=>$kurikulumid, 'id_tahun_ajaran'=>$tahunid], '*', IGNORE_MULTIPLE);
        if ($record) { return $record; }
        return local_akademikmonitor_backend_ensure_kurikulum_jurusan($jurusanid, $kurikulumid, $tahunid);
    }
    if ($tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', ['id_jurusan'=>$jurusanid, 'id_tahun_ajaran'=>$tahunid], '*', IGNORE_MULTIPLE);
        if ($record) { return $record; }
    }
    $records = $DB->get_records('kurikulum_jurusan', ['id_jurusan'=>$jurusanid], 'id DESC', '*', 0, 1);
    return $records ? reset($records) : null;
}
function local_akademikmonitor_backend_normalize_tingkat(string $tingkat): string {
    $tingkat = strtoupper(trim($tingkat));
    return in_array($tingkat, ['X', 'XI', 'XII'], true) ? $tingkat : 'X';
}
function local_akademikmonitor_backend_jam(string $jam): string {
    $jam = trim($jam);
    if ($jam === '') { return ''; }
    if (preg_match('/^\d{1,2}$/', $jam)) { return $jam; }
    return substr($jam, 0, 8);
}

$km = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);
$cp = $DB->get_record('capaian_pembelajaran', ['id' => $cpid, 'id_kurikulum_mapel' => $kmid], '*', MUST_EXIST);
$tptablecolumns = $DB->get_columns('tujuan_pembelajaran');
$hasKonten = isset($tptablecolumns['konten']);
$hasidcourse = isset($tptablecolumns['id_course']);
$hasstatus = isset($tptablecolumns['status']);

if ($deleteid > 0) {
    require_sesskey();
    $tp = $DB->get_record('tujuan_pembelajaran', ['id' => $deleteid, 'id_capaian_pembelajaran' => $cpid], '*', MUST_EXIST);

    /**
     * Proteksi: jika TP sudah terhubung ke gradebook DAN sudah ada nilai (finalgrade),
     * maka TP tidak boleh dihapus. Guru hanya bisa Edit.
     *
     * Rantai pengecekan:
     *   tujuan_pembelajaran → grade_items_tp → grade_items
     *                      → grade_grades (finalgrade IS NOT NULL)
     */
    $sudah_dinilai = \local_akademikmonitor\service\tp_gradebook_service::tp_has_grades((int)$tp->id);

    if ($sudah_dinilai) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', [
                'kmid' => $kmid,
                'cpid' => $cpid,
            ]),
            'Tujuan Pembelajaran tidak dapat dihapus karena sudah memiliki nilai di gradebook. '
            . 'Lakukan Edit jika perlu perubahan, atau hapus nilai di gradebook terlebih dahulu.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Jika belum ada nilai, TP boleh dihapus walaupun sudah terhubung ke course/gradebook.
    // Struktur gradebook TP akan dibersihkan sebelum data TP dihapus.

    // Aman untuk dihapus.
    $DB->delete_records('assignment_tp', ['id_tp' => $tp->id]);
    $DB->delete_records('quiz_tp',       ['id_tp' => $tp->id]);
    try {
        \local_akademikmonitor\service\tp_gradebook_service::delete_grade_items_for_tp((int)$tp->id);
    } catch (\Throwable $e) {
        // Jika pembersihan gradebook gagal, proses hapus data TP tetap dilanjutkan jika belum ada nilai.
    }

    $DB->delete_records('tujuan_pembelajaran', ['id' => $tp->id]);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', [
            'kmid' => $kmid,
            'cpid' => $cpid,
            'filtercourseid' => $filtercourseid,
        ]),
        'Tujuan pembelajaran berhasil dihapus.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $kontens = optional_param_array('konten', [], PARAM_TEXT);
    $kompetensis = optional_param_array('kompetensi', [], PARAM_TEXT);
    $dpls = optional_param_array('dpl', [], PARAM_TEXT);
    $atps = optional_param_array('atp', [], PARAM_TEXT);
    $deskripsis = optional_param_array('deskripsi', [], PARAM_RAW_TRIMMED);

    $inserted = 0;
    $max = max(count($kontens), count($kompetensis), count($dpls), count($atps), count($deskripsis));
    for ($i = 0; $i < $max; $i++) {
        $deskripsi = trim((string)($deskripsis[$i] ?? ''));
        $kompetensi = trim((string)($kompetensis[$i] ?? ''));
        $konten = trim((string)($kontens[$i] ?? ''));
        $dpl = trim((string)($dpls[$i] ?? ''));
        $atp = trim((string)($atps[$i] ?? ''));

        if ($deskripsi === '' && $kompetensi === '' && $konten === '' && $dpl === '' && $atp === '') {
            continue;
        }
        if ($deskripsi === '') {
            continue;
        }

        $data = new stdClass();
        if ($hasKonten) {
            $data->konten = substr($konten, 0, 100);
        }
        $data->kompetensi = $kompetensi;
        $data->dpl = $dpl;
        $data->atp = $atp;
        $data->deskripsi = $deskripsi;
        $data->id_capaian_pembelajaran = $cpid;
        if ($hasidcourse && $filtercourseid > 0) {
            $data->id_course = $filtercourseid;
        }
        if ($hasstatus) {
            $data->status = 'aktif';
        }
        $tpid = $DB->insert_record('tujuan_pembelajaran', $data);

        // Sinkronisasi gradebook mengikuti course yang sedang dipilih di filter.
        // Jika admin memilih Course/Kelas/Guru, TP manual langsung menjadi milik course tersebut.
        try {
            if ($hasidcourse && $filtercourseid > 0) {
                \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp_course((int)$tpid, (int)$filtercourseid);
            } else {
                \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp((int)$tpid);
            }
        } catch (\Throwable $e) {
            // Gagal sinkron gradebook tidak menghentikan simpan data TP.
        }

        $inserted++;
    }

    redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', [
        'kmid' => $kmid,
        'cpid' => $cpid,
        'filtercourseid' => $filtercourseid,
    ]), $inserted . ' tujuan pembelajaran berhasil disimpan.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Saat admin memilih Course/Kelas/Guru di filter, sinkronkan gradebook hanya untuk TP
// yang id_course-nya sama dengan course tersebut. Ini juga membersihkan kategori TP lama
// yang sempat ikut masuk ke gradebook dari course lain selama belum ada nilai.
if ($hasidcourse && $filtercourseid > 0) {
    try {
        \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_kurikulum_mapel((int)$kmid, (int)$filtercourseid);
    } catch (\Throwable $e) {
        // Jangan gagalkan halaman TP hanya karena sinkronisasi gradebook gagal.
    }
}

$where = ['tp.id_capaian_pembelajaran = :cpid'];
$params = ['cpid' => $cpid];
if ($hasidcourse && $filtercourseid > 0) {
    $where[] = 'tp.id_course = :filtercourseid';
    $params['filtercourseid'] = $filtercourseid;
}
// if ($searchtp !== '') {
//     $where[] = '(' . $DB->sql_like('tp.deskripsi', ':searchtp', false, false) . ' OR ' . $DB->sql_like('tp.konten', ':searchtp2', false, false) . ')';
//     $params['searchtp'] = '%' . $searchtp . '%';
//     $params['searchtp2'] = '%' . $searchtp . '%';
// }
$records = $DB->get_records_sql('SELECT tp.* FROM {tujuan_pembelajaran} tp WHERE ' . implode(' AND ', $where) . ' ORDER BY tp.id ASC', $params);

$groups  = [];
$index   = [];
$no      = 1;

/**
 * JP per konten = kurikulum_mapel.jam_pelajaran secara langsung.
 *
 * Artinya:
 * - jam_pelajaran bukan lagi dianggap sebagai total JP mapel.
 * - jam_pelajaran dianggap sebagai JP untuk setiap konten.
 *
 * Contoh:
 * jam_pelajaran = 2
 * jumlah konten = 3
 * total JP = 2 x 3 = 6 JP
 */
$jp_per_konten = (int)($km->jam_pelajaran ?? 0) > 0
    ? (int)$km->jam_pelajaran
    : null;

// Hitung jumlah konten unik di seluruh CP dalam kmid ini.
$konten_values = $DB->get_fieldset_sql(
    "SELECT DISTINCT tp.konten
       FROM {tujuan_pembelajaran} tp
       JOIN {capaian_pembelajaran} cp ON cp.id = tp.id_capaian_pembelajaran
      WHERE cp.id_kurikulum_mapel = :kmid
        AND tp.konten IS NOT NULL
        AND tp.konten <> ''",
    ['kmid' => $kmid]
);

$konten_unik = [];
foreach ($konten_values as $konten) {
    $k = trim((string)$konten);
    if ($k !== '') {
        $konten_unik[$k] = true;
    }
}

$jumlah_konten = count($konten_unik);

// Total JP = JP per konten x jumlah konten.
$total_jp_mapel = ($jp_per_konten !== null && $jumlah_konten > 0)
    ? $jp_per_konten * $jumlah_konten
    : ($jp_per_konten ?? 0);

foreach ($records as $record) {
    $konten = $hasKonten && isset($record->konten) && trim((string)$record->konten) !== ''
        ? (string)$record->konten
        : 'Tanpa Konten';

    if (!isset($index[$konten])) {
        $index[$konten] = count($groups);
        $groups[] = [
            'konten'    => format_string($konten),
            'jp_konten' => $jp_per_konten,
            'rows'      => [],
        ];
    }

$tp_dipetakan =
    \local_akademikmonitor\service\tp_gradebook_service::tp_has_active_course(
        (int)$record->id
    );
    $tp_bernilai  = $tp_dipetakan
        ? \local_akademikmonitor\service\tp_gradebook_service::tp_has_grades((int)$record->id)
        : false;

    // TP boleh dihapus selama belum memiliki nilai.
    // Jika hanya terhubung ke course/gradebook tetapi belum ada nilai, struktur gradebook akan dibersihkan saat hapus.
    $can_delete = !$tp_bernilai;

    $groups[$index[$konten]]['rows'][] = [
        'no'          => $no++,
        'kompetensi'  => format_string((string)($record->kompetensi ?? '')),
        'dpl'         => format_string((string)($record->dpl ?? '')),
        'atp'         => format_string((string)($record->atp ?? '')),
        'deskripsi'   => format_text((string)$record->deskripsi, FORMAT_PLAIN),
        'course_name' => (!empty($record->id_course) && $DB->record_exists('course', ['id' => (int)$record->id_course])) ? format_string($DB->get_field('course', 'fullname', ['id' => (int)$record->id_course])) : '-',
        'teacher_names' => local_akademikmonitor_backend_tp_teacher_names((int)($record->id_course ?? 0)),
        'tp_status' => $hasstatus ? ucfirst((string)($record->status ?? 'aktif')) : 'Aktif',
        'edit_url'    => (new moodle_url('/local/akademikmonitor/pages/jurusan/tp_form.php', [
            'id'   => $record->id,
            'kmid' => $kmid,
            'cpid' => $cpid,
        ]))->out(false),
        'delete_url'  => (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', [
            'kmid'     => $kmid,
            'cpid'     => $cpid,
            'deleteid' => $record->id,
            'sesskey'  => sesskey(),
        ]))->out(false),
        'can_delete'     => $can_delete,
        'tp_dipetakan'   => $tp_dipetakan,
        'tp_bernilai'    => $tp_bernilai,
        'status_label'   => $tp_bernilai  ? 'Sudah Dinilai'
                          : ($tp_dipetakan ? 'Terhubung Course'
                          : 'Belum Dipetakan'),
        'status_color'   => $tp_bernilai  ? '#22c55e'
                          : ($tp_dipetakan ? '#f59e0b'
                          : '#94a3b8'),
    ];
}


// if ($searchguru !== '') {
//     $filteredgroups = [];
//     foreach ($groups as $group) {
//         $rows = [];
//         foreach ($group['rows'] as $row) {
//             if (stripos((string)($row['teacher_names'] ?? ''), $searchguru) !== false) {
//                 $rows[] = $row;
//             }
//         }
//         if ($rows) {
//             $group['rows'] = $rows;
//             $filteredgroups[] = $group;
//         }
//     }
//     $groups = $filteredgroups;
// }

// ── Ambil meta info untuk info panel & export ────────────────────────────────
$_kj = $DB->get_record('kurikulum_jurusan', ['id' => $km->id_kurikulum_jurusan], '*', IGNORE_MISSING);

$_nama_jurusan   = '-';
$_nama_kurikulum = '-';
$_tahun_ajaran   = '-';
$_nama_sekolah   = get_config('local_akademikmonitor', 'nama_sekolah') ?: 'SMK PGRI 2 Giri';

if ($_kj) {
    $_jur = $DB->get_record('jurusan', ['id' => $_kj->id_jurusan], '*', IGNORE_MISSING);
    if ($_jur) {
        $_nama_jurusan = format_string((string)$_jur->nama_jurusan);
    }
    $_namefield_kur = local_akademikmonitor_backend_kurikulum_namefield();
    $_kur = $DB->get_record('kurikulum', ['id' => $_kj->id_kurikulum], '*', IGNORE_MISSING);
    if ($_kur) {
        $_nama_kurikulum = format_string((string)($_kur->{$_namefield_kur} ?? '-'));
    }
    $_ta = $DB->get_record('tahun_ajaran', ['id' => $_kj->id_tahun_ajaran], '*', IGNORE_MISSING);
    if ($_ta) {
        $_tahun_ajaran = local_akademikmonitor_backend_tahun_label($_ta);
    }
}

$_mapel = $DB->get_record('mata_pelajaran', ['id' => $km->id_mapel], '*', IGNORE_MISSING);
$_nama_mapel_raw = $_mapel ? (string)$_mapel->nama_mapel : '-';
$_nama_mapel     = trim(preg_replace('/^\[.*?\]\s*/', '', $_nama_mapel_raw)) ?: $_nama_mapel_raw;
// Label JP untuk ditampilkan di halaman TP.
$_jp_int = $jp_per_konten ?? 0;
$_jp_label = $_jp_int > 0
    ? $_jp_int . ' JP/konten' . ($jumlah_konten > 0 ? ' x ' . $jumlah_konten . ' konten = ' . $total_jp_mapel . ' JP total' : '')
    : '-';

$course_filter_options = [];
if ($hasidcourse) {
    $course_records = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname
          FROM {course} c
          JOIN {course_mapel} cm ON cm.id_course = c.id
         WHERE cm.id_kurikulum_mapel = :kmid
         ORDER BY c.fullname ASC", ['kmid' => $kmid]);
    foreach ($course_records as $c) {
        $course_filter_options[] = [
            'id' => (int)$c->id,
            // Dropdown cukup menampilkan course. Detail guru/kelas/jurusan tampil setelah tombol Cari diklik.
            'label' => format_string($c->fullname),
            'selected' => (int)$c->id === $filtercourseid,
        ];
    }
}

$selected_course_info = null;
if ($hasidcourse && $filtercourseid > 0 && $DB->record_exists('course', ['id' => $filtercourseid])) {
    $selected_course_info = [
        'course_name' => format_string($DB->get_field('course', 'fullname', ['id' => $filtercourseid])),
        'teacher_names' => local_akademikmonitor_backend_tp_teacher_names($filtercourseid),
        // Course hasil generate sudah mewakili kelas; nama kelas ditampilkan dari nama course.
        'kelas_name' => format_string($DB->get_field('course', 'fullname', ['id' => $filtercourseid])),
        'jurusan_name' => $_nama_jurusan,
    ];
}

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls('jurusan'), [
    'cp'             => format_text($cp->deskripsi, FORMAT_PLAIN),
    'jp' => $_jp_label,
    'kmid'           => $kmid,
    'cpid'           => $cpid,
    'groups'         => $groups,
    'has_groups'     => !empty($groups),
    'filter_url'     => (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php'))->out(false),
    'filtercourseid' => $filtercourseid,
    // 'searchtp'       => s($searchtp),
    // 'searchguru'     => s($searchguru),
    'course_filter_options' => $course_filter_options,
    'has_course_filter_options' => !empty($course_filter_options),
    'selected_course_info' => $selected_course_info,
    'has_selected_course_info' => !empty($selected_course_info),
    'action_url'     => (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', [
        'kmid' => $kmid,
        'cpid' => $cpid,
        'filtercourseid' => $filtercourseid,
    ]))->out(false),
    'back_url'       => (new moodle_url('/local/akademikmonitor/pages/jurusan/capaian_pembelajaran.php', ['kmid' => $kmid]))->out(false),
    'sesskey'        => sesskey(),
    // Export URL — Excel tetap memakai export_tp.php, PDF memakai Dompdf.
    'export_url'     => (new moodle_url('/local/akademikmonitor/pages/jurusan/export_tp.php', [
        'kmid' => $kmid,
        'cpid' => $cpid,
        'filtercourseid' => $filtercourseid,
    ]))->out(false),
    'export_pdf_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/export_tp_pdf.php', [
        'kmid' => $kmid,
        'cpid' => $cpid,
        'filtercourseid' => $filtercourseid,
    ]))->out(false),
    // Info panel
    'nama_mapel'     => $_nama_mapel,
    'nama_jurusan'   => $_nama_jurusan,
    'nama_kurikulum' => $_nama_kurikulum,
    'tahun_ajaran'   => $_tahun_ajaran,
    'tingkat'        => s((string)($km->tingkat_kelas ?? '-')),
    'kktp'           => (int)($km->kktp ?? 0),
    'nama_sekolah'   => $_nama_sekolah,
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/tp', $templatecontext);
echo $OUTPUT->footer();
