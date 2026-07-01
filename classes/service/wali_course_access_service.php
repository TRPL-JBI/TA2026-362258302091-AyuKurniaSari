<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class wali_course_access_service {

    /**
     * Sinkronisasi akses wali kelas berdasarkan tahun ajaran aktif.
     *
     * Konsep:
     * - Wali kelas viewer memakai role teacher / non-editing teacher.
     * - Guru pengampu memakai role editingteacher dan tidak disentuh oleh service ini.
     * - Siswa tidak disentuh.
     * - Course, nilai, aktivitas, dan data lama tidak dihapus.
     *
     * Efek:
     * - Saat tahun ajaran aktif 2025/2026, wali kelas tahun itu melihat course kelasnya.
     * - Saat tahun ajaran aktif 2026/2027, akses wali kelas 2025/2026 dilepas dari My courses.
     * - Saat tahun ajaran dibalik lagi ke 2025/2026, akses wali kelas 2025/2026 dipasang lagi.
     */
    public static function sync_by_tahunajaran(int $tahunajaranid): void {
        global $DB, $CFG;

        if ($tahunajaranid <= 0) {
            return;
        }

        require_once($CFG->dirroot . '/enrol/manual/lib.php');

        $viewrole = $DB->get_record('role', ['shortname' => 'teacher'], 'id', IGNORE_MISSING);

        if (!$viewrole) {
            return;
        }

        $viewroleid = (int)$viewrole->id;

        /*
         * Semua user yang pernah/masih menjadi wali kelas di tabel kelas.
         * Mereka ini yang role viewer wali kelasnya boleh disinkronkan.
         */
        $waliids = self::get_all_wali_userids();

        if (!$waliids) {
            return;
        }

        /*
         * Course generate plugin saja.
         * Jangan sentuh course Moodle manual di luar plugin.
         */
        $courses = $DB->get_records_sql(
            "SELECT id, idnumber
               FROM {course}
              WHERE idnumber LIKE :pattern",
            ['pattern' => 'AM-TA%-K%-KM%-S%']
        );

        if (!$courses) {
            return;
        }

        /*
         * Data kelas aktif.
         * Format valid:
         * kelasid => waliuserid
         */
        $activewali = self::get_active_wali_map($tahunajaranid);

        /*
         * Bersihkan role teacher yang tidak sesuai tahun ajaran aktif.
         *
         * Ini tidak menghapus:
         * - role editingteacher guru mapel
         * - role student
         * - course
         * - nilai
         * - aktivitas
         */
        foreach ($courses as $course) {
            $parts = self::parse_generated_idnumber((string)$course->idnumber);

            if (!$parts) {
                continue;
            }

            $coursecontext = \context_course::instance((int)$course->id);
            $validuserid = 0;

            if (
                (int)$parts['tahunajaranid'] === $tahunajaranid
                && !empty($activewali[(int)$parts['kelasid']])
            ) {
                $validuserid = (int)$activewali[(int)$parts['kelasid']];
            }

            foreach ($waliids as $waliid) {
                /*
                 * Kalau course ini course tahun aktif dan user ini memang wali kelasnya,
                 * jangan dilepas.
                 */
                if ($validuserid > 0 && (int)$waliid === $validuserid) {
                    continue;
                }

                if ($DB->record_exists('role_assignments', [
                    'roleid' => $viewroleid,
                    'userid' => $waliid,
                    'contextid' => $coursecontext->id,
                ])) {
                    role_unassign($viewroleid, $waliid, $coursecontext->id);
                }

                /*
                 * Setelah role teacher dicabut, kalau user tidak punya role lain
                 * di course ini, unenrol manual supaya course tidak muncul di My courses.
                 *
                 * Kalau user juga guru pengampu editingteacher di course itu,
                 * dia tetap punya role lain, jadi tidak di-unenrol.
                 */
                if (!self::user_has_any_course_role($coursecontext->id, $waliid)) {
                    self::manual_unenrol_user((int)$course->id, $waliid);
                }
            }
        }

        /*
         * Pasang ulang akses wali kelas untuk tahun ajaran aktif.
         */
        self::add_active_wali_access($tahunajaranid, $viewroleid);
    }

    /**
     * Dipakai setelah generate satu course.
     *
     * Kalau course yang baru dibuat termasuk tahun ajaran aktif dan kelasnya punya wali,
     * wali kelas langsung diberi akses viewer.
     */
    public static function ensure_for_course(
        \stdClass $course,
        \stdClass $manualinstance,
        \stdClass $kelas,
        \stdClass $jurusan,
        \stdClass $tahun,
        int $groupid = 0
    ): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/enrol/manual/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');

        if (empty($kelas->id_user) || empty($tahun->id) || empty($course->id)) {
            return;
        }

        $viewrole = $DB->get_record('role', ['shortname' => 'teacher'], 'id', IGNORE_MISSING);

        if (!$viewrole) {
            return;
        }

        $userid = (int)$kelas->id_user;

        $user = $DB->get_record('user', [
            'id' => $userid,
            'deleted' => 0,
            'suspended' => 0,
        ], 'id', IGNORE_MISSING);

        if (!$user) {
            return;
        }

        self::manual_enrol_user(
            $course,
            $manualinstance,
            $userid,
            (int)$viewrole->id,
            $groupid
        );
    }

    private static function get_all_wali_userids(): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT DISTINCT id_user
               FROM {kelas}
              WHERE id_user IS NOT NULL
                AND id_user > 0"
        );

        $ids = [];

        foreach ($records as $record) {
            $ids[] = (int)$record->id_user;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private static function get_active_wali_map(int $tahunajaranid): array {
        global $DB;

        $kelasrecords = $DB->get_records('kelas', [
            'id_tahun_ajaran' => $tahunajaranid,
        ]);

        $map = [];

        foreach ($kelasrecords as $kelas) {
            if (!empty($kelas->id_user)) {
                $map[(int)$kelas->id] = (int)$kelas->id_user;
            }
        }

        return $map;
    }

    private static function parse_generated_idnumber(string $idnumber): ?array {
        $idnumber = trim($idnumber);

        if (preg_match('/^AM-TA(\d+)-K(\d+)-KM(\d+)-S([12])$/', $idnumber, $matches)) {
            return [
                'tahunajaranid' => (int)$matches[1],
                'kelasid' => (int)$matches[2],
                'kmid' => (int)$matches[3],
                'semester' => (int)$matches[4],
            ];
        }

        return null;
    }

    private static function add_active_wali_access(int $tahunajaranid, int $viewroleid): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/enrol/manual/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');

        $kelasrecords = $DB->get_records('kelas', [
            'id_tahun_ajaran' => $tahunajaranid,
        ]);

        if (!$kelasrecords) {
            return;
        }

        foreach ($kelasrecords as $kelas) {
            if (empty($kelas->id_user)) {
                continue;
            }

            $wali = $DB->get_record('user', [
                'id' => $kelas->id_user,
                'deleted' => 0,
                'suspended' => 0,
            ], 'id', IGNORE_MISSING);

            if (!$wali) {
                continue;
            }

            $courses = $DB->get_records_sql(
                "SELECT id, fullname, idnumber
                   FROM {course}
                  WHERE idnumber LIKE :pattern",
                [
                    'pattern' => 'AM-TA' . $tahunajaranid . '-K' . (int)$kelas->id . '-KM%-S%',
                ]
            );

            if (!$courses) {
                continue;
            }

            $jurusan = null;

            if (!empty($kelas->id_jurusan)) {
                $jurusan = $DB->get_record('jurusan', ['id' => $kelas->id_jurusan], '*', IGNORE_MISSING);
            }

            $groupname = 'Kelas ' . (int)$kelas->id;

            if ($jurusan) {
                $groupname = course_name_service::rombel_label($kelas, $jurusan);
            }

            foreach ($courses as $course) {
                $manualinstance = self::get_manual_instance((int)$course->id);

                if (!$manualinstance) {
                    continue;
                }

                $groupid = self::ensure_group((int)$course->id, $groupname);

                self::manual_enrol_user(
                    $course,
                    $manualinstance,
                    (int)$kelas->id_user,
                    $viewroleid,
                    $groupid
                );
            }
        }
    }

    private static function get_manual_instance(int $courseid): ?\stdClass {
        $instances = enrol_get_instances($courseid, true);

        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                return $instance;
            }
        }

        $plugin = enrol_get_plugin('manual');

        if (!$plugin) {
            return null;
        }

        $course = get_course($courseid);
        $plugin->add_instance($course);

        $instances = enrol_get_instances($courseid, true);

        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                return $instance;
            }
        }

        return null;
    }

    private static function ensure_group(int $courseid, string $groupname): int {
        global $DB;

        $groupname = trim($groupname);

        if ($groupname === '') {
            $groupname = 'Kelas';
        }

        $group = $DB->get_record('groups', [
            'courseid' => $courseid,
            'name' => $groupname,
        ], 'id', IGNORE_MULTIPLE);

        if ($group) {
            return (int)$group->id;
        }

        $newgroup = new \stdClass();
        $newgroup->courseid = $courseid;
        $newgroup->name = $groupname;
        $newgroup->description = 'Group kelas dibuat otomatis oleh Plugin Akademik & Monitoring.';
        $newgroup->descriptionformat = FORMAT_HTML;

        return groups_create_group($newgroup);
    }

    private static function manual_enrol_user(
        \stdClass $course,
        \stdClass $instance,
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

        $coursecontext = \context_course::instance((int)$course->id);

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

    private static function manual_unenrol_user(int $courseid, int $userid): void {
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $instance = self::get_manual_instance($courseid);

        if (!$instance) {
            return;
        }

        $plugin = enrol_get_plugin('manual');

        if (!$plugin) {
            return;
        }

        $plugin->unenrol_user($instance, $userid);
    }

    private static function user_has_any_course_role(int $contextid, int $userid): bool {
        global $DB;

        return $DB->record_exists_select(
            'role_assignments',
            'contextid = :contextid AND userid = :userid',
            [
                'contextid' => $contextid,
                'userid' => $userid,
            ]
        );
    }
}