<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}
require_sesskey();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB, $CFG;

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_category.php');
require_once($CFG->libdir . '/grade/grade_item.php');

$kelasid = required_param('kelasid', PARAM_INT);

// Tetap support URL lama.
$onlykmid = optional_param('kmid', 0, PARAM_INT);
$onlysemester = optional_param('semester', 0, PARAM_INT);

// Support alur baru dari form preview.
$generateone = optional_param('generate_one', '', PARAM_TEXT);
$guruinput = optional_param_array('guru', [], PARAM_INT);

if ($generateone !== '') {
    $parts = explode('_', $generateone);

    if (count($parts) === 2) {
        $onlykmid = (int)$parts[0];
        $onlysemester = (int)$parts[1];
    }
}

/**
 * Membersihkan bagian nama kategori.
 *
 * Function ini tetap ada di generate.php karena masih dipakai khusus
 * untuk membuat kategori Moodle.
 */
function local_akademikmonitor_generate_clean_course_part(string $text): string {
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = str_replace(['/', '\\'], '-', $text);

    return $text;
}

/**
 * Ambil atau buat kategori Moodle berdasarkan nama dan parent.
 */
function local_akademikmonitor_generate_get_or_create_category(string $categoryname, int $parent = 0): int {
    global $DB;

    $categoryname = local_akademikmonitor_generate_clean_course_part($categoryname);

    if ($categoryname === '') {
        $categoryname = 'Akademik Monitoring';
    }

    $existing = $DB->get_record('course_categories', [
        'name' => $categoryname,
        'parent' => $parent,
    ], 'id', IGNORE_MULTIPLE);

    if ($existing) {
        return (int)$existing->id;
    }

    $category = \core_course_category::create([
        'name' => $categoryname,
        'parent' => $parent,
        'visible' => 1,
    ]);

    return (int)$category->id;
}

/**
 * Mengambil kategori tujuan course.
 *
 * Struktur:
 * Tahun Ajaran -> Jurusan -> Rombel -> Semester
 *
 * Label tahun, rombel, dan semester sekarang diambil dari course_name_service
 * supaya tidak ada logic nama/label yang dobel.
 */
function local_akademikmonitor_generate_course_category_id(
    stdClass $kelas,
    stdClass $jurusan,
    stdClass $tahun,
    int $semester
): int {
    $tahunlabel = \local_akademikmonitor\service\course_name_service::tahun_label($tahun);
    $tahunlabel = local_akademikmonitor_generate_clean_course_part($tahunlabel);

    $jurusanlabel = local_akademikmonitor_generate_clean_course_part((string)$jurusan->nama_jurusan);
    $rombollabel = \local_akademikmonitor\service\course_name_service::rombel_label($kelas, $jurusan);
    $semesterlabel = \local_akademikmonitor\service\course_name_service::semester_label($semester);

    $tahuncatid = local_akademikmonitor_generate_get_or_create_category($tahunlabel, 0);

    if ($tahuncatid <= 0) {
        return 0;
    }

    $jurusancatid = local_akademikmonitor_generate_get_or_create_category($jurusanlabel, $tahuncatid);

    if ($jurusancatid <= 0) {
        return 0;
    }

    $rombelcatid = local_akademikmonitor_generate_get_or_create_category($rombollabel, $jurusancatid);

    if ($rombelcatid <= 0) {
        return 0;
    }

    return local_akademikmonitor_generate_get_or_create_category($semesterlabel, $rombelcatid);
}

/**
 * Cari role Moodle.
 */
function local_akademikmonitor_generate_find_role(array $shortnames, array $namekeywords = []): ?stdClass {
    global $DB;

    foreach ($shortnames as $shortname) {
        $role = $DB->get_record('role', ['shortname' => $shortname], '*', IGNORE_MISSING);

        if ($role) {
            return $role;
        }
    }

    if ($namekeywords) {
        $roles = $DB->get_records('role', null, 'sortorder ASC, id ASC');

        foreach ($roles as $role) {
            $haystack = strtolower(trim(($role->shortname ?? '') . ' ' . ($role->name ?? '')));
            $match = true;

            foreach ($namekeywords as $keyword) {
                if (strpos($haystack, strtolower($keyword)) === false) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return $role;
            }
        }
    }

    return null;
}

