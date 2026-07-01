<?php
namespace local_akademikmonitor\service\walikelas;

use local_akademikmonitor\service\course_period_service;
use local_akademikmonitor\service\period_filter_service;

defined('MOODLE_INTERNAL') || die();

class rapor_service {

    public static function get_mapel_by_kelas(int $groupid, int $semester = 0): array {
        global $DB;

        if ($groupid <= 0) {
            return [];
        }

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, name, courseid', MUST_EXIST);
        $kelasid = common_service::get_generated_kelasid_from_group($groupid);

        if ($kelasid > 0) {
            return self::get_generated_courses_by_kelas($kelasid, $semester);
        }

        return self::get_legacy_courses_by_group($group, $semester);
    }

private static function get_generated_courses_by_kelas(int $kelasid, int $semester = 0): array {
    global $DB;

    if ($kelasid <= 0) {
        return [];
    }

    $tahunajaranid = period_filter_service::get_selected_tahunajaranid();

$conditions = [];
$params = [];

/*
 * Untuk Moodle 5.1.3 dan plugin final, utamakan format baru yang punya tahun ajaran:
 *
 * AM-TA{id_tahun_ajaran}-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
 *
 * Kenapa format lama tidak langsung digabung?
 * Karena format lama AM-K{id_kelas}-KM%-S% tidak punya tahun ajaran.
 * Kalau ikut dibaca, rapor bisa mengambil nilai dari tahun ajaran lama.
 */
if ($tahunajaranid > 0) {
    $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S%';

    if (in_array($semester, [1, 2], true)) {
        $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S' . $semester;
    }

    $conditions[] = $DB->sql_like('idnumber', ':newpattern', false);
    $params['newpattern'] = $newpattern;
} else {
    $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S%';

    if (in_array($semester, [1, 2], true)) {
        $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S' . $semester;
    }

    $conditions[] = $DB->sql_like('idnumber', ':newpattern', false);
    $params['newpattern'] = $newpattern;
}

/*
 * Fallback format lama hanya dipakai kalau tidak ada tahun ajaran aktif.
 * Ini untuk jaga-jaga data lama, tapi tidak mencampur rapor tahun ajaran aktif.
 */
if ($tahunajaranid <= 0) {
    $oldpattern = 'AM-K' . $kelasid . '-KM%-S%';

    if (in_array($semester, [1, 2], true)) {
        $oldpattern = 'AM-K' . $kelasid . '-KM%-S' . $semester;
    }

    $conditions[] = $DB->sql_like('idnumber', ':oldpattern', false);
    $params['oldpattern'] = $oldpattern;
}

    $courses = $DB->get_records_select(
        'course',
        '(' . implode(' OR ', $conditions) . ')',
        $params,
        'fullname ASC, id ASC',
        'id, fullname, shortname, idnumber'
    );

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

    $kmidbycourse = [];
    $kmids = [];

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
    $matapelajarans = [];

    if ($kmids) {
        $kurikulummapels = $DB->get_records_list(
            'kurikulum_mapel',
            'id',
            array_values($kmids),
            '',
            'id, id_mapel, kktp, tingkat_kelas'
        );

        $mapelids = [];

        foreach ($kurikulummapels as $km) {
            $mapelid = (int)($km->id_mapel ?? 0);

            if ($mapelid > 0) {
                $mapelids[$mapelid] = $mapelid;
            }
        }

        if ($mapelids) {
            $matapelajarans = $DB->get_records_list(
                'mata_pelajaran',
                'id',
                array_values($mapelids),
                '',
                'id, nama_mapel'
            );
        }
    }

    $out = [];

    foreach ($courses as $course) {
        $courseid = (int)$course->id;
        $kmid = $kmidbycourse[$courseid] ?? 0;
        $km = ($kmid > 0 && isset($kurikulummapels[$kmid])) ? $kurikulummapels[$kmid] : null;

        $mapelname = '';
        $kktp = 0;

        if ($km) {
            $mapelid = (int)($km->id_mapel ?? 0);
            $kktp = (int)($km->kktp ?? 0);

            if ($mapelid > 0 && isset($matapelajarans[$mapelid])) {
                $mapelname = (string)$matapelajarans[$mapelid]->nama_mapel;
            }
        }

        if ($mapelname === '') {
            $mapelname = (string)$course->fullname;
        }

        $out[$courseid] = (object)[
            'id' => $courseid,
            'fullname' => (string)$course->fullname,
            'shortname' => (string)($course->shortname ?? ''),
            'idnumber' => (string)($course->idnumber ?? ''),
            'nama_mapel' => self::clean_mapel_name($mapelname),
            'kktp' => $kktp,
        ];
    }

    uasort($out, static function($a, $b) {
        return strcasecmp((string)$a->nama_mapel, (string)$b->nama_mapel);
    });

    return $out;
}

    private static function get_legacy_courses_by_group(\stdClass $group, int $semester = 0): array {
        global $DB;

        $cleanname = preg_replace('/\s\d+$/', '', (string)$group->name);
        $parts = preg_split('/\s+/', trim($cleanname));

        if (!$parts || count($parts) < 2) {
            return [];
        }

        $tingkat = $parts[0];
        $jurusan = $parts[1];

        $select = $DB->sql_like('fullname', ':tingkat', false) . ' AND ' .
            $DB->sql_like('fullname', ':jurusan', false);

        $courses = $DB->get_records_select(
            'course',
            $select,
            [
                'tingkat' => '%' . $DB->sql_like_escape($tingkat) . '%',
                'jurusan' => '%' . $DB->sql_like_escape($jurusan) . '%',
            ],
            'fullname ASC',
            'id, fullname, shortname, idnumber'
        );

        $courses = course_period_service::filter_courses_by_semester($courses, $semester);

        $out = [];

        foreach ($courses as $c) {
            $out[(int)$c->id] = (object)[
                'id' => (int)$c->id,
                'fullname' => (string)$c->fullname,
                'shortname' => (string)($c->shortname ?? ''),
                'nama_mapel' => self::clean_mapel_name((string)$c->fullname),
                'kktp' => 0,
            ];
        }

        return $out;
    }

public static function get_raport_kelas(
    int $groupid,
    int $waliuserid = 0,
    int $semester = 0,
    int $tahunajaranid = 0,
    int $kelasid = 0
): array {
    global $DB;

    if ($groupid <= 0) {
        return [];
    }

    if ($semester <= 0) {
        $semester = period_filter_service::get_selected_semester();
    }

    if ($tahunajaranid <= 0) {
        $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
    }

    /*
     * $groupid adalah ID group Moodle.
     * $kelasid adalah ID kelas custom dari tabel {kelas}.
     *
     * Nilai sikap disimpan berdasarkan ID kelas custom agar tidak campur
     * dengan tahun ajaran atau group Moodle yang berbeda.
     */
    if ($kelasid <= 0) {
        $kelasid = common_service::get_generated_kelasid_from_group($groupid);
    }

    $students = common_service::get_siswa_group($groupid, $waliuserid);

    if (!$students) {
        return [];
    }

    $courses = self::get_mapel_by_kelas($groupid, $semester);
    $courseids = array_map('intval', array_keys($courses));

    if (!$courseids) {
        return [];
    }

    $userids = array_map('intval', array_keys($students));
    $nisnmap = common_service::get_nisn_map_by_userids($userids);

    $sum = [];

    foreach ($userids as $uid) {
        $sum[$uid] = 0.0;
    }

    foreach ($courseids as $cid) {
        $gi = $DB->get_record(
            'grade_items',
            [
                'courseid' => $cid,
                'idnumber' => 'am_nilai_akhir',
            ],
            'id',
            IGNORE_MISSING
        );

        if (!$gi) {
            continue;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');
        $params['itemid'] = (int)$gi->id;

        $grades = $DB->get_records_select(
            'grade_grades',
            "itemid = :itemid AND userid {$insql}",
            $params,
            '',
            'userid, finalgrade'
        );

        foreach ($userids as $uid) {
            $g = $grades[$uid] ?? null;
            $sum[$uid] += ($g && $g->finalgrade !== null) ? (float)$g->finalgrade : 0.0;
        }
    }

    $data = [];
    $coursecount = count($courseids);

    foreach ($students as $u) {
        $uid = (int)$u->id;
        $jumlah = $sum[$uid] ?? 0.0;

        $data[] = (object)[
            'id' => $uid,
            'firstname' => (string)($u->firstname ?? ''),
            'lastname' => (string)($u->lastname ?? ''),
            'nisn' => !empty($nisnmap[$uid]) ? (string)$nisnmap[$uid] : '-',
            'jumlah' => $jumlah,
            'rata_rata' => $coursecount > 0 ? ($jumlah / $coursecount) : 0.0,
        ];
    }

    usort($data, static function($a, $b) {
        return $b->jumlah <=> $a->jumlah;
    });

    /*
     * Ambil nilai sikap dari tabel baru.
     * Kalau belum ada data, nanti default tetap A di format_raport().
     */
    $sikapmap = self::get_nilai_sikap_map(
        $userids,
        $kelasid,
        $semester,
        $tahunajaranid
    );

    return self::format_raport($data, $sikapmap);
}
private static function get_nilai_sikap_map(
    array $userids,
    int $kelasid,
    int $semester,
    int $tahunajaranid
): array {
    global $DB;

    $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));

    if (!$userids || $kelasid <= 0 || $semester <= 0 || $tahunajaranid <= 0) {
        return [];
    }

