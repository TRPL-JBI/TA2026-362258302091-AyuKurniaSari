<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB, $PAGE, $OUTPUT, $CFG;

require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

$kelasid = required_param('kelasid', PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $kelasid]));
$PAGE->set_context($context);
$PAGE->set_title('Peserta Kelas');
$PAGE->set_heading('Peserta Kelas');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/**
 * URL sidebar admin.
 */
function local_akademikmonitor_backend_admin_urls_peserta(string $active): array {
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
 * Ambil label role sesuai nama role di Moodle.
 */
function local_akademikmonitor_peserta_role_label(?stdClass $role, context $context): string {
    if (!$role) {
        return '-';
    }

    $name = role_get_name($role, $context, ROLENAME_ALIAS);

    return $name ?: $role->shortname;
}

/**
 * Cari role berdasarkan shortname atau kata pada nama role.
 */
function local_akademikmonitor_peserta_find_role(array $shortnames, array $namekeywords = []): ?stdClass {
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
            $haystack = strtolower(trim(($role->name ?? '') . ' ' . ($role->shortname ?? '')));
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
 * Format nama kelas agar status mudah dibaca.
 */
function local_akademikmonitor_peserta_kelas_label(stdClass $row): string {
    $parts = [];

    if (!empty($row->tingkat)) {
        $parts[] = trim((string)$row->tingkat);
    }

    if (!empty($row->nama_jurusan)) {
        $parts[] = trim((string)$row->nama_jurusan);
    }

    if (!empty($row->nama)) {
        $parts[] = trim((string)$row->nama);
    }

    $label = trim(implode(' ', $parts));

    return $label !== '' ? $label : 'Kelas ID ' . (int)($row->id ?? 0);
}

/**
 * Insert atau update peserta kelas.
 *
 * Dipakai untuk menyimpan wali kelas dan siswa saja.
 * Guru mapel tidak disimpan di peserta kelas, karena guru mapel dipilih saat generate course.
 */
function local_akademikmonitor_peserta_upsert(int $kelasid, int $userid, int $roleid): void {
    global $DB;

    if ($kelasid <= 0 || $userid <= 0 || $roleid <= 0) {
        return;
    }

    $existing = $DB->get_record('peserta_kelas', [
        'id_kelas' => $kelasid,
        'id_user' => $userid,
    ], '*', IGNORE_MULTIPLE);

    if ($existing) {
        $existing->id_role = $roleid;
        $DB->update_record('peserta_kelas', $existing);
        return;
    }

    $record = new stdClass();
    $record->id_kelas = $kelasid;
    $record->id_user = $userid;
    $record->id_role = $roleid;

    $DB->insert_record('peserta_kelas', $record);
}

/**
 * Membersihkan array id user dari input checkbox.
 */
function local_akademikmonitor_peserta_unique_ints(array $values): array {
    $clean = [];

    foreach ($values as $value) {
        $id = (int)$value;

        if ($id > 0) {
            $clean[$id] = $id;
        }
    }

    return array_values($clean);
}

/**
 * Ambil instance manual enrolment.
 *
 * Kalau course belum punya manual enrolment, dibuatkan.
 */
function local_akademikmonitor_peserta_get_manual_instance(stdClass $course): ?stdClass {
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
 * Pastikan group kelas ada pada course.
 */
function local_akademikmonitor_peserta_ensure_group(int $courseid, string $groupname): int {
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
 * Enrol user ke course.
 *
 * Dipakai untuk sinkron siswa dan wali kelas ke course yang sudah digenerate.
 * Guru mapel tidak diambil dari peserta kelas.
 */
function local_akademikmonitor_peserta_enrol_user(
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

    // Pastikan role assignment tetap ada.
    // Ini mencegah kasus user sudah enrolled tapi di Participants tampil "No roles".
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
function local_akademikmonitor_peserta_get_generated_courses(stdClass $kelas): array {
    global $DB;

    $kelasid = (int)$kelas->id;
    $tahunajaranid = !empty($kelas->id_tahun_ajaran) ? (int)$kelas->id_tahun_ajaran : 0;

    if ($kelasid <= 0) {
        return [];
    }

    $conditions = [];
    $params = [];

    /*
     * Format lama yang spesifik:
     * AM-K{idkelas}-KM{idkurikulummapel}-S{semester}
     */
    $conditions[] = $DB->sql_like('idnumber', ':oldpattern', false, false);
    $params['oldpattern'] = 'AM-K' . $kelasid . '-KM%-S%';

    /*
     * Format lama yang lebih longgar.
     * Ini menjaga kalau format idnumber lama ternyata punya tambahan berbeda.
     */
    $conditions[] = $DB->sql_like('idnumber', ':oldfallbackpattern', false, false);
    $params['oldfallbackpattern'] = 'AM-K' . $kelasid . '-%';

    /*
     * Format baru yang spesifik:
     * AM-TA{idtahunajaran}-K{idkelas}-KM{idkurikulummapel}-S{semester}
     */
    if ($tahunajaranid > 0) {
        $conditions[] = $DB->sql_like('idnumber', ':newpattern', false, false);
        $params['newpattern'] = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S%';

        /*
         * Format baru yang lebih longgar untuk tahun ajaran yang sama.
         */
        $conditions[] = $DB->sql_like('idnumber', ':newwidepattern', false, false);
        $params['newwidepattern'] = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-%';
    }

    /*
     * Fallback format baru tanpa mengunci tahun ajaran.
     */
    $conditions[] = $DB->sql_like('idnumber', ':fallbackpattern', false, false);
    $params['fallbackpattern'] = 'AM-TA%-K' . $kelasid . '-%';

    $sql = "SELECT id, fullname, shortname, idnumber
              FROM {course}
             WHERE " . implode(' OR ', $conditions) . "
          ORDER BY fullname ASC";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Keluarkan user dari course Moodle hasil generate kelas.
 *
 * Ini dipakai saat peserta dihapus dari kelas atau tidak dicentang lagi.
 *
 * Kenapa perlu?
 * Karena menghapus dari tabel peserta_kelas hanya menghapus data plugin.
 * Moodle masih punya data sendiri di:
 * - user_enrolments
 * - role_assignments
 * - groups_members
 *
 * Kalau bagian ini tidak dibersihkan, siswa tetap muncul di Participants course
 * dan tetap muncul di dashboard wali kelas.
 */
function local_akademikmonitor_peserta_remove_user_from_existing_courses(
    stdClass $kelas,
    int $userid,
    int $roleid
): int {
    global $DB;

    if ($userid <= 0 || $roleid <= 0) {
        return 0;
    }

    $courses = local_akademikmonitor_peserta_get_generated_courses($kelas);

    if (!$courses) {
        return 0;
    }

    $manualplugin = enrol_get_plugin('manual');

    if (!$manualplugin) {
        return 0;
    }

    $removed = 0;

    foreach ($courses as $course) {
        $courseid = (int)$course->id;
        $coursecontext = context_course::instance($courseid);

        /*
         * 1. Hapus dari group course.
         *
         * Ini penting karena fitur wali kelas biasanya membaca siswa dari group.
         */
        $groups = groups_get_all_groups($courseid, $userid);

        foreach ($groups as $group) {
            groups_remove_member((int)$group->id, $userid);
        }

        /*
         * 2. Hapus role yang sesuai.
         *
         * Untuk siswa, roleid = student.
         * Untuk wali kelas, roleid = role wali/teacher yang dipakai.
         */
        role_unassign($roleid, $userid, $coursecontext->id);

        /*
         * 3. Jangan langsung unenrol kalau user masih punya role lain.
         *
         * Ini supaya tidak merusak kasus user yang juga guru mapel
         * atau punya role lain di course yang sama.
         */
        $stillhasrole = $DB->record_exists('role_assignments', [
            'userid' => $userid,
            'contextid' => $coursecontext->id,
        ]);

        if ($stillhasrole) {
            $removed++;
            continue;
        }

        /*
         * 4. Kalau sudah tidak punya role apa pun, baru unenrol manual.
         *
         * Ini yang membuat user hilang dari halaman Participants.
         */
        $instances = enrol_get_instances($courseid, true);

        foreach ($instances as $instance) {
            if ($instance->enrol !== 'manual') {
                continue;
            }

            $manualplugin->unenrol_user($instance, $userid);
            $removed++;
            break;
        }
    }

    return $removed;
}

/**
 * Sinkron peserta kelas ke course yang sudah pernah digenerate.
 *
 * Yang disinkronkan hanya:
 * - siswa dari peserta_kelas dengan role student
 * - wali kelas dari field kelas.id_user
 *
 * Teacher/guru mapel sengaja tidak disinkronkan dari peserta_kelas,
 * karena guru mapel harus dipilih di halaman generate course.
 */
function local_akademikmonitor_peserta_sync_existing_courses(
    stdClass $kelas,
    int $studentroleid,
    int $teacherroleid,
    int $walikelasroleid
): int {
    global $DB;

    $kelasid = (int)$kelas->id;

    if ($kelasid <= 0) {
        return 0;
    }

    $courses = local_akademikmonitor_peserta_get_generated_courses($kelas);

    if (!$courses) {
        return 0;
    }

    $pesertas = $DB->get_records('peserta_kelas', ['id_kelas' => $kelasid], 'id ASC');

    $selected = [];

    foreach ($pesertas as $peserta) {
        $userid = (int)$peserta->id_user;
        $roleid = !empty($peserta->id_role) ? (int)$peserta->id_role : $studentroleid;

        // Hanya siswa yang boleh disinkronkan dari peserta_kelas.
        if ($userid > 0 && $roleid === $studentroleid) {
            $selected[$userid] = $studentroleid;
        }
    }

    // Wali kelas tetap diambil dari kelas.id_user.
    if (!empty($kelas->id_user)) {
        $fallbackroleid = $walikelasroleid > 0 ? $walikelasroleid : $teacherroleid;

        if ($fallbackroleid > 0) {
            $selected[(int)$kelas->id_user] = $fallbackroleid;
        }
    }

    $groupname = trim((string)$kelas->nama);

    if ($groupname === '') {
        $groupname = 'Kelas ' . $kelasid;
    }

    $synced = 0;

    foreach ($courses as $course) {
        $manualinstance = local_akademikmonitor_peserta_get_manual_instance($course);

        if (!$manualinstance) {
            continue;
        }

        $groupid = local_akademikmonitor_peserta_ensure_group((int)$course->id, $groupname);

        foreach ($selected as $userid => $roleid) {
            local_akademikmonitor_peserta_enrol_user(
                $course,
                $manualinstance,
                (int)$userid,
                (int)$roleid,
                $groupid
            );

            $synced++;
        }
    }

    return $synced;
}

/**
 * Ambil daftar user yang sudah menjadi wali kelas di kelas lain pada tahun ajaran yang sama.
 */
function local_akademikmonitor_peserta_get_used_wali(int $kelasid, int $tahunajaranid): array {
    global $DB;

    if ($kelasid <= 0 || $tahunajaranid <= 0) {
        return [];
    }

    $sql = "SELECT k.id,
                   k.id_user,
                   k.nama,
                   k.tingkat,
                   j.nama_jurusan
              FROM {kelas} k
              LEFT JOIN {jurusan} j ON j.id = k.id_jurusan
             WHERE k.id_tahun_ajaran = :tahunajaranid
               AND k.id <> :kelasid
               AND k.id_user IS NOT NULL
               AND k.id_user > 0";

    $records = $DB->get_records_sql($sql, [
        'tahunajaranid' => $tahunajaranid,
        'kelasid' => $kelasid,
    ]);

    $used = [];

    foreach ($records as $record) {
        $userid = (int)$record->id_user;

        $used[$userid] = [
            'kelasid' => (int)$record->id,
            'label' => local_akademikmonitor_peserta_kelas_label($record),
        ];
    }

    return $used;
}

/**
 * Ambil daftar user yang sudah menjadi siswa di kelas lain pada tahun ajaran yang sama.
 */
function local_akademikmonitor_peserta_get_used_siswa(int $kelasid, int $tahunajaranid, int $studentroleid): array {
    global $DB;

    if ($kelasid <= 0 || $tahunajaranid <= 0 || $studentroleid <= 0) {
        return [];
    }

    $sql = "SELECT pk.id,
                   pk.id_user,
                   k.id AS kelasid,
                   k.nama,
                   k.tingkat,
                   j.nama_jurusan
              FROM {peserta_kelas} pk
              JOIN {kelas} k ON k.id = pk.id_kelas
         LEFT JOIN {jurusan} j ON j.id = k.id_jurusan
             WHERE k.id_tahun_ajaran = :tahunajaranid
               AND k.id <> :kelasid
               AND pk.id_role = :studentroleid";

    $records = $DB->get_records_sql($sql, [
        'tahunajaranid' => $tahunajaranid,
        'kelasid' => $kelasid,
        'studentroleid' => $studentroleid,
    ]);

    $used = [];

    foreach ($records as $record) {
        $userid = (int)$record->id_user;

        $used[$userid] = [
            'kelasid' => (int)$record->kelasid,
            'label' => local_akademikmonitor_peserta_kelas_label($record),
        ];
    }

    return $used;
}

/**
 * Validasi agar user tidak dobel sebagai wali kelas/siswa pada tahun ajaran yang sama.
 */
function local_akademikmonitor_peserta_validate_no_duplicate(
    int $kelasid,
    int $tahunajaranid,
    int $waliuserid,
    array $siswauserids,
    int $studentroleid
): void {
    global $DB;

    if ($kelasid <= 0 || $tahunajaranid <= 0) {
        return;
    }

    $selectedusers = [];

    if ($waliuserid > 0) {
        $selectedusers[$waliuserid] = $waliuserid;
    }

    foreach ($siswauserids as $userid) {
        $userid = (int)$userid;

        if ($userid > 0 && $userid !== $waliuserid) {
            $selectedusers[$userid] = $userid;
        }
    }

    if (!$selectedusers) {
        return;
    }

    [$insql, $inparams] = $DB->get_in_or_equal(array_values($selectedusers), SQL_PARAMS_NAMED, 'uid');

    // Cek apakah user sudah menjadi wali kelas di kelas lain pada tahun ajaran yang sama.
    $paramswali = $inparams + [
        'tahunajaranid' => $tahunajaranid,
        'kelasid' => $kelasid,
    ];

    $sqlwali = "SELECT k.id,
                       k.id_user,
                       k.nama,
                       k.tingkat,
                       j.nama_jurusan
                  FROM {kelas} k
             LEFT JOIN {jurusan} j ON j.id = k.id_jurusan
                 WHERE k.id_tahun_ajaran = :tahunajaranid
                   AND k.id <> :kelasid
                   AND k.id_user {$insql}";

    $conflictwali = $DB->get_records_sql($sqlwali, $paramswali);

    if ($conflictwali) {
        $first = reset($conflictwali);
        $label = local_akademikmonitor_peserta_kelas_label($first);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $kelasid]),
            'Ada user yang sudah menjadi wali kelas di kelas lain pada tahun ajaran yang sama: ' . $label,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Cek apakah user sudah menjadi siswa di kelas lain pada tahun ajaran yang sama.
    if ($studentroleid > 0) {
        $paramssiswa = $inparams + [
            'tahunajaranid' => $tahunajaranid,
            'kelasid' => $kelasid,
            'studentroleid' => $studentroleid,
        ];

        $sqlsiswa = "SELECT pk.id,
                            pk.id_user,
                            k.id AS kelasid,
                            k.nama,
                            k.tingkat,
                            j.nama_jurusan
                       FROM {peserta_kelas} pk
                       JOIN {kelas} k ON k.id = pk.id_kelas
                  LEFT JOIN {jurusan} j ON j.id = k.id_jurusan
                      WHERE k.id_tahun_ajaran = :tahunajaranid
                        AND k.id <> :kelasid
                        AND pk.id_role = :studentroleid
                        AND pk.id_user {$insql}";

        $conflictsiswa = $DB->get_records_sql($sqlsiswa, $paramssiswa);

        if ($conflictsiswa) {
            $first = reset($conflictsiswa);
            $label = local_akademikmonitor_peserta_kelas_label($first);

            redirect(
                new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $kelasid]),
                'Ada user yang sudah menjadi siswa di kelas lain pada tahun ajaran yang sama: ' . $label,
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }
}

$kelas = $DB->get_record('kelas', ['id' => $kelasid], '*', MUST_EXIST);
$tahunajaranid = !empty($kelas->id_tahun_ajaran) ? (int)$kelas->id_tahun_ajaran : 0;

$studentrole = local_akademikmonitor_peserta_find_role(['student']);
$teacherrole = local_akademikmonitor_peserta_find_role(['editingteacher', 'teacher']);

$walikelasrole = local_akademikmonitor_peserta_find_role(
    ['walikelas', 'wali_kelas', 'wali-kelas', 'wali_kelas_role', 'wali'],
    ['wali', 'kelas']
);

if (!$studentrole) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/index.php'),
        'Role student tidak ditemukan di Moodle.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$studentroleid = (int)$studentrole->id;
$teacherroleid = $teacherrole ? (int)$teacherrole->id : 0;
$walikelasroleid = $walikelasrole ? (int)$walikelasrole->id : $teacherroleid;

// Hapus satu peserta.
if ($deleteid > 0) {
    require_sesskey();

    $deleted = $DB->get_record('peserta_kelas', [
        'id' => $deleteid,
        'id_kelas' => $kelasid,
    ], '*', IGNORE_MISSING);

    $removedfromcourses = 0;

    if ($deleted) {
        $deleteduserid = (int)$deleted->id_user;
        $deletedroleid = !empty($deleted->id_role) ? (int)$deleted->id_role : $studentroleid;

        $transaction = $DB->start_delegated_transaction();

        if (!empty($kelas->id_user) && (int)$kelas->id_user === $deleteduserid) {
            $kelas->id_user = null;
            $DB->update_record('kelas', $kelas);
        }

        $DB->delete_records('peserta_kelas', [
            'id' => $deleteid,
            'id_kelas' => $kelasid,
        ]);

        $transaction->allow_commit();

        /*
         * Setelah data plugin dihapus, bersihkan juga data course Moodle.
         */
        $removedfromcourses = local_akademikmonitor_peserta_remove_user_from_existing_courses(
            $kelas,
            $deleteduserid,
            $deletedroleid
        );
    }

    $message = 'Peserta berhasil dihapus dari kelas.';

    if ($removedfromcourses > 0) {
        $message .= ' Peserta juga sudah dikeluarkan dari course Moodle yang tergenerate.';
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $kelasid]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Simpan peserta kelas.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $waliuserid = optional_param('wali_userid', 0, PARAM_INT);

    $siswauserids = local_akademikmonitor_peserta_unique_ints(
        optional_param_array('siswa_userids', [], PARAM_INT)
    );

    $allselected = [];

    if ($waliuserid > 0) {
        $allselected[$waliuserid] = $waliuserid;
    }

    foreach ($siswauserids as $userid) {
        $allselected[$userid] = $userid;
    }

    // Validasi user aktif.
    if ($allselected) {
        [$insql, $params] = $DB->get_in_or_equal(array_values($allselected), SQL_PARAMS_NAMED, 'uid');

        $validusers = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0 AND suspended = 0",
            $params,
            '',
            'id'
        );

        foreach (array_keys($allselected) as $userid) {
            if (!isset($validusers[$userid])) {
                unset($allselected[$userid]);
            }
        }
    }

    // Bersihkan siswa yang tidak valid.
    $siswauserids = array_values(array_filter($siswauserids, function($userid) use ($allselected) {
        return isset($allselected[(int)$userid]);
    }));

    if ($waliuserid > 0 && !isset($allselected[$waliuserid])) {
        $waliuserid = 0;
    }

// Validasi anti-duplikat lintas kelas pada tahun ajaran yang sama.
local_akademikmonitor_peserta_validate_no_duplicate(
    $kelasid,
    $tahunajaranid,
    $waliuserid,
    $siswauserids,
    $studentroleid
);

/*
 * Ambil peserta lama sebelum tabel peserta_kelas di-reset.
 *
 * Ini tidak mengubah fungsi lama.
 * Ini hanya dipakai untuk tahu siapa yang sebelumnya ada,
 * tetapi sekarang sudah tidak dicentang lagi.
 */
$oldpesertas = $DB->get_records('peserta_kelas', ['id_kelas' => $kelasid], 'id ASC');

$oldstudentids = [];
$oldwaliids = [];

foreach ($oldpesertas as $oldpeserta) {
    $olduserid = (int)$oldpeserta->id_user;
    $oldroleid = !empty($oldpeserta->id_role) ? (int)$oldpeserta->id_role : 0;

    if ($olduserid <= 0) {
        continue;
    }

    if ($oldroleid === $studentroleid) {
        $oldstudentids[$olduserid] = $olduserid;
    }

    if ($walikelasroleid > 0 && $oldroleid === $walikelasroleid) {
        $oldwaliids[$olduserid] = $olduserid;
    }
}

$transaction = $DB->start_delegated_transaction();

    // Reset peserta kelas agar data lama teacher yang pernah tersimpan ikut bersih.
    $DB->delete_records('peserta_kelas', ['id_kelas' => $kelasid]);

    // Simpan wali kelas.
    if ($waliuserid > 0) {
        $kelas->id_user = $waliuserid;

        if ($walikelasroleid > 0) {
            local_akademikmonitor_peserta_upsert($kelasid, $waliuserid, $walikelasroleid);
        }
    } else {
        $kelas->id_user = null;
    }

    $DB->update_record('kelas', $kelas);

    // Simpan siswa.
    foreach ($siswauserids as $userid) {
        $userid = (int)$userid;

        // Jika user yang sama dipilih sebagai wali dan siswa,
        // prioritasnya wali kelas, jadi tidak disimpan sebagai siswa.
        if ($userid === $waliuserid) {
            continue;
        }

        local_akademikmonitor_peserta_upsert($kelasid, $userid, $studentroleid);
    }

$transaction->allow_commit();

/*
 * Bersihkan peserta lama yang sekarang sudah tidak dipilih.
 *
 * Contoh:
 * - Selvi sebelumnya siswa kelas XII RPL 2.
 * - Sekarang checkbox Selvi tidak dicentang.
 * - Maka Selvi dikeluarkan juga dari semua course hasil generate kelas itu.
 */
$newstudentids = [];

foreach ($siswauserids as $userid) {
    $userid = (int)$userid;

    if ($userid > 0 && $userid !== $waliuserid) {
        $newstudentids[$userid] = $userid;
    }
}

$removedfromcourses = 0;

foreach ($oldstudentids as $olduserid) {
    if (!isset($newstudentids[$olduserid])) {
        $removedfromcourses += local_akademikmonitor_peserta_remove_user_from_existing_courses(
            $kelas,
            (int)$olduserid,
            $studentroleid
        );
    }
}

/*
 * Kalau wali kelas lama diganti/dikosongkan, role wali kelas lama juga
 * dibersihkan dari course hasil generate kelas.
 */
$newwaliids = [];

if ($waliuserid > 0) {
    $newwaliids[$waliuserid] = $waliuserid;
}

foreach ($oldwaliids as $olduserid) {
    if (!isset($newwaliids[$olduserid])) {
        $removedfromcourses += local_akademikmonitor_peserta_remove_user_from_existing_courses(
            $kelas,
            (int)$olduserid,
            $walikelasroleid
        );
    }
}

$synced = local_akademikmonitor_peserta_sync_existing_courses(
    $kelas,
    $studentroleid,
    $teacherroleid,
    $walikelasroleid
);

$message = 'Pengaturan peserta kelas berhasil disimpan.';

if ($removedfromcourses > 0) {
    $message .= ' Peserta lama yang tidak dipilih juga sudah dikeluarkan dari course Moodle.';
}

    if ($synced > 0) {
        $message .= ' Siswa dan wali kelas juga sudah disinkronkan ke course Moodle yang sudah digenerate.';
    } else {
        $message .= ' Course Moodle belum ada atau belum digenerate, jadi siswa dan wali kelas akan masuk ke course saat generate dijalankan.';
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $kelasid]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$currentpesertas = $DB->get_records('peserta_kelas', ['id_kelas' => $kelasid], 'id ASC');

$currentrolebyuser = [];
$currentstudentids = [];

foreach ($currentpesertas as $peserta) {
    $userid = (int)$peserta->id_user;
    $roleid = (int)$peserta->id_role;

    $currentrolebyuser[$userid] = $roleid;

    if ($roleid === $studentroleid) {
        $currentstudentids[$userid] = $userid;
    }
}

// Data bentrok lintas kelas pada tahun ajaran yang sama.
$usedwali = local_akademikmonitor_peserta_get_used_wali($kelasid, $tahunajaranid);
$usedsiswa = local_akademikmonitor_peserta_get_used_siswa($kelasid, $tahunajaranid, $studentroleid);

/**
 * Ambil user dengan field nama lengkap yang aman untuk fullname().
 * Jangan pakai get_all_user_name_fields() karena deprecated di Moodle baru.
 */
$userfields = \core_user\fields::for_name()
    ->including('email')
    ->get_sql('u', false, '', '', false);

$selects = 'u.id';

if (!empty($userfields->selects)) {
    $cleanselects = preg_replace('/^\s*,\s*/', '', $userfields->selects);
    $selects .= ', ' . $cleanselects;
}

$sqlusers = "SELECT {$selects}
               FROM {user} u
              WHERE u.deleted = 0
                AND u.suspended = 0
                AND u.id > 1
           ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC";

$users = $DB->get_records_sql($sqlusers, $userfields->params);
$alluserids = array_map(function($user) {
    return (int)$user->id;
}, $users);

$nisnmap = local_akademikmonitor_peserta_get_profile_values(
    $alluserids,
    ['nisn']
);

$npamap = local_akademikmonitor_peserta_get_profile_values(
    $alluserids,
    ['npa', 'nip', 'nuptk']
);
$userrows = [];
$no = 1;

foreach ($users as $user) {
    $userid = (int)$user->id;
    $currentroleid = $currentrolebyuser[$userid] ?? 0;

    $iswali = !empty($kelas->id_user) && (int)$kelas->id_user === $userid;

    if (!$iswali && $walikelasrole && $currentroleid === $walikelasroleid) {
        $iswali = true;
    }

    // Di halaman peserta kelas, checkbox yang ada hanya Wali Kelas dan Siswa.
    // Guru tidak diceklis di sini karena guru mapel dipilih di halaman generate course.
    $issiswa = !$iswali && $currentroleid === $studentroleid;

    $waliconflict = !$iswali && isset($usedwali[$userid]);
    $siswaconflict = !$issiswa && isset($usedsiswa[$userid]);

    /**
     * Kalau user sudah menjadi wali kelas di kelas lain,
     * dia tidak boleh dipilih sebagai wali atau siswa di kelas ini.
     *
     * Kalau user sudah menjadi siswa di kelas lain,
     * dia juga tidak boleh dipilih sebagai wali atau siswa di kelas ini.
     *
     * Jadi satu user tidak bisa punya kelas dobel dalam tahun ajaran yang sama.
     */
    $hasanyconflict = $waliconflict || $siswaconflict;

    $statuses = [];

    if ($iswali) {
        $statuses[] = [
            'text' => 'Wali kelas di kelas ini',
            'type_current' => true,
            'type_conflict' => false,
            'type_available' => false,
        ];
    }

    if ($issiswa) {
        $statuses[] = [
            'text' => 'Siswa di kelas ini',
            'type_current' => true,
            'type_conflict' => false,
            'type_available' => false,
        ];
    }

    if ($waliconflict) {
        $statuses[] = [
            'text' => 'Sudah menjadi wali kelas di ' . $usedwali[$userid]['label'],
            'type_current' => false,
            'type_conflict' => true,
            'type_available' => false,
        ];
    }

    if ($siswaconflict) {
        $statuses[] = [
            'text' => 'Sudah menjadi siswa di ' . $usedsiswa[$userid]['label'],
            'type_current' => false,
            'type_conflict' => true,
            'type_available' => false,
        ];
    }

    if (!$statuses) {
        $statuses[] = [
            'text' => 'Tersedia',
            'type_current' => false,
            'type_conflict' => false,
            'type_available' => true,
        ];
    }

    $userrows[] = [
        'no' => $no++,
        'id' => $userid,
        'nama' => fullname($user),
        'email' => !empty($user->email) ? $user->email : '-',

        'wali_checked' => $iswali,
        'siswa_checked' => $issiswa,

        'wali_disabled' => $hasanyconflict,
        'siswa_disabled' => $hasanyconflict,

        'row_muted' => $hasanyconflict,
        'status_list' => $statuses,
        'nisn' => $nisnmap[$userid] ?? '-',
        'npa' => $npamap[$userid] ?? '-',
        'search_text' => strtolower(
            fullname($user)
            . ' '
            . ($user->email ?? '')
            . ' '
            . ($nisnmap[$userid] ?? '')
            . ' '
            . ($npamap[$userid] ?? '')
        ),
    ];
}

$items = [];
$no = 1;

foreach ($currentpesertas as $peserta) {
    $userid = (int)$peserta->id_user;
    $roleid = !empty($peserta->id_role) ? (int)$peserta->id_role : 0;

    $iswali = !empty($kelas->id_user) && (int)$kelas->id_user === $userid;
    $issiswa = $roleid === $studentroleid;

    // Data lama teacher di peserta_kelas tidak ditampilkan lagi.
    // Nanti akan bersih otomatis setelah tombol Simpan Peserta Kelas ditekan.
    if (!$iswali && !$issiswa) {
        continue;
    }

    $user = $DB->get_record('user', [
        'id' => $userid,
        'deleted' => 0,
    ], '*', IGNORE_MISSING);

    if (!$user) {
        continue;
    }

    if ($iswali) {
        $role = $walikelasrole ?: $teacherrole;
        $rolename = $walikelasrole
            ? local_akademikmonitor_peserta_role_label($walikelasrole, $context)
            : 'Wali Kelas';
    } else {
        $role = $studentrole;
        $rolename = local_akademikmonitor_peserta_role_label($role, $context);
    }

    $items[] = [
        'no' => $no++,
        'nama' => fullname($user),
        'email' => $user->email ?: '-',
        'role' => $rolename,
        'delete_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', [
            'kelasid' => $kelasid,
            'deleteid' => $peserta->id,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

function local_akademikmonitor_peserta_get_profile_values(
    array $userids,
    array $shortnames
): array {

    global $DB;

    if (!$userids || !$shortnames) {
        return [];
    }

    [$userinsql, $userparams] =
        $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

    [$fieldinsql, $fieldparams] =
        $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED);

    $sql = "SELECT d.userid, d.data
              FROM {user_info_data} d
              JOIN {user_info_field} f
                ON f.id = d.fieldid
             WHERE d.userid {$userinsql}
               AND LOWER(f.shortname) {$fieldinsql}";

    $records = $DB->get_records_sql(
        $sql,
        $userparams + $fieldparams
    );

    $values = [];

    foreach ($records as $record) {
        $values[(int)$record->userid] =
            trim((string)$record->data);
    }

    return $values;
}

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls_peserta('kelas'), [
    'kelas' => format_string($kelas->nama),
    'user_rows' => $userrows,
    'items' => $items,
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $kelasid]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
    'sesskey' => sesskey(),
    'role_wali_label' => $walikelasrole ? local_akademikmonitor_peserta_role_label($walikelasrole, $context) : 'Wali Kelas',
    'role_siswa_label' => local_akademikmonitor_peserta_role_label($studentrole, $context),
    'has_wali_role' => (bool)$walikelasrole,
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/peserta', $templatecontext);
echo $OUTPUT->footer();

