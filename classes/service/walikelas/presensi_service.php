<?php
namespace local_akademikmonitor\service\walikelas;

use local_akademikmonitor\service\period_filter_service;

defined('MOODLE_INTERNAL') || die();

class presensi_service {

    /**
     * Mengambil daftar course/mapel milik kelas wali kelas.
     *
     * Kenapa tidak bikin query course baru?
     * Karena monitoring nilai kamu sudah punya logic yang benar untuk membaca
     * course hasil generate berdasarkan:
     * - idnumber AM-TA{idtahunajaran}-K{idkelas}-KM{idmapel}-S{semester}
     * - semester
     * - tahun ajaran aktif
     *
     * Jadi fitur presensi cukup memakai ulang monitoring_service::get_mapel_by_kelas()
     * supaya hasil dropdown course sama seperti menu Monitoring Kelas.
     */
    public static function get_course_options_by_kelas(int $groupid, int $semester = 0): array {
        if ($groupid <= 0) {
            return [];
        }

        return monitoring_service::get_mapel_by_kelas($groupid, $semester);
    }

    /**
     * Mengecek apakah tabel plugin attendance tersedia.
     *
     * Kenapa perlu?
     * Karena fitur ini bergantung pada mod_attendance. Kalau plugin attendance
     * belum terpasang, halaman tidak boleh error SQL "table does not exist".
     */
    private static function attendance_tables_available(): bool {
        global $DB;

        $dbman = $DB->get_manager();

        return $dbman->table_exists(new \xmldb_table('attendance'))
            && $dbman->table_exists(new \xmldb_table('attendance_sessions'))
            && $dbman->table_exists(new \xmldb_table('attendance_log'))
            && $dbman->table_exists(new \xmldb_table('attendance_statuses'));
    }

    /**
     * Mengambil semua instance attendance dalam satu course.
     *
     * Satu course bisa punya lebih dari satu aktivitas attendance.
     * Contoh:
     * - Presensi Matematika
     * - Presensi Praktikum
     *
     * Maka kita ambil semua attendance berdasarkan course id.
     */
    private static function get_attendance_instances(int $courseid): array {
        global $DB;

        if ($courseid <= 0 || !self::attendance_tables_available()) {
            return [];
        }

        return $DB->get_records(
            'attendance',
            ['course' => $courseid],
            'id ASC',
            'id, course, name'
        );
    }

    /**
     * Mengambil sesi/pertemuan presensi dari semua instance attendance di course.
     *
     * Kenapa groupid dicek?
     * Di plugin attendance, session bisa untuk:
     * - groupid = 0 artinya untuk semua siswa
     * - groupid = id group tertentu artinya hanya untuk kelas/group itu
     *
     * Karena halaman ini untuk wali kelas tertentu, maka session yang ditampilkan:
     * - session umum
     * - session yang sesuai group kelas wali
     */
    private static function get_attendance_sessions(int $courseid, int $groupid): array {
        global $DB;

        $instances = self::get_attendance_instances($courseid);

        if (!$instances) {
            return [];
        }

        $attendanceids = array_map('intval', array_keys($instances));

        [$attinsql, $attparams] = $DB->get_in_or_equal(
            $attendanceids,
            SQL_PARAMS_NAMED,
            'attid'
        );

        $params = $attparams;
        $params['groupid'] = $groupid;

        $sessions = $DB->get_records_select(
            'attendance_sessions',
            "attendanceid {$attinsql}
             AND (groupid = 0 OR groupid = :groupid)",
            $params,
            'sessdate ASC, id ASC',
            'id, attendanceid, groupid, sessdate, duration, description, lasttaken, statusset'
        );

        if (!$sessions) {
            return [];
        }

        $number = 1;
        $out = [];

        foreach ($sessions as $session) {
            $description = trim(strip_tags((string)($session->description ?? '')));

            if ($description === '') {
                $description = 'Pertemuan ' . $number;
            }

            $date = !empty($session->sessdate)
                ? userdate((int)$session->sessdate, '%d %B %Y')
                : '-';

            $out[(int)$session->id] = (object)[
                'id' => (int)$session->id,
                'attendanceid' => (int)$session->attendanceid,
                'groupid' => (int)$session->groupid,
                'sessdate' => (int)$session->sessdate,
                'duration' => (int)$session->duration,
                'description' => $description,
                'date_label' => $date,
                // 'column_label' => $description . '<br><small>' . $date . '</small>',
                'date' => $date,
                'number' => $number,
            ];

            $number++;
        }

        return $out;
    }

