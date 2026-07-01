<?php
namespace local_akademikmonitor\service\walikelas;

defined('MOODLE_INTERNAL') || die();

class common_service {

    private static function get_student_roleid(): int {
        global $DB;
        return (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
    }


    /**
     * Membaca identitas kelas dari course hasil generate plugin.
     *
     * Format baru:
     * - AM-TA{tahunajaranid}-K{kelasid}-KM{kurikulummapelid}-S{semester}
     *
     * Format lama:
     * - AM-K{kelasid}-KM{kurikulummapelid}-S{semester}
     *
     * Fungsi ini dibuat di common_service supaya fitur wali kelas lain
     * tidak perlu mengulang regex idnumber course.
     */
    public static function get_generated_course_info_from_courseid(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [
                'kelasid' => 0,
                'tahunajaranid' => 0,
                'semester' => 0,
            ];
        }

        $idnumber = trim((string)$DB->get_field('course', 'idnumber', ['id' => $courseid], IGNORE_MISSING));
        if ($idnumber === '') {
            return [
                'kelasid' => 0,
                'tahunajaranid' => 0,
                'semester' => 0,
            ];
        }

        if (preg_match('/^AM-TA(\d+)-K(\d+)-KM(\d+)-S([12])$/', $idnumber, $matches)) {
            return [
                'kelasid' => (int)$matches[2],
                'tahunajaranid' => (int)$matches[1],
                'semester' => (int)$matches[4],
            ];
        }

        if (preg_match('/^AM-K(\d+)-KM(\d+)-S([12])$/', $idnumber, $matches)) {
            return [
                'kelasid' => (int)$matches[1],
                'tahunajaranid' => 0,
                'semester' => (int)$matches[3],
            ];
        }

        return [
            'kelasid' => 0,
            'tahunajaranid' => 0,
            'semester' => 0,
        ];
    }

    public static function get_generated_kelasid_from_courseid(int $courseid): int {
        $info = self::get_generated_course_info_from_courseid($courseid);
        return (int)$info['kelasid'];
    }