/**
 * Ambil manual enrol instance.
 *
 * Kalau belum ada, dibuatkan.
 */
function local_akademikmonitor_generate_get_manual_instance(stdClass $course): ?stdClass {
    $instances = enrol_get_instances($course->id, true);

    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            return $instance;
        }
    }

    $plugin = enrol_get_plugin('manual');

    if (!$plugin) {
        return null;
    }

    $plugin->add_instance($course);

    $instances = enrol_get_instances($course->id, true);

    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            return $instance;
        }
    }

    return null;
}

/**
 * Pastikan group kelas ada di course.
 */
function local_akademikmonitor_generate_ensure_group(int $courseid, string $groupname): int {
    global $DB;

    $groupname = trim($groupname);

    if ($groupname === '') {
        $groupname = 'Kelas';
    }

    $group = $DB->get_record('groups', [
        'courseid' => $courseid,
        'name' => $groupname,
    ], '*', IGNORE_MULTIPLE);

    if ($group) {
        return (int)$group->id;
    }

    $newgroup = new stdClass();
    $newgroup->courseid = $courseid;
    $newgroup->name = $groupname;
    $newgroup->description = 'Group kelas dibuat otomatis oleh Plugin Akademik & Monitoring.';
    $newgroup->descriptionformat = FORMAT_HTML;

    return groups_create_group($newgroup);
}

/**
 * Pastikan relasi course-mapel ada.
 *
 * Bagian ini memakai execute untuk INSERT saja, bukan SELECT manual.
 * Ini dipertahankan karena tabel course_mapel kamu kemungkinan tidak punya
 * id auto increment standar sehingga insert_record() bisa error inserted id.
 */
function local_akademikmonitor_generate_ensure_relation(int $courseid, int $kmid): void {
    global $DB;

    if ($courseid <= 0 || $kmid <= 0) {
        return;
    }

    $exists = $DB->record_exists('course_mapel', [
        'id_course' => $courseid,
        'id_kurikulum_mapel' => $kmid,
    ]);

    if ($exists) {
        return;
    }

    $DB->execute(
        "INSERT INTO {course_mapel} (id_course, id_kurikulum_mapel)
              VALUES (:idcourse, :idkurikulummapel)",
        [
            'idcourse' => $courseid,
            'idkurikulummapel' => $kmid,
        ]
    );
}

/**
 * Enrol user ke course dan pastikan role assignment ada.
 */
function local_akademikmonitor_generate_enrol_user(
    stdClass $course,
    stdClass $instance,
    int $userid,
    int $roleid,
    int $groupid = 0
): void {
    global $DB;

    if ($userid <= 0 || $roleid <= 0) {
        return;
    }

    $plugin = enrol_get_plugin('manual');

    if (!$plugin) {
        return;
    }

    $coursecontext = context_course::instance($course->id);

    if (!is_enrolled($coursecontext, $userid, '', true)) {
        $plugin->enrol_user($instance, $userid, $roleid, time(), 0, ENROL_USER_ACTIVE);
    }

    if (!$DB->record_exists('role_assignments', [
        'roleid' => $roleid,
        'userid' => $userid,
        'contextid' => $coursecontext->id,
    ])) {
        role_assign($roleid, $userid, $coursecontext->id);
    }

    if ($groupid > 0 && !$DB->record_exists('groups_members', [
        'groupid' => $groupid,
        'userid' => $userid,
    ])) {
        groups_add_member($groupid, $userid);
    }
}

/**
 * Ambil guru yang dipilih dari form generate.
 *
 * Nama input dari template:
 * guru[kmid_semester]
 */
function local_akademikmonitor_generate_get_selected_guru(array $guruinput, int $kmid, int $semester): int {
    $key = $kmid . '_' . $semester;

    if (isset($guruinput[$key])) {
        return (int)$guruinput[$key];
    }

    return 0;
}

/**
 * Ambil atau buat kategori nilai di gradebook.
 */
