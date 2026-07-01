<?php
require_once(__DIR__ . '/../../../../config.php');

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $PAGE, $OUTPUT;

$idjurusan = required_param('id', PARAM_INT);
$deletekmid = optional_param('deletekmid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', [
    'id' => $idjurusan,
]));
$PAGE->set_context($context);
$PAGE->set_title('Set Mata Pelajaran Jurusan');
$PAGE->set_heading('Set Mata Pelajaran Jurusan');
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
    if (!$tahun) {
        return '-';
    }

    if (property_exists($tahun, 'tahun_ajaran')) {
        return (string)$tahun->tahun_ajaran;
    }

    if (property_exists($tahun, 'nama')) {
        return (string)$tahun->nama;
    }

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

        if ($record) {
            return $record;
        }
    }

    $columns = $DB->get_columns('tahun_ajaran');

    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('tahun_ajaran', [$field => 1], '*', IGNORE_MULTIPLE);

            if ($record) {
                return $record;
            }
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

        if ($record) {
            return $record;
        }
    }

    $columns = $DB->get_columns('kurikulum');

    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('kurikulum', [$field => 1], '*', IGNORE_MULTIPLE);

            if ($record) {
                return $record;
            }
        }
    }

    $records = $DB->get_records('kurikulum', null, 'id DESC', '*', 0, 1);

    return $records ? reset($records) : null;
}

function local_akademikmonitor_backend_ensure_kurikulum_jurusan(
    int $jurusanid,
    ?int $kurikulumid = null,
    ?int $tahunajaranid = null
): ?stdClass {
    global $DB;

    if ($jurusanid <= 0) {
        return null;
    }

    if (empty($kurikulumid)) {
        $kurikulum = local_akademikmonitor_backend_active_kurikulum();
        $kurikulumid = $kurikulum ? (int)$kurikulum->id : 0;
    }

    if (empty($tahunajaranid)) {
        $tahun = local_akademikmonitor_backend_active_tahun();
        $tahunajaranid = $tahun ? (int)$tahun->id : 0;
    }

    if ($kurikulumid <= 0 || $tahunajaranid <= 0) {
        return null;
    }

    $existing = $DB->get_record('kurikulum_jurusan', [
        'id_jurusan' => $jurusanid,
        'id_kurikulum' => $kurikulumid,
        'id_tahun_ajaran' => $tahunajaranid,
    ], '*', IGNORE_MULTIPLE);

    if ($existing) {
        return $existing;
    }

    $record = new stdClass();
    $record->id_jurusan = $jurusanid;
    $record->id_kurikulum = $kurikulumid;
    $record->id_tahun_ajaran = $tahunajaranid;
    $record->id = $DB->insert_record('kurikulum_jurusan', $record);

    return $record;
}

function local_akademikmonitor_backend_get_kurikulum_jurusan(
    int $jurusanid,
    ?int $tahunajaranid = null
): ?stdClass {
    global $DB;

    if ($jurusanid <= 0) {
        return null;
    }

    $activekurikulum = local_akademikmonitor_backend_active_kurikulum();
    $activetahun = local_akademikmonitor_backend_active_tahun();

    $kurikulumid = $activekurikulum ? (int)$activekurikulum->id : 0;
    $tahunid = $tahunajaranid ?: ($activetahun ? (int)$activetahun->id : 0);

    if ($kurikulumid > 0 && $tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', [
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunid,
        ], '*', IGNORE_MULTIPLE);

        if ($record) {
            return $record;
        }

        return local_akademikmonitor_backend_ensure_kurikulum_jurusan(
            $jurusanid,
            $kurikulumid,
            $tahunid
        );
    }

    if ($tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', [
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunid,
        ], '*', IGNORE_MULTIPLE);

        if ($record) {
            return $record;
        }
    }

    $records = $DB->get_records('kurikulum_jurusan', [
        'id_jurusan' => $jurusanid,
    ], 'id DESC', '*', 0, 1);

    return $records ? reset($records) : null;
}

function local_akademikmonitor_backend_normalize_tingkat(string $tingkat): string {
    $tingkat = strtoupper(trim($tingkat));

    return in_array($tingkat, ['X', 'XI', 'XII'], true) ? $tingkat : 'X';
}