    public static function get_generated_kelasid_from_group(int $groupid): int {
        global $DB;

        if ($groupid <= 0) {
            return 0;
        }

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, courseid', IGNORE_MISSING);
        if (!$group || empty($group->courseid)) {
            return 0;
        }

        return self::get_generated_kelasid_from_courseid((int)$group->courseid);
    }
private static function user_is_walikelas_for_group(int $userid, \stdClass $group): bool {
    global $DB;

    if ($userid <= 0 || empty($group->id)) {
        return false;
    }

    /*
     * Prioritas utama: data wali kelas dari tabel kelas.
     *
     * Ini yang paling benar untuk kasus kamu:
     * - 2025/2026 user bisa menjadi wali kelas X RPL 1 lama.
     * - 2026/2027 user belum tentu menjadi wali kelas X RPL 1 baru.
     *
     * Jadi jangan menebak wali kelas hanya dari group_members,
     * karena guru mapel juga bisa masuk ke group yang sama.
     */
    $kelas = self::get_kelas_record_from_group((int)$group->id);

    if ($kelas && property_exists($kelas, 'id_user') && (int)$kelas->id_user > 0) {
        return (int)$kelas->id_user === (int)$userid;
    }

    /*
     * Fallback untuk data lama:
     * kalau tabel kelas belum punya id_user, cek role course.
     *
     * Moodle default:
     * - editingteacher = guru yang bisa edit course.
     * - teacher = non-editing teacher.
     *
     * Wali kelas seharusnya non-editing teacher, jadi yang dicek adalah teacher,
     * bukan editingteacher.
     */
    $courseid = (int)($group->courseid ?? 0);

    if ($courseid <= 0) {
        return false;
    }

    $context = \context_course::instance($courseid, IGNORE_MISSING);

    if (!$context) {
        return false;
    }

    $roleshortnames = ['teacher', 'noneditingteacher'];
    [$rolesql, $roleparams] = $DB->get_in_or_equal($roleshortnames, SQL_PARAMS_NAMED, 'rs');

    $roleids = $DB->get_fieldset_select(
        'role',
        'id',
        "shortname {$rolesql}",
        $roleparams
    );

    if (!$roleids) {
        return false;
    }

    $roleids = array_map('intval', $roleids);
    [$roleidsql, $roleidparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');

    $params = array_merge($roleidparams, [
        'userid' => (int)$userid,
        'contextid' => (int)$context->id,
    ]);

    return $DB->record_exists_select(
        'role_assignments',
        "userid = :userid
         AND contextid = :contextid
         AND roleid {$roleidsql}",
        $params
    );
}
public static function get_group_walikelas(int $userid): array {
    global $DB;

    if ($userid <= 0) {
        return [];
    }

    /*
     * Ambil group yang diikuti user.
     *
     * Tapi ini baru kandidat, belum tentu wali kelas.
     * Guru mapel juga bisa masuk group, jadi nanti tetap difilter lagi
     * memakai user_is_walikelas_for_group().
     */
    $memberships = $DB->get_records(
        'groups_members',
        ['userid' => $userid],
        'groupid ASC',
        'groupid'
    );

    if (!$memberships) {
        return [];
    }

    $groupids = array_map('intval', array_keys($memberships));

    $groups = $DB->get_records_list(
        'groups',
        'id',
        $groupids,
        'id ASC',
        'id, name, courseid'
    );

    if (!$groups) {
        return [];
    }

    $bykelas = [];

    foreach ($groups as $g) {
        /*
         * Ini filter yang memperbaiki bug kamu.
         *
         * Sebelumnya:
         * semua group yang diikuti user dianggap wali kelas.
         *
         * Sekarang:
         * group hanya dihitung kalau user benar-benar wali kelas
         * berdasarkan tabel kelas.id_user atau fallback role teacher.
         */
        if (!self::user_is_walikelas_for_group($userid, $g)) {
            continue;
        }

        $name = (string)$g->name;
        $courseid = (int)($g->courseid ?? 0);
        $generatedkelasid = self::get_generated_kelasid_from_courseid($courseid);

        /*
         * Course hasil generate punya idnumber:
         * AM-TA{tahunajaranid}-K{kelasid}-KM{mapelid}-S{semester}
         *
         * Satu kelas punya banyak course/mapel, jadi harus dedupe berdasarkan kelas.
         */
        $key = $generatedkelasid > 0 ? ('kelas:' . $generatedkelasid) : ('name:' . $name);

        if (!isset($bykelas[$key])) {
            $bykelas[$key] = (object)[
                'id' => (int)$g->id,
                'name' => $name,
                'courseid' => $courseid,
                'generatedkelasid' => $generatedkelasid,
            ];
            continue;
        }

        $current = $bykelas[$key];
        $currentisgenerated = !empty($current->generatedkelasid);
        $newisgenerated = $generatedkelasid > 0;

        /*
         * Kalau ada pilihan antara group manual dan group hasil generate,
         * utamakan group hasil generate.
         */
        if (
            (!$currentisgenerated && $newisgenerated) ||
            ($currentisgenerated === $newisgenerated && (int)$g->id < (int)$current->id)
        ) {
            $bykelas[$key] = (object)[
                'id' => (int)$g->id,
                'name' => $name,
                'courseid' => $courseid,
                'generatedkelasid' => $generatedkelasid,
            ];
        }
    }

    $out = [];

    foreach ($bykelas as $g) {
        $out[(int)$g->id] = $g;
    }

    ksort($out);

    return $out;
}
private static function get_group_for_kelas_record(\stdClass $kelas): ?\stdClass {
    global $DB;

    $kelasid = (int)($kelas->id ?? 0);
    $tahunajaranid = (int)($kelas->id_tahun_ajaran ?? 0);
    $namakelas = trim((string)($kelas->nama ?? ''));

    if ($kelasid <= 0) {
        return null;
    }

    /*
     * Cari group dari course hasil generate plugin.
     *
     * Format baru:
     * AM-TA{id_tahunajaran}-K{idkelas}-KM{idkurikulummapel}-S{semester}
     *
     * Kenapa dicari dari course?
     * Karena halaman wali kelas masih memakai group id untuk membaca siswa.
     */
    $patterns = [];

    if ($tahunajaranid > 0) {
        $patterns[] = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S%';
    }

    /*
     * Fallback untuk data lama.
     * Aman karena id kelas tetap unik.
     */
    $patterns[] = 'AM-K' . $kelasid . '-KM%-S%';

    foreach ($patterns as $idx => $pattern) {
        $like = $DB->sql_like('c.idnumber', ':pattern' . $idx, false, false);

        $records = $DB->get_records_sql(
            "SELECT g.id,
                    g.name,
                    g.courseid,
                    c.idnumber
               FROM {course} c
               JOIN {groups} g ON g.courseid = c.id
              WHERE {$like}
           ORDER BY c.id ASC, g.id ASC",
            [
                'pattern' . $idx => $pattern,
            ],
            0,
            1
        );

        if (!$records) {
            continue;
        }

        $group = reset($records);

        return (object)[
            'id' => (int)$group->id,
            'name' => $namakelas !== '' ? $namakelas : (string)$group->name,
            'courseid' => (int)$group->courseid,
            'generatedkelasid' => $kelasid,
        ];
    }

    return null;
}
    /**
     * Ambil daftar kelas wali kelas sesuai tahun ajaran terpilih.
     *
     * Kenapa tidak langsung memakai get_group_walikelas()?
     * Karena satu wali bisa pernah menjadi wali di beberapa tahun ajaran.
     * Kalau tidak difilter, halaman wali kelas bisa mengambil group/course lama,
     * misalnya X RPL 1 tahun 2025/2026 tetap muncul saat filter 2026/2027.
     */
public static function get_group_walikelas_by_tahunajaran(int $userid, int $tahunajaranid = 0): array {
    global $DB;

    if ($userid <= 0) {
        return [];
    }

    $out = [];
    $processedkelas = [];

    /*
     * Prioritas utama:
     * Ambil kelas dari tabel plugin {kelas}.
     *
     * Ini memperbaiki kasus:
     * - Yusuf Maulana wali kelas XI pada 2025/2026.
     * - Yusuf Maulana wali kelas XII pada 2026/2027.
     *
     * Jadi dashboard tidak boleh bergantung hanya pada groups_members Moodle.
     */
    $conditions = ['id_user = :userid'];
    $params = [
        'userid' => (int)$userid,
    ];

    if ($tahunajaranid > 0) {
        $conditions[] = 'id_tahun_ajaran = :tahunajaranid';
        $params['tahunajaranid'] = (int)$tahunajaranid;
    }

    $kelasrecords = $DB->get_records_select(
        'kelas',
        implode(' AND ', $conditions),
        $params,
        'id ASC',
        'id, nama, tingkat, id_jurusan, id_tahun_ajaran, id_user'
    );

    foreach ($kelasrecords as $kelas) {
        $group = self::get_group_for_kelas_record($kelas);

        /*
         * Kalau course/group belum digenerate, kelas belum bisa dipakai
         * oleh halaman wali kelas yang berbasis group.
         *
         * Jadi kelas tidak dimasukkan dulu.
         * Solusinya: admin klik tombol Course / Generate Course pada kelas itu.
         */
        if (!$group || empty($group->id)) {
            continue;
        }
        $group->kelasid = (int)$kelas->id;

        $processedkelas[(int)$kelas->id] = true;

        $out[(int)$group->id] = $group;
    }

    /*
     * Fallback:
     * tetap ambil data lama dari group membership, untuk jaga-jaga
     * kalau ada data lama yang belum lengkap di tabel kelas.
     */
    $legacygroups = self::get_group_walikelas($userid);

    foreach ($legacygroups as $group) {
        $groupid = (int)($group->id ?? 0);

        if ($groupid <= 0) {
            continue;
        }

        if ($tahunajaranid > 0 && !self::group_matches_tahunajaran($groupid, $tahunajaranid)) {
            continue;
        }

        $kelasid = self::get_generated_kelasid_from_group($groupid);

        if ($kelasid > 0 && isset($processedkelas[$kelasid])) {
            continue;
        }

        $group->kelasid = $kelasid;

        $out[$groupid] = $group;

        if ($kelasid > 0) {
            $processedkelas[$kelasid] = true;
        }
    }

    ksort($out);

    return $out;
}

    public static function get_first_group_walikelas_by_tahunajaran(int $userid, int $tahunajaranid = 0): ?\stdClass {
        $groups = self::get_group_walikelas_by_tahunajaran($userid, $tahunajaranid);

        if (!$groups) {
            return null;
        }

        return reset($groups) ?: null;
    }

    public static function get_first_group_walikelas(int $userid): ?\stdClass {
        $groups = self::get_group_walikelas($userid);
        if (!$groups) {
            return null;
        }
        return reset($groups) ?: null;
    }

    public static function get_siswa_group(int $groupid, int $waliuserid = 0): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, courseid', MUST_EXIST);
        $courseid = (int)($group->courseid ?? 0);

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $fields = 'u.id, u.idnumber, u.username, u.email, u.deleted, u.suspended, ' . $namefields;

        $members = groups_get_members($groupid, $fields, 'u.firstname ASC');
        if (!$members) {
            return [];
        }

        foreach ($members as $uid => $u) {
            if (($waliuserid > 0 && (int)$u->id === (int)$waliuserid) || !empty($u->deleted) || !empty($u->suspended)) {
                unset($members[$uid]);
            }
        }

        if (!$members) {
            return [];
        }

        // Simpan backup hasil member group setelah buang wali/deleted/suspended.
        $fallbackmembers = $members;

        $studentroleid = self::get_student_roleid();
        if ($studentroleid <= 0 || $courseid <= 0) {
            return $fallbackmembers;
        }

        $contextid = (int)\context_course::instance($courseid)->id;
        $userids = array_map('intval', array_keys($members));

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['roleid'] = $studentroleid;
        $params['ctxid'] = $contextid;

        $studentids = $DB->get_fieldset_select(
            'role_assignments',
            'userid',
            "roleid = :roleid AND contextid = :ctxid AND userid $insql",
            $params
        );

        // Kalau role assignment student tidak ketemu sama sekali,
        // jangan kosongkan semua siswa. Pakai fallback group members.
        if (empty($studentids)) {
            return $fallbackmembers;
        }

        $studentset = array_flip(array_map('intval', $studentids));

        foreach ($members as $uid => $u) {
            if (!isset($studentset[(int)$u->id])) {
                unset($members[$uid]);
            }
        }

        // Kalau setelah filter hasilnya kosong, pakai fallback juga.
        if (empty($members)) {
            return $fallbackmembers;
        }

        return $members;
    }