    /*
     * Pengaman agar halaman rapor tidak rusak kalau tabel belum terbuat.
     * Setelah upgrade berhasil, tabel ini akan terbaca normal.
     */
    $dbman = $DB->get_manager();

    if (!$dbman->table_exists(new \xmldb_table('rapor_nilai_sikap'))) {
        return [];
    }

    [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');

    $params['kelasid'] = $kelasid;
    $params['semester'] = $semester;
    $params['tahunajaranid'] = $tahunajaranid;

    $records = $DB->get_records_select(
        'rapor_nilai_sikap',
        "id_siswa {$insql}
         AND id_kelas = :kelasid
         AND semester = :semester
         AND id_tahun_ajaran = :tahunajaranid",
        $params,
        '',
        'id, id_siswa, nilai_sikap'
    );

    $map = [];

    foreach ($records as $record) {
        $userid = (int)($record->id_siswa ?? 0);
        $nilai = strtoupper(trim((string)($record->nilai_sikap ?? '')));

        if ($userid > 0 && in_array($nilai, ['A', 'B', 'C', 'D'], true)) {
            $map[$userid] = $nilai;
        }
    }

    return $map;
}

public static function save_nilai_sikap_kelas(
    int $kelasid,
    int $semester,
    int $tahunajaranid,
    array $sikapdata,
    int $penginputid
): void {
    global $DB;

    if ($kelasid <= 0 || $semester <= 0 || $tahunajaranid <= 0) {
        throw new \exception('invaliddata', 'error');
    }

    $dbman = $DB->get_manager();

    if (!$dbman->table_exists(new \xmldb_table('rapor_nilai_sikap'))) {
        throw new \exception(
            'Tabel rapor_nilai_sikap belum tersedia. Jalankan upgrade plugin terlebih dahulu.'
        );
    }

    $allowed = ['A', 'B', 'C', 'D'];
    $now = time();

    foreach ($sikapdata as $userid => $nilai) {
        $userid = (int)$userid;
        $nilai = strtoupper(trim((string)$nilai));

        if ($userid <= 0 || !in_array($nilai, $allowed, true)) {
            continue;
        }

        $conditions = [
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
            'semester' => $semester,
            'id_tahun_ajaran' => $tahunajaranid,
        ];

        $existing = $DB->get_record(
            'rapor_nilai_sikap',
            $conditions,
            '*',
            IGNORE_MISSING
        );

        if ($existing) {
            $existing->nilai_sikap = $nilai;
            $existing->id_penginput = $penginputid;
            $existing->timemodified = $now;

            $DB->update_record('rapor_nilai_sikap', $existing);
            continue;
        }

        $record = (object)[
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
            'semester' => $semester,
            'id_tahun_ajaran' => $tahunajaranid,
            'nilai_sikap' => $nilai,
            'id_penginput' => $penginputid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $DB->insert_record('rapor_nilai_sikap', $record);
    }
}
private static function format_raport(array $data, array $sikapmap = []): array {
    $result = [];
    $rank = 1;
    $lastnilai = null;
    $no = 0;

    foreach ($data as $d) {
        $no++;

        $userid = (int)($d->id ?? 0);
        $jumlahrounded = round((float)$d->jumlah, 0);

        if ($lastnilai !== $jumlahrounded) {
            $rank = $no;
        }

        $nilaisikap = strtoupper(trim((string)($sikapmap[$userid] ?? 'A')));

        if (!in_array($nilaisikap, ['A', 'B', 'C', 'D'], true)) {
            $nilaisikap = 'A';
        }

        $result[] = [
            'userid' => $userid,
            'no' => $no,
            'nama' => trim((string)$d->firstname . ' ' . (string)$d->lastname),
            'nisn' => !empty($d->nisn) ? (string)$d->nisn : '-',
            'rata_rata' => round((float)$d->rata_rata, 0),
            'jumlah' => $jumlahrounded,
            'ranking' => $rank,

            /*
             * Nilai sikap lama sebelumnya hardcode A.
             * Sekarang diambil dari tabel rapor_nilai_sikap.
             */
            'nilai_sikap' => $nilaisikap,
            'sikap_a' => ($nilaisikap === 'A'),
            'sikap_b' => ($nilaisikap === 'B'),
            'sikap_c' => ($nilaisikap === 'C'),
            'sikap_d' => ($nilaisikap === 'D'),

            'detail_url' => (new \moodle_url(
                '/local/akademikmonitor/pages/walikelas/rapor/detail.php',
                ['userid' => $userid]
            ))->out(false),
        ];

        $lastnilai = $jumlahrounded;
    }

    return $result;
}

public static function get_student_ranking_summary(
    int $userid,
    int $groupid,
    int $waliuserid = 0,
    int $semester = 0,
    int $tahunajaranid = 0,
    int $kelasid = 0
): array {
    $rows = self::get_raport_kelas(
        $groupid,
        $waliuserid,
        $semester,
        $tahunajaranid,
        $kelasid
    );

    if (!$rows) {
        return [
            'jumlah' => 0.0,
            'ranking' => 0,
            'total_siswa' => 0,
        ];
    }

    foreach ($rows as $row) {
        if ((int)($row['userid'] ?? 0) === $userid) {
            return [
                'jumlah' => (float)($row['jumlah'] ?? 0),
                'ranking' => (int)($row['ranking'] ?? 0),
                'total_siswa' => count($rows),
            ];
        }
    }

    return [
        'jumlah' => 0.0,
        'ranking' => 0,
        'total_siswa' => count($rows),
    ];
}

public static function get_page_data(int $userid, int $semester = 1, int $tahunajaranid = 0): array {
    if ($semester <= 0) {
        $semester = period_filter_service::get_selected_semester();
    }

    if ($tahunajaranid <= 0) {
        $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
    }

    $data = common_service::get_sidebar_data('rapor', $userid, $tahunajaranid);

    $group = common_service::get_first_group_walikelas_by_tahunajaran(
        $userid,
        $tahunajaranid
    );

    if (!$group) {
        $data['nokelas'] = true;
        $data += period_filter_service::build_filter_data();

        return $data;
    }

    /*
     * $group->id adalah ID group Moodle.
     * $kelasid adalah ID kelas custom dari tabel {kelas}.
     */
    $groupid = (int)$group->id;
    $kelasid = common_service::get_generated_kelasid_from_group($groupid);

    $rows = array_values(self::get_raport_kelas(
        $groupid,
        $userid,
        $semester,
        $tahunajaranid,
        $kelasid
    ));

    foreach ($rows as $index => $row) {
        $studentid = (int)($row['userid'] ?? 0);

        $rows[$index]['detail_url'] = (new \moodle_url(
            '/local/akademikmonitor/pages/walikelas/rapor/detail.php',
            period_filter_service::append_filter_params([
                'userid' => $studentid,

                /*
                 * Detail rapor masih memakai groupid, karena halaman detail
                 * mengecek keanggotaan siswa berdasarkan groups_members.
                 */
                'kelasid' => $groupid,
            ])
        ))->out(false);
    }

    $data['kelas'] = (string)$group->name;
    $data['rows'] = $rows;

    /*
     * Ini dipakai di form simpan nilai sikap.
     * Yang dikirim adalah ID kelas custom, sesuai tabel rapor_nilai_sikap.
     */
    $data['kelasid'] = $kelasid;
    $data['groupid'] = $groupid;
    $data['sesskey'] = sesskey();
    $data['save_sikap_url'] = (new \moodle_url(
        '/local/akademikmonitor/pages/walikelas/rapor/index.php',
        period_filter_service::append_filter_params([])
    ))->out(false);

    $data['selectedsemester'] = $semester;
    $data['selectedtahunajaranid'] = $tahunajaranid;
    $data['selected_tahunajaranid'] = $tahunajaranid;

    $data += period_filter_service::build_filter_data();

    return $data;
}

public static function get_primary_groupid_by_student(int $userid, int $waliuserid = 0): int {
    global $DB, $USER;

    if ($userid <= 0) {
        return 0;
    }

    if ($waliuserid <= 0 && !empty($USER->id)) {
        $waliuserid = (int)$USER->id;
    }

    $params = ['userid' => $userid];
    $walisql = '';

    if ($waliuserid > 0) {
        $params['waliuserid'] = $waliuserid;
        $walisql = " AND EXISTS (
                        SELECT 1
                          FROM {groups_members} gmwali
                         WHERE gmwali.groupid = g.id
                           AND gmwali.userid = :waliuserid
                     )";
    }

    $sql = "SELECT g.id, g.courseid, c.idnumber
              FROM {groups_members} gm
              JOIN {groups} g ON g.id = gm.groupid
         LEFT JOIN {course} c ON c.id = g.courseid
             WHERE gm.userid = :userid
                   {$walisql}
          ORDER BY CASE
                       WHEN c.idnumber LIKE 'AM-TA%-K%-KM%-S%' THEN 0
                       WHEN c.idnumber LIKE 'AM-K%-KM%-S%' THEN 1
                       ELSE 2
                   END,
                   g.id ASC";

    $records = $DB->get_records_sql($sql, $params, 0, 1);

    if (!$records) {
        return 0;
    }

    $first = reset($records);

    return (int)($first->id ?? 0);
}

    public static function get_student_course(int $userid): ?\stdClass {
        global $DB;

        if ($userid <= 0) {
            return null;
        }

        return $DB->get_record_sql(
            "SELECT c.id, c.fullname, c.shortname, c.idnumber
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {course} c ON c.id = e.courseid
              WHERE ue.userid = :userid
           ORDER BY c.id ASC",
            ['userid' => $userid],
            IGNORE_MULTIPLE
        ) ?: null;
    }