function local_akademikmonitor_backend_jam(string $jam): string {
    $jam = trim($jam);

    if ($jam === '') {
        return '';
    }

    if (preg_match('/^\d{1,2}$/', $jam)) {
        return $jam;
    }

    return substr($jam, 0, 8);
}

/**
 * Bersihkan relasi course_mapel yang course-nya sudah tidak ada.
 *
 * Kenapa perlu?
 * Karena saat course Moodle dihapus, record di tabel custom course_mapel
 * bisa saja masih tertinggal. Kalau tidak dibersihkan, plugin mengira
 * mapel masih terhubung ke course aktif.
 */
function local_akademikmonitor_backend_cleanup_orphan_course_mapel(?int $kmid = null): void {
    global $DB;

    $params = [];

    $where = 'c.id IS NULL';

    if ($kmid !== null && $kmid > 0) {
        $where .= ' AND cm.id_kurikulum_mapel = :kmid';
        $params['kmid'] = $kmid;
    }

    $DB->execute(
        "DELETE cm
           FROM {course_mapel} cm
      LEFT JOIN {course} c ON c.id = cm.id_course
          WHERE {$where}",
        $params
    );
}

/**
 * Cek apakah mapel masih terhubung dengan course Moodle yang benar-benar aktif/ada.
 */
function local_akademikmonitor_backend_km_has_active_course(int $kmid): bool {
    global $DB;

    if ($kmid <= 0) {
        return false;
    }

    return $DB->record_exists_sql(
        "SELECT 1
           FROM {course_mapel} cm
           JOIN {course} c ON c.id = cm.id_course
          WHERE cm.id_kurikulum_mapel = :kmid",
        [
            'kmid' => $kmid,
        ]
    );
}

$jurusan = $DB->get_record('jurusan', ['id' => $idjurusan], '*', MUST_EXIST);
$kurjur = local_akademikmonitor_backend_get_kurikulum_jurusan($idjurusan);