    public static function get_kelas_record_from_group(int $groupid): ?\stdClass {
        global $DB;

        if ($groupid <= 0) {
            return null;
        }

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, name, courseid', IGNORE_MISSING);
        if (!$group) {
            return null;
        }

        $generatedkelasid = self::get_generated_kelasid_from_courseid((int)$group->courseid);
        if ($generatedkelasid > 0) {
            $kelas = $DB->get_record(
                'kelas',
                ['id' => $generatedkelasid],
                'id, nama, tingkat, id_jurusan, id_tahun_ajaran, id_user',
                IGNORE_MISSING
            );

            if ($kelas) {
                return $kelas;
            }
        }

        return null;
    }

    public static function is_tingkat_xii(string $tingkat): bool {
        $tingkat = strtoupper(trim($tingkat));
        $tingkat = preg_replace('/\s+/', '', $tingkat);

        return in_array($tingkat, ['XII', '12'], true);
    }

    public static function is_group_kelas_xii(int $groupid): bool {
        $kelas = self::get_kelas_record_from_group($groupid);
        if (!$kelas) {
            return false;
        }

        return self::is_tingkat_xii((string)($kelas->tingkat ?? ''));
    }

public static function group_matches_tahunajaran(int $groupid, int $tahunajaranid): bool {
    global $DB;

    if ($tahunajaranid <= 0) {
        return true;
    }

    if ($groupid <= 0) {
        return false;
    }

    /*
     * Prioritas utama tetap tabel kelas.
     */
    $kelas = self::get_kelas_record_from_group($groupid);

    if ($kelas) {
        return (int)($kelas->id_tahun_ajaran ?? 0) === (int)$tahunajaranid;
    }

    /*
     * Fallback: baca langsung dari idnumber course.
     * Format baru:
     * AM-TA{tahunajaranid}-K{kelasid}-KM{mapelid}-S{semester}
     */
    $group = $DB->get_record(
        'groups',
        ['id' => $groupid],
        'id, courseid',
        IGNORE_MISSING
    );

    if (!$group || empty($group->courseid)) {
        return false;
    }

    $info = self::get_generated_course_info_from_courseid((int)$group->courseid);

    if (!empty($info['tahunajaranid'])) {
        return (int)$info['tahunajaranid'] === (int)$tahunajaranid;
    }

    /*
     * Kalau tidak bisa dibuktikan group ini milik tahun ajaran yang dipilih,
     * jangan tampilkan. Ini lebih aman supaya data tahun lain tidak bocor.
     */
    return false;
}

public static function wali_has_kelas_xii(int $userid, int $tahunajaranid = 0): bool {
    $groups = self::get_group_walikelas_by_tahunajaran($userid, $tahunajaranid);

    foreach ($groups as $group) {
        $groupid = (int)($group->id ?? 0);

        if ($groupid <= 0) {
            continue;
        }

        if (!self::group_matches_tahunajaran($groupid, $tahunajaranid)) {
            continue;
        }

        if (self::is_group_kelas_xii($groupid)) {
            return true;
        }
    }

    return false;
}

