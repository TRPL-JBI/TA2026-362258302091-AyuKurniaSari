<?php
require('../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$PAGE->set_url('/local/akademik/index.php');
$PAGE->set_title('Manajemen Akademik');
$PAGE->set_heading('Manajemen Akademik');

echo $OUTPUT->header();

echo html_writer::tag('h3', 'Menu Akademik');
echo html_writer::alist([
    html_writer::link(new moodle_url('/local/akademik/pages/tahun_ajaran/index.php'), 'Tahun Ajaran'),
    html_writer::link(new moodle_url('/local/akademik/pages/kurikulum/index.php'), 'Kurikulum'),
    html_writer::link(new moodle_url('/local/akademik/pages/mata_pelajaran/index.php'), 'Mata Pelajaran'),
    html_writer::link(new moodle_url('/local/akademik/pages/jurusan/index.php'), 'Manajemen Jurusan'),
    html_writer::link(new moodle_url('/local/akademik/pages/kelas/index.php'), 'Manajemen Kelas'),
    html_writer::link(new moodle_url('/local/akademik/pages/kartu_ujian/index.php'), 'Manajemen Kartu Ujian'),
    html_writer::link(new moodle_url('/local/akademik/pages/Generate/index.php'), 'Generate Kelas'),
]);

echo $OUTPUT->footer();