if (!$kurjur) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/jurusan/setkurikulum.php', [
            'id' => $idjurusan,
        ]),
        'Aktifkan kurikulum dan tahun ajaran terlebih dahulu.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($deletekmid > 0) {
    require_sesskey();

    $km = $DB->get_record('kurikulum_mapel', [
        'id' => $deletekmid,
        'id_kurikulum_jurusan' => $kurjur->id,
    ], '*', MUST_EXIST);

    /*
     * Bersihkan dulu relasi course_mapel yang course-nya sudah dihapus.
     * Ini membuat tombol hapus tetap bisa digunakan kalau course lama sudah
     * dihapus dari Moodle, tapi relasi custom masih tertinggal.
     */
    local_akademikmonitor_backend_cleanup_orphan_course_mapel((int)$km->id);

    /*
     * Setelah orphan dibersihkan, baru cek course yang benar-benar masih ada.
     * Kalau masih ada course aktif, mapel tetap tidak boleh dihapus supaya
     * struktur course/rapor tidak rusak.
     */
    if (local_akademikmonitor_backend_km_has_active_course((int)$km->id)) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', [
                'id' => $idjurusan,
            ]),
            'Mapel tidak bisa dihapus karena masih terhubung dengan course Moodle yang aktif.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $cps = $DB->get_records('capaian_pembelajaran', [
        'id_kurikulum_mapel' => $km->id,
    ], '', 'id');

    foreach ($cps as $cp) {
        $tps = $DB->get_records('tujuan_pembelajaran', [
            'id_capaian_pembelajaran' => $cp->id,
        ], '', 'id');

        foreach ($tps as $tp) {
            $DB->delete_records('grade_items_tp', ['id_tp' => $tp->id]);
            $DB->delete_records('assignment_tp', ['id_tp' => $tp->id]);
            $DB->delete_records('quiz_tp', ['id_tp' => $tp->id]);
        }

        $DB->delete_records('tujuan_pembelajaran', [
            'id_capaian_pembelajaran' => $cp->id,
        ]);
    }

    $DB->delete_records('capaian_pembelajaran', [
        'id_kurikulum_mapel' => $km->id,
    ]);

    /*
     * Safety tambahan: hapus relasi course_mapel untuk km ini.
     * Normalnya sudah kosong karena tidak ada course aktif.
     */
    $DB->delete_records('course_mapel', [
        'id_kurikulum_mapel' => $km->id,
    ]);

    $DB->delete_records('kurikulum_mapel', [
        'id' => $km->id,
    ]);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', [
            'id' => $idjurusan,
        ]),
        'Mapel berhasil dihapus dari jurusan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $mode = optional_param('mode', 'single', PARAM_ALPHA);

    if ($mode === 'multi') {
        /*
         * Mode multi: memproses array id_mapel[], tingkat[], jam_pelajaran[], kktp[]
         * sehingga admin bisa menambahkan banyak mapel sekaligus.
         */
        $mapelids    = optional_param_array('id_mapel', [], PARAM_INT);
        $tingkats    = optional_param_array('tingkat', [], PARAM_TEXT);
        $jams        = optional_param_array('jam_pelajaran', [], PARAM_TEXT);
        $kktps       = optional_param_array('kktp', [], PARAM_INT);

        $inserted = 0;
        $updated  = 0;
        $max = count($mapelids);

        for ($i = 0; $i < $max; $i++) {
            $mapelid = (int)($mapelids[$i] ?? 0);
            if ($mapelid <= 0) {
                continue;
            }

            if (!$DB->record_exists('mata_pelajaran', ['id' => $mapelid])) {
                continue;
            }

            $tingkat = local_akademikmonitor_backend_normalize_tingkat((string)($tingkats[$i] ?? 'X'));
            $jam     = local_akademikmonitor_backend_jam((string)($jams[$i] ?? ''));
            $kktp    = max(0, min(100, (int)($kktps[$i] ?? 75)));

            $existing = $DB->get_record('kurikulum_mapel', [
                'id_kurikulum_jurusan' => $kurjur->id,
                'id_mapel'             => $mapelid,
                'tingkat_kelas'        => $tingkat,
            ], '*', IGNORE_MULTIPLE);

            $rec = $existing ?: new stdClass();
            $rec->id_kurikulum_jurusan = $kurjur->id;
            $rec->id_mapel             = $mapelid;
            $rec->tingkat_kelas        = $tingkat;
            $rec->jam_pelajaran        = $jam;
            $rec->kktp                 = $kktp;

            if ($existing) {
                $DB->update_record('kurikulum_mapel', $rec);
                $updated++;
            } else {
                $DB->insert_record('kurikulum_mapel', $rec);
                $inserted++;
            }
        }

        $msg = "$inserted mapel ditambahkan, $updated diperbarui.";

    } else {
        /*
         * Mode single (fallback kompatibilitas lama): satu mapel per submit.
         */
        $mapelid = required_param('id_mapel', PARAM_INT);
        $tingkat = local_akademikmonitor_backend_normalize_tingkat(required_param('tingkat', PARAM_TEXT));
        $jam     = local_akademikmonitor_backend_jam(optional_param('jam_pelajaran', '', PARAM_TEXT));
        $kktp    = max(0, min(100, optional_param('kktp', 0, PARAM_INT)));

        $DB->get_record('mata_pelajaran', ['id' => $mapelid], '*', MUST_EXIST);

        $existing = $DB->get_record('kurikulum_mapel', [
            'id_kurikulum_jurusan' => $kurjur->id,
            'id_mapel'             => $mapelid,
            'tingkat_kelas'        => $tingkat,
        ], '*', IGNORE_MULTIPLE);

        $rec = $existing ?: new stdClass();
        $rec->id_kurikulum_jurusan = $kurjur->id;
        $rec->id_mapel             = $mapelid;
        $rec->tingkat_kelas        = $tingkat;
        $rec->jam_pelajaran        = $jam;
        $rec->kktp                 = $kktp;

        if ($existing) {
            $DB->update_record('kurikulum_mapel', $rec);
            $msg = 'Mapel jurusan berhasil diperbarui.';
        } else {
            $DB->insert_record('kurikulum_mapel', $rec);
            $msg = 'Mapel berhasil ditambahkan ke jurusan.';
        }
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', [
            'id' => $idjurusan,
        ]),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$namefield = local_akademikmonitor_backend_kurikulum_namefield();

$kurikulum = $DB->get_record('kurikulum', [
    'id' => $kurjur->id_kurikulum,
], '*', IGNORE_MISSING);

