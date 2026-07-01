<?php
/**
 * Halaman pemilihan course untuk Guru — Capaian & Tujuan Pembelajaran.
 *
 * File BARU. Muncul saat guru klik menu "Capaian & Tujuan Pembelajaran"
 * di frontpage/homepage Moodle.
 *
 * Karena satu guru bisa mengajar beberapa course sekaligus, halaman ini
 * menampilkan daftar course yang diajar guru tersebut, lalu guru memilih
 * course mana yang ingin dikelola TP-nya.
 *
 * Jika guru hanya mengajar 1 course, otomatis redirect langsung ke guru/tp.php.
 *
 * URL: /local/akademikmonitor/pages/guru/tp_pilih_course.php
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();

global $DB, $PAGE, $OUTPUT, $USER;

$context = context_system::instance();

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/guru/tp_pilih_course.php'));
$PAGE->set_context($context);
$PAGE->set_title('Pilih Course — Tujuan Pembelajaran');
$PAGE->set_heading('Capaian & Tujuan Pembelajaran');
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));

// ── Ambil semua course yang diajar user ini ───────────────────────────────────
// Cari role editingteacher / teacher berdasarkan archetype (portable antar Moodle).
$roles = $DB->get_records_list(
    'role',
    'archetype',
    ['editingteacher'],
    '',
    'id'
);

if (empty($roles)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Tidak ditemukan role guru di sistem ini. Hubungi Admin.', 'warning');
    echo $OUTPUT->footer();
    die();
}

$roleids = array_keys($roles);

[$insql, $inparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'roleid');
$inparams['userid']       = (int)$USER->id;
$inparams['contextlevel'] = CONTEXT_COURSE;

$sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
          FROM {role_assignments} ra
          JOIN {context} ctx ON ctx.id = ra.contextid
          JOIN {course} c ON c.id = ctx.instanceid
         WHERE ra.userid = :userid
           AND ra.roleid {$insql}
           AND ctx.contextlevel = :contextlevel
           AND c.id <> 1
      ORDER BY c.fullname ASC";

$courses = $DB->get_records_sql($sql, $inparams);

// ── Jika tidak ada course sama sekali ────────────────────────────────────────
if (empty($courses)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'Anda belum terdaftar sebagai pengajar di course manapun. Hubungi Admin untuk menetapkan Anda sebagai Teacher di course yang sesuai.',
        'warning'
    );
    echo $OUTPUT->footer();
    die();
}

// ── Jika hanya 1 course, langsung redirect ────────────────────────────────────
if (count($courses) === 1) {
    $singlecourse = reset($courses);
    redirect(new moodle_url('/local/akademikmonitor/pages/guru/tp.php', [
        'courseid' => $singlecourse->id,
    ]));
}

// ── Filter: hanya tampilkan course yang sudah dipetakan ke mapel ──────────────
// Course yang belum dipetakan tidak akan bisa menampilkan CP/TP.
$courselist = [];
foreach ($courses as $c) {
    $mapped = false;

    if ($DB->get_manager()->table_exists('course_mapel')) {
        $mapped = $DB->record_exists('course_mapel', ['id_course' => $c->id]);
    }

    if (!$mapped && $DB->get_manager()->table_exists('course_mapel_mapping')) {
        $mapped = $DB->record_exists('course_mapel_mapping', ['id_course' => $c->id]);
    }

    if (!$mapped && $DB->get_manager()->table_exists('mapping_course_mapel')) {
        $mapped = $DB->record_exists('mapping_course_mapel', ['id_course' => $c->id]);
    }

    // Bersihkan nama course: hapus prefix [kategori] jika ada.
    $namaclean = trim(preg_replace('/^\[.*?\]\s*/', '', (string)$c->fullname));
    if ($namaclean === '') {
        $namaclean = format_string($c->fullname);
    }

    $courselist[] = [
        'id'      => (int)$c->id,
        'nama'    => format_string($c->fullname),
        'nama_clean' => $namaclean,
        'mapped'  => $mapped,
        'tp_url'  => (new moodle_url('/local/akademikmonitor/pages/guru/tp.php', ['courseid' => $c->id]))->out(false),
        'course_url' => (new moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
    ];
}

// ── Template context ──────────────────────────────────────────────────────────
$templatecontext = [
    'courses'     => $courselist,
    'has_courses' => !empty($courselist),
    'home_url'    => (new moodle_url('/'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/guru_pilih_course', $templatecontext);
echo $OUTPUT->footer();
