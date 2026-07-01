<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $OUTPUT, $PAGE;

$id = required_param('id', PARAM_INT);
$selectedsemester = optional_param('semester', 1, PARAM_INT);

if (!in_array($selectedsemester, [1, 2], true)) {
    $selectedsemester = 1;
}

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', [
    'id' => $id,
    'semester' => $selectedsemester,
]));
$PAGE->set_context($context);
$PAGE->set_title('Course Moodle Kelas');
$PAGE->set_heading('Course Moodle Kelas');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$PAGE->requires->js(
    new moodle_url('https://code.jquery.com/jquery-3.6.0.min.js')
);

$PAGE->requires->css(
    new moodle_url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css')
);

$PAGE->requires->js(
    new moodle_url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js')
);





function local_akademikmonitor_cm_admin_urls(string $active): array {
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

/**
 * Ambil SELECT field user yang aman untuk fullname().
 *
 * fullname($user) di Moodle baru butuh field nama lengkap,
 * bukan hanya firstname dan lastname.
 */
function local_akademikmonitor_cm_user_selects(string $alias = 'u', array $including = []): array {
    $fields = \core_user\fields::for_name();

    if (!empty($including)) {
        $fields = $fields->including(...$including);
    }

    $sql = $fields->get_sql($alias, false, '', '', false);

    $selects = $alias . '.id';

    if (!empty($sql->selects)) {
        $cleanselects = preg_replace('/^\s*,\s*/', '', $sql->selects);
        $selects .= ', ' . $cleanselects;
    }

    return [$selects, $sql->params];
}

/**
 * Ambil pilihan guru pengampu.
 *
 * Konsep:
 * - Wali kelas tetap boleh menjadi guru mapel.
 * - Tetapi wali kelas tidak otomatis menjadi guru course.
 * - Dia baru menjadi guru course kalau dipilih di dropdown guru pengampu.
 *
 * Sumber user yang ditampilkan:
 * 1. User dengan custom profile jenis_pengguna = guru.
 * 2. User dengan custom profile jenis_pengguna = wali_kelas / walikelas / wali kelas.
 * 3. User yang dipilih sebagai wali kelas di tabel kelas.id_user.
 *
 * Kenapa tabel kelas.id_user ikut dicek?
 * Karena konsep plugin kamu menentukan wali kelas dari rombel,
 * bukan dari role Moodle global.
 */
function local_akademikmonitor_cm_get_teacher_options(): array {
    global $DB;

    [$selects, $fieldparams] = local_akademikmonitor_cm_user_selects('u', ['email', 'username']);

    $params = $fieldparams + [
        'shortname' => 'jenis_pengguna',
        'jenisguru' => 'guru',
        'jeniswali1' => 'wali_kelas',
        'jeniswali2' => 'walikelas',
        'jeniswali3' => 'wali kelas',
    ];

    $sql = "SELECT DISTINCT {$selects}
              FROM {user} u
         LEFT JOIN {user_info_field} uif
                ON uif.shortname = :shortname
         LEFT JOIN {user_info_data} uid
                ON uid.fieldid = uif.id
               AND uid.userid = u.id
         LEFT JOIN {kelas} k
                ON k.id_user = u.id
             WHERE u.deleted = 0
               AND u.suspended = 0
               AND u.id > 1
               AND (
                    LOWER(TRIM(uid.data)) = :jenisguru
                    OR LOWER(TRIM(uid.data)) = :jeniswali1
                    OR LOWER(TRIM(uid.data)) = :jeniswali2
                    OR LOWER(TRIM(uid.data)) = :jeniswali3
                    OR k.id IS NOT NULL
               )
          ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC";

    $users = $DB->get_records_sql($sql, $params);

    $options = [];

    foreach ($users as $user) {
        $email = !empty($user->email) ? ' - ' . $user->email : '';

        $options[] = [
            'id' => (int)$user->id,
            'name' => fullname($user) . $email,
        ];
    }

    return $options;
}

/**
 * Ambil guru yang sudah terpasang di course.
 *
 * Ini tetap pakai role_assignments karena course-nya sudah ada.
 */
function local_akademikmonitor_cm_get_course_teachers(int $courseid): array {
    global $DB;

    if ($courseid <= 0) {
        return [];
    }

    $roles = $DB->get_records_list('role', 'shortname', ['editingteacher']);

    if (!$roles) {
        return [];
    }

    $roleids = [];

    foreach ($roles as $role) {
        $roleids[] = (int)$role->id;
    }

    if (!$roleids) {
        return [];
    }

    $coursecontext = context_course::instance($courseid);

    [$roleinsql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'roleid');
    [$selects, $fieldparams] = local_akademikmonitor_cm_user_selects('u', ['email']);

    $params = $roleparams + $fieldparams + [
        'contextid' => $coursecontext->id,
    ];

    $users = $DB->get_records_sql(
        "SELECT DISTINCT {$selects}
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
          WHERE ra.contextid = :contextid
            AND ra.roleid {$roleinsql}
            AND u.deleted = 0
            AND u.suspended = 0
       ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC",
        $params
    );

    $names = [];

    foreach ($users as $user) {
        $names[] = fullname($user);
    }

    return $names;
}
/**
 * Ambil ID guru editingteacher yang sudah terpasang di course.
 */
function local_akademikmonitor_cm_get_course_teacher_ids(int $courseid): array {
    global $DB;

    if ($courseid <= 0) {
        return [];
    }

    $roles = $DB->get_records_list('role', 'shortname', ['editingteacher']);

    if (!$roles) {
        return [];
    }

    $roleids = [];

    foreach ($roles as $role) {
        $roleids[] = (int)$role->id;
    }

    $coursecontext = context_course::instance($courseid);

    [$roleinsql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'roleid');

    $params = $roleparams + [
        'contextid' => $coursecontext->id,
    ];

    $records = $DB->get_records_sql(
        "SELECT DISTINCT u.id
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
          WHERE ra.contextid = :contextid
            AND ra.roleid {$roleinsql}
            AND u.deleted = 0
            AND u.suspended = 0",
        $params
    );

    return array_map('intval', array_keys($records));
}

/**
 * Ambil default guru dari semester lain.
 */
function local_akademikmonitor_cm_get_default_teacher_from_semester(
    stdClass $mapel,
    stdClass $kelas,
    stdClass $jurusan,
    stdClass $tahun,
    int $sourcesemester
): int {

    global $DB;

    $names = \local_akademikmonitor\service\course_name_service::build_names(
        $mapel,
        $kelas,
        $jurusan,
        $tahun,
        $sourcesemester
    );

    if (empty($names['idnumber'])) {
        return 0;
    }

    $sourcecourse = $DB->get_record(
        'course',
        ['idnumber' => $names['idnumber']],
        'id',
        IGNORE_MISSING
    );

    if (!$sourcecourse) {
        return 0;
    }

    $teacherids = local_akademikmonitor_cm_get_course_teacher_ids(
        (int)$sourcecourse->id
    );

    return $teacherids ? (int)reset($teacherids) : 0;
}
/**
 * Ambil data mapel tanpa SELECT JOIN manual.
 *
 * Alur:
 * kurikulum_mapel -> mata_pelajaran
 *
 * Kenapa dibuat begini?
 * Supaya tidak bergantung pada SELECT JOIN panjang untuk preview.
 */
function local_akademikmonitor_cm_get_mapels(
    int $kurikulumjurusanid,
    string $tingkatkelas
): array {
    global $DB;

    $kurikulummapels = $DB->get_records(
        'kurikulum_mapel',
        [
            'id_kurikulum_jurusan' => $kurikulumjurusanid,
            'tingkat_kelas' => $tingkatkelas,
        ],
        'id ASC'
    );

    if (!$kurikulummapels) {
        return [];
    }

    $mapelids = [];

    foreach ($kurikulummapels as $km) {
        if (!empty($km->id_mapel)) {
            $mapelids[] = (int)$km->id_mapel;
        }
    }

    $mapelids = array_values(array_unique(array_filter($mapelids)));

    if (!$mapelids) {
        return [];
    }

    $mapelrecords = $DB->get_records_list(
        'mata_pelajaran',
        'id',
        $mapelids
    );

    if (!$mapelrecords) {
        return [];
    }

    $result = [];

    foreach ($kurikulummapels as $km) {
        $mapelid = !empty($km->id_mapel) ? (int)$km->id_mapel : 0;

        if ($mapelid <= 0 || empty($mapelrecords[$mapelid])) {
            continue;
        }

        $mp = $mapelrecords[$mapelid];

        $row = new stdClass();
        $row->kmid = (int)$km->id;
        $row->id_mapel = $mapelid;
        $row->tingkat_kelas = $km->tingkat_kelas ?? '';
        $row->jam_pelajaran = $km->jam_pelajaran ?? '';
        $row->kktp = $km->kktp ?? '';
        $row->nama_mapel = $mp->nama_mapel ?? ($mp->nama ?? 'Mata Pelajaran');

        /*
         * Bawa kemungkinan field jenis mapel dari tabel mapel atau kurikulum_mapel.
         * Nanti course_name_service yang menentukan jenis mapel final.
         */
        foreach (['jenis', 'jenis_mapel', 'kategori', 'kelompok', 'kelompok_mapel'] as $field) {
            if (property_exists($mp, $field)) {
                $row->{$field} = $mp->{$field};
            } else if (property_exists($km, $field)) {
                $row->{$field} = $km->{$field};
            }
        }

        $result[] = $row;
    }

    usort($result, static function($a, $b) {
        return strcasecmp((string)$a->nama_mapel, (string)$b->nama_mapel);
    });

    return $result;
}

$kelas = $DB->get_record('kelas', ['id' => $id], '*', MUST_EXIST);
$jurusan = $DB->get_record('jurusan', ['id' => $kelas->id_jurusan], '*', MUST_EXIST);

$tahun = null;

if (!empty($kelas->id_tahun_ajaran)) {
    $tahun = $DB->get_record('tahun_ajaran', ['id' => $kelas->id_tahun_ajaran], '*', IGNORE_MISSING);
}

if (!$tahun) {
    $tahunrecords = $DB->get_records('tahun_ajaran', null, 'id DESC', '*', 0, 1);
    $tahun = $tahunrecords ? reset($tahunrecords) : null;
}

if (!$tahun) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Belum ada tahun ajaran. Buat tahun ajaran terlebih dahulu.', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$validtingkat = ['X', 'XI', 'XII'];
$tingkatkelas = trim((string)$kelas->tingkat);

if (!in_array($tingkatkelas, $validtingkat, true)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Tingkat kelas tidak valid: ' . s($kelas->tingkat), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$activekurikulumid = (int)get_config('local_akademikmonitor', 'active_kurikulumid');

$kurjurparams = [
    'id_jurusan' => $kelas->id_jurusan,
    'id_tahun_ajaran' => $tahun->id,
];

if ($activekurikulumid > 0) {
    $kurjurparams['id_kurikulum'] = $activekurikulumid;
}

$kurjur = $DB->get_record('kurikulum_jurusan', $kurjurparams, '*', IGNORE_MULTIPLE);

if (!$kurjur && $activekurikulumid > 0) {
    $newkj = new stdClass();
    $newkj->id_jurusan = $kelas->id_jurusan;
    $newkj->id_kurikulum = $activekurikulumid;
    $newkj->id_tahun_ajaran = $tahun->id;
    $newkj->id = $DB->insert_record('kurikulum_jurusan', $newkj);

    $kurjur = $newkj;
}

if (!$kurjur) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Kurikulum belum diset untuk jurusan dan tahun ajaran kelas ini.', 'notifyproblem');
    echo $OUTPUT->continue_button(new moodle_url('/local/akademikmonitor/pages/jurusan/setkurikulum.php', [
        'id' => $kelas->id_jurusan,
    ]));
    echo $OUTPUT->footer();
    exit;
}

$mapels = local_akademikmonitor_cm_get_mapels(
    (int)$kurjur->id,
    $tingkatkelas
);

$teacheroptionssource = local_akademikmonitor_cm_get_teacher_options();

$items = [];
$totalbelum = 0;
$totalsudah = 0;
$no = 1;

/*
 * Semua label utama diambil dari course_name_service supaya sama dengan generate.php.
 */
$tahunlabel = \local_akademikmonitor\service\course_name_service::tahun_label($tahun);
$kelaslabel = \local_akademikmonitor\service\course_name_service::rombel_label($kelas, $jurusan);
$selectedsemesterlabel = \local_akademikmonitor\service\course_name_service::semester_label($selectedsemester);

/*
 * Di sini hanya loop semester yang sedang dipilih.
 * Jadi Generate Semua mengikuti semester halaman.
 */
foreach ($mapels as $m) {
    foreach ([$selectedsemester] as $semester) {
        /*
         * Semua identitas course dibuat oleh course_name_service.
         *
         * Jadi coursemoodle.php tidak lagi punya rumus nama course sendiri.
         * generate.php juga harus memakai service yang sama.
         */
        $names = \local_akademikmonitor\service\course_name_service::build_names(
            $m,
            $kelas,
            $jurusan,
            $tahun,
            $semester
        );

        $idnumber = $names['idnumber'];
        $namacourse = $names['fullname'];
        $semesterlabel = $names['semester_label'];

        $existingcourse = $DB->get_record(
            'course',
            ['idnumber' => $idnumber],
            'id, fullname, shortname, idnumber',
            IGNORE_MISSING
        );

        $sudah = $existingcourse ? true : false;

        if ($sudah) {
            $totalsudah++;
        } else {
            $totalbelum++;
        }

        $selectedteacherid = 0;

        if ($existingcourse) {
            $existingteacherids = local_akademikmonitor_cm_get_course_teacher_ids(
                (int)$existingcourse->id
            );

            $selectedteacherid = $existingteacherids
                ? (int)reset($existingteacherids)
                : 0;
        }

        if ($selectedteacherid <= 0 && (int)$semester === 2) {
            $selectedteacherid =
                local_akademikmonitor_cm_get_default_teacher_from_semester(
                    $m,
                    $kelas,
                    $jurusan,
                    $tahun,
                    1
                );
        }
        $teacheroptions = [];

        foreach ($teacheroptionssource as $teacheroption) {
        $teacherid = (int)$teacheroption['id'];

        $teacheroptions[] = [
            'id' => $teacherid,
            'name' => $teacheroption['name'],
            'selected' => (
                $selectedteacherid > 0
                && $selectedteacherid === $teacherid
            ),
        ];
        }

        $teacherexisting = [];

        if ($existingcourse) {
            $teacherexisting = local_akademikmonitor_cm_get_course_teachers((int)$existingcourse->id);
        }

        $items[] = [
            'no' => $no++,
            'nama_mapel' => format_string($m->nama_mapel),
            'tingkat' => format_string($m->tingkat_kelas),
            'semester' => $semesterlabel,
            'kktp' => $m->kktp !== null && $m->kktp !== '' ? (int)$m->kktp : '-',
            'nama_course' => format_string($namacourse),
            'idnumber' => $idnumber,
            'sudah' => $sudah,
            'belum' => !$sudah,
            'teacher_field_name' => 'guru[' . $m->kmid . '_' . $semester . ']',
            'generate_value' => $m->kmid . '_' . $semester,
            'teacher_options' => $teacheroptions,
            'teacher_existing' => $teacherexisting ? implode(', ', $teacherexisting) : '-',
            'course_url' => $existingcourse ? (new moodle_url('/course/view.php', [
                'id' => $existingcourse->id,
            ]))->out(false) : '',
        ];
    }
}

$wali = null;

if (!empty($kelas->id_user)) {
    [$waliselects, $waliparams] = local_akademikmonitor_cm_user_selects('u', ['email']);

    $wali = $DB->get_record_sql(
        "SELECT {$waliselects}
           FROM {user} u
          WHERE u.id = :userid
            AND u.deleted = 0",
        $waliparams + [
            'userid' => $kelas->id_user,
        ],
        IGNORE_MISSING
    );
}

$templatecontext = array_merge(local_akademikmonitor_cm_admin_urls('kelas'), [
    'kelas_id' => (int)$kelas->id,
    'kelas_nama' => format_string($kelas->nama),
    'kelas_label' => format_string($kelaslabel),
    'jurusan_nama' => format_string($jurusan->nama_jurusan),
    'tingkat' => format_string($tingkatkelas),
    'wali_nama' => $wali ? fullname($wali) : '-',
    'tahun_ajaran' => format_string($tahunlabel),

    'selected_semester' => $selectedsemester,
    'selected_semester_label' => $selectedsemesterlabel,
    'is_semester_ganjil' => $selectedsemester === 1,
    'is_semester_genap' => $selectedsemester === 2,

    'semester_ganjil_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', [
        'id' => $id,
        'semester' => 1,
    ]))->out(false),

    'semester_genap_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', [
        'id' => $id,
        'semester' => 2,
    ]))->out(false),

    'total_mapel' => count($mapels),
    'total_course' => count($items),
    'total_sudah' => $totalsudah,
    'total_belum' => $totalbelum,
    'semua_sudah' => $totalbelum === 0,

    'items' => $items,
    'has_items' => !empty($items),
    'has_teacher_options' => !empty($teacheroptionssource),

    'generate_action' => (new moodle_url('/local/akademikmonitor/pages/kelas/generate.php'))->out(false),
    'sesskey' => sesskey(),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
]);
$PAGE->requires->js_call_amd(
    'local_akademikmonitor/searchable_select',
    'init'
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
'local_akademikmonitor/coursemoodle', 
$templatecontext
);

echo $OUTPUT->footer();