    public static function get_nilai_akademik_detail(int $studentid, int $groupid, int $semester = 0): array {
        global $DB;

        $courses = self::get_mapel_by_kelas($groupid, $semester);

        if (!$courses) {
            return [];
        }

        $student = $DB->get_record('user', ['id' => $studentid], '*', IGNORE_MISSING);
        $studentname = $student ? fullname($student) : 'peserta didik';

        $rows = [];
        $no = 1;

        foreach ($courses as $course) {
            $courseid = (int)$course->id;
            $rawmapelname = trim((string)($course->nama_mapel ?? $course->fullname ?? $course->shortname ?? '-'));

            $kategori = self::extract_mapel_category($rawmapelname);
            $namamapel = self::get_display_mapel_name($course);
            $nilaiakhir = self::get_course_total_grade($studentid, $courseid);

            $tpitems = self::get_sumatif_items_with_grade($studentid, $courseid);
            $tertinggi = self::pick_extreme_sumatif($tpitems, 'highest');
            $terendah = self::pick_extreme_sumatif($tpitems, 'lowest');

            $rows[] = [
                'no' => $no++,
                'courseid' => $courseid,
                'kelompok_key' => $kategori['key'],
                'kelompok_label' => $kategori['label'],
                'mata_pelajaran' => $namamapel,
                'nilai_akhir' => self::format_grade($nilaiakhir),
                'capaian_atas' => self::build_positive_competency_text($studentname, $namamapel, $tertinggi),
                'capaian_bawah' => self::build_negative_competency_text($studentname, $namamapel, $terendah),
            ];
        }

        usort($rows, static function($a, $b) {
            $order = [
                'umum' => 1,
                'muatan_lokal' => 2,
                'kejuruan' => 3,
                'lainnya' => 4,
            ];

            $ao = $order[(string)($a['kelompok_key'] ?? 'lainnya')] ?? 99;
            $bo = $order[(string)($b['kelompok_key'] ?? 'lainnya')] ?? 99;

            if ($ao === $bo) {
                return strcmp((string)$a['mata_pelajaran'], (string)$b['mata_pelajaran']);
            }

            return $ao <=> $bo;
        });

        $no = 1;

        foreach ($rows as $index => $row) {
            $rows[$index]['no'] = $no++;
        }

        return $rows;
    }

    private static function get_course_total_grade(int $studentid, int $courseid): ?float {
        global $DB;

        $gradeitem = $DB->get_record(
            'grade_items',
            ['courseid' => $courseid, 
            'idnumber' => 'am_nilai_akhir'],
            'id',
            IGNORE_MISSING
        );

        if (!$gradeitem) {
            return null;
        }

        $grade = $DB->get_record(
            'grade_grades',
            [
                'itemid' => (int)$gradeitem->id,
                'userid' => $studentid,
            ],
            'finalgrade',
            IGNORE_MISSING
        );

        if (!$grade || $grade->finalgrade === null) {
            return null;
        }

        return (float)$grade->finalgrade;
    }

    private static function get_sumatif_items_with_grade(int $studentid, int $courseid): array {
        global $DB;

        if ($studentid <= 0 || $courseid <= 0) {
            return [];
        }

        $gradeitems = $DB->get_records(
            'grade_items',
            ['courseid' => $courseid],
            'sortorder ASC, id ASC',
            'id, courseid, itemname, itemtype, idnumber, grademax, sortorder'
        );

        if (!$gradeitems) {
            return [];
        }

        $gradeitemids = array_map('intval', array_keys($gradeitems));

        [$giinsql, $giparams] = $DB->get_in_or_equal($gradeitemids, SQL_PARAMS_NAMED, 'gi');

        $relations = $DB->get_records_select(
            'grade_items_tp',
            "id_grade_items {$giinsql}",
            $giparams,
            'id_grade_items ASC, id_tp ASC',
            'id, id_grade_items, id_tp'
        );

        if (!$relations) {
            return [];
        }

        $tpids = [];
        $tpidsbyitem = [];

        foreach ($relations as $relation) {
            $itemid = (int)($relation->id_grade_items ?? 0);
            $tpid = (int)($relation->id_tp ?? 0);

            if ($itemid <= 0 || $tpid <= 0 || !isset($gradeitems[$itemid])) {
                continue;
            }

            $tpids[$tpid] = $tpid;
            $tpidsbyitem[$itemid][$tpid] = $tpid;
        }

        if (!$tpids || !$tpidsbyitem) {
            return [];
        }

        $grades = $DB->get_records_select(
            'grade_grades',
            "userid = :userid AND itemid {$giinsql}",
            ['userid' => $studentid] + $giparams,
            '',
            'id, itemid, userid, finalgrade'
        );

        $gradebyitem = [];

        foreach ($grades as $grade) {
            $gradebyitem[(int)$grade->itemid] = $grade->finalgrade !== null
                ? (float)$grade->finalgrade
                : null;
        }

        $tps = self::get_tp_records(array_values($tpids));

        if (!$tps) {
            return [];
        }

        $cps = self::get_cp_records_from_tps($tps);

        $items = [];

        foreach ($tpidsbyitem as $itemid => $itemtpids) {
            if (!isset($gradeitems[$itemid])) {
                continue;
            }

            $gradeitem = $gradeitems[$itemid];

            $item = (object)[
                'itemid' => (int)$itemid,
                'itemname' => trim((string)($gradeitem->itemname ?? '')),
                'grade' => array_key_exists($itemid, $gradebyitem) ? $gradebyitem[$itemid] : null,
                'tpid' => 0,
                'tp_texts' => [],
                'tp_text' => '',
            ];

            foreach ($itemtpids as $tpid) {
                if (!isset($tps[$tpid])) {
                    continue;
                }

                $tp = $tps[$tpid];
                $cpid = (int)($tp->id_capaian_pembelajaran ?? 0);
                $cp = ($cpid > 0 && isset($cps[$cpid])) ? $cps[$cpid] : null;

                if ($item->tpid <= 0) {
                    $item->tpid = (int)$tpid;
                }

                $tptext = self::build_tp_rapor_text($tp, $cp);

                if ($tptext !== '') {
                    $item->tp_texts[] = $tptext;
                }
            }

            $texts = array_values(array_unique(array_filter(array_map('trim', $item->tp_texts))));
            $item->tp_texts = $texts;
            $item->tp_text = $texts ? implode('; ', $texts) : '';

            $items[] = $item;
        }

        usort($items, static function($a, $b) use ($gradeitems) {
            $asort = isset($gradeitems[$a->itemid]) ? (int)$gradeitems[$a->itemid]->sortorder : 0;
            $bsort = isset($gradeitems[$b->itemid]) ? (int)$gradeitems[$b->itemid]->sortorder : 0;

            if ($asort === $bsort) {
                return ((int)$a->itemid) <=> ((int)$b->itemid);
            }

            return $asort <=> $bsort;
        });

        return $items;
    }

    private static function get_tp_records(array $tpids): array {
        global $DB;

        $tpids = array_values(array_unique(array_filter(array_map('intval', $tpids))));

        if (!$tpids) {
            return [];
        }

        [$tpinsql, $tpparams] = $DB->get_in_or_equal($tpids, SQL_PARAMS_NAMED, 'tp');

        $tpcolumns = $DB->get_columns('tujuan_pembelajaran');

        $tpfields = [
            'id',
            'id_capaian_pembelajaran',
        ];

        foreach (['konten', 'deskripsi', 'kompetensi', 'atp', 'dpl'] as $field) {
            if (isset($tpcolumns[$field])) {
                $tpfields[] = $field;
            }
        }

        return $DB->get_records_select(
            'tujuan_pembelajaran',
            "id {$tpinsql}",
            $tpparams,
            'id ASC',
            implode(', ', array_unique($tpfields))
        );
    }

    private static function get_cp_records_from_tps(array $tps): array {
        global $DB;

        $cpids = [];

        foreach ($tps as $tp) {
            $cpid = (int)($tp->id_capaian_pembelajaran ?? 0);

            if ($cpid > 0) {
                $cpids[$cpid] = $cpid;
            }
        }

        if (!$cpids) {
            return [];
        }

        [$cpinsql, $cpparams] = $DB->get_in_or_equal(array_values($cpids), SQL_PARAMS_NAMED, 'cp');

        $cpcolumns = $DB->get_columns('capaian_pembelajaran');
        $cpfields = ['id'];

        if (isset($cpcolumns['deskripsi'])) {
            $cpfields[] = 'deskripsi';
        }

        return $DB->get_records_select(
            'capaian_pembelajaran',
            "id {$cpinsql}",
            $cpparams,
            '',
            implode(', ', array_unique($cpfields))
        );
    }

    private static function build_tp_rapor_text(\stdClass $tp, ?\stdClass $cp = null): string {
        $kompetensi = self::normalize_tp_part((string)($tp->kompetensi ?? ''));
        $deskripsi = self::normalize_tp_part((string)($tp->deskripsi ?? ''));
        $konten = self::normalize_tp_part((string)($tp->konten ?? ''));
        $cpdeskripsi = $cp ? self::normalize_tp_part((string)($cp->deskripsi ?? '')) : '';

        if ($kompetensi !== '') {
            return self::combine_tp_kompetensi_and_konten($kompetensi, $konten);
        }

        if ($deskripsi !== '') {
            return self::combine_tp_kompetensi_and_konten($deskripsi, $konten);
        }

        if ($cpdeskripsi !== '') {
            return self::combine_tp_kompetensi_and_konten($cpdeskripsi, $konten);
        }

        if ($konten !== '') {
            return 'memahami dan menerapkan materi ' . $konten;
        }

        return '';
    }