    /**
     * Mengambil log presensi siswa untuk session yang tampil.
     *
     * attendance_log menyimpan:
     * - sessionid
     * - studentid
     * - statusid
     *
     * Statusid nanti dicocokkan ke attendance_statuses supaya yang tampil
     * bukan angka, tapi label seperti Present, Absent, Late, Excused.
     */
    private static function get_logs_by_sessions_and_students(array $sessionids, array $userids): array {
        global $DB;

        $sessionids = array_values(array_unique(array_map('intval', $sessionids)));
        $userids = array_values(array_unique(array_map('intval', $userids)));

        if (!$sessionids || !$userids) {
            return [];
        }

        [$sessionsql, $sessionparams] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'sid'
        );

        [$usersql, $userparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'uid'
        );

        $params = array_merge($sessionparams, $userparams);

        $logs = $DB->get_records_select(
            'attendance_log',
            "sessionid {$sessionsql}
             AND studentid {$usersql}",
            $params,
            'id ASC',
            'id, sessionid, studentid, statusid, remarks'
        );

        if (!$logs) {
            return [];
        }

        $statusids = [];

        foreach ($logs as $log) {
            $statusid = (int)($log->statusid ?? 0);
            if ($statusid > 0) {
                $statusids[$statusid] = $statusid;
            }
        }

        $statuses = [];

        if ($statusids) {
            $statuses = $DB->get_records_list(
                'attendance_statuses',
                'id',
                array_values($statusids),
                '',
                'id, acronym, description, grade'
            );
        }

        $out = [];

        foreach ($logs as $log) {
            $sessionid = (int)$log->sessionid;
            $studentid = (int)$log->studentid;
            $statusid = (int)$log->statusid;

            $status = $statuses[$statusid] ?? null;

            $label = '-';
            $acronym = '';
            $description = '';

            if ($status) {
                $acronym = trim((string)($status->acronym ?? ''));
                $description = trim((string)($status->description ?? ''));

                if ($description !== '') {
                    $label = $description;
                } else if ($acronym !== '') {
                    $label = $acronym;
                }
            }

            $out[$studentid][$sessionid] = [
                'label' => $label,
                'acronym' => $acronym,
                'description' => $description,
                'remarks' => (string)($log->remarks ?? ''),
            ];
        }

        return $out;
    }

/**
 * Mendeteksi jenis status presensi dari data Attendance Moodle.
 *
 * Kenapa function ini perlu?
 * Karena plugin Attendance Moodle bisa menyimpan status dalam bahasa Inggris
 * seperti Present, Absent, Late, Excused. Supaya tampilan plugin sekolah
 * tetap rapi dan mudah dipahami, status tersebut kita normalisasi dulu
 * menjadi istilah sekolah dalam bahasa Indonesia.
 */
private static function get_status_meta(array $status): array {
    $label = trim((string)($status['label'] ?? '-'));
    $acronym = strtolower(trim((string)($status['acronym'] ?? '')));
    $description = strtolower(trim((string)($status['description'] ?? '')));

    $text = $acronym . ' ' . $description . ' ' . strtolower($label);

    if ($label === '-' || $label === '') {
        return [
            'display' => '-',
            'class' => 'am-presensi-empty',
            'title' => 'Belum ada data presensi',
        ];
    }

    if (
        str_contains($text, 'present') ||
        str_contains($text, 'hadir') ||
        $acronym === 'p' ||
        $acronym === 'h'
    ) {
        return [
            'display' => 'Hadir',
            'class' => 'am-presensi-present',
            'title' => 'Hadir / Present',
        ];
    }

    if (
        str_contains($text, 'absent') ||
        str_contains($text, 'alfa') ||
        str_contains($text, 'alpha') ||
        str_contains($text, 'tanpa keterangan') ||
        $acronym === 'a'
    ) {
        return [
            'display' => 'Tanpa Keterangan',
            'class' => 'am-presensi-absent',
            'title' => 'Tanpa Keterangan / Absent',
        ];
    }

    if (
        str_contains($text, 'late') ||
        str_contains($text, 'terlambat') ||
        $acronym === 'l' ||
        $acronym === 't'
    ) {
        return [
            'display' => 'Terlambat',
            'class' => 'am-presensi-late',
            'title' => 'Terlambat / Late',
        ];
    }

    if (
        str_contains($text, 'sakit') ||
        $acronym === 's'
    ) {
        return [
            'display' => 'Sakit',
            'class' => 'am-presensi-excused',
            'title' => 'Sakit',
        ];
    }

    if (
        str_contains($text, 'excused') ||
        str_contains($text, 'izin') ||
        $acronym === 'e' ||
        $acronym === 'i'
    ) {
        return [
            'display' => 'Izin',
            'class' => 'am-presensi-excused',
            'title' => 'Izin / Excused',
        ];
    }

    return [
        'display' => $label,
        'class' => 'am-presensi-info',
        'title' => $label,
    ];
}