function local_akademikmonitor_generate_get_or_create_grade_category(
    int $courseid,
    string $fullname,
    int $aggregation = GRADE_AGGREGATE_MEAN
): grade_category {
    $category = grade_category::fetch([
        'courseid' => $courseid,
        'fullname' => $fullname,
    ]);

    if ($category) {
        $category->aggregation = $aggregation;
        $category->aggregateonlygraded = 1;
        $category->update('local_akademikmonitor');

        return $category;
    }

    $category = new grade_category();
    $category->courseid = $courseid;
    $category->fullname = $fullname;
    $category->aggregation = $aggregation;
    $category->aggregateonlygraded = 1;
    $category->insert('local_akademikmonitor');

    return $category;
}

/**
 * Ambil atau buat manual grade item.
 *
 * Dipakai untuk UTS dan UAS agar semua course otomatis punya kolom ujian.
 */
function local_akademikmonitor_generate_get_or_create_manual_grade_item(
    int $courseid,
    string $itemname,
    string $idnumber,
    int $categoryid,
    float $grademax = 100.0
): grade_item {
    $gradeitem = grade_item::fetch([
        'courseid' => $courseid,
        'idnumber' => $idnumber,
    ]);

    if ($gradeitem) {
        $gradeitem->itemname = $itemname;
        $gradeitem->categoryid = $categoryid;
        $gradeitem->gradetype = GRADE_TYPE_VALUE;
        $gradeitem->grademin = 0;
        $gradeitem->grademax = $grademax;
        $gradeitem->hidden = 0;
        $gradeitem->locked = 0;
        $gradeitem->update('local_akademikmonitor');

        return $gradeitem;
    }

    $gradeitem = new grade_item();
    $gradeitem->courseid = $courseid;
    $gradeitem->categoryid = $categoryid;
    $gradeitem->itemtype = 'manual';
    $gradeitem->itemname = $itemname;
    $gradeitem->idnumber = $idnumber;
    $gradeitem->gradetype = GRADE_TYPE_VALUE;
    $gradeitem->grademin = 0;
    $gradeitem->grademax = $grademax;
    $gradeitem->hidden = 0;
    $gradeitem->locked = 0;
    $gradeitem->insert('local_akademikmonitor');

    return $gradeitem;
}

/**
 * Set idnumber untuk total kategori.
 */
function local_akademikmonitor_generate_set_category_total_idnumber(
    grade_category $category,
    string $idnumber
): void {
    $gradeitem = $category->load_grade_item();

    if (!$gradeitem) {
        return;
    }

    if ((string)$gradeitem->idnumber !== $idnumber) {
        $gradeitem->idnumber = $idnumber;
        $gradeitem->update('local_akademikmonitor');
    }
}

/**
 * Setup struktur gradebook otomatis.
 *
 * Struktur:
 * - Ujian
 *   - UTS
 *   - UAS
 *
 * TP dibuat oleh:
 * \local_akademikmonitor\service\tp_gradebook_service
 */
function local_akademikmonitor_generate_setup_gradebook_formula(int $courseid): void {
    if ($courseid <= 0) {
        return;
    }

    $ujiancategory = local_akademikmonitor_generate_get_or_create_grade_category(
        $courseid,
        'Ujian',
        GRADE_AGGREGATE_MEAN
    );

    local_akademikmonitor_generate_get_or_create_manual_grade_item(
        $courseid,
        'UTS',
        'am_uts',
        (int)$ujiancategory->id,
        100.0
    );

    local_akademikmonitor_generate_get_or_create_manual_grade_item(
        $courseid,
        'UAS',
        'am_uas',
        (int)$ujiancategory->id,
        100.0
    );

    local_akademikmonitor_generate_set_category_total_idnumber(
        $ujiancategory,
        'am_ujian_total'
    );

    $coursecategory = grade_category::fetch_course_category($courseid);

    if (!$coursecategory) {
        return;
    }

    $changed = false;

    if ((int)$coursecategory->aggregateonlygraded !== 1) {
        $coursecategory->aggregateonlygraded = 1;
        $changed = true;
    }

    if ($changed) {
        $coursecategory->update('local_akademikmonitor');
    }

    $courseitem = $coursecategory->load_grade_item();

    if ($courseitem) {


    }

    grade_regrade_final_grades($courseid);
}

/**
 * Ambil daftar mapel tanpa SELECT JOIN manual.
 *
 * Alur:
 * kurikulum_mapel -> mata_pelajaran
 */