    private static function combine_tp_kompetensi_and_konten(string $kompetensi, string $konten): string {
        $kompetensi = self::normalize_tp_part($kompetensi);
        $konten = self::normalize_tp_part($konten);

        if ($kompetensi === '') {
            return $konten !== '' ? 'memahami dan menerapkan materi ' . $konten : '';
        }

        if ($konten === '') {
            return $kompetensi;
        }

        if (self::text_contains_ignore_case($kompetensi, $konten)) {
            return $kompetensi;
        }

        if (self::is_short_kompetensi_text($kompetensi)) {
            return $kompetensi . ' materi ' . $konten;
        }

        return $kompetensi . ' pada materi ' . $konten;
    }

    private static function normalize_tp_part(string $text): string {
        $text = trim(strip_tags($text));

        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string)$text);
        $text = rtrim($text, ". \t\n\r\0\x0B");

        return self::lowercase_first_letter($text);
    }

    private static function lowercase_first_letter(string $text): string {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $first = \core_text::substr($text, 0, 1);
        $rest = \core_text::substr($text, 1);

        return \core_text::strtolower($first) . $rest;
    }

    private static function text_contains_ignore_case(string $haystack, string $needle): bool {
        $haystack = \core_text::strtolower(trim($haystack));
        $needle = \core_text::strtolower(trim($needle));

        if ($haystack === '' || $needle === '') {
            return false;
        }

        return \core_text::strpos($haystack, $needle) !== false;
    }

    private static function is_short_kompetensi_text(string $text): bool {
        $words = preg_split('/\s+/', trim($text));

        return count($words ?: []) <= 4;
    }

    private static function pick_extreme_sumatif(array $items, string $mode): ?\stdClass {
        $filtered = array_filter($items, static function($item) {
            return isset($item->grade) && $item->grade !== null;
        });

        if (!$filtered) {
            return null;
        }

        usort($filtered, static function($a, $b) use ($mode) {
            $gradea = (float)$a->grade;
            $gradeb = (float)$b->grade;

            if ($gradea === $gradeb) {
                return ((int)($a->tpid ?? 0)) <=> ((int)($b->tpid ?? 0));
            }

            return ($mode === 'highest')
                ? ($gradeb <=> $gradea)
                : ($gradea <=> $gradeb);
        });

        return $filtered[0] ?? null;
    }

    private static function get_display_mapel_name(\stdClass $course): string {
        $namamapel = trim((string)($course->nama_mapel ?? ''));
        $fullname = trim((string)($course->fullname ?? ''));
        $shortname = trim((string)($course->shortname ?? ''));

        if ($namamapel !== '') {
            return self::clean_mapel_name($namamapel);
        }

        if ($fullname !== '') {
            return self::clean_mapel_name($fullname);
        }

        if ($shortname !== '') {
            return self::clean_mapel_name($shortname);
        }

        return '-';
    }

    private static function extract_mapel_category(string $namamapel): array {
        $name = trim($namamapel);
        $rawcategory = 'lainnya';

        if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $name, $matches)) {
            $rawcategory = strtolower(trim((string)$matches[1]));
        }

        $key = preg_replace('/[^a-z0-9]+/', '_', $rawcategory);
        $key = trim((string)$key, '_');

        if ($key === '') {
            $key = 'lainnya';
        }

        if (in_array($key, ['umum', 'mata_pelajaran_umum'], true)) {
            return [
                'key' => 'umum',
                'label' => 'Kelompok Mata Pelajaran Umum',
            ];
        }

        if (in_array($key, ['muatan_lokal', 'lokal'], true)) {
            return [
                'key' => 'muatan_lokal',
                'label' => 'Muatan Lokal',
            ];
        }

        if (in_array($key, ['kejuruan', 'produktif'], true)) {
            return [
                'key' => 'kejuruan',
                'label' => 'Mata Pelajaran Kejuruan',
            ];
        }

        return [
            'key' => 'lainnya',
            'label' => 'Mata Pelajaran Lainnya',
        ];
    }

    private static function clean_mapel_name(string $namamapel): string {
        $name = trim($namamapel);

        if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $name, $matches)) {
            $name = trim((string)$matches[2]);
        }

        $parts = array_map('trim', explode(' - ', $name));

        if (count($parts) >= 2) {
            $name = $parts[0];
        }

        return $name !== '' ? $name : '-';
    }

    private static function format_grade(?float $grade): string {
        if ($grade === null) {
            return '-';
        }

        return (string)round($grade, 0);
    }

    private static function build_positive_competency_text(string $studentname, string $namamapel, ?\stdClass $item): string {
        if (!$item) {
            return 'Belum ada data capaian tertinggi.';
        }

        $tptext = self::clean_tp_text_for_rapor((string)($item->tp_text ?? ''));

        if ($tptext !== '') {
            return self::make_sentence('Menunjukkan ananda ' . $studentname . ' mampu ' . $tptext);
        }

        $itemname = trim((string)($item->itemname ?? ''));

        if ($itemname !== '') {
            return self::make_sentence(
                'Menunjukkan ananda ' . $studentname .
                ' mampu menunjukkan capaian terbaik pada ' . $itemname .
                ' mata pelajaran ' . $namamapel
            );
        }

        return 'Belum ada data capaian tertinggi.';
    }

    private static function build_negative_competency_text(string $studentname, string $namamapel, ?\stdClass $item): string {
        if (!$item) {
            return 'Belum ada data yang perlu ditingkatkan.';
        }

        $tptext = self::clean_tp_text_for_rapor((string)($item->tp_text ?? ''));

        if ($tptext !== '') {
            return self::make_sentence('Ananda ' . $studentname . ' perlu bimbingan dalam ' . $tptext);
        }

        $itemname = trim((string)($item->itemname ?? ''));

        if ($itemname !== '') {
            return self::make_sentence(
                'Ananda ' . $studentname .
                ' perlu bimbingan pada ' . $itemname .
                ' mata pelajaran ' . $namamapel
            );
        }

        return 'Belum ada data yang perlu ditingkatkan.';
    }

    private static function clean_tp_text_for_rapor(string $text): string {
        $text = trim(strip_tags($text));

        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string)$text);

        $patterns = [
            '/^peserta\s+didik\s+mampu\s+/iu',
            '/^siswa\s+mampu\s+/iu',
            '/^murid\s+mampu\s+/iu',
            '/^ananda\s+[^\s]+\s+mampu\s+/iu',
            '/^mampu\s+/iu',
            '/^dapat\s+/iu',
            '/^menunjukkan\s+kemampuan\s+dalam\s+/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
            $text = trim((string)$text);
        }

        return rtrim($text, ". \t\n\r\0\x0B");
    }

    private static function make_sentence(string $text): string {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string)$text);

        if ($text === '') {
            return '';
        }

        $lastchar = substr($text, -1);

        if (!in_array($lastchar, ['.', '!', '?'], true)) {
            $text .= '.';
        }

        return $text;
    }

    public static function get_catatan(int $userid, int $kelasid, int $semester) {
        global $DB;

        return $DB->get_record('rapor_catatan_akademik', [
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
            'semester' => $semester,
        ]);
    }

    public static function save_catatan(int $userid, int $kelasid, int $semester, string $catatan, int $waliid): void {
        global $DB;

        $existing = self::get_catatan($userid, $kelasid, $semester);

        if ($existing) {
            $existing->catatan = $catatan;
            $existing->timemodified = time();
            $DB->update_record('rapor_catatan_akademik', $existing);
            return;
        }

        $now = time();

        $DB->insert_record('rapor_catatan_akademik', (object)[
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
            'semester' => $semester,
            'catatan' => $catatan,
            'id_wali_kelas' => $waliid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function save_kokurikuler(int $userid, int $kelasid, int $semester, string $kokurikuler, int $waliid): void {
        global $DB;

        $existing = self::get_catatan($userid, $kelasid, $semester);

        if ($existing) {
            $existing->kokurikuler = $kokurikuler;
            $existing->id_wali_kelas = $waliid;
            $existing->timemodified = time();

            $DB->update_record('rapor_catatan_akademik', $existing);
            return;
        }

        $now = time();

        $record = new \stdClass();
        $record->id_siswa = $userid;
        $record->id_kelas = $kelasid;
        $record->semester = $semester;
        $record->catatan = null;
        $record->kokurikuler = $kokurikuler;
        $record->id_wali_kelas = $waliid;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $DB->insert_record('rapor_catatan_akademik', $record);
    }
    public static function get_kenaikan_kelas(int $userid, int $kelasid) {
        global $DB;

        return $DB->get_record('rapor_kenaikan_kelas', [
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
        ]);
    }

    public static function save_kenaikan_kelas(int $userid, int $kelasid, string $keputusan, int $waliid): void {
        global $DB;

        $existing = self::get_kenaikan_kelas($userid, $kelasid);

        if ($existing) {
            $existing->keputusan = $keputusan;
            $existing->timemodified = time();
            $DB->update_record('rapor_kenaikan_kelas', $existing);
            return;
        }

        $now = time();

        $DB->insert_record('rapor_kenaikan_kelas', (object)[
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
            'keputusan' => $keputusan,
            'id_penginput' => $waliid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

private static function get_manual_ketidakhadiran(int $userid, int $kelasid, int $semester) {
    global $DB;

    /*
     * Fungsi ini khusus membaca data manual wali kelas dari tabel plugin.
     *
     * Kenapa dipisah?
     * Karena get_ketidakhadiran() nanti bisa mengambil data otomatis dari
     * plugin Attendance Moodle. Saat wali kelas klik Simpan, yang boleh
     * di-update hanya data manual di tabel rapor_ketidakhadiran.
     */
    $manual = $DB->get_record('rapor_ketidakhadiran', [
        'id_siswa' => $userid,
        'id_kelas' => $kelasid,
        'semester' => $semester,
    ], '*', IGNORE_MISSING);

    if ($manual) {
        $manual->source = 'manual';
        $manual->isauto = false;
        return $manual;
    }

    return null;
}

private static function get_ketidakhadiran_from_attendance_daily(
    int $userid,
    int $groupid,
    int $semester,
    int $tahunajaranid = 0
): ?\stdClass {
    global $DB;

    if ($userid <= 0 || $groupid <= 0 || !in_array($semester, [1, 2], true)) {
        return null;
    }

    /*
     * Di halaman detail rapor, $kelasid biasanya adalah ID group Moodle.
     *
     * Sedangkan course hasil generate Akademik Monitoring memakai ID kelas
     * custom dari tabel {kelas} di idnumber course.
     *
     * Contoh:
     * - URL detail rapor membawa kelasid=15
     * - 15 itu ID group Moodle
     * - course idnumber berbentuk AM-TA2-K6-KM83-S1
     * - berarti ID kelas custom yang harus dicari adalah 6
     *
     * Karena itu groupid dikonversi dulu menjadi custom kelas id.
     */
    $customkelasid = self::get_custom_kelasid_from_generated_groupid($groupid);

    if ($customkelasid <= 0) {
        $customkelasid = $groupid;
    }

    /*
     * Cek apakah plugin Attendance sudah terpasang.
     *
     * Kalau tabel-tabel ini tidak ada, berarti fitur otomatis tidak bisa
     * dipakai dan sistem akan fallback ke manual/kosong.
     */
    $dbman = $DB->get_manager();

foreach (['attendance', 'attendance_sessions', 'attendance_log', 'attendance_statuses'] as $table) {
    if (!$dbman->table_exists(new \xmldb_table($table))) {
        return null;
    }
}

    /*
     * Ambil semua course hasil generate sesuai kelas custom, semester,
     * dan tahun ajaran.
     *
     * Ini penting supaya presensi dari course tahun ajaran/semester lain
     * tidak ikut terhitung.
     */
    $courses = self::get_generated_attendance_courses_by_kelas_period(
        $customkelasid,
        $semester,
        $tahunajaranid
    );

    if (!$courses) {
        return null;
    }

    $courseids = array_map('intval', array_keys($courses));

    if (!$courseids) {
        return null;
    }

    [$courseinsql, $courseparams] = $DB->get_in_or_equal(
        $courseids,
        SQL_PARAMS_NAMED,
        'courseid'
    );

    /*
     * Ambil log presensi siswa dari plugin Attendance Moodle.
     *
     * Relasi tabel Attendance:
     * - attendance.course = course.id
     * - attendance_sessions.attendanceid = attendance.id
     * - attendance_log.sessionid = attendance_sessions.id
     * - attendance_log.statusid = attendance_statuses.id
     */
    $sql = "SELECT al.id,
                   ats.sessdate,
                   ast.acronym,
                   ast.description
              FROM {attendance_log} al
              JOIN {attendance_sessions} ats ON ats.id = al.sessionid
              JOIN {attendance} a ON a.id = ats.attendanceid
              JOIN {attendance_statuses} ast ON ast.id = al.statusid
             WHERE al.studentid = :userid
               AND a.course {$courseinsql}
          ORDER BY ats.sessdate ASC, al.id ASC";

    $params = array_merge($courseparams, [
        'userid' => $userid,
    ]);

    $logs = $DB->get_records_sql($sql, $params);

    if (!$logs) {
        return null;
    }

    /*
     * Hitung per tanggal, bukan per course.
     *
     * Contoh:
     * 2026-05-05 Matematika = Alfa
     * 2026-05-05 Bahasa Indonesia = Alfa
     *
     * Di rapor tetap dihitung:
     * Alfa = 1
     *
     * Bukan:
     * Alfa = 2
     */
    $perday = [];

    foreach ($logs as $log) {
        $datekey = userdate((int)$log->sessdate, '%Y-%m-%d');

        $status = self::normalize_attendance_status_for_rapor(
            (string)($log->acronym ?? ''),
            (string)($log->description ?? '')
        );

        if (!isset($perday[$datekey])) {
            $perday[$datekey] = $status;
            continue;
        }

        /*
         * Kalau dalam satu hari ada beberapa status, ambil status terkuat.
         *
         * Prioritas:
         * Alfa > Izin > Sakit > Hadir
         */
        $perday[$datekey] = self::pick_stronger_absence_status(
            $perday[$datekey],
            $status
        );
    }

    $result = new \stdClass();
    $result->sakit = 0;
    $result->izin = 0;
    $result->alfa = 0;
    $result->source = 'attendance_daily';
    $result->isauto = true;

    foreach ($perday as $status) {
        if ($status === 'sakit') {
            $result->sakit++;
        } else if ($status === 'izin') {
            $result->izin++;
        } else if ($status === 'alfa') {
            $result->alfa++;
        }
    }

    return $result;
}

private static function normalize_attendance_status_for_rapor(string $acronym, string $description): string {
    $text = \core_text::strtolower(trim($acronym . ' ' . $description));

    /*
     * Mapping Attendance Moodle ke format rapor:
     * Present / P / Hadir      => hadir
     * Late / L / Terlambat     => hadir
     * Excused / E / Izin       => izin
     * Sick / S / Sakit         => sakit
     * Absent / A / Alfa        => alfa
     */

    if (preg_match('/\b(s|sakit|sick)\b/u', $text)) {
        return 'sakit';
    }

    if (preg_match('/\b(e|excused|izin|ijin|permission)\b/u', $text)) {
        return 'izin';
    }

    if (preg_match('/\b(a|alfa|alpha|absent|tidak hadir|tanpa keterangan)\b/u', $text)) {
        return 'alfa';
    }

    return 'hadir';
}

private static function pick_stronger_absence_status(string $current, string $new): string {
    /*
     * Kalau dalam satu hari ada beberapa presensi dari beberapa mapel,
     * ambil status yang paling kuat.
     *
     * Contoh:
     * Hadir + Alfa => Alfa
     * Hadir + Izin => Izin
     * Sakit + Alfa => Alfa
     */
    $priority = [
        'hadir' => 0,
        'sakit' => 1,
        'izin' => 2,
        'alfa' => 3,
    ];

    $currentvalue = $priority[$current] ?? 0;
    $newvalue = $priority[$new] ?? 0;

    return ($newvalue > $currentvalue) ? $new : $current;
}

private static function get_generated_attendance_courses_by_kelas_period(
    int $kelasid,
    int $semester,
    int $tahunajaranid = 0
): array {
    global $DB;

    if ($kelasid <= 0 || !in_array($semester, [1, 2], true)) {
        return [];
    }

    $conditions = [];
    $params = [];

    /*
     * Format baru dengan KM:
     * AM-TA{id_tahunajaran}-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
     *
     * Contoh:
     * AM-TA2-K6-KM83-S1
     */
    if ($tahunajaranid > 0) {
        $conditions[] = $DB->sql_like('idnumber', ':newpatternkm', false, false);
        $params['newpatternkm'] = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S' . $semester;
    } else {
        $conditions[] = $DB->sql_like('idnumber', ':newpatternkm', false, false);
        $params['newpatternkm'] = 'AM-TA%-K' . $kelasid . '-KM%-S' . $semester;
    }

    /*
     * Format lama dengan KM:
     * AM-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
     *
     * Contoh:
     * AM-K6-KM83-S1
     */
    $conditions[] = $DB->sql_like('idnumber', ':oldpatternkm', false, false);
    $params['oldpatternkm'] = 'AM-K' . $kelasid . '-KM%-S' . $semester;

    /*
     * Jaga-jaga kalau nanti kamu punya course absensi khusus tanpa KM.
     *
     * Format baru tanpa KM:
     * AM-TA{id_tahunajaran}-K{id_kelas}-S{semester}
     *
     * Format lama tanpa KM:
     * AM-K{id_kelas}-S{semester}
     */
    if ($tahunajaranid > 0) {
        $conditions[] = $DB->sql_like('idnumber', ':newpatternnokm', false, false);
        $params['newpatternnokm'] = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-S' . $semester;
    } else {
        $conditions[] = $DB->sql_like('idnumber', ':newpatternnokm', false, false);
        $params['newpatternnokm'] = 'AM-TA%-K' . $kelasid . '-S' . $semester;
    }

    $conditions[] = $DB->sql_like('idnumber', ':oldpatternnokm', false, false);
    $params['oldpatternnokm'] = 'AM-K' . $kelasid . '-S' . $semester;

    return $DB->get_records_select(
        'course',
        '(' . implode(' OR ', $conditions) . ')',
        $params,
        'fullname ASC, id ASC',
        'id, fullname, shortname, idnumber'
    );
}

public static function get_ketidakhadiran(
    int $userid,
    int $kelasid,
    int $semester,
    int $tahunajaranid = 0
) {
    /*
     * Prioritas 1:
     * Data manual wali kelas.
     *
     * Kalau wali kelas sudah pernah edit dan simpan manual, data manual ini
     * yang dipakai agar input wali kelas tidak tertimpa data otomatis.
     */
    $manual = self::get_manual_ketidakhadiran($userid, $kelasid, $semester);

    if ($manual) {
        return $manual;
    }

    /*
     * Prioritas 2:
     * Data otomatis dari plugin Attendance Moodle.
     *
     * Ini hanya dipakai kalau belum ada data manual.
     */
    $auto = self::get_ketidakhadiran_from_attendance_daily(
        $userid,
        $kelasid,
        $semester,
        $tahunajaranid
    );

    if ($auto !== null) {
        return $auto;
    }

    /*
     * Prioritas 3:
     * Kosong.
     */
    return (object)[
        'sakit' => 0,
        'izin' => 0,
        'alfa' => 0,
        'source' => 'empty',
        'isauto' => false,
    ];
}

public static function save_ketidakhadiran(
    int $userid,
    int $kelasid,
    int $semester,
    int $sakit,
    int $izin,
    int $alfa,
    int $waliid
): void {
    global $DB;

    /*
     * Simpan manual hanya mengecek tabel manual.
     *
     * Jangan pakai get_ketidakhadiran() di sini, karena function itu bisa
     * mengembalikan data otomatis dari Attendance Moodle. Kalau data otomatis
     * dianggap existing record, nanti update_record akan salah karena data
     * otomatis tidak berasal dari tabel rapor_ketidakhadiran.
     */
    $existing = self::get_manual_ketidakhadiran($userid, $kelasid, $semester);

    if ($existing) {
        $existing->sakit = $sakit;
        $existing->izin = $izin;
        $existing->alfa = $alfa;
        $existing->id_penginput = $waliid;
        $existing->timemodified = time();

        $DB->update_record('rapor_ketidakhadiran', $existing);
        return;
    }

    $now = time();

    $DB->insert_record('rapor_ketidakhadiran', (object)[
        'id_siswa' => $userid,
        'id_kelas' => $kelasid,
        'semester' => $semester,
        'sakit' => $sakit,
        'izin' => $izin,
        'alfa' => $alfa,
        'id_penginput' => $waliid,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
}
public static function reset_ketidakhadiran_manual(
    int $userid,
    int $kelasid,
    int $semester
): void {
    global $DB;

    if ($userid <= 0 || $kelasid <= 0 || $semester <= 0) {
        throw new \exception('Data reset ketidakhadiran tidak valid.');
    }

    $DB->delete_records('rapor_ketidakhadiran', [
        'id_siswa' => $userid,
        'id_kelas' => $kelasid,
        'semester' => $semester,
    ]);
}
    public static function format_tanggal_indo(?string $tanggal): string {
        if (empty($tanggal)) {
            return '-';
        }

        $timestamp = strtotime($tanggal);

        if (!$timestamp) {
            return '-';
        }

        $bulan = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        return date('j', $timestamp) . ' ' . $bulan[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }

private static function clean_data_value($value, string $default = '-'): string {
    if ($value === null || $value === false) {
        return $default;
    }

    $value = trim((string)$value);

    return $value !== '' ? $value : $default;
}

private static function get_profile_or_user_value(
    \stdClass $user,
    \stdClass $profile,
    array $profilefields,
    array $userfields = [],
    string $default = '-'
): string {
    /*
     * Custom profile field Moodle akan menjadi property object dari
     * profile_user_record($userid).
     *
     * Masalahnya, property PHP case-sensitive.
     * Jadi:
     *   $profile->Alamat_Peserta_Didik
     * tidak sama dengan:
     *   $profile->alamat_peserta_didik
     *
     * Karena itu kita buat index lowercase agar shortname custom field
     * tetap terbaca walaupun di Moodle ditulis huruf besar/kecil campur.
     */
    $profilemap = [];

    foreach ((array)$profile as $key => $value) {
        $lowerkey = \core_text::strtolower(trim((string)$key));
        $profilemap[$lowerkey] = $value;
    }

    foreach ($profilefields as $field) {
        $field = trim((string)$field);

        if ($field === '') {
            continue;
        }

        $lowerfield = \core_text::strtolower($field);

        if (array_key_exists($lowerfield, $profilemap)) {
            $value = trim((string)$profilemap[$lowerfield]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    /*
     * Core user field Moodle seperti address, phone1, phone2, city
     * umumnya lowercase. Tapi tetap dibuat case-insensitive supaya aman.
     */
    $usermap = [];

    foreach ((array)$user as $key => $value) {
        $lowerkey = \core_text::strtolower(trim((string)$key));
        $usermap[$lowerkey] = $value;
    }

    foreach ($userfields as $field) {
        $field = trim((string)$field);

        if ($field === '') {
            continue;
        }

        $lowerfield = \core_text::strtolower($field);

        if (array_key_exists($lowerfield, $usermap)) {
            $value = trim((string)$usermap[$lowerfield]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return $default;
}

private static function format_profile_date_value(string $value): string {
    $value = trim($value);

    if ($value === '' || $value === '-') {
        return '-';
    }

    /*
     * Custom profile field tanggal di Moodle kadang tersimpan sebagai:
     * 1. teks tanggal: 2025-07-14
     * 2. timestamp Unix: 1752451200
     * 3. format Indonesia: 14/07/2025
     *
     * Jadi jangan hanya mengandalkan strtotime biasa.
     */

    // Jika angka panjang, anggap sebagai Unix timestamp Moodle.
    if (preg_match('/^\d{9,11}$/', $value)) {
        $timestamp = (int)$value;

        if ($timestamp > 0) {
            return self::format_tanggal_indo(date('Y-m-d', $timestamp));
        }
    }

    // Jika format dd/mm/yyyy atau dd-mm-yyyy.
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];

        if (checkdate($month, $day, $year)) {
            return self::format_tanggal_indo(sprintf('%04d-%02d-%02d', $year, $month, $day));
        }
    }

    // Jika format yyyy-mm-dd atau format lain yang bisa dibaca strtotime.
    return self::format_tanggal_indo($value);
}

private static function get_datadiri_array(\stdClass $user, \stdClass $profile): array {
    $tempatlahir = self::get_profile_or_user_value(
        $user,
        $profile,
        ['tempat_lahir', 'tempatlahir', 'tempat'],
        ['city'],
        '-'
    );

    $tanggallahirraw = self::get_profile_or_user_value(
        $user,
        $profile,
        ['tanggal_lahir', 'tanggallahir', 'tgl_lahir', 'tanggal'],
        [],
        '-'
    );

    return [
        'nama' => fullname($user),

        'nisn' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['nisn', 'NISN'],
            ['idnumber'],
            '-'
        ),

        'nis' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['nis', 'NIS', 'nomor_induk_siswa'],
            [],
            '-'
        ),

        'tempatlahir' => $tempatlahir,

        'tanggal_lahir' => self::format_profile_date_value($tanggallahirraw),

        'jk' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['jenis_kelamin', 'jeniskelamin', 'jk', 'gender'],
            [],
            '-'
        ),

        'agama' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['agama', 'religion'],
            [],
            '-'
        ),

        'status' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['status_dalam_keluarga', 'status_keluarga', 'status'],
            [],
            '-'
        ),

        'anak_ke' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['anak_ke', 'anakke'],
            [],
            '-'
        ),

        'alamat_peserta_didik' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['alamat_peserta_didik', 'alamat_siswa', 'alamat', 'alamat_peserta'],
            ['address'],
            '-'
        ),

        'telepon_rumah' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['telepon_rumah', 'telp_rumah', 'telepon', 'no_hp', 'nomor_hp', 'hp'],
            ['phone1', 'phone2'],
            '-'
        ),

        'sekolah_asal' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['sekolah_asal', 'asal_sekolah'],
            ['institution'],
            '-'
        ),

        'diterima_di_kelas' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['diterima_di_kelas', 'diterima_kelas', 'kelas_diterima'],
            [],
            '-'
        ),

        'tanggal_diterima' => self::format_profile_date_value(
            self::get_profile_or_user_value(
                $user,
                $profile,
                [
                    'tanggal_diterima',
                    'tgl_diterima',
                    'diterima_tanggal',
                    'tanggal_masuk',
                    'tgl_masuk',
                    'pada_tanggal',
                    'diterima_pada_tanggal',
                    'tanggal_diterima_di_sekolah',
                    'Tanggal_Diterima',
                    'TANGGAL_DITERIMA'
                ],
                [],
                '-'
            )
        ),

        'nama_ayah' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['nama_ayah', 'ayah'],
            [],
            '-'
        ),

        'nama_ibu' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['nama_ibu', 'ibu'],
            [],
            '-'
        ),

        'alamat_orang_tua' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['alamat_orang_tua', 'alamat_ortu', 'alamat_wali_murid'],
            [],
            '-'
        ),

        'telepon_orang_tua' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['telepon_orang_tua', 'telp_orang_tua', 'telepon_ortu', 'no_hp_ortu'],
            [],
            '-'
        ),

        'pekerjaan_ayah' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['pekerjaan_ayah', 'kerja_ayah'],
            [],
            '-'
        ),

        'pekerjaan_ibu' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['pekerjaan_ibu', 'kerja_ibu'],
            [],
            '-'
        ),

        'nama_wali' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['nama_wali', 'wali'],
            [],
            '-'
        ),

        'alamat_wali' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['alamat_wali'],
            [],
            '-'
        ),

        'telepon_wali' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['telepon_wali', 'telp_wali', 'no_hp_wali'],
            [],
            '-'
        ),

        'pekerjaan_wali' => self::get_profile_or_user_value(
            $user,
            $profile,
            ['pekerjaan_wali', 'kerja_wali'],
            [],
            '-'
        ),
    ];
}

    public static function get_detail_page_data(int $userid, int $semester = 1, int $tahunajaranid = 0, int $kelasidparam = 0): array {
        global $DB, $USER;

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $profile = profile_user_record($userid);

        $groupid = 0;

        if ($kelasidparam > 0 && $DB->record_exists('groups_members', ['groupid' => $kelasidparam, 'userid' => $userid])) {
            $groupid = $kelasidparam;
        }

        if ($groupid <= 0) {
            $groupid = self::get_primary_groupid_by_student($userid, (int)($USER->id ?? 0));
        }

        if ($groupid <= 0) {
            throw new \exception('Siswa belum terdaftar pada kelas/grup wali kelas ini');
        }

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, name, courseid', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => (int)$group->courseid], 'id, fullname, shortname, idnumber', IGNORE_MISSING);

        if (!$course) {
            $course = self::get_student_course($userid);
        }

        if (!$course) {
            throw new \exception('Siswa belum terdaftar pada course mana pun');
        }

        // $kelasid = $groupid;

        // $ekskul = ekskul_service::get_ekskul_siswa($userid, $kelasid, $semester);
        // $showpkl = common_service::is_group_kelas_xii($kelasid);
        // $pkl = $showpkl ? pkl_service::get_pkl_siswa($userid, $kelasid, $semester) : [];
/*
 * groupid = ID group Moodle.
 */
$groupid = (int)$group->id;

/*
 * customkelasid = ID kelas pada tabel {kelas}.
 */
$customkelasid = self::get_custom_kelasid_from_generated_groupid($groupid);

/*
 * Ekskul dan PKL masih menggunakan group Moodle.
 */
$ekskul = ekskul_service::get_ekskul_siswa(
    $userid,
    $groupid,
    $semester
);

$showpkl = common_service::is_group_kelas_xii($groupid);

$pkl = $showpkl
    ? pkl_service::get_pkl_siswa(
        $userid,
        $groupid,
        $semester
    )
    : [];

        foreach ($pkl as $index => $item) {
            $item->no = $index + 1;
        }

        foreach ($ekskul as $index => $item) {
            $item->no = $index + 1;
            $item->keterangan = ekskul_service::get_keterangan_predikat((string)$item->predikat);
        }

        $nilaiakademik = self::get_nilai_akademik_detail($userid, $groupid, $semester);

$catatan = self::get_catatan(
    $userid,
    $customkelasid,
    $semester
);

$keputusan = self::get_kenaikan_kelas(
    $userid,
    $customkelasid
);

$ketidakhadiran = self::get_ketidakhadiran(
    $userid,
    $customkelasid,
    $semester,
    $tahunajaranid
);

        $template = common_service::get_sidebar_data('rapor', (int)($USER->id ?? 0), $tahunajaranid);
        $datadiri = self::get_datadiri_array($user, $profile);
        $template += $datadiri + [
            'ekskul' => array_values($ekskul),
            'show_pkl' => $showpkl,
            'pkl' => array_values($pkl),
            'nilai_akademik' => array_values($nilaiakademik),
            'has_nilai_akademik' => !empty($nilaiakademik),
            'empty_nilai_akademik' => empty($nilaiakademik),
            'catatan' => $catatan->catatan ?? 'Belum ada catatan',
            'kokurikuler' => $catatan->kokurikuler ?? 'Belum ada catatan kokurikuler',
            'keputusan' => $keputusan->keputusan ?? 'Belum ada keputusan',
            'sakit' => $ketidakhadiran->sakit ?? 0,
            'izin' => $ketidakhadiran->izin ?? 0,
            'alfa' => $ketidakhadiran->alfa ?? 0,
            'ketidakhadiran_auto' => (($ketidakhadiran->source ?? '') === 'attendance_daily'),
            'ketidakhadiran_manual' => (($ketidakhadiran->source ?? '') === 'manual'),
            'ketidakhadiran_empty' => (($ketidakhadiran->source ?? '') === 'empty'),
            'reset_ketidakhadiran_url' => (new \moodle_url(
                '/local/akademikmonitor/pages/walikelas/rapor/detail.php',
                period_filter_service::append_filter_params([
                    'userid' => $userid,
                    'kelasid' => $groupid,
                ])
            ))->out(false),

            'sesskey' => sesskey(),
            'semester' => $semester,
            'semester_label' => period_filter_service::get_semester_label($semester),
            'tahunajaranid' => $tahunajaranid,
            'tahunajaran_label' => period_filter_service::get_tahunajaran_label($tahunajaranid),
            'exportexcel' => (new \moodle_url(
                '/local/akademikmonitor/pages/walikelas/rapor/export_excel.php',
                period_filter_service::append_filter_params([
                    'userid' => $userid,
                    'kelasid' => $groupid,
                    'sesskey' => sesskey(),
                    't' => time(),
                ])
            ))->out(false),
            'exportpdf' => (new \moodle_url(
                '/local/akademikmonitor/pages/walikelas/rapor/export_pdf.php',
                period_filter_service::append_filter_params([
                    'userid' => $userid,
                    'kelasid' => $groupid,
                    'sesskey' => sesskey(),
                    't' => time(),
                ])
            ))->out(false),
        ];
        $template += period_filter_service::build_filter_data();
        $template['caneditperiod'] = period_filter_service::can_edit_tahunajaran($tahunajaranid);
        $template['readonlyperiod'] = !$template['caneditperiod'];
        return [
            'course' => $course,
            'group' => $group,
            'kelasid' => $customkelasid,
            'template' => $template,
        ];
    }

