<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}

require_sesskey();

global $DB;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$id = required_param('id', PARAM_INT);

// Pastikan data ada.
$record = $DB->get_record('mata_pelajaran', ['id' => $id], '*', MUST_EXIST);

/**
 * Cek apakah mata pelajaran masih dipakai di tabel kurikulum_mapel.
 * Kalau masih dipakai, jangan langsung dihapus supaya relasi data tidak rusak.
 */
if ($DB->get_manager()->table_exists('kurikulum_mapel')) {
    $used = $DB->record_exists('kurikulum_mapel', [
        'id_mapel' => $id,
    ]);

    if ($used) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
            'Mata pelajaran tidak dapat dihapus karena masih digunakan pada data kurikulum.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

/**
 * Cek apakah sudah terhubung ke course Moodle yang aktif.
 * Rantai: mata_pelajaran -> kurikulum_mapel -> course_mapel -> course
 */
if ($DB->get_manager()->table_exists('course_mapel')) {
    $hasCourse = $DB->record_exists_sql(
        "SELECT 1
           FROM {kurikulum_mapel} km
           JOIN {course_mapel} cm ON cm.id_kurikulum_mapel = km.id
           JOIN {course} c ON c.id = cm.id_course
          WHERE km.id_mapel = :mapelid",
        ['mapelid' => $id]
    );

    if ($hasCourse) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
            'Mata pelajaran tidak dapat dihapus karena sudah terhubung dengan course Moodle yang aktif.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

/**
 * Cek apakah ada TP yang sudah terhubung ke nilai/gradebook.
 * Rantai: mata_pelajaran -> kurikulum_mapel -> capaian_pembelajaran
 *         -> tujuan_pembelajaran -> grade_items_tp
 */
if ($DB->get_manager()->table_exists('grade_items_tp')) {
    $hasGrade = $DB->record_exists_sql(
        "SELECT 1
           FROM {kurikulum_mapel} km
           JOIN {capaian_pembelajaran} cp ON cp.id_kurikulum_mapel = km.id
           JOIN {tujuan_pembelajaran} tp ON tp.id_capaian_pembelajaran = cp.id
           JOIN {grade_items_tp} gitp ON gitp.id_tp = tp.id
          WHERE km.id_mapel = :mapelid",
        ['mapelid' => $id]
    );

    if ($hasGrade) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
            'Mata pelajaran tidak dapat dihapus karena sudah memiliki Tujuan Pembelajaran yang terhubung ke nilai/gradebook. Hapus nilai terkait terlebih dahulu melalui menu Manajemen Jurusan.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

/**
 * Cek apakah ada TP yang sudah dibuat (meski belum ada nilai).
 * Jika ada TP, mata pelajaran tetap tidak boleh dihapus.
 */
if ($DB->get_manager()->table_exists('tujuan_pembelajaran')) {
    $hasTP = $DB->record_exists_sql(
        "SELECT 1
           FROM {kurikulum_mapel} km
           JOIN {capaian_pembelajaran} cp ON cp.id_kurikulum_mapel = km.id
           JOIN {tujuan_pembelajaran} tp ON tp.id_capaian_pembelajaran = cp.id
          WHERE km.id_mapel = :mapelid",
        ['mapelid' => $id]
    );

    if ($hasTP) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
            'Mata pelajaran tidak dapat dihapus karena sudah memiliki Tujuan Pembelajaran. Hapus TP terlebih dahulu melalui menu Manajemen Jurusan.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$DB->delete_records('mata_pelajaran', [
    'id' => $record->id,
]);

redirect(
    new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
    'Data mata pelajaran berhasil dihapus.',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