function local_akademikmonitor_generate_get_mapels(
    int $kurikulumjurusanid,
    string $tingkatkelas,
    int $onlykmid = 0
): array {
    global $DB;

    $conditions = [
        'id_kurikulum_jurusan' => $kurikulumjurusanid,
        'tingkat_kelas' => $tingkatkelas,
    ];

    if ($onlykmid > 0) {
        $conditions['id'] = $onlykmid;
    }

    $kurikulummapels = $DB->get_records(
        'kurikulum_mapel',
        $conditions,
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
        $row->nama_mapel = $mp->nama_mapel ?? ($mp->nama ?? 'Mata Pelajaran');

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

$kelas = $DB->get_record('kelas', ['id' => $kelasid], '*', MUST_EXIST);
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
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/index.php'),
        'Belum ada tahun ajaran.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$tingkatkelas = trim((string)$kelas->tingkat);

if (!in_array($tingkatkelas, ['X', 'XI', 'XII'], true)) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', ['id' => $kelasid]),
        'Tingkat kelas tidak valid: ' . $kelas->tingkat,
        null,
        \core\output\notification::NOTIFY_ERROR
    );
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
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', ['id' => $kelasid]),
        'Kurikulum belum diset untuk jurusan dan tahun ajaran kelas ini.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$mapels = local_akademikmonitor_generate_get_mapels(
    (int)$kurjur->id,
    $tingkatkelas,
    $onlykmid
);

if (!$mapels) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', ['id' => $kelasid]),
        'Tidak ada mata pelajaran untuk tingkat ini.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$semesters = [1, 2];

if (in_array($onlysemester, [1, 2], true)) {
    $semesters = [$onlysemester];
}

$studentrole = local_akademikmonitor_generate_find_role(['student']);
$teacherrole = local_akademikmonitor_generate_find_role(['editingteacher']);

$studentroleid = $studentrole ? (int)$studentrole->id : 0;
$teacherroleid = $teacherrole ? (int)$teacherrole->id : 0;

$teacherroles = $DB->get_records_list(
    'role',
    'shortname',
    ['editingteacher'],
    '',
    'id, shortname'
);

$teacherroleids = $teacherroles ? array_map('intval', array_keys($teacherroles)) : [];

if ($studentroleid <= 0) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', ['id' => $kelasid]),
        'Role student tidak ditemukan.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$pesertas = $DB->get_records('peserta_kelas', ['id_kelas' => $kelasid], 'id ASC');

$groupname = \local_akademikmonitor\service\course_name_service::rombel_label($kelas, $jurusan);

if (trim($groupname) === '') {
    $groupname = 'Kelas';
}

$created = 0;
$updated = 0;
$enrolled = 0;
$teacherassigned = 0;
$teacherempty = 0;