    public static function filter_groups_kelas_xii(array $groups, int $tahunajaranid = 0): array {
        $out = [];

        foreach ($groups as $key => $group) {
            $groupid = (int)($group->id ?? 0);
            if ($groupid <= 0) {
                continue;
            }

            if (!self::group_matches_tahunajaran($groupid, $tahunajaranid)) {
                continue;
            }

            if (!self::is_group_kelas_xii($groupid)) {
                continue;
            }

            $out[$key] = $group;
        }

        return $out;
    }

    public static function get_sidebar_data(string $active = '', int $userid = 0, int $tahunajaranid = 0): array {
        $showpklmenu = true;

        if ($userid > 0) {
            $showpklmenu = self::wali_has_kelas_xii($userid, $tahunajaranid);
        }

        return [
            'show_pkl_menu' => $showpklmenu,

            'is_dashboard' => ($active === 'dashboard'),
            'is_monitoring_kelas' => ($active === 'monitoring'),
            'is_monitoring_presensi' => ($active === 'presensi'),
            'is_ekskul_siswa' => ($active === 'ekskul'),
            'is_pkl_siswa' => ($active === 'pkl'),
            'is_raport' => ($active === 'rapor'),

            'dashboard_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/dashboard.php'))->out(false),
            'monitoring_kelas_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php'))->out(false),
            'monitoring_presensi_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/presensi/index.php'))->out(false),
            'ekskul_siswa_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php'))->out(false),
            'pkl_siswa_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php'))->out(false),
            'raport_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/rapor/index.php'))->out(false),
        ];
    }

    public static function get_nisn_map_by_userids(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }

        $field = $DB->get_record('user_info_field', ['shortname' => 'nisn'], 'id', IGNORE_MISSING);
        if (!$field) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['fieldid'] = (int)$field->id;

        $records = $DB->get_records_select(
            'user_info_data',
            "fieldid = :fieldid AND userid $insql",
            $params,
            '',
            'userid, data'
        );

        $map = [];
        foreach ($records as $r) {
            $map[(int)$r->userid] = (string)$r->data;
        }

        return $map;
    }
}