<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class admin_presensi_guru_service {

    /**
     * Mengecek tabel plugin Attendance.
     *
     * Kenapa dicek?
     * Karena fitur ini hanya membaca data dari mod_attendance.
     * Kalau plugin Attendance belum terpasang, halaman tidak boleh langsung error database.
     */
    private static function attendance_tables_available(): bool {
        global $DB;

        $dbman = $DB->get_manager();

        return $dbman->table_exists(new \xmldb_table('attendance'))
            && $dbman->table_exists(new \xmldb_table('attendance_sessions'))
            && $dbman->table_exists(new \xmldb_table('attendance_log'));
    }

    /**
     * Sidebar admin.
     *
     * Dibuat di service supaya halaman index.php tetap bersih.
     */
    public static function get_admin_sidebar_data(string $active = ''): array {
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
            'is_kartu_ujian' => $active === 'kartu_ujian',
            'is_monitoring_presensi' => $active === 'monitoring_presensi',
            'is_monitoring_presensi_guru' => $active === 'monitoring_presensi_guru',

            'dashboard_url' => (new \moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
            'tahun_ajaran_url' => (new \moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
            'kurikulum_url' => (new \moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
            'manajemen_jurusan_url' => (new \moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
            'manajemen_kelas_url' => (new \moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
            'mata_pelajaran_url' => (new \moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
            'matpel_url' => (new \moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
            'kktp_url' => (new \moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
            'notif_url' => (new \moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
            'ekskul_url' => (new \moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
            'mitra_url' => (new \moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
            'monitoring_presensi_url' => (new \moodle_url('/local/akademikmonitor/pages/presensi/index.php'))->out(false),
            'monitoring_presensi_guru_url' => (new \moodle_url('/local/akademikmonitor/pages/presensi_guru/index.php'))->out(false),
        ];
    }

    /**
     * Ambil label tahun ajaran secara fleksibel.
     *
     * Kenapa fleksibel?
     * Karena di project kamu kadang field tahun ajaran memakai nama:
     * - tahun_ajaran
     * - nama
     */
    private static function tahun_label(?\stdClass $tahun): string {
        if (!$tahun) {
            return '-';
        }

        foreach (['tahun_ajaran', 'nama', 'label', 'periode'] as $field) {
            if (property_exists($tahun, $field) && trim((string)$tahun->{$field}) !== '') {
                return trim((string)$tahun->{$field});
            }
        }

        return '-';
    }

    /**
     * Dropdown tahun ajaran.
     */
    private static function get_tahun_options(int $selectedid): array {
        global $DB;

        $records = $DB->get_records('tahun_ajaran', null, 'id DESC');

        $out = [];

        foreach ($records as $tahun) {
            $out[] = [
                'id' => (int)$tahun->id,
                'nama' => format_string(self::tahun_label($tahun)),
                'selected' => (int)$tahun->id === (int)$selectedid,
            ];
        }

        return $out;
    }

    /**
     * Dropdown kelas berdasarkan tahun ajaran.
     *
     * Admin harus memilih kelas dulu karena presensi guru tetap dibaca dari course
     * hasil generate untuk kelas tersebut.
     */
    private static function get_kelas_options(int $tahunajaranid, int $selectedkelasid): array {
        global $DB;

        if ($tahunajaranid <= 0) {
            return [];
        }

        $sql = "SELECT k.id,
                       k.nama,
                       k.tingkat,
                       k.id_jurusan,
                       k.id_tahun_ajaran,
                       j.nama_jurusan,
                       j.kode_jurusan
                  FROM {kelas} k
                  JOIN {jurusan} j ON j.id = k.id_jurusan
                 WHERE k.id_tahun_ajaran = :tahunajaranid
              ORDER BY k.tingkat ASC, j.nama_jurusan ASC, k.nama ASC";

        $records = $DB->get_records_sql($sql, [
            'tahunajaranid' => $tahunajaranid,
        ]);

        $out = [];

        foreach ($records as $kelas) {
            $label = course_name_service::rombel_label($kelas, (object)[
                'nama_jurusan' => $kelas->nama_jurusan,
            ]);

            $out[] = [
                'id' => (int)$kelas->id,
                'nama' => format_string($label),
                'selected' => (int)$kelas->id === (int)$selectedkelasid,
            ];
        }

        return $out;
    }

    /**
     * Ambil record kelas lengkap.
     */
    private static function get_kelas_record(int $kelasid): ?\stdClass {
        global $DB;

        if ($kelasid <= 0) {
            return null;
        }

        $sql = "SELECT k.id,
                       k.nama,
                       k.tingkat,
                       k.id_jurusan,
                       k.id_tahun_ajaran,
                       j.nama_jurusan,
                       j.kode_jurusan
                  FROM {kelas} k
                  JOIN {jurusan} j ON j.id = k.id_jurusan
                 WHERE k.id = :kelasid";

        $record = $DB->get_record_sql($sql, [
            'kelasid' => $kelasid,
        ], IGNORE_MISSING);

        return $record ?: null;
    }

    /**
     * Ambil course hasil generate untuk kelas, tahun ajaran, dan semester.
     *
     * Kenapa berdasarkan idnumber?
     * Karena course plugin kamu dibuat dengan pola:
     * - AM-TA{id_tahunajaran}-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
     * - AM-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester} untuk format lama.
     */
    private static function get_generated_courses(
        int $kelasid,
        int $tahunajaranid,
        int $semester
    ): array {
        global $DB;

        if ($kelasid <= 0) {
            return [];
        }

        $conditions = [];
        $params = [];

        $oldpattern = 'AM-K' . $kelasid . '-KM%-S%';

        if (in_array($semester, [1, 2], true)) {
            $oldpattern = 'AM-K' . $kelasid . '-KM%-S' . $semester;
        }

        $conditions[] = $DB->sql_like('idnumber', ':oldpattern', false);
        $params['oldpattern'] = $oldpattern;

        if ($tahunajaranid > 0) {
            $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S%';

            if (in_array($semester, [1, 2], true)) {
                $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S' . $semester;
            }
        } else {
            $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S%';

            if (in_array($semester, [1, 2], true)) {
                $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S' . $semester;
            }
        }

        $conditions[] = $DB->sql_like('idnumber', ':newpattern', false);
        $params['newpattern'] = $newpattern;

        return $DB->get_records_select(
            'course',
            '(' . implode(' OR ', $conditions) . ')',
            $params,
            'fullname ASC, id ASC',
            'id, fullname, shortname, idnumber'
        );
    }

    /**
     * Ambil nama mapel untuk course.
     *
     * Kenapa tidak langsung pakai fullname?
     * Karena fullname course kamu biasanya panjang:
     * KEJURUAN-MATEMATIKA-X-TEKNIK...
     *
     * Untuk tampilan presensi guru lebih enak kalau yang muncul nama mapel.
     */
    private static function get_course_mapel_names(array $courses): array {
        global $DB;

        if (!$courses) {
            return [];
        }

        $courseids = array_map('intval', array_keys($courses));

        [$courseinsql, $courseparams] = $DB->get_in_or_equal(
            $courseids,
            SQL_PARAMS_NAMED,
            'courseid'
        );

        $coursemapels = $DB->get_records_select(
            'course_mapel',
            "id_course {$courseinsql}",
            $courseparams,
            '',
            'id_course, id_kurikulum_mapel'
        );

        if (!$coursemapels) {
            $names = [];

            foreach ($courses as $course) {
                $names[(int)$course->id] = format_string((string)$course->fullname);
            }

            return $names;
        }

        $kmids = [];
        $kmidbycourse = [];

        foreach ($coursemapels as $cm) {
            $courseid = (int)($cm->id_course ?? 0);
            $kmid = (int)($cm->id_kurikulum_mapel ?? 0);

            if ($courseid <= 0 || $kmid <= 0) {
                continue;
            }

            $kmidbycourse[$courseid] = $kmid;
            $kmids[$kmid] = $kmid;
        }

        $kurikulummapels = [];
        $mapels = [];

        if ($kmids) {
            $kurikulummapels = $DB->get_records_list(
                'kurikulum_mapel',
                'id',
                array_values($kmids),
                '',
                'id, id_mapel'
            );

            $mapelids = [];

            foreach ($kurikulummapels as $km) {
                $mapelid = (int)($km->id_mapel ?? 0);

                if ($mapelid > 0) {
                    $mapelids[$mapelid] = $mapelid;
                }
            }

            if ($mapelids) {
                $mapels = $DB->get_records_list(
                    'mata_pelajaran',
                    'id',
                    array_values($mapelids),
                    '',
                    'id, nama_mapel'
                );
            }
        }

        $names = [];

        foreach ($courses as $course) {
            $courseid = (int)$course->id;
            $name = '';

            $kmid = $kmidbycourse[$courseid] ?? 0;

            if ($kmid > 0 && isset($kurikulummapels[$kmid])) {
                $mapelid = (int)($kurikulummapels[$kmid]->id_mapel ?? 0);

                if ($mapelid > 0 && isset($mapels[$mapelid])) {
                    $name = (string)$mapels[$mapelid]->nama_mapel;
                }
            }

            if ($name === '') {
                $name = (string)$course->fullname;
            }

            $names[$courseid] = format_string($name);
        }

        return $names;
    }

    /**
     * Ambil daftar guru pada kelas yang dipilih.
     *
     * Guru diambil dari course-course hasil generate kelas tersebut.
     * Jadi kalau guru mengajar Matematika di kelas X Multimedia 1,
     * maka guru itu muncul di dropdown guru.
     */
    private static function get_teacher_options(
        int $kelasid,
        int $tahunajaranid,
        int $semester,
        int $selectedteacherid
    ): array {
        global $DB;

        $courses = self::get_generated_courses($kelasid, $tahunajaranid, $semester);

        if (!$courses) {
            return [];
        }

        $courseids = array_map('intval', array_keys($courses));

        $teacherroleids = $DB->get_fieldset_select(
            'role',
            'id',
            "shortname IN ('editingteacher', 'teacher')",
            []
        );

        $teacherroleids = array_values(array_unique(array_map('intval', $teacherroleids)));

        if (!$teacherroleids) {
            return [];
        }

        $contextids = [];

        foreach ($courseids as $courseid) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);

            if ($context) {
                $contextids[] = (int)$context->id;
            }
        }

        if (!$contextids) {
            return [];
        }

        [$contextsql, $contextparams] = $DB->get_in_or_equal(
            $contextids,
            SQL_PARAMS_NAMED,
            'ctxid'
        );

        [$rolesql, $roleparams] = $DB->get_in_or_equal(
            $teacherroleids,
            SQL_PARAMS_NAMED,
            'roleid'
        );

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $sql = "SELECT DISTINCT u.id,
                       u.username,
                       u.email,
                       {$namefields}
                  FROM {role_assignments} ra
                  JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid {$contextsql}
                   AND ra.roleid {$rolesql}
                   AND u.deleted = 0
                   AND u.suspended = 0
              ORDER BY u.firstname ASC, u.lastname ASC";

        $teachers = $DB->get_records_sql($sql, array_merge($contextparams, $roleparams));

        $out = [];

        foreach ($teachers as $teacher) {
            $out[] = [
                'id' => (int)$teacher->id,
                'nama' => fullname($teacher),
                'selected' => (int)$teacher->id === (int)$selectedteacherid,
            ];
        }

        return $out;
    }

    /**
     * Ambil course yang diajar guru tertentu di kelas yang dipilih.
     *
     * Kenapa perlu?
     * Karena kalau satu kelas punya banyak mapel, guru yang dipilih hanya boleh
     * melihat sesi attendance pada course yang memang dia ajar.
     */
    private static function get_courses_taught_by_teacher(
        int $kelasid,
        int $tahunajaranid,
        int $semester,
        int $teacherid
    ): array {
        global $DB;

        if ($teacherid <= 0) {
            return [];
        }

        $courses = self::get_generated_courses($kelasid, $tahunajaranid, $semester);

        if (!$courses) {
            return [];
        }

        $teacherroleids = $DB->get_fieldset_select(
            'role',
            'id',
            "shortname IN ('editingteacher', 'teacher')",
            []
        );

        $teacherroleids = array_values(array_unique(array_map('intval', $teacherroleids)));

        if (!$teacherroleids) {
            return [];
        }

        $out = [];

        foreach ($courses as $course) {
            $context = \context_course::instance((int)$course->id, IGNORE_MISSING);

            if (!$context) {
                continue;
            }

            [$rolesql, $roleparams] = $DB->get_in_or_equal(
                $teacherroleids,
                SQL_PARAMS_NAMED,
                'roleid'
            );

            $params = $roleparams;
            $params['contextid'] = (int)$context->id;
            $params['userid'] = $teacherid;

            $exists = $DB->record_exists_select(
                'role_assignments',
                "contextid = :contextid
                 AND userid = :userid
                 AND roleid {$rolesql}",
                $params
            );

            if ($exists) {
                $out[(int)$course->id] = $course;
            }
        }

        return $out;
    }

    /**
     * Ambil nama guru.
     */
    private static function get_teacher_name(int $teacherid): string {
        global $DB;

        if ($teacherid <= 0) {
            return '-';
        }

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $user = $DB->get_record_sql(
            "SELECT u.id, {$namefields}
               FROM {user} u
              WHERE u.id = :userid",
            ['userid' => $teacherid],
            IGNORE_MISSING
        );

        return $user ? fullname($user) : '-';
    }

    /**
     * Ambil semua instance Attendance dari course.
     */
    private static function get_attendance_instances_by_courses(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_map('intval', $courseids)));

        if (!$courseids || !self::attendance_tables_available()) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal(
            $courseids,
            SQL_PARAMS_NAMED,
            'courseid'
        );

        return $DB->get_records_select(
            'attendance',
            "course {$coursesql}",
            $courseparams,
            'course ASC, id ASC',
            'id, course, name'
        );
    }

    /**
     * Ambil group kelas pada course.
     *
     * Kenapa group perlu?
     * Session Attendance bisa dibuat untuk:
     * - semua siswa, groupid = 0
     * - group tertentu, groupid = id group
     *
     * Karena admin memilih kelas, maka session yang dibaca harus session umum
     * atau session milik group kelas tersebut.
     */
    private static function get_groupid_for_course_kelas(int $courseid, \stdClass $kelas): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }

        $groupname = course_name_service::rombel_label($kelas, (object)[
            'nama_jurusan' => $kelas->nama_jurusan,
        ]);

        $group = $DB->get_record(
            'groups',
            [
                'courseid' => $courseid,
                'name' => $groupname,
            ],
            'id',
            IGNORE_MISSING
        );

        if ($group) {
            return (int)$group->id;
        }

        $groups = $DB->get_records(
            'groups',
            ['courseid' => $courseid],
            'id ASC',
            'id, name'
        );

        if (!$groups) {
            return 0;
        }

        $first = reset($groups);

        return $first ? (int)$first->id : 0;
    }

    /**
     * Ambil sesi Attendance untuk course-course yang diajar guru.
     *
     * Sesi tetap dibatasi berdasarkan kelas yang dipilih.
     */
    private static function get_sessions_for_teacher_courses(
        array $courses,
        array $mapelnames,
        \stdClass $kelas
    ): array {
        global $DB;

        if (!$courses || !self::attendance_tables_available()) {
            return [];
        }

        $courseids = array_map('intval', array_keys($courses));
        $attendances = self::get_attendance_instances_by_courses($courseids);

        if (!$attendances) {
            return [];
        }

        $attendancebyid = [];
        $attendanceids = [];

        foreach ($attendances as $attendance) {
            $attendanceid = (int)$attendance->id;
            $attendancebyid[$attendanceid] = $attendance;
            $attendanceids[] = $attendanceid;
        }

        [$attinsql, $attparams] = $DB->get_in_or_equal(
            $attendanceids,
            SQL_PARAMS_NAMED,
            'attid'
        );

        $sessions = $DB->get_records_select(
            'attendance_sessions',
            "attendanceid {$attinsql}",
            $attparams,
            'sessdate ASC, id ASC',
            'id, attendanceid, groupid, sessdate, duration, description, lasttaken, lasttakenby, timemodified'
        );

        if (!$sessions) {
            return [];
        }

        $validgroupbycourse = [];

        foreach ($courses as $course) {
            $validgroupbycourse[(int)$course->id] = self::get_groupid_for_course_kelas((int)$course->id, $kelas);
        }

        $out = [];
        $number = 1;

        foreach ($sessions as $session) {
            $attendanceid = (int)$session->attendanceid;

            if (!isset($attendancebyid[$attendanceid])) {
                continue;
            }

            $attendance = $attendancebyid[$attendanceid];
            $courseid = (int)$attendance->course;

            if (!isset($courses[$courseid])) {
                continue;
            }

            $groupid = (int)$session->groupid;
            $validgroupid = (int)($validgroupbycourse[$courseid] ?? 0);

            if ($groupid > 0 && $validgroupid > 0 && $groupid !== $validgroupid) {
                continue;
            }

            if ($groupid > 0 && $validgroupid <= 0) {
                continue;
            }

            $description = trim(strip_tags((string)($session->description ?? '')));

            if ($description === '') {
                $description = 'Pertemuan ' . $number;
            }

            $date = !empty($session->sessdate)
                ? userdate((int)$session->sessdate, '%d %B %Y')
                : '-';

            $mapelname = $mapelnames[$courseid] ?? format_string((string)$courses[$courseid]->fullname);

            $label = $description;

            if (count($courses) > 1) {
                $label = $mapelname . '<br><small>' . $description . ' - ' . $date . '</small>';
            } else {
                $label = $description . '<br><small>' . $date . '</small>';
            }

            $out[(int)$session->id] = [
                'id' => (int)$session->id,
                'attendanceid' => $attendanceid,
                'courseid' => $courseid,
                'mapelname' => $mapelname,
                'groupid' => $groupid,
                'sessdate' => (int)$session->sessdate,
                'description' => $description,
                'date_label' => $date,
                'column_label' => $label,
                'lasttaken' => (int)($session->lasttaken ?? 0),
                'lasttakenby' => (int)($session->lasttakenby ?? 0),
                'timemodified' => (int)($session->timemodified ?? 0),
            ];

            $number++;
        }

        return $out;
    }

    /**
     * Ambil siapa saja yang mengambil presensi pada setiap session.
     *
     * Kenapa perlu baca attendance_log juga?
     * Karena kadang lasttakenby di attendance_sessions bisa 0 atau tidak lengkap,
     * sedangkan attendance_log.takenby menyimpan user yang melakukan input status siswa.
     */
    private static function get_takenby_map(array $sessionids): array {
        global $DB;

        $sessionids = array_values(array_unique(array_map('intval', $sessionids)));

        if (!$sessionids || !self::attendance_tables_available()) {
            return [];
        }

        [$sessionsql, $sessionparams] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'sid'
        );

        $logs = $DB->get_records_select(
            'attendance_log',
            "sessionid {$sessionsql}
             AND takenby > 0",
            $sessionparams,
            'id ASC',
            'id, sessionid, takenby, timetaken'
        );

        $out = [];

        foreach ($logs as $log) {
            $sessionid = (int)$log->sessionid;
            $takenby = (int)$log->takenby;

            if ($sessionid <= 0 || $takenby <= 0) {
                continue;
            }

            $out[$sessionid][$takenby] = [
                'takenby' => $takenby,
                'timetaken' => (int)($log->timetaken ?? 0),
            ];
        }

        return $out;
    }
/**
 * Ambil daftar id siswa pada kelas terpilih.
 *
 * Kenapa function ini perlu?
 * Karena presensi guru tidak hanya dilihat dari siapa yang menginput presensi,
 * tetapi juga dari apakah sudah ada siswa kelas tersebut yang melakukan presensi mandiri.
 */
private static function get_student_ids_for_kelas(int $kelasid): array {
    global $DB;

    if ($kelasid <= 0) {
        return [];
    }

    $studentroleid = (int)$DB->get_field(
        'role',
        'id',
        ['shortname' => 'student'],
        IGNORE_MISSING
    );

    if ($studentroleid <= 0) {
        return [];
    }

    $sql = "SELECT DISTINCT u.id
              FROM {peserta_kelas} pk
              JOIN {user} u ON u.id = pk.id_user
             WHERE pk.id_kelas = :kelasid
               AND pk.id_role = :studentroleid
               AND u.deleted = 0
               AND u.suspended = 0";

    $ids = $DB->get_fieldset_sql($sql, [
        'kelasid' => $kelasid,
        'studentroleid' => $studentroleid,
    ]);

    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * Mengecek session mana saja yang sudah punya minimal 1 log presensi siswa.
 *
 * Kenapa function ini perlu?
 * Karena pada model presensi mandiri, guru bisa dianggap hadir jika siswa sudah
 * bisa melakukan presensi. Artinya sesi presensi sudah dibuka/diaktifkan.
 */
private static function get_session_has_student_log_map(array $sessions, int $kelasid): array {
    global $DB;

    if (!$sessions || $kelasid <= 0 || !self::attendance_tables_available()) {
        return [];
    }

    $sessionids = [];

    foreach ($sessions as $session) {
        $sessionid = (int)($session['id'] ?? 0);

        if ($sessionid > 0) {
            $sessionids[$sessionid] = $sessionid;
        }
    }

    if (!$sessionids) {
        return [];
    }

    $studentids = self::get_student_ids_for_kelas($kelasid);

    if (!$studentids) {
        return [];
    }

    [$sessionsql, $sessionparams] = $DB->get_in_or_equal(
        array_values($sessionids),
        SQL_PARAMS_NAMED,
        'sid'
    );

    [$studentsql, $studentparams] = $DB->get_in_or_equal(
        array_values($studentids),
        SQL_PARAMS_NAMED,
        'uid'
    );

    $params = array_merge($sessionparams, $studentparams);

    $logs = $DB->get_records_select(
        'attendance_log',
        "sessionid {$sessionsql}
         AND studentid {$studentsql}",
        $params,
        'id ASC',
        'id, sessionid, studentid'
    );

    $out = [];

    foreach ($logs as $log) {
        $sessionid = (int)$log->sessionid;

        if ($sessionid > 0) {
            $out[$sessionid] = true;
        }
    }

    return $out;
}
/**
 * Render status presensi guru.
 *
 * Aturan:
 * Guru dianggap hadir jika salah satu kondisi terpenuhi:
 * 1. Guru tersebut menginputkan presensi.
 * 2. Minimal ada satu siswa pada kelas tersebut yang melakukan presensi mandiri.
 *
 * Kenapa digabung?
 * Karena sekolah bisa memakai dua model:
 * - guru mengisi presensi siswa,
 * - siswa melakukan presensi sendiri setelah sesi dibuka guru.
 */
private static function render_teacher_status(
    int $teacherid,
    array $session,
    array $takenbymap,
    array $sessionhasstudentlog
): array {
    $sessionid = (int)($session['id'] ?? 0);
    $lasttakenby = (int)($session['lasttakenby'] ?? 0);

    if ($teacherid <= 0 || $sessionid <= 0) {
        return [
            'class' => 'am-presensi-empty',
            'text'  => 'Belum Diambil',
        ];
    }

    /*
     * Kondisi 1:
     * Guru tersebut yang menginput / mengambil presensi.
     */
    $takenbyteacher = !empty($takenbymap[$sessionid][$teacherid]);

    if ($lasttakenby === $teacherid || $takenbyteacher) {
        return [
            'class' => 'am-presensi-present',
            'text' => 'Hadir',
        ];
    }

    /*
     * Kondisi 2:
     * Ada minimal satu siswa kelas tersebut yang sudah melakukan presensi.
     */
    if (!empty($sessionhasstudentlog[$sessionid])) {
        return [
            'class' => 'am-presensi-present',
            'text' => 'Hadir',
        ];
    }

    return [
    'class' => 'am-presensi-empty',
    'text' => 'Belum Diambil',
];
}

/**
 * Buat tabel presensi guru dalam bentuk vertikal.
 *
 * Kenapa tidak lagi pakai kolom pertemuan melebar?
 * Karena halaman ini hanya untuk 1 guru yang dipilih.
 * Nama guru sudah tampil di bagian informasi atas, jadi tabel cukup berisi:
 * - jenis presensi / pertemuan
 * - tanggal presensi
 * - status kehadiran
 */
private static function get_monitoring_presensi_guru(
    int $kelasid,
    int $tahunajaranid,
    int $semester,
    int $teacherid
): array {
    $kelas = self::get_kelas_record($kelasid);

    if (!$kelas || $teacherid <= 0) {
        return [
            'rows' => [],
            'total_sessions' => 0,
            'hadir_count' => 0,
            'belum_count' => 0,
            'lain_count' => 0,
        ];
    }

    $courses = self::get_courses_taught_by_teacher(
        $kelasid,
        $tahunajaranid,
        $semester,
        $teacherid
    );

    if (!$courses) {
        return [
            'rows' => [],
            'total_sessions' => 0,
            'hadir_count' => 0,
            'belum_count' => 0,
            'lain_count' => 0,
        ];
    }

    $mapelnames = self::get_course_mapel_names($courses);

    $sessions = self::get_sessions_for_teacher_courses(
        $courses,
        $mapelnames,
        $kelas
    );

    if (!$sessions) {
        return [
            'rows' => [],
            'total_sessions' => 0,
            'hadir_count' => 0,
            'belum_count' => 0,
            'lain_count' => 0,
        ];
    }

$sessionids = array_map('intval', array_keys($sessions));
$takenbymap = self::get_takenby_map($sessionids);
$sessionhasstudentlog = self::get_session_has_student_log_map($sessions, $kelasid);

    $rows = [];

    $hadir = 0;
    $belum = 0;
    $lain = 0;

    foreach ($sessions as $session) {
$statushtml = self::render_teacher_status(
    $teacherid,
    $session,
    $takenbymap,
    $sessionhasstudentlog
);

if ($statushtml['class'] === 'am-presensi-present') {
    $hadir++;
} else {
    $belum++;
}

        /*
         * Kalau guru mengajar lebih dari 1 mapel di kelas yang sama,
         * jenis presensi diberi nama mapel supaya tidak membingungkan.
         *
         * Contoh:
         * Matematika - Pertemuan 1
         * Bahasa Inggris - Pertemuan 1
         */
        $jenis = (string)($session['description'] ?? '-');

        if (count($courses) > 1) {
            $mapelname = (string)($session['mapelname'] ?? '');
            if ($mapelname !== '') {
                $jenis = $mapelname . ' - ' . $jenis;
            }
        }

        $rows[] = [
            'jenis_presensi' => format_string($jenis),
            'tanggal_presensi' => format_string((string)($session['date_label'] ?? '-')),
            'status_kehadiran' => $statushtml,
        ];
    }

    return [
        'rows' => $rows,
        'total_sessions' => count($sessions),
        'hadir_count' => $hadir,
        'belum_count' => $belum,
        'lain_count' => $lain,
    ];
}

public static function get_teacher_attendance_summary(
    int $kelasid,
    int $tahunajaranid,
    int $semester,
    int $teacherid
): array {

    return self::get_monitoring_presensi_guru(
        $kelasid,
        $tahunajaranid,
        $semester,
        $teacherid
    );
}
public static function get_teacher_list(
    int $kelasid,
    int $tahunajaranid,
    int $semester
): array {

    return self::get_teacher_options(
        $kelasid,
        $tahunajaranid,
        $semester,
        0
    );
}
    /**
     * Data utama halaman.
     */
    public static function get_page_data(
        int $tahunajaranid,
        int $semester,
        int $kelasid,
        int $teacherid
    ): array {
        if ($tahunajaranid <= 0) {
            $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
        }

        if (!in_array($semester, [1, 2], true)) {
            $semester = period_filter_service::get_selected_semester();
        }

        $data = self::get_admin_sidebar_data('monitoring_presensi_guru');

        $tahunoptions = self::get_tahun_options($tahunajaranid);

        $kelasoptions = self::get_kelas_options($tahunajaranid, $kelasid);

        if ($kelasid <= 0 && $kelasoptions) {
            $firstkelas = reset($kelasoptions);
            $kelasid = (int)$firstkelas['id'];
        }

        foreach ($kelasoptions as &$kelasoption) {
            $kelasoption['selected'] = (int)$kelasoption['id'] === (int)$kelasid;
        }
        unset($kelasoption);

        $teacheroptions = self::get_teacher_options(
            $kelasid,
            $tahunajaranid,
            $semester,
            $teacherid
        );

        if ($teacherid <= 0 && $teacheroptions) {
            $firstteacher = reset($teacheroptions);
            $teacherid = (int)$firstteacher['id'];
        }

        foreach ($teacheroptions as &$teacheroption) {
            $teacheroption['selected'] = (int)$teacheroption['id'] === (int)$teacherid;
        }
        unset($teacheroption);

        $kelas = self::get_kelas_record($kelasid);

        $selectedkelasname = '-';

        if ($kelas) {
            $selectedkelasname = course_name_service::rombel_label($kelas, (object)[
                'nama_jurusan' => $kelas->nama_jurusan,
            ]);
        }

        $presensi = [
            'columns' => [],
            'rows' => [],
            'total_sessions' => 0,
            'hadir_count' => 0,
            'belum_count' => 0,
            'lain_count' => 0,
        ];

        if ($kelasid > 0 && $teacherid > 0 && self::attendance_tables_available()) {
            $presensi = self::get_monitoring_presensi_guru(
                $kelasid,
                $tahunajaranid,
                $semester,
                $teacherid
            );
        }

        return array_merge($data, [
            'attendance_available' => self::attendance_tables_available(),

            'filter_action' => (new \moodle_url('/local/akademikmonitor/pages/presensi_guru/index.php'))->out(false),

            'tahun_options' => $tahunoptions,
            'kelas_options' => $kelasoptions,
            'teacher_options' => $teacheroptions,

            'has_tahun_options' => !empty($tahunoptions),
            'has_kelas_options' => !empty($kelasoptions),
            'has_teacher_options' => !empty($teacheroptions),

            'selected_tahunajaranid' => $tahunajaranid,
            'selectedsemester' => $semester,
            'is_semester_1' => $semester === 1,
            'is_semester_2' => $semester === 2,

            'selected_kelasid' => $kelasid,
            'selected_teacherid' => $teacherid,
            'selected_kelas_name' => format_string($selectedkelasname),
            'selected_teacher_name' => format_string(self::get_teacher_name($teacherid)),

            'rows' => $presensi['rows'],

            'total_sessions' => (int)$presensi['total_sessions'],
            'hadir_count' => (int)$presensi['hadir_count'],
            'belum_count' => (int)$presensi['belum_count'],
            'lain_count' => (int)$presensi['lain_count'],
        ]);
    }
}