foreach ($mapels as $m) {
    foreach ($semesters as $semester) {
        $coursecategoryid = local_akademikmonitor_generate_course_category_id(
            $kelas,
            $jurusan,
            $tahun,
            $semester
        );

        if ($coursecategoryid <= 0) {
            redirect(
                new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', ['id' => $kelasid]),
                'Kategori course gagal dibuat.',
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        /*
         * Semua identitas course sekarang dibuat oleh course_name_service.
         *
         * Ini supaya generate.php tidak punya rumus nama sendiri.
         * Preview di coursemoodle.php nanti juga harus pakai service yang sama.
         */
        $names = \local_akademikmonitor\service\course_name_service::build_names(
            $m,
            $kelas,
            $jurusan,
            $tahun,
            $semester
        );

        $idnumber = $names['idnumber'];
        $fullname = $names['fullname'];
        $shortname = $names['shortname'];

        $course = $DB->get_record('course', ['idnumber' => $idnumber], '*', IGNORE_MISSING);

        if ($course) {
            $course->fullname = $fullname;
            $course->shortname = $shortname;
            $course->category = $coursecategoryid;

            update_course($course);
            $updated++;
        } else {
            $newcourse = new stdClass();
            $newcourse->fullname = $fullname;
            $newcourse->shortname = $shortname;
            $newcourse->idnumber = $idnumber;
            $newcourse->category = $coursecategoryid;
            $newcourse->summary = 'Course dibuat otomatis dari Plugin Akademik & Monitoring.';
            $newcourse->summaryformat = FORMAT_HTML;
            $newcourse->format = 'topics';
            $newcourse->visible = 1;
            $newcourse->startdate = time();
            $newcourse->enddate = 0;
            $newcourse->numsections = 10;

            $course = create_course($newcourse);
            $created++;
        }

        local_akademikmonitor_generate_setup_gradebook_formula((int)$course->id);

        local_akademikmonitor_generate_ensure_relation((int)$course->id, (int)$m->kmid);

        if (class_exists('\local_akademikmonitor\service\tp_gradebook_service')) {
            \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_kurikulum_mapel(
                (int)$m->kmid,
                (int)$course->id
            );
        }

        local_akademikmonitor_generate_setup_gradebook_formula((int)$course->id);

        $manualinstance = local_akademikmonitor_generate_get_manual_instance($course);
        $groupid = local_akademikmonitor_generate_ensure_group((int)$course->id, $groupname);

        if (!$manualinstance) {
            continue;
        }

        $processedusers = [];

        $selectedguruid = local_akademikmonitor_generate_get_selected_guru(
            $guruinput,
            (int)$m->kmid,
            $semester
        );

        /*
         * Pastikan satu course hanya punya satu guru pengampu.
         *
         * Yang dibersihkan hanya role editingteacher.
         * Role teacher tidak dibersihkan karena dipakai untuk wali kelas viewer.
         */
        if ($teacherroleids) {
            $coursecontext = context_course::instance((int)$course->id);

            list($roleinsql, $roleparams) = $DB->get_in_or_equal(
                $teacherroleids,
                SQL_PARAMS_NAMED,
                'teacherrole'
            );

            $params = $roleparams + [
                'contextid' => $coursecontext->id,
            ];

            $oldteachers = $DB->get_records_sql(
                "SELECT ra.id, ra.userid, ra.roleid
                   FROM {role_assignments} ra
                  WHERE ra.contextid = :contextid
                    AND ra.roleid {$roleinsql}",
                $params
            );

            foreach ($oldteachers as $oldteacher) {
                role_unassign(
                    (int)$oldteacher->roleid,
                    (int)$oldteacher->userid,
                    $coursecontext->id
                );
            }
        }

        if ($selectedguruid > 0 && $teacherroleid > 0) {
            local_akademikmonitor_generate_enrol_user(
                $course,
                $manualinstance,
                $selectedguruid,
                $teacherroleid,
                $groupid
            );

            $processedusers[$selectedguruid] = true;
            $teacherassigned++;
            $enrolled++;
        } else {
            $teacherempty++;
        }

        /*
         * Pasang akses wali kelas viewer setelah guru pengampu diproses.
         *
         * Wali kelas mendapat role teacher/non-editing teacher.
         * Kalau wali kelas juga guru pengampu, role editingteacher tetap aman.
         */
        \local_akademikmonitor\service\wali_course_access_service::ensure_for_course(
            $course,
            $manualinstance,
            $kelas,
            $jurusan,
            $tahun,
            $groupid
        );

        // Masukkan siswa dari peserta_kelas.
        foreach ($pesertas as $peserta) {
            $userid = (int)$peserta->id_user;
            $roleid = !empty($peserta->id_role) ? (int)$peserta->id_role : $studentroleid;

            if ($userid <= 0) {
                continue;
            }

            if (isset($processedusers[$userid])) {
                continue;
            }

            if ($roleid !== $studentroleid) {
                continue;
            }

            local_akademikmonitor_generate_enrol_user(
                $course,
                $manualinstance,
                $userid,
                $studentroleid,
                $groupid
            );

            $processedusers[$userid] = true;
            $enrolled++;
        }
    }
}

$message = 'Generate selesai. Course baru: ' . $created .
    ', course diperbarui: ' . $updated .
    ', guru mapel dimasukkan: ' . $teacherassigned .
    ', proses enrol peserta: ' . $enrolled . '.';

if ($teacherempty > 0) {
    $message .= ' Ada ' . $teacherempty . ' course tanpa guru mapel karena guru belum dipilih.';
}

\local_akademikmonitor\service\wali_course_access_service::sync_by_tahunajaran((int)$tahun->id);

redirect(
    new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', [
        'id' => $kelasid,
        'semester' => $onlysemester > 0 ? $onlysemester : 1,
    ]),
    $message,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);