/**
 * Memberi class warna dan label Indonesia berdasarkan status presensi.
 *
 * Kenapa function ini dipisah dari get_status_meta()?
 * Supaya get_status_meta() fokus menentukan arti status,
 * sedangkan render_status_badge() fokus membuat HTML badge-nya.
 * Jadi kalau nanti tampilan badge mau diubah, logic deteksi statusnya
 * tidak perlu ikut diacak-acak.
 */
// private static function render_status_badge(array $status): string {
//     $meta = self::get_status_meta($status);

//     return '<span class="am-presensi-badge ' . s($meta['class']) . '" title="' . s($meta['title']) . '">'
//         . s($meta['display'])
//         . '</span>';
// }

/**
 * Daftar keterangan status untuk ditampilkan di bawah tabel.
 *
 * Kenapa dibuat dari service?
 * Karena template cukup menerima data siap tampil.
 * Kalau nanti istilah sekolah berubah, misalnya "Tanpa Keterangan"
 * diganti menjadi "Alpa", cukup ubah di sini.
 */
private static function get_status_legend(): array {
    return [
        [
            'class' => 'am-presensi-present',
            'label' => 'Hadir',
            'description' => 'Siswa hadir dan mengikuti pembelajaran.',
        ],
        [
            'class' => 'am-presensi-late',
            'label' => 'Terlambat',
            'description' => 'Siswa hadir tetapi terlambat.',
        ],
        [
            'class' => 'am-presensi-excused',
            'label' => 'Izin',
            'description' => 'Siswa tidak hadir dengan keterangan atau izin.',
        ],
        [
            'class' => 'am-presensi-absent',
            'label' => 'Tanpa Keterangan',
            'description' => 'Siswa tidak hadir tanpa keterangan.',
        ],
        [
            'class' => 'am-presensi-empty',
            'label' => '-',
            'description' => 'Presensi belum diambil atau belum ada data.',
        ],
    ];
}

    /**
     * Membuat tabel pivot:
     *
     * Baris  : siswa
     * Kolom  : sesi presensi/pertemuan
     * Isi    : status presensi siswa pada sesi itu
     */
    public static function get_monitoring_presensi(int $groupid, int $courseid, int $waliuserid): array {
        $students = common_service::get_siswa_group($groupid, $waliuserid);

        if (!$students || $courseid <= 0) {
            return [
                'columns' => [],
                'rows' => [],
                'summary' => [
                    'total_sessions' => 0,
                    'total_students' => count($students),
                ],
            ];
        }

        $sessions = self::get_attendance_sessions($courseid, $groupid);

        if (!$sessions) {
            $rows = [];

            foreach ($students as $student) {
                $rows[] = [
                    'userid' => (int)$student->id,
                    'nama' => fullname($student),
                    'presensi_list' => [],
                ];
            }

            return [
                'columns' => [],
                'rows' => $rows,
                'summary' => [
                    'total_sessions' => 0,
                    'total_students' => count($students),
                ],
            ];
        }

        $sessionids = array_map('intval', array_keys($sessions));
        $userids = array_map('intval', array_keys($students));
        $logs = self::get_logs_by_sessions_and_students($sessionids, $userids);

        $columns = [];

        foreach ($sessions as $session) {
            $columns[] = [
                'id' => (int)$session->id,
                'description' => $session->description,
                'date' => $session->date,
            ];
        }

        $rows = [];

        foreach ($students as $student) {
            $studentid = (int)$student->id;

            $list = [];

            foreach ($sessions as $session) {
                $sessionid = (int)$session->id;
                $status = $logs[$studentid][$sessionid] ?? [
                    'label' => '-',
                    'acronym' => '',
                    'description' => '',
                    'remarks' => '',
                ];

                $meta = self::get_status_meta($status);

                $list[] = [
                    'label' => $meta['display'],
                    'class' => $meta['class'],
                    'title' => $meta['title'],
                ];
            }

            $rows[] = [
                'userid' => $studentid,
                'nama' => fullname($student),
                'presensi_list' => $list,
            ];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'summary' => [
                'total_sessions' => count($sessions),
                'total_students' => count($students),
            ],
        ];
    }

    /**
     * Data utama untuk halaman monitoring presensi.
     *
     * Alurnya:
     * 1. Ambil tahun ajaran dan semester aktif.
     * 2. Ambil kelas wali kelas sesuai tahun ajaran.
     * 3. Ambil daftar course/mapel dari kelas itu.
     * 4. Validasi courseid dari URL.
     * 5. Ambil data presensi dari plugin attendance.
     */
    public static function get_page_data(
        int $userid,
        int $courseid = 0,
        int $semester = 0,
        int $tahunajaranid = 0
    ): array {
        if ($semester <= 0) {
            $semester = period_filter_service::get_selected_semester();
        }

        if ($tahunajaranid <= 0) {
            $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
        }

        $data = common_service::get_sidebar_data('presensi', $userid, $tahunajaranid);

        $group = common_service::get_first_group_walikelas_by_tahunajaran($userid, $tahunajaranid);

        if (!$group) {
            $data['nokelas'] = true;

            $data += period_filter_service::build_filter_data();
            $data += period_filter_service::get_filter_ui_data(
                '/local/akademikmonitor/pages/walikelas/presensi/index.php',
                ['courseid' => $courseid]
            );

            return $data;
        }

        $courses = self::get_course_options_by_kelas((int)$group->id, $semester);

        $validcourseids = [];

        foreach ($courses as $course) {
            $validcourseids[(int)$course->id] = true;
        }

        if ($courseid <= 0 || !isset($validcourseids[$courseid])) {
            $courseid = !empty($courses) ? (int)$courses[0]->id : 0;
        }

        foreach ($courses as &$course) {
            $course->is_selected = ((int)$course->id === $courseid);
        }
        unset($course);

        $selectedcoursename = '-';

        foreach ($courses as $course) {
            if ((int)$course->id === $courseid) {
                $selectedcoursename = (string)$course->nama_mapel;
                break;
            }
        }

        $data['kelas'] = (string)$group->name;
        $data['courseid'] = $courseid;
        $data['selected_course'] = $courseid;
        $data['selected_course_name'] = $selectedcoursename;
        $data['selectedsemester'] = $semester;
        $data['selectedtahunajaranid'] = $tahunajaranid;
        $data['selected_tahunajaranid'] = $tahunajaranid;
        $data['attendance_available'] = self::attendance_tables_available();

        $data['courses'] = array_map(static function($course) {
            return [
                'id' => (int)$course->id,
                'nama_mapel' => (string)$course->nama_mapel,
                'is_selected' => !empty($course->is_selected),
            ];
        }, $courses);

        $data += period_filter_service::build_filter_data();
        $data += period_filter_service::get_filter_ui_data(
            '/local/akademikmonitor/pages/walikelas/presensi/index.php',
            ['courseid' => $courseid]
        );

        if ($courseid > 0 && self::attendance_tables_available()) {
            $presensi = self::get_monitoring_presensi((int)$group->id, $courseid, $userid);

            $data['columns'] = $presensi['columns'];
            $data['rows'] = $presensi['rows'];
            $data['summary'] = $presensi['summary'];
            $data['total_sessions'] = (int)$presensi['summary']['total_sessions'];
            $data['total_students'] = (int)$presensi['summary']['total_students'];
        } else {
            $data['columns'] = [];
            $data['rows'] = [];
            $data['summary'] = [
                'total_sessions' => 0,
                'total_students' => 0,
            ];
            $data['total_sessions'] = 0;
            $data['total_students'] = 0;
        }
        $data['status_legend'] = self::get_status_legend();
        return $data;
    }
}