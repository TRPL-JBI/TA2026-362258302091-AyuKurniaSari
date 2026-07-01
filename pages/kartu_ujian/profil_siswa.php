<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $DB, $PAGE, $OUTPUT, $USER;

$userid = optional_param('userid', (int)$USER->id, PARAM_INT);

if ($userid <= 0) {
    $userid = (int)$USER->id;
}

$profileuser = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$context = context_user::instance($userid);

/*
 * Kalau yang membuka bukan siswa itu sendiri,
 * maka user harus punya izin melihat detail user.
 *
 * require_capability() otomatis menampilkan error permission Moodle
 * kalau user tidak punya hak akses, jadi tidak perlu throw exception manual.
 */
if ((int)$USER->id !== $userid) {
    require_capability('moodle/user:viewdetails', $context);
}

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/profil_siswa.php', [
    'userid' => $userid,
]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Profil Siswa - Kartu Ujian');
$PAGE->set_heading('Profil Siswa');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));

/*
 * Status yang dianggap boleh tampil di profil siswa.
 *
 * Kenapa ada "layak"?
 * Karena fitur kartu ujian dari temanmu memakai status "layak"
 * untuk siswa yang boleh mendapatkan kartu ujian.
 */
$allowedstatuses = [
    'published',
    'publish',
    'aktif',
    'terbit',
    'layak',
];

$kuscolumns = $DB->get_columns('kartu_ujian_siswa');
$kucolumns = $DB->get_columns('kartu_ujian');

$params = [
    'userid' => $userid,
];

$statusconditions = [];

/*
 * Cek status dari tabel kartu_ujian.
 * Ini untuk kondisi kartu ujian global sudah dipublish.
 */
if (isset($kucolumns['status'])) {
    [$kuinsql, $kuparams] = $DB->get_in_or_equal(
        $allowedstatuses,
        SQL_PARAMS_NAMED,
        'kustatus'
    );

    $statusconditions[] = "LOWER(ku.status) {$kuinsql}";

    foreach ($kuparams as $key => $value) {
        $params[$key] = strtolower((string)$value);
    }
}

/*
 * Cek status dari tabel kartu_ujian_siswa jika memang ada kolom status.
 * Ini untuk kondisi per siswa punya status "layak".
 */
if (isset($kuscolumns['status'])) {
    [$kusinsql, $kusparams] = $DB->get_in_or_equal(
        $allowedstatuses,
        SQL_PARAMS_NAMED,
        'kusstatus'
    );

    $statusconditions[] = "LOWER(kus.status) {$kusinsql}";

    foreach ($kusparams as $key => $value) {
        $params[$key] = strtolower((string)$value);
    }
}

/*
 * Kalau tidak ada kolom status sama sekali, tetap tampilkan kartu yang relasinya
 * ada di kartu_ujian_siswa. Ini aman karena halaman ini memang khusus siswa.
 */
$statussql = '';

if (!empty($statusconditions)) {
    $statussql = ' AND (' . implode(' OR ', $statusconditions) . ')';
}

$sql = "SELECT ku.id,
               ku.nama_ujian,
               ku.semester,
               ku.status,
               ku.penandatangan,
               ku.timecreated,
               ku.timemodified,
               k.nama AS nama_kelas,
               k.tingkat,
               j.nama_jurusan,
               ta.tahun_ajaran
          FROM {kartu_ujian_siswa} kus
          JOIN {kartu_ujian} ku ON ku.id = kus.id_kartu_ujian
     LEFT JOIN {kelas} k ON k.id = ku.id_kelas
     LEFT JOIN {jurusan} j ON j.id = k.id_jurusan
     LEFT JOIN {tahun_ajaran} ta ON ta.id = ku.id_tahun_ajaran
         WHERE kus.id_user = :userid
               {$statussql}
      ORDER BY ku.id DESC";

$records = $DB->get_records_sql($sql, $params);

$items = [];
$no = 1;

foreach ($records as $r) {
    $kelasparts = [];

    if (!empty($r->tingkat)) {
        $kelasparts[] = (string)$r->tingkat;
    }

    if (!empty($r->nama_jurusan)) {
        $kelasparts[] = (string)$r->nama_jurusan;
    }

    if (!empty($r->nama_kelas)) {
        $kelasparts[] = (string)$r->nama_kelas;
    }

    $items[] = [
        'no' => $no++,
        'id' => (int)$r->id,
        'nama_ujian' => format_string((string)($r->nama_ujian ?? '-')),
        'semester' => format_string((string)($r->semester ?? '-')),
        'status' => format_string((string)($r->status ?? '-')),
        'kelas' => !empty($kelasparts) ? trim(implode(' ', $kelasparts)) : '-',
        'tahun_ajaran' => !empty($r->tahun_ajaran) ? format_string((string)$r->tahun_ajaran) : '-',
        'download_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/download.php', [
            'kid' => (int)$r->id,
            'uid' => $userid,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

$templatecontext = [
    'nama_siswa' => fullname($profileuser),
    'userid' => $userid,
    'items' => $items,
    'has_items' => !empty($items),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/profil_siswa_kartu', $templatecontext);
echo $OUTPUT->footer();