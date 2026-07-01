<?php

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $PAGE, $OUTPUT;

$idjurusan = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/setkurikulum.php', ['id' => $idjurusan]));
$PAGE->set_context($context);
$PAGE->set_title('Set Kurikulum Jurusan');
$PAGE->set_heading('Set Kurikulum Jurusan');
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
// function local_akademikmonitor_backend_normalize_tingkat(string $tingkat): string {
//     $tingkat = strtoupper(trim($tingkat));
//     return in_array($tingkat, ['X', 'XI', 'XII'], true) ? $tingkat : 'X';
// }
// function local_akademikmonitor_backend_jam(string $jam): string {
//     $jam = trim($jam);
//     if ($jam === '') { return ''; }
//     if (preg_match('/^\d{1,2}$/', $jam)) { return $jam; }
//     return substr($jam, 0, 8);
// }

$jurusan = $DB->get_record('jurusan', ['id' => $idjurusan], '*', MUST_EXIST);
$namefield = local_akademikmonitor_backend_kurikulum_namefield();

$activekurikulum = local_akademikmonitor_backend_active_kurikulum();
$activetahun = local_akademikmonitor_backend_active_tahun();
$selected = null;

if ($activekurikulum && $activetahun) {
    $selected = local_akademikmonitor_backend_ensure_kurikulum_jurusan($idjurusan, (int)$activekurikulum->id, (int)$activetahun->id);
}

$selectedkurikulumid = $selected ? (int)$selected->id_kurikulum : ($activekurikulum ? (int)$activekurikulum->id : 0);
$tahunajaran = $activetahun ? local_akademikmonitor_backend_tahun_label($activetahun) : '-';

$kurikulums = $DB->get_records('kurikulum', null, $namefield . ' ASC');
$items = [];
$no = 1;
foreach ($kurikulums as $k) {
    $isactive = ((int)$k->id === $selectedkurikulumid);
    $items[] = [
        'no' => $no++,
        'nama' => format_string($k->{$namefield} ?? '-'),
        'status_label' => $isactive ? 'Digunakan' : 'Tidak digunakan',
        'status_class' => $isactive ? 'am-badge-success' : 'am-badge-muted',
        'is_active' => $isactive,
    ];
}

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls('jurusan'), [
    'jurusan_id' => (int)$jurusan->id,
    'jurusan_nama' => format_string($jurusan->nama_jurusan),
    'jurusan_kode' => isset($jurusan->kode_jurusan) ? format_string((string)$jurusan->kode_jurusan) : '',
    'tahun_ajaran' => format_string((string)$tahunajaran),
    'items' => $items,
    'has_items' => !empty($items),
    'has_selected' => $selectedkurikulumid > 0,
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
    'set_mapel_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', ['id' => $idjurusan]))->out(false),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/setkurikulum', $templatecontext);
echo $OUTPUT->footer();