private static function get_custom_kelasid_from_generated_groupid(int $groupid): int {
    global $DB;

    if ($groupid <= 0) {
        return 0;
    }

    $group = $DB->get_record(
        'groups',
        ['id' => $groupid],
        'id, courseid',
        IGNORE_MISSING
    );

    if (!$group || empty($group->courseid)) {
        return 0;
    }

    $course = $DB->get_record(
        'course',
        ['id' => (int)$group->courseid],
        'id, idnumber',
        IGNORE_MISSING
    );

    if (!$course || empty($course->idnumber)) {
        return 0;
    }

    $idnumber = trim((string)$course->idnumber);

    /*
     * Mendukung format dengan KM:
     * - AM-TA2-K6-KM83-S1
     * - AM-K6-KM83-S1
     *
     * Mendukung format tanpa KM:
     * - AM-TA2-K6-S1
     * - AM-K6-S1
     *
     * Yang diambil adalah angka setelah K, yaitu ID kelas custom.
     */
    if (preg_match('/^AM-(?:TA\d+-)?K(\d+)(?:-KM\d+)?-S([12])$/', $idnumber, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

    private static function get_kelas_rapor_identity(int $groupid, int $tahunajaranid = 0): array {
        global $DB;

        $customkelasid = self::get_custom_kelasid_from_generated_groupid($groupid);

        if ($customkelasid <= 0) {
            return self::empty_kelas_identity($tahunajaranid);
        }

        $kelas = $DB->get_record(
            'kelas',
            ['id' => $customkelasid],
            'id, nama, tingkat, id_user, id_tahun_ajaran, id_jurusan',
            IGNORE_MISSING
        );

        if (!$kelas) {
            return self::empty_kelas_identity($tahunajaranid, $customkelasid);
        }

        $jurusan = null;

        if (!empty($kelas->id_jurusan)) {
            $jurusan = $DB->get_record(
                'jurusan',
                ['id' => (int)$kelas->id_jurusan],
                'id, nama_jurusan',
                IGNORE_MISSING
            );
        }

        $tahun = null;

        if (!empty($kelas->id_tahun_ajaran)) {
            $tahun = $DB->get_record(
                'tahun_ajaran',
                ['id' => (int)$kelas->id_tahun_ajaran],
                'id, tahun_ajaran',
                IGNORE_MISSING
            );
        }

        $tingkat = trim((string)($kelas->tingkat ?? ''));
        $jurusanname = $jurusan ? trim((string)($jurusan->nama_jurusan ?? '')) : '';
        $fase = self::get_fase_by_tingkat($tingkat);

        if ($tahun && !empty($tahun->tahun_ajaran)) {
            $tahunpelajaran = (string)$tahun->tahun_ajaran;
        } else if ($tahunajaranid > 0) {
            $tahunpelajaran = period_filter_service::get_tahunajaran_label($tahunajaranid);
        } else {
            $tahunpelajaran = '-';
        }

        return [
            'custom_kelasid' => (int)$kelas->id,
            'program_keahlian' => $jurusanname !== '' ? $jurusanname : '-',
            'kelas_label' => self::build_kelas_label($kelas, $jurusan),
            'tingkat' => $tingkat !== '' ? $tingkat : '-',
            'fase' => $fase,
            'tahun_pelajaran' => $tahunpelajaran,
            'wali_kelas_id' => (int)($kelas->id_user ?? 0),
        ];
    }

    private static function empty_kelas_identity(int $tahunajaranid = 0, int $customkelasid = 0): array {
        return [
            'custom_kelasid' => $customkelasid,
            'program_keahlian' => '-',
            'kelas_label' => '-',
            'tingkat' => '-',
            'fase' => '-',
            'tahun_pelajaran' => period_filter_service::get_tahunajaran_label($tahunajaranid),
            'wali_kelas_id' => 0,
        ];
    }

    private static function get_fase_by_tingkat(string $tingkat): string {
        if ($tingkat === 'X') {
            return 'E';
        }

        if ($tingkat === 'XI' || $tingkat === 'XII') {
            return 'F';
        }

        return '-';
    }

    private static function build_kelas_label(?\stdClass $kelas, ?\stdClass $jurusan = null): string {
        if (!$kelas) {
            return '-';
        }

        $tingkat = preg_replace('/\s+/', ' ', trim((string)($kelas->tingkat ?? '')));
        $namakelas = preg_replace('/\s+/', ' ', trim((string)($kelas->nama ?? '')));
        $jurusanname = $jurusan ? preg_replace('/\s+/', ' ', trim((string)($jurusan->nama_jurusan ?? ''))) : '';

        $lowernama = \core_text::strtolower($namakelas);
        $lowertingkat = \core_text::strtolower($tingkat);
        $lowerjurusan = \core_text::strtolower($jurusanname);

        if (
            $namakelas !== ''
            && $tingkat !== ''
            && $jurusanname !== ''
            && \core_text::strpos($lowernama, $lowertingkat) !== false
            && \core_text::strpos($lowernama, $lowerjurusan) !== false
        ) {
            return $namakelas;
        }

        $parts = [];

        if ($tingkat !== '') {
            $parts[] = $tingkat;
        }

        if ($jurusanname !== '') {
            $parts[] = $jurusanname;
        }

        if ($namakelas !== '') {
            $parts[] = $namakelas;
        }

        $label = preg_replace('/\s+/', ' ', trim(implode(' ', $parts)));

        return $label !== '' ? $label : '-';
    }

    public static function get_export_data(int $userid, int $groupid, int $semester = 1, int $tahunajaranid = 0): array {
        global $DB, $USER;

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $profile = profile_user_record($userid);
        $customkelasid =
            self::get_custom_kelasid_from_generated_groupid($groupid);
        $course = self::get_student_course($userid);
        $kelasrapor = self::get_kelas_rapor_identity($groupid, $tahunajaranid);

        $walikelasid = !empty($kelasrapor['wali_kelas_id'])
            ? (int)$kelasrapor['wali_kelas_id']
            : (int)($USER->id ?? 0);

        $showpkl = common_service::is_group_kelas_xii($groupid);

        return [
            'user' => $user,
            'profile' => $profile,
            'course' => $course,
            'jurusan' => $kelasrapor['program_keahlian'],
            'kelas_rapor' => $kelasrapor,
            'semester' => $semester,
            'semester_label' => period_filter_service::get_semester_label($semester),
            'tahunajaranid' => $tahunajaranid,
            'tahunajaran_label' => $kelasrapor['tahun_pelajaran'],
            'datadiri' => self::get_datadiri_array($user, $profile),
            'nilai_akademik' => self::get_nilai_akademik_detail($userid, $groupid, $semester),
'ringkasan_ranking' => self::get_student_ranking_summary(
    $userid,
    $groupid,
    $walikelasid,
    $semester,
    $tahunajaranid,
    $customkelasid
),
            'show_pkl' => $showpkl,
            'pkl' => $showpkl ? pkl_service::get_pkl_siswa($userid, $groupid, $semester) : [],
            'ekskul' => ekskul_service::get_ekskul_siswa($userid, $groupid, $semester),
            'absen' => self::get_ketidakhadiran($userid, $customkelasid, $semester, $tahunajaranid),
            'catatan' => self::get_catatan($userid, $customkelasid, $semester),
            'keputusan' => self::get_kenaikan_kelas($userid, $customkelasid),
            'walikelas' => self::get_walikelas_signature_data($walikelasid),
        ];
    }

    private static function get_profile_field_value(int $userid, array $shortnames, string $default = '-'): string {
        global $DB;

        if ($userid <= 0 || empty($shortnames)) {
            return $default;
        }

        foreach ($shortnames as $shortname) {
            $shortname = trim((string)$shortname);

            if ($shortname === '') {
                continue;
            }

            $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id', IGNORE_MISSING);

            if (!$field) {
                continue;
            }

            $value = $DB->get_field('user_info_data', 'data', [
                'userid' => $userid,
                'fieldid' => (int)$field->id,
            ], IGNORE_MISSING);

            if ($value !== false && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return $default;
    }

    public static function get_walikelas_signature_data(int $waliuserid): array {
        global $DB;

        if ($waliuserid <= 0) {
            return [
                'nama' => '-',
                'npa' => '-',
            ];
        }

        $user = $DB->get_record('user', ['id' => $waliuserid], '*', IGNORE_MISSING);

        if (!$user) {
            return [
                'nama' => '-',
                'npa' => '-',
            ];
        }

        $npa = self::get_profile_field_value($waliuserid, ['npa', 'nip', 'nuptk'], '');

        if ($npa === '' && !empty($user->idnumber)) {
            $npa = trim((string)$user->idnumber);
        }

        return [
            'nama' => fullname($user),
            'npa' => ($npa !== '' ? $npa : '-'),
        ];
    }

    private static function get_jurusan_by_courseid(int $courseid): string {
        global $DB;

        if ($courseid <= 0) {
            return '-';
        }

        $cm = $DB->get_record(
            'course_mapel',
            ['id_course' => $courseid],
            'id_course, id_kurikulum_mapel',
            IGNORE_MISSING
        );

        if (!$cm || empty($cm->id_kurikulum_mapel)) {
            return '-';
        }

        $km = $DB->get_record(
            'kurikulum_mapel',
            ['id' => (int)$cm->id_kurikulum_mapel],
            'id, id_kurikulum_jurusan',
            IGNORE_MISSING
        );

        if (!$km || empty($km->id_kurikulum_jurusan)) {
            return '-';
        }

        $kj = $DB->get_record(
            'kurikulum_jurusan',
            ['id' => (int)$km->id_kurikulum_jurusan],
            'id, id_jurusan',
            IGNORE_MISSING
        );

        if (!$kj || empty($kj->id_jurusan)) {
            return '-';
        }

        $jurusan = $DB->get_record(
            'jurusan',
            ['id' => (int)$kj->id_jurusan],
            'id, nama_jurusan',
            IGNORE_MISSING
        );

        return $jurusan ? (string)$jurusan->nama_jurusan : '-';
    }

    public static function get_school_profile_config(): array {
        $config = get_config('local_akademikmonitor');

        $coverlogosrc = self::get_rapor_setting_image_datauri(
            'rapor_cover_logo',
            self::get_default_rapor_logo_datauri()
        );

        $watermarksrc = self::get_rapor_setting_image_datauri(
            'rapor_watermark',
            $coverlogosrc
        );

        $config = get_config('local_akademikmonitor');

        $kepsekuserid = (int)($config->kepalasekolahuserid ?? 0);

        $namakepalasekolah = 'Wahyudi, ST';

        if ($kepsekuserid) {
            $kepsek = \core_user::get_user($kepsekuserid);

            if ($kepsek) {
                $namakepalasekolah = fullname($kepsek);
            }
        }

        return [
            'namasekolah' => trim($config->namasekolah ?? '') !== ''
                ? $config->namasekolah
                : 'SMKS PGRI 2 Giri Banyuwangi',

            'alamatsekolah' => trim($config->alamatsekolah ?? '') !== ''
                ? $config->alamatsekolah
                : '-',

            'kotattd' => trim($config->kotattd ?? '') !== ''
                ? $config->kotattd
                : 'Banyuwangi',

            'namakepalasekolah' => $namakepalasekolah,

            'npakepalasekolah' => trim($config->npakepalasekolah ?? '') !== ''
                ? $config->npakepalasekolah
                : '1333.1.800.166',

            'semesterdefault' => trim($config->semesterdefault ?? '') !== ''
                ? $config->semesterdefault
                : 'Ganjil',

            'tahunpelajarandefault' => trim($config->tahunpelajarandefault ?? '') !== ''
                ? $config->tahunpelajarandefault
                : '2025/2026',

            'tahuncoverdefault' => !empty($config->tahuncoverdefault)
                ? $config->tahuncoverdefault
                : date('Y'),

            /*
            * Gambar rapor dari pengaturan admin.
            *
            * coverlogosrc:
            * - dipakai untuk logo di halaman sampul.
            *
            * watermarksrc:
            * - dipakai untuk watermark seluruh halaman.
            * - jika admin tidak upload watermark, fallback ke logo sampul.
            */
            'coverlogosrc' => $coverlogosrc,
            'watermarksrc' => $watermarksrc,
        ];
    }

    private static function get_rapor_setting_image_datauri(string $filearea, string $fallback = ''): string {
        $context = \context_system::instance();
        $fs = get_file_storage();

        $files = $fs->get_area_files(
            $context->id,
            'local_akademikmonitor',
            $filearea,
            0,
            'sortorder DESC, timemodified DESC, id DESC',
            false
        );

        if (!$files) {
            return $fallback;
        }

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $mimetype = (string)$file->get_mimetype();

            if (!preg_match('/^image\/(png|jpeg|jpg)$/i', $mimetype)) {
                continue;
            }

            return 'data:' . $mimetype . ';base64,' . base64_encode($file->get_content());
        }

        return $fallback;
    }

    private static function get_default_rapor_logo_datauri(): string {
        global $CFG;

        $candidates = [
            $CFG->dirroot . '/local/akademikmonitor/pix/logo.jpg',
            $CFG->dirroot . '/local/akademikmonitor/pix/logo.jpeg',
            $CFG->dirroot . '/local/akademikmonitor/pix/logo.png',
        ];

        foreach ($candidates as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if ($extension === 'png') {
                $mimetype = 'image/png';
            } else {
                $mimetype = 'image/jpeg';
            }

            return 'data:' . $mimetype . ';base64,' . base64_encode(file_get_contents($path));
        }

        return '';
    }
}