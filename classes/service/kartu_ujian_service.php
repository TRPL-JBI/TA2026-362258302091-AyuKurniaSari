<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Service kelayakan kartu ujian.
 *
 * Aturan yang dipakai:
 * - Kartu ujian hanya mempertimbangkan nilai akhir mapel terhadap KKTP.
 * - Presensi/alpa tidak dipakai sebagai syarat kartu ujian.
 * - Jika ada salah satu mapel nilainya di bawah KKTP, siswa belum layak mendapat kartu ujian.
 */
class kartu_ujian_service {

    /**
     * Konversi semester dari bentuk teks/angka menjadi angka Moodle plugin.
     */
    public static function normalize_semester($semester): int {
        $raw = strtolower(trim((string)$semester));

        if ($raw === '1' || $raw === 'ganjil') {
            return 1;
        }

        if ($raw === '2' || $raw === 'genap') {
            return 2;
        }

        return 0;
    }

    /**
     * Ambil course generated plugin berdasarkan tahun ajaran, kelas, dan semester.
     */
    public static function get_courseids(int $kelasid, int $tahunajaranid, int $semester): array {
        global $DB;

        if ($kelasid <= 0 || $tahunajaranid <= 0 || !in_array($semester, [1, 2], true)) {
            return [];
        }

        $pattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S' . $semester;
        $like = $DB->sql_like('c.idnumber', ':pattern', false, false);

        $courseids = $DB->get_fieldset_sql(
            "SELECT c.id
               FROM {course} c
              WHERE {$like}
           ORDER BY c.sortorder ASC, c.fullname ASC",
            ['pattern' => $pattern]
        );

        return array_values(array_map('intval', $courseids));
    }

    /**
     * Ambil detail course + nilai akhir + KKTP untuk satu siswa.
     */
    public static function get_grade_requirements(int $userid, array $courseids): array {
        global $DB;

        $rows = [];

        if ($userid <= 0 || empty($courseids)) {
            return $rows;
        }

        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid <= 0) {
                continue;
            }

            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, idnumber', IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            $kmid = self::resolve_kurikulum_mapelid($course);
            $kktp = 0;
            $mapelname = format_string((string)($course->fullname ?? $course->shortname ?? 'Mata Pelajaran'));

            if ($kmid > 0) {
                $km = $DB->get_record('kurikulum_mapel', ['id' => $kmid], 'id, id_mapel, kktp', IGNORE_MISSING);
                if ($km) {
                    $kktp = max(0, min(100, (int)($km->kktp ?? 0)));
                    $mapel = $DB->get_record('mata_pelajaran', ['id' => (int)$km->id_mapel], 'id, nama_mapel', IGNORE_MISSING);
                    if ($mapel && trim((string)$mapel->nama_mapel) !== '') {
                        $mapelname = trim(preg_replace('/^\[.*?\]\s*/', '', (string)$mapel->nama_mapel));
                    }
                }
            }

            $grade = $DB->get_field_sql(
                "SELECT gg.finalgrade
                   FROM {grade_items} gi
              LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                  WHERE gi.courseid = :courseid
                    AND gi.itemtype = 'course'",
                [
                    'userid' => $userid,
                    'courseid' => $courseid,
                ]
            );

            $grade = $grade !== null ? round((float)$grade, 1) : null;

            $rows[] = [
                'courseid' => $courseid,
                'kmid' => $kmid,
                'mapel' => $mapelname !== '' ? $mapelname : format_string((string)$course->fullname),
                'nilai' => $grade,
                'kktp' => $kktp,
                'aman' => $grade !== null && $grade >= $kktp,
                'belum_ada_nilai' => $grade === null,
            ];
        }

        return $rows;
    }

    /**
     * Hitung total sesi dan jumlah alpa dari plugin Attendance.
     */
    public static function get_attendance_summary(int $userid, array $courseids): array {
        global $DB;

        $result = [
            'available' => false,
            'total_pertemuan' => 0,
            'jumlah_alpa' => 0,
            'batas_alpa' => 0,
            'aman' => false,
            'label' => 'N/A',
        ];

        if ($userid <= 0 || empty($courseids)) {
            return $result;
        }

        $dbman = $DB->get_manager();
        $attendanceavailable =
            $dbman->table_exists('attendance') &&
            $dbman->table_exists('attendance_sessions') &&
            $dbman->table_exists('attendance_log') &&
            $dbman->table_exists('attendance_statuses');

        if (!$attendanceavailable) {
            return $result;
        }

        [$courseinsql, $courseparams] = $DB->get_in_or_equal(
            array_values(array_map('intval', $courseids)),
            SQL_PARAMS_NAMED,
            'ku_att_course'
        );

        $totalparams = $courseparams;
        $totalparams['userid'] = $userid;

        $total = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT ats.id)
               FROM {attendance_sessions} ats
               JOIN {attendance} at ON at.id = ats.attendanceid
              WHERE at.course {$courseinsql}
                AND EXISTS (
                    SELECT 1
                      FROM {enrol} e
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                     WHERE e.courseid = at.course
                       AND ue.userid = :userid
                )",
            $totalparams
        );

        if ($total <= 0) {
            $result['available'] = true;
            $result['label'] = '0 pertemuan';
            return $result;
        }

        $absentparams = $courseparams;
        $absentparams['userid_absen'] = $userid;
        $absentparams['desc_absent'] = 'Absent';
        $absentparams['desc_alpa'] = 'Alpa';
        $absentparams['desc_tk'] = 'Tanpa Keterangan';
        $absentparams['desc_tidak_hadir'] = 'Tidak Hadir';

        $alpa = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT al.id)
               FROM {attendance_log} al
               JOIN {attendance_sessions} ats ON ats.id = al.sessionid
               JOIN {attendance} at ON at.id = ats.attendanceid
               JOIN {attendance_statuses} ast ON ast.id = al.statusid
              WHERE al.studentid = :userid_absen
                AND at.course {$courseinsql}
                AND (
                    ast.acronym IN ('A')
                    OR " . $DB->sql_compare_text('ast.description') . " = " . $DB->sql_compare_text(':desc_absent') . "
                    OR " . $DB->sql_compare_text('ast.description') . " = " . $DB->sql_compare_text(':desc_alpa') . "
                    OR " . $DB->sql_compare_text('ast.description') . " = " . $DB->sql_compare_text(':desc_tk') . "
                    OR " . $DB->sql_compare_text('ast.description') . " = " . $DB->sql_compare_text(':desc_tidak_hadir') . "
                )",
            $absentparams
        );

        $batas = (int)floor($total * 0.10);
        $aman = $alpa <= $batas;

        return [
            'available' => true,
            'total_pertemuan' => $total,
            'jumlah_alpa' => $alpa,
            'batas_alpa' => $batas,
            'aman' => $aman,
            'label' => $alpa . '/' . $batas . ' alpa',
        ];
    }

    /**
     * Hitung kelayakan kartu ujian untuk satu siswa.
     */
/**
 * Hitung kelayakan kartu ujian untuk satu siswa.
 *
 * Aturan terbaru:
 * - Kartu ujian TIDAK lagi mempertimbangkan presensi/alpa.
 * - Syarat otomatis hanya dari nilai akhir mapel.
 * - Jika ada minimal satu nilai mapel di bawah KKTP, siswa belum layak.
 * - Jika nilai belum lengkap, siswa juga belum layak.
 */
public static function get_eligibility(int $userid, int $kelasid, int $tahunajaranid, int $semester): array {
    $courseids = self::get_courseids($kelasid, $tahunajaranid, $semester);
    $grades = self::get_grade_requirements($userid, $courseids);

    $reasons = [];
    $nilai_bawah_kktp = [];
    $nilai_belum_lengkap = [];

    if (empty($courseids)) {
        $reasons[] = 'Course semester ini belum ditemukan.';
    }

    if (empty($grades)) {
        $reasons[] = 'Data nilai semester ini belum tersedia.';
    }

    foreach ($grades as $grade) {
        if (!empty($grade['belum_ada_nilai'])) {
            $nilai_belum_lengkap[] = $grade['mapel'];
            continue;
        }

        if (empty($grade['aman'])) {
            $nilai_bawah_kktp[] = $grade;
        }
    }

    if (!empty($nilai_belum_lengkap)) {
        $reasons[] = 'Nilai belum lengkap: ' . implode(', ', $nilai_belum_lengkap) . '.';
    }

if (!empty($nilai_bawah_kktp)) {
    $reasons[] = 'Terdapat nilai di bawah KKTP. Lihat detail mapel pada kolom alasan.';
}

    $eligible = empty($reasons);

    /*
     * Data attendance sengaja dibuat default supaya file lama yang masih membaca
     * key "attendance" tidak error. Tapi data ini tidak lagi mempengaruhi
     * kelayakan kartu ujian.
     */
    $attendance = [
        'available' => true,
        'total_pertemuan' => 0,
        'jumlah_alpa' => 0,
        'batas_alpa' => 0,
        'aman' => true,
        'label' => 'Tidak digunakan',
    ];

    return [
        'eligible' => $eligible,
        'boleh_cetak' => $eligible,
        'courseids' => $courseids,
        'attendance' => $attendance,
        'grades' => $grades,
        'reasons' => $reasons,
        'reason_text' => $eligible ? 'Memenuhi syarat.' : implode(' ', $reasons),
        'avg_nilai' => self::average_grade($grades),
        'mapel_di_bawah_kktp' => $nilai_bawah_kktp,
        'nilai_belum_lengkap' => $nilai_belum_lengkap,
    ];
}

    /**
     * Resolve kurikulum_mapel id dari course_mapel atau dari idnumber course.
     */
    private static function resolve_kurikulum_mapelid(\stdClass $course): int {
        global $DB;

        $kmid = (int)$DB->get_field('course_mapel', 'id_kurikulum_mapel', ['id_course' => (int)$course->id], IGNORE_MULTIPLE);
        if ($kmid > 0) {
            return $kmid;
        }

        if (!empty($course->idnumber) && preg_match('/-KM(\d+)-S\d+$/', (string)$course->idnumber, $m)) {
            return (int)$m[1];
        }

        return 0;
    }

    /**
     * Rata-rata nilai akhir course yang sudah ada nilainya.
     */
    private static function average_grade(array $grades): ?float {
        $sum = 0.0;
        $count = 0;

        foreach ($grades as $grade) {
            if ($grade['nilai'] !== null) {
                $sum += (float)$grade['nilai'];
                $count++;
            }
        }

        if ($count <= 0) {
            return null;
        }

        return round($sum / $count, 1);
    }
}