$tahun = $DB->get_record('tahun_ajaran', [
    'id' => $kurjur->id_tahun_ajaran,
], '*', IGNORE_MISSING);

$sql = "SELECT km.id AS kmid,
               km.id_mapel,
               km.tingkat_kelas,
               km.jam_pelajaran,
               km.kktp,
               mp.nama_mapel,
               COUNT(DISTINCT cp.id) AS total_cp,
               COUNT(DISTINCT tp.id) AS total_tp,
               MIN(cp.id) AS first_cpid
          FROM {kurikulum_mapel} km
          JOIN {mata_pelajaran} mp ON mp.id = km.id_mapel
     LEFT JOIN {capaian_pembelajaran} cp ON cp.id_kurikulum_mapel = km.id
     LEFT JOIN {tujuan_pembelajaran} tp ON tp.id_capaian_pembelajaran = cp.id
         WHERE km.id_kurikulum_jurusan = :kjid
      GROUP BY km.id, km.id_mapel, km.tingkat_kelas, km.jam_pelajaran, km.kktp, mp.nama_mapel
      ORDER BY km.tingkat_kelas ASC, mp.nama_mapel ASC";

$records = $DB->get_records_sql($sql, [
    'kjid' => $kurjur->id,
]);

$items = [];
$no = 1;

foreach ($records as $r) {
    $items[] = [
        'no' => $no++,
        'kmid' => (int)$r->kmid,
        'nama_mapel' => format_string($r->nama_mapel),
        'tingkat' => format_string($r->tingkat_kelas),
        'jam_pelajaran' => $r->jam_pelajaran !== null && $r->jam_pelajaran !== '' ? s($r->jam_pelajaran) : '-',
        'kktp' => $r->kktp !== null && $r->kktp !== '' ? (int)$r->kktp : '-',
        'total_cp' => (int)$r->total_cp,
        'total_tp' => (int)$r->total_tp,
        'has_cp' => ((int)$r->total_cp > 0),
        'set_detail_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setdetail.php', [
            'id' => $r->kmid,
        ]))->out(false),
        'cp_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/capaian_pembelajaran.php', [
            'kmid' => $r->kmid,
        ]))->out(false),
        'tp_url' => !empty($r->first_cpid) ? (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', [
            'kmid' => $r->kmid,
            'cpid' => $r->first_cpid,
        ]))->out(false) : '',
        'has_tp_url' => !empty($r->first_cpid),
        'delete_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', [
            'id' => $idjurusan,
            'deletekmid' => $r->kmid,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

$mapeloptions = [];
$mapels = $DB->get_records('mata_pelajaran', null, 'nama_mapel ASC');

foreach ($mapels as $m) {
    $mapeloptions[] = [
        'id' => (int)$m->id,
        'nama' => format_string($m->nama_mapel),
    ];
}

$tingkatoptions = [
    ['value' => 'X', 'label' => 'X'],
    ['value' => 'XI', 'label' => 'XI'],
    ['value' => 'XII', 'label' => 'XII'],
];

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls('jurusan'), [
    'jurusan_id' => (int)$jurusan->id,
    'nama_jurusan' => format_string($jurusan->nama_jurusan),
    'kode_jurusan' => isset($jurusan->kode_jurusan) ? format_string((string)$jurusan->kode_jurusan) : '',
    'nama_kurikulum' => $kurikulum ? format_string($kurikulum->{$namefield} ?? '-') : '-',
    'tahun_ajaran' => local_akademikmonitor_backend_tahun_label($tahun),
    'items' => $items,
    'has_items' => !empty($items),
    'mapel_options' => $mapeloptions,
    'tingkat_options' => $tingkatoptions,
    // JSON untuk JavaScript multi-row
    'mapel_options_json' => json_encode(array_map(fn($m) => ['id' => $m['id'], 'nama' => $m['nama']], $mapeloptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
    'tingkat_options_json' => json_encode(array_map(fn($t) => ['value' => $t['value'], 'label' => $t['label']], $tingkatoptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
    'sesskey' => sesskey(),
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', [
        'id' => $idjurusan,
    ]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
    'set_kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setkurikulum.php', [
        'id' => $idjurusan,
    ]))->out(false),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/setmapel', $templatecontext);
echo $OUTPUT->footer();