<?php
namespace local_akademikmonitor\service;
use local_akademikmonitor\service\admin_presensi_guru_service;
use local_akademikmonitor\service\report_export_service;
defined('MOODLE_INTERNAL') || die();

class notif_dispatcher_service {

    public static function run(): void {
        global $DB;

        mtrace('[akademikmonitor] Dispatcher notifikasi mulai.');

        $rules = $DB->get_records('notif_rule', ['is_enabled' => '1'], 'id ASC');

        if (!$rules) {
            mtrace('[akademikmonitor] STOP: Tidak ada rule notif aktif.');
            return;
        }

        mtrace('[akademikmonitor] Jumlah rule aktif: ' . count($rules));

        foreach ($rules as $rule) {
            $rulecode = trim((string)$rule->rule_kode);

            mtrace('[akademikmonitor] Proses rule: ' . $rulecode);

            try {
                switch ($rulecode) {
                    case 'pengingat_tugas':
                        self::process_pengingat_tugas($rule);
                        break;

                    case 'pengingat_event':
                        self::process_pengingat_event($rule);
                        break;

                    case 'nilai_kktp':
                        self::process_event_kktp($rule);
                        break;

                    case 'kehadiran_guru_harian':
                        self::process_kehadiran_guru_harian($rule);
                        break;

                    case 'kehadiran_siswa_harian':
                        self::process_kehadiran_siswa_harian($rule);
                        break;

                    case 'kehadiran_siswa_mingguan':
                        self::process_kehadiran_siswa_mingguan($rule);
                        break;

                    default:
                        mtrace('[akademikmonitor] Rule tidak dikenali: ' . $rulecode);
                        break;
                }
            } catch (\Throwable $e) {
                mtrace('[akademikmonitor] ERROR rule ' . $rulecode . ': ' . $e->getMessage());
            }
        }

        mtrace('[akademikmonitor] Dispatcher notifikasi selesai.');
    }

    /* ============================================================
     * 1. PENGINGAT TUGAS
     * ============================================================ */

    protected static function process_pengingat_tugas(\stdClass $rule): void {
        global $DB;

        $offsetdays = (int)($rule->offset_days ?? 0);
        $sendtime = trim((string)($rule->send_time ?? '07:00:00'));
        $recipientconfig = (string)($rule->recipients ?? '');

        mtrace('[akademikmonitor] Rule pengingat_tugas mulai.');
        mtrace('[akademikmonitor] offset_days = ' . $offsetdays);
        mtrace('[akademikmonitor] send_time = ' . $sendtime);
        mtrace('[akademikmonitor] recipients = ' . $recipientconfig);

        if (!self::is_now_in_send_window($sendtime)) {
            mtrace('[akademikmonitor] STOP: belum masuk jam kirim.');
            return;
        }

        $sendtosiswa = self::has_recipient($recipientconfig, ['siswa', 'student']);
        $sendtowali = self::has_recipient($recipientconfig, ['wali', 'wali kelas', 'walikelas']);
        $sendtoguru = self::has_recipient($recipientconfig, ['guru', 'teacher']);

        mtrace('[akademikmonitor] target siswa = ' . ($sendtosiswa ? 'YA' : 'TIDAK'));
        mtrace('[akademikmonitor] target wali = ' . ($sendtowali ? 'YA' : 'TIDAK'));
        mtrace('[akademikmonitor] target guru = ' . ($sendtoguru ? 'YA' : 'TIDAK'));

        if (!$sendtosiswa && !$sendtowali && !$sendtoguru) {
            mtrace('[akademikmonitor] STOP: rule pengingat_tugas tidak punya target penerima.');
            return;
        }

        $start = strtotime('today +' . $offsetdays . ' day');
        $end = strtotime('tomorrow +' . $offsetdays . ' day') - 1;

        mtrace('[akademikmonitor] range deadline mulai = ' . date('Y-m-d H:i:s', $start));
        mtrace('[akademikmonitor] range deadline akhir = ' . date('Y-m-d H:i:s', $end));

        $assignments = $DB->get_records_select(
            'assign',
            'duedate > 0 AND duedate >= :starttime AND duedate <= :endtime',
            [
                'starttime' => $start,
                'endtime' => $end,
            ],
            'duedate ASC',
            'id, name, duedate, course'
        );

        mtrace('[akademikmonitor] jumlah tugas ditemukan = ' . count($assignments));

        if (!$assignments) {
            mtrace('[akademikmonitor] STOP: tidak ada tugas untuk pengingat.');
            return;
        }

        $courseids = [];

        foreach ($assignments as $assign) {
            $courseids[] = (int)$assign->course;
        }

        $courseids = array_values(array_unique(array_filter($courseids)));

        $courses = [];

        if ($courseids) {
            $courses = $DB->get_records_list(
                'course',
                'id',
                $courseids,
                'fullname ASC',
                'id, fullname'
            );
        }

        foreach ($assignments as $assign) {
            $courseid = (int)$assign->course;

            $assign->coursename = isset($courses[$courseid])
                ? format_string($courses[$courseid]->fullname)
                : '-';

            mtrace('[akademikmonitor] proses tugas: ' . format_string($assign->name));
            mtrace('[akademikmonitor] courseid = ' . $courseid);
            mtrace('[akademikmonitor] course = ' . $assign->coursename);

            $students = self::get_course_students($courseid);

            mtrace('[akademikmonitor] jumlah siswa course = ' . count($students));

            if (!$students) {
                mtrace('[akademikmonitor] SKIP: tidak ada siswa di course tugas.');
                continue;
            }

            $scheduledat = date(
                'Y-m-d H:i:s',
                strtotime(date('Y-m-d', (int)$assign->duedate) . ' ' . $sendtime)
            );

            $duedate = userdate((int)$assign->duedate, '%d %B %Y %H:%M');

            $walimap = [];
            $unsubmittedstudents = [];

            foreach ($students as $student) {
                mtrace('[akademikmonitor] cek siswa: ' . fullname($student) . ' | userid=' . (int)$student->id);

                if (self::has_student_submitted_assignment((int)$assign->id, (int)$student->id)) {
                    mtrace('[akademikmonitor] SKIP siswa sudah submit: ' . fullname($student));
                    continue;
                }

                $unsubmittedstudents[(int)$student->id] = $student;

                if ($sendtosiswa) {
                    self::send_assignment_reminder_to_student(
                        $student,
                        $assign,
                        $offsetdays,
                        $duedate,
                        $scheduledat
                    );
                }

                if ($sendtowali) {
                    $walis = self::get_walikelas_for_student_in_course(
                        (int)$student->id,
                        $courseid
                    );

                    mtrace('[akademikmonitor] jumlah wali untuk siswa ' . fullname($student) . ' = ' . count($walis));

                    foreach ($walis as $wali) {
                        $waliid = (int)$wali->id;

                        if (!isset($walimap[$waliid])) {
                            $walimap[$waliid] = [
                                'user' => $wali,
                                'students' => [],
                            ];
                        }

                        $walimap[$waliid]['students'][(int)$student->id] = $student;
                    }
                }
            }

            mtrace('[akademikmonitor] jumlah wali yang akan dikirimi = ' . count($walimap));

            if ($sendtowali && $walimap) {
                foreach ($walimap as $data) {
                    self::send_assignment_reminder_to_wali(
                        $data['user'],
                        array_values($data['students']),
                        $assign,
                        $offsetdays,
                        $duedate,
                        $scheduledat
                    );
                }
            }

            if ($sendtoguru && $unsubmittedstudents) {
                $teachers = self::get_course_teachers($courseid);

                mtrace('[akademikmonitor] jumlah guru course yang akan dicek = ' . count($teachers));

                foreach ($teachers as $teacher) {
                    self::send_assignment_reminder_to_teacher(
                        $teacher,
                        array_values($unsubmittedstudents),
                        $assign,
                        $offsetdays,
                        $duedate,
                        $scheduledat
                    );
                }
            }
        }
    }

protected static function process_kehadiran_guru_harian(\stdClass $rule): void {
    global $DB;

    $sendtime = trim((string)($rule->send_time ?? '16:00:00'));

    if (!self::is_now_in_send_window($sendtime)) {
        return;
    }

    mtrace('[akademikmonitor] proses laporan guru harian');

    $start = strtotime('today');
    $end = strtotime('tomorrow') - 1;

    $sessions = self::get_generated_attendance_sessions_in_range($start, $end);

    mtrace('[akademikmonitor] jumlah sesi guru hari ini = ' . count($sessions));

    if (!$sessions) {
        mtrace('[akademikmonitor] STOP: tidak ada sesi attendance guru hari ini.');
    }

    $sessionids = array_map(static function($session) {
        return (int)($session->sessionid ?? 0);
    }, $sessions);

    $takenbymap = self::get_session_takenby_map($sessionids);
    $sessionhasstudentlog = self::get_session_has_student_log_map($sessionids);

    $reports = [];

    foreach ($sessions as $session) {
        $sessionid = (int)($session->sessionid ?? 0);
        $courseid = (int)($session->courseid ?? 0);
        $coursename = format_string((string)($session->coursename ?? '-'));
        $description = trim(strip_tags((string)($session->description ?? '')));
        $description = $description !== '' ? $description : 'Pertemuan';
        $date = !empty($session->sessdate)
            ? userdate((int)$session->sessdate, '%d %B %Y')
            : '-';

        $teachers = self::get_course_teachers($courseid);

        if (!$teachers) {
            mtrace('[akademikmonitor] SKIP sesi tanpa guru terdaftar. courseid=' . $courseid);
            continue;
        }

        foreach ($teachers as $teacher) {
            $teacherid = (int)$teacher->id;

            if (!isset($reports[$teacherid])) {
                $reports[$teacherid] = [
                    'teacher' => $teacher,
                    'present' => 0,
                    'absent' => 0,
                    'items' => [],
                ];
            }

            $present = false;

            if ((int)($session->lasttakenby ?? 0) === $teacherid) {
                $present = true;
            } elseif (!empty($takenbymap[$sessionid][$teacherid])) {
                $present = true;
            } elseif (!empty($sessionhasstudentlog[$sessionid])) {
                $present = true;
            }

            $statuslabel = $present ? 'Hadir' : 'Belum diambil';

            if ($present) {
                $reports[$teacherid]['present']++;
            } else {
                $reports[$teacherid]['absent']++;
            }

            $reports[$teacherid]['items'][] = $coursename . ' — ' . $description . ' (' . $date . ') : ' . $statuslabel;
        }
    }

    if (!$reports) {
        mtrace('[akademikmonitor] STOP: tidak ada guru yang perlu dilaporkan hari ini.');
        return;
    }

    uasort($reports, static function($a, $b) {
        return strcasecmp(fullname($a['teacher']), fullname($b['teacher']));
    });

    $message = self::build_teacher_daily_report_message($reports, $start, $end);

    $recipients = self::get_teacher_report_recipients();

    mtrace('[akademikmonitor] jumlah penerima laporan guru = ' . count($recipients));

    if (!$recipients) {
        mtrace('[akademikmonitor] STOP: tidak ada penerima yang terhubung Telegram untuk laporan guru.');
        return;
    }

    $scheduledat = date('Y-m-d H:i:s', strtotime(date('Y-m-d', $start) . ' ' . $sendtime));

    foreach ($recipients as $recipient) {
        self::send_to_all_telegram_links(
            (int)$recipient->id,
            0,
            'kehadiran_guru_harian',
            0,
            0,
            'Laporan Kehadiran Guru Harian',
            $scheduledat,
            $message,
            'penerima_guru'
        );
    }
}

protected static function process_kehadiran_siswa_mingguan(\stdClass $rule): void {
    global $DB;

    $sendtime = trim((string)($rule->send_time ?? '18:00:00'));

    if (!self::is_now_in_send_window($sendtime)) {
        return;
    }

    $days = array_filter(
        array_map(
            'trim',
            str_split((string)$rule->offset_days)
        )
    );

    if (empty($days)) {
        $days = ['6'];
    }

    if (!in_array((string)date('N'), $days, true)) {
        return;
    }

    mtrace('[akademikmonitor] proses laporan siswa mingguan');

    $start = strtotime('monday this week');
    $end = strtotime('today 23:59:59');

    $students = self::get_report_student_users();

    mtrace('[akademikmonitor] jumlah siswa kandidat = ' . count($students));

    if (!$students) {
        mtrace('[akademikmonitor] STOP: tidak ada user siswa untuk laporan mingguan.');
        return;
    }

    $studentids = array_map(static function($student) {
        return (int)$student->id;
    }, $students);

    $reports = self::get_student_weekly_reports($studentids, $start, $end);

    mtrace('[akademikmonitor] jumlah siswa dengan data laporan = ' . count($reports));

    $scheduledat = date('Y-m-d H:i:s', strtotime(date('Y-m-d', $start) . ' ' . $sendtime));

    foreach ($students as $student) {
        $studentid = (int)$student->id;
        $report = $reports[$studentid] ?? [
            'student' => $student,
            'total' => 0,
            'present' => 0,
            'late' => 0,
            'excused' => 0,
            'absent' => 0,
            'other' => 0,
            'courses' => [],
            'details' => [],
        ];

        $message = self::build_student_weekly_report_message($student, $report, $start, $end);

global $CFG;

$tempdir =
    make_temp_directory(
        'akademikmonitor'
    );

$pdf =
    $tempdir .
    '/presensi_' .
    $studentid .
    '.pdf';

$excel =
    $tempdir .
    '/presensi_' .
    $studentid .
    '.xlsx';

report_export_service::create_student_pdf(
    $student,
    $report,
    $pdf
);

report_export_service::create_student_excel(
    $student,
    $report,
    $excel
);

        self::send_to_all_telegram_links(
            $studentid,
            0,
            'kehadiran_siswa_mingguan',
            0,
            0,
            'Laporan Kehadiran Siswa Mingguan',
            $scheduledat,
            $message,
            'siswa',
            $pdf,
            $excel
        );

        // self::send_report_files_to_user(
        //     $studentid,
        //     $pdf,
        //     $excel
        // );

        if (file_exists($pdf)) {
            unlink($pdf);
        }

        if (file_exists($excel)) {
            unlink($excel);
        }
    }
}

protected static function process_kehadiran_siswa_harian(\stdClass $rule): void {
    $sendtime = trim((string)($rule->send_time ?? '15:00:00'));

    $recipientconfig = (string)($rule->recipients ?? '');

    $sendtosiswa = self::has_recipient(
        $recipientconfig,
        ['siswa', 'student']
    );

    $sendtoguru = self::has_recipient(
        $recipientconfig,
        ['guru', 'teacher']
    );

    $sendtowali = self::has_recipient(
        $recipientconfig,
        ['wali', 'wali kelas', 'walikelas']
    );

    if (!self::is_now_in_send_window($sendtime)) {
        return;
    }

    mtrace('[akademikmonitor] proses laporan siswa harian');

    $start = strtotime('today');
    $end = strtotime('tomorrow') - 1;

    $students = self::get_report_student_users();

    if (!$students) {
        mtrace('[akademikmonitor] tidak ada siswa');
        return;
    }

    $studentids = array_map(static function($student) {
        return (int)$student->id;
    }, $students);

    $reports = self::get_student_weekly_reports(
        $studentids,
        $start,
        $end
    );

    $scheduledat = date(
        'Y-m-d H:i:s',
        strtotime(date('Y-m-d') . ' ' . $sendtime)
    );

    foreach ($students as $student) {

        $studentid = (int)$student->id;

        $report = $reports[$studentid] ?? [
            'student' => $student,
            'total' => 0,
            'present' => 0,
            'late' => 0,
            'excused' => 0,
            'absent' => 0,
            'other' => 0,
            'courses' => [],
            'details' => [],
        ];

        $message = self::build_student_daily_report_message(
            $student,
            $report,
            $start
        );
if ($sendtowali) {

    $allcourses = array_keys($report['courses'] ?? []);

    $sentwali = [];

    foreach ($allcourses as $courseid) {

        $walis = self::get_walikelas_for_student_in_course(
            $studentid,
            (int)$courseid
        );

        foreach ($walis as $wali) {

            if (isset($sentwali[$wali->id])) {
                continue;
            }

            $sentwali[$wali->id] = true;

            self::send_to_all_telegram_links(
                (int)$wali->id,
                (int)$courseid,
                'kehadiran_siswa_harian_wali',
                0,
                0,
                'Laporan Kehadiran Siswa Harian',
                $scheduledat,
                $message,
                'wali'
            );
        }
    }
}

if ($sendtosiswa) {

    self::send_to_all_telegram_links(
        $studentid,
        0,
        'kehadiran_siswa_harian',
        0,
        0,
        'Laporan Kehadiran Siswa Harian',
        $scheduledat,
        $message,
        'siswa'
    );
}

    }
    if ($sendtoguru) {

    self::send_teacher_daily_attendance_summary(
        $start,
        $end,
        $scheduledat
    );
}
}

protected static function get_generated_attendance_sessions_in_range(int $startts, int $endts): array {
    global $DB;

    $likegeneratednew = $DB->sql_like('c.idnumber', ':newpattern', false);
    $likegeneratedold = $DB->sql_like('c.idnumber', ':oldpattern', false);

    $sql = "SELECT s.id AS sessionid,
                   s.attendanceid,
                   s.groupid,
                   s.sessdate,
                   s.description,
                   s.lasttakenby,
                   c.id AS courseid,
                   c.fullname AS coursename,
                   c.idnumber
              FROM {attendance_sessions} s
              JOIN {attendance} a ON a.id = s.attendanceid
              JOIN {course} c ON c.id = a.course
             WHERE s.sessdate >= :starttime
               AND s.sessdate <= :endtime
               AND c.idnumber <> ''
               AND ({$likegeneratednew} OR {$likegeneratedold})
          ORDER BY c.fullname ASC, s.sessdate ASC, s.id ASC";

    return $DB->get_records_sql($sql, [
        'starttime' => $startts,
        'endtime' => $endts,
        'newpattern' => 'AM-TA%-K%-KM%-S%',
        'oldpattern' => 'AM-K%-KM%-S%',
    ]);
}

protected static function get_session_takenby_map(array $sessionids): array {
    global $DB;

    $sessionids = array_values(array_unique(array_map('intval', $sessionids)));

    if (!$sessionids) {
        return [];
    }

    [$sessionsql, $sessionparams] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sid');

    $records = $DB->get_records_select(
        'attendance_log',
        "sessionid {$sessionsql} AND takenby > 0",
        $sessionparams,
        'id ASC',
        'id, sessionid, takenby'
    );

    $out = [];

    foreach ($records as $record) {
        $sessionid = (int)($record->sessionid ?? 0);
        $takenby = (int)($record->takenby ?? 0);

        if ($sessionid <= 0 || $takenby <= 0) {
            continue;
        }

        if (!isset($out[$sessionid])) {
            $out[$sessionid] = [];
        }

        $out[$sessionid][$takenby] = true;
    }

    return $out;
}

protected static function get_session_has_student_log_map(array $sessionids): array {
    global $DB;

    $sessionids = array_values(array_unique(array_map('intval', $sessionids)));

    if (!$sessionids) {
        return [];
    }

    [$sessionsql, $sessionparams] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sid');

    $records = $DB->get_records_select(
        'attendance_log',
        "sessionid {$sessionsql} AND studentid > 0",
        $sessionparams,
        'id ASC',
        'id, sessionid'
    );

    $out = [];

    foreach ($records as $record) {
        $sessionid = (int)($record->sessionid ?? 0);

        if ($sessionid > 0) {
            $out[$sessionid] = true;
        }
    }

    return $out;
}

protected static function get_teacher_report_recipients(): array {
    $users = [];

    if (function_exists('\\get_admins')) {
        $admins = \get_admins();

        if (is_array($admins)) {
            foreach ($admins as $admin) {
                if (!$admin || empty($admin->id)) {
                    continue;
                }

                if (!empty($admin->deleted) || !empty($admin->suspended)) {
                    continue;
                }

                $users[(int)$admin->id] = $admin;
            }
        }
    }

    $kepsekuserid = (int)(get_config('local_akademikmonitor', 'kepalasekolahuserid') ?? 0);

    if ($kepsekuserid > 0) {
        $kepsek = \core_user::get_user($kepsekuserid);

        if ($kepsek && empty($kepsek->deleted) && empty($kepsek->suspended)) {
            $users[(int)$kepsek->id] = $kepsek;
        }
    }

    return array_values($users);
}
protected static function get_report_student_users(): array {
    global $DB;

    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);

    if (!$studentroleid) {
        return [];
    }

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.*
           FROM {user} u
      LEFT JOIN {role_assignments} ra
             ON ra.userid = u.id
            AND ra.roleid = :studentroleid
      LEFT JOIN {user_info_field} nif
             ON nif.shortname = :nisnfield
      LEFT JOIN {user_info_data} nid
             ON nid.userid = u.id
            AND nid.fieldid = nif.id
          WHERE u.deleted = 0
            AND u.suspended = 0
            AND u.id > 2
            AND (
                ra.id IS NOT NULL
                OR TRIM(COALESCE(nid.data, '')) <> ''
                OR TRIM(COALESCE(u.idnumber, '')) <> ''
            )
       ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC",
        [
            'studentroleid' => $studentroleid,
            'nisnfield' => 'nisn',
        ]
    );

    return $students ? array_values($students) : [];
}

protected static function get_student_weekly_reports(array $studentids, int $startts, int $endts): array {
    global $DB;

    $studentids = array_values(array_unique(array_map('intval', $studentids)));

    if (!$studentids) {
        return [];
    }

    [$studentsql, $studentparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'studentid');

    $likegeneratednew = $DB->sql_like('c.idnumber', ':newpattern', false);
    $likegeneratedold = $DB->sql_like('c.idnumber', ':oldpattern', false);

    $sql = "SELECT al.id,
                   al.studentid,
                   al.sessionid,
                   al.statusid,
                   al.remarks,
                   s.sessdate,
                   s.description,
                   c.id AS courseid,
                   c.fullname AS coursename,
                   st.acronym,
                   st.description AS statusdescription,
                   st.grade
              FROM {attendance_log} al
              JOIN {attendance_sessions} s ON s.id = al.sessionid
              JOIN {attendance} a ON a.id = s.attendanceid
              JOIN {course} c ON c.id = a.course
         LEFT JOIN {attendance_statuses} st ON st.id = al.statusid
             WHERE al.studentid {$studentsql}
               AND s.sessdate >= :starttime
               AND s.sessdate <= :endtime
               AND c.idnumber <> ''
               AND ({$likegeneratednew} OR {$likegeneratedold})
          ORDER BY al.studentid ASC, 
                   c.fullname ASC, 
                   s.sessdate ASC, 
                   s.id ASC, 
                   al.id ASC";

    $records = $DB->get_records_sql($sql, array_merge($studentparams, [
        'starttime' => $startts,
        'endtime' => $endts,
        'newpattern' => 'AM-TA%-K%-KM%-S%',
        'oldpattern' => 'AM-K%-KM%-S%',
    ]));

    $reports = [];

    foreach ($records as $record) {
        $studentid = (int)($record->studentid ?? 0);

        if ($studentid <= 0) {
            continue;
        }

        if (!isset($reports[$studentid])) {
            $reports[$studentid] = [
                'student' => null,
                'total' => 0,
                'present' => 0,
                'late' => 0,
                'excused' => 0,
                'absent' => 0,
                'other' => 0,
                'courses' => [],
                'details' => [],
            ];
        }

        $bucket = self::classify_attendance_status(
            (string)($record->acronym ?? ''),
            (string)($record->statusdescription ?? '')
        );

        $courseid = (int)($record->courseid ?? 0);
        $coursename = format_string((string)($record->coursename ?? '-'));
        $sessiondesc = trim(strip_tags((string)($record->description ?? '')));
        $sessiondesc = $sessiondesc !== '' ? $sessiondesc : 'Pertemuan';
        $sessiondate = !empty($record->sessdate)
            ? userdate((int)$record->sessdate, '%d %B %Y')
            : '-';

        if (!isset($reports[$studentid]['courses'][$courseid])) {
            $reports[$studentid]['courses'][$courseid] = [
                'name' => $coursename,
                'total' => 0,
                'present' => 0,
                'late' => 0,
                'excused' => 0,
                'absent' => 0,
                'other' => 0,
            ];
        }

        $reports[$studentid]['total']++;
        $reports[$studentid][$bucket['bucket']]++;
        $reports[$studentid]['courses'][$courseid]['total']++;
        $reports[$studentid]['courses'][$courseid][$bucket['bucket']]++;

        $reports[$studentid]['details'][] = [
            'date' => $sessiondate,
            'course' => $coursename,
            'session' => $sessiondesc,
            'status' => $bucket['label'],
        ];
    }

    return $reports;
}

protected static function classify_attendance_status(string $acronym, string $description): array {
    $acronym = strtolower(trim($acronym));
    $description = strtolower(trim($description));
    $text = $acronym . ' ' . $description;

    if (
        str_contains($text, 'present') ||
        str_contains($text, 'hadir') ||
        $acronym === 'p' ||
        $acronym === 'h'
    ) {
        return [
            'bucket' => 'present',
            'label' => 'Hadir',
        ];
    }

    if (
        str_contains($text, 'late') ||
        str_contains($text, 'terlambat') ||
        $acronym === 'l' ||
        $acronym === 't'
    ) {
        return [
            'bucket' => 'late',
            'label' => 'Terlambat',
        ];
    }

    if (
        str_contains($text, 'excused') ||
        str_contains($text, 'izin') ||
        str_contains($text, 'sakit') ||
        $acronym === 'e' ||
        $acronym === 'i' ||
        $acronym === 's'
    ) {
        return [
            'bucket' => 'excused',
            'label' => 'Izin/Sakit',
        ];
    }

    if (
        str_contains($text, 'absent') ||
        str_contains($text, 'alfa') ||
        str_contains($text, 'alpha') ||
        $acronym === 'a'
    ) {
        return [
            'bucket' => 'absent',
            'label' => 'Alfa',
        ];
    }

    return [
        'bucket' => 'other',
        'label' => $description !== '' ? ucfirst($description) : ($acronym !== '' ? strtoupper($acronym) : '-'),
    ];
}

protected static function build_teacher_daily_report_message(array $reports, int $startts, int $endts): string {
    $lines = [];

    $lines[] = '👨‍🏫 <b>Laporan Kehadiran Guru Harian</b>';
    $lines[] = '';
    $lines[] = '📅 Periode: <b>' . s(userdate($startts, '%d %B %Y')) . '</b>';
    $lines[] = '📊 Jumlah guru terdata: <b>' . count($reports) . '</b>';
    $lines[] = '';
    $lines[] = 'Ringkasan:';

    $totalpresent = 0;
    $totalabsent = 0;
    $totalsessions = 0;

    foreach ($reports as $report) {
        $totalpresent += (int)($report['present'] ?? 0);
        $totalabsent += (int)($report['absent'] ?? 0);
        $totalsessions += (int)($report['present'] ?? 0) + (int)($report['absent'] ?? 0);
    }

    $lines[] = '• Sesi hadir: <b>' . $totalpresent . '</b>';
    $lines[] = '• Sesi belum diambil: <b>' . $totalabsent . '</b>';
    $lines[] = '• Total sesi dicek: <b>' . $totalsessions . '</b>';
    $lines[] = '';

    $teacherIndex = 1;

    foreach ($reports as $report) {
        $teacher = $report['teacher'];
        $present = (int)($report['present'] ?? 0);
        $absent = (int)($report['absent'] ?? 0);
        $items = array_values($report['items'] ?? []);

        $lines[] = $teacherIndex . '. <b>' . s(fullname($teacher)) . '</b>';
        $lines[] = '   Hadir: <b>' . $present . '</b> | Belum diambil: <b>' . $absent . '</b>';

        $itemlimit = 8;
        $shown = 0;

        foreach ($items as $item) {
            $lines[] = '   • ' . s($item);
            $shown++;
            if ($shown >= $itemlimit) {
                break;
            }
        }

        if (count($items) > $itemlimit) {
            $lines[] = '   • ... dan ' . (count($items) - $itemlimit) . ' sesi lainnya';
        }

        $lines[] = '';
        $teacherIndex++;
    }

    return trim(implode("\n", $lines));
}

protected static function build_student_weekly_report_message(\stdClass $student, array $report, int $startts, int $endts): string {
    $total = (int)($report['total'] ?? 0);
    $present = (int)($report['present'] ?? 0);
    $late = (int)($report['late'] ?? 0);
    $excused = (int)($report['excused'] ?? 0);
    $absent = (int)($report['absent'] ?? 0);
    $other = (int)($report['other'] ?? 0);

    $lines = [];

    $lines[] = '📚 <b>Laporan Kehadiran Siswa Mingguan</b>';
    $lines[] = '';
    $lines[] = 'Halo <b>' . s(fullname($student)) . '</b>, berikut rekap presensi kamu.';
    $lines[] = '📅 Periode: <b>' . s(userdate($startts, '%d %B %Y')) . '</b> s/d <b>' . s(userdate($endts, '%d %B %Y')) . '</b>';
    $lines[] = '📊 Total catatan: <b>' . $total . '</b>';
    $lines[] = '• Hadir: <b>' . $present . '</b>';
    $lines[] = '• Terlambat: <b>' . $late . '</b>';
    $lines[] = '• Izin/Sakit: <b>' . $excused . '</b>';
    $lines[] = '• Alfa: <b>' . $absent . '</b>';
    $lines[] = '• Lainnya: <b>' . $other . '</b>';
    $lines[] = '';

    $courses = $report['courses'] ?? [];

    if ($courses) {
        $lines[] = 'Rekap per mapel:';

        uasort($courses, static function($a, $b) {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        foreach ($courses as $course) {
            $lines[] = '• <b>' . s((string)($course['name'] ?? '-')) . '</b>'
                . ' — H ' . (int)($course['present'] ?? 0)
                . ' | T ' . (int)($course['late'] ?? 0)
                . ' | I ' . (int)($course['excused'] ?? 0)
                . ' | A ' . (int)($course['absent'] ?? 0);
        }

        $lines[] = '';
    }

    $details = array_values($report['details'] ?? []);

    if ($details) {
        $lines[] = 'Detail terbaru:';

        $limit = 12;
        $shown = 0;

        foreach ($details as $detail) {

            $lines[] =
                '• ' .
                s($detail['date']) .
                ' | ' .
                s($detail['course']) .
                ' | ' .
                s($detail['status']);

            $shown++;

            if ($shown >= $limit) {
                break;
            }
        }

        if (count($details) > $limit) {
            $lines[] = '• ... dan ' . (count($details) - $limit) . ' catatan lainnya';
        }
    } else {
        $lines[] = 'Belum ada data presensi pada periode ini.';
    }

    return trim(implode("\n", $lines));
}

protected static function build_student_daily_report_message(
    \stdClass $student,
    array $report,
    int $date
): string {

    $lines = [];

    $lines[] = '📋 <b>Laporan Kehadiran Harian</b>';
    $lines[] = '';

    $lines[] = '👤 Nama: <b>' .
        s(fullname($student)) .
        '</b>';

    $lines[] = '📅 Tanggal: <b>' .
        s(userdate($date, '%d %B %Y')) .
        '</b>';

    $lines[] = '';

    $lines[] = '📊 Total Presensi: <b>' .
        (int)$report['total'] .
        '</b>';

    $lines[] = '✅ Hadir: <b>' .
        (int)$report['present'] .
        '</b>';

    $lines[] = '⏰ Terlambat: <b>' .
        (int)$report['late'] .
        '</b>';

    $lines[] = '🟡 Izin/Sakit: <b>' .
        (int)$report['excused'] .
        '</b>';

    $lines[] = '❌ Alfa: <b>' .
        (int)$report['absent'] .
        '</b>';

    $lines[] = '';

    if (!empty($report['details'])) {

        $lines[] = 'Detail:';

        $limit = 10;
        $shown = 0;

        foreach ($report['details'] as $detail) {

            $lines[] =
                '• ' .
                s($detail['date']) .
                ' | ' .
                s($detail['course']) .
                ' | ' .
                s($detail['status']);

            $shown++;

            if ($shown >= $limit) {
                break;
            }
        }

        if (count($report['details']) > $limit) {

            $lines[] =
                '• ... dan ' .
                (count($report['details']) - $limit) .
                ' catatan lainnya';
        }
    } else {

        $lines[] = 'Belum ada data presensi hari ini.';
    }

    return implode("\n", $lines);
}
protected static function build_teacher_daily_summary_message(
    string $coursename,
    int $total,
    int $present,
    int $excused,
    int $absent
): string {

    $lines = [];

    $lines[] = '📋 <b>Laporan Kehadiran Harian</b>';
    $lines[] = '';

    $lines[] = '📚 Mapel:';
    $lines[] = '<b>' . s($coursename) . '</b>';

    $lines[] = '';

    $lines[] = '👥 Jumlah siswa:';
    $lines[] = '<b>' . $total . '</b>';

    $lines[] = '';

    $lines[] = '✅ Hadir : <b>' . $present . '</b>';
    $lines[] = '🟡 Izin : <b>' . $excused . '</b>';
    $lines[] = '❌ Alfa : <b>' . $absent . '</b>';

    return implode("\n", $lines);
}

protected static function send_teacher_daily_attendance_summary(
    int $start,
    int $end,
    string $scheduledat
): void {

    global $DB;

    $sessions = self::get_generated_attendance_sessions_in_range(
        $start,
        $end
    );

    if (!$sessions) {
        return;
    }

    $coursemap = [];

    foreach ($sessions as $session) {

        $courseid = (int)$session->courseid;

        if (!isset($coursemap[$courseid])) {

            $coursemap[$courseid] = [
                'coursename' => $session->coursename,
                'present' => 0,
                'excused' => 0,
                'absent' => 0,
                'students' => 0,
            ];
        }
    }
        $studentids = [];

    $students = self::get_report_student_users();

    foreach ($students as $student) {
        $studentids[] = (int)$student->id;
    }

    $reports = self::get_student_weekly_reports(
        $studentids,
        $start,
        $end
    );
        foreach ($reports as $report) {

foreach (($report['courses'] ?? []) as $courseid => $course) {

    if (!isset($coursemap[$courseid])) {
        continue;
    }

    $coursemap[$courseid]['present'] +=
        (int)$course['present'];

    $coursemap[$courseid]['excused'] +=
        (int)$course['excused'];

    $coursemap[$courseid]['absent'] +=
        (int)$course['absent'];

    $coursemap[$courseid]['students']++;
}
    }
        foreach ($coursemap as $courseid => $data) {

        $teachers =
            self::get_course_teachers(
                (int)$courseid
            );

        if (!$teachers) {
            continue;
        }

        $message =
            self::build_teacher_daily_summary_message(
                $data['coursename'],
                (int)$data['students'],
                (int)$data['present'],
                (int)$data['excused'],
                (int)$data['absent']
            );

        foreach ($teachers as $teacher) {

            self::send_to_all_telegram_links(
                (int)$teacher->id,
                (int)$courseid,
                'kehadiran_siswa_harian_guru',
                0,
                0,
                'Laporan Kehadiran Harian Guru',
                $scheduledat,
                $message,
                'guru'
            );
        }
    }
}

    protected static function send_assignment_reminder_to_student(
    \stdClass $student,
    \stdClass $assign,
    int $offsetdays,
    string $duedate,
    string $scheduledat
): void {
    mtrace('[akademikmonitor] coba kirim ke siswa: ' . fullname($student) . ' | userid=' . (int)$student->id);

    $message = "📚 <b>Pengingat Tugas</b>\n\n" .
        "Halo <b>" . s(fullname($student)) . "</b>,\n" .
        "Tugas <b>" . s(format_string($assign->name)) . "</b> pada course <b>" . s(format_string($assign->coursename)) . "</b> " .
        "akan berakhir <b>H-" . $offsetdays . "</b>.\n\n" .
        "⏰ Deadline: <b>" . s($duedate) . "</b>\n" .
        "Status: <b>belum mengerjakan / belum submit</b>.";

    self::send_to_all_telegram_links(
        (int)$student->id,
        (int)$assign->course,
        'pengingat_tugas_siswa',
        (int)$assign->id,
        0,
        format_string($assign->name),
        $scheduledat,
        $message,
        'siswa'
    );
}

    protected static function send_assignment_reminder_to_wali(
    \stdClass $wali,
    array $students,
    \stdClass $assign,
    int $offsetdays,
    string $duedate,
    string $scheduledat
): void {
    if (!$students) {
        mtrace('[akademikmonitor] STOP wali: tidak ada siswa untuk dikirim.');
        return;
    }

    mtrace('[akademikmonitor] coba kirim ke wali: ' . fullname($wali) . ' | userid=' . (int)$wali->id);
    mtrace('[akademikmonitor] jumlah siswa belum submit untuk wali ini: ' . count($students));

    usort($students, function($a, $b) {
        return strcasecmp(fullname($a), fullname($b));
    });

    $studentlines = [];
    $number = 1;

    foreach ($students as $student) {
        $studentlines[] = $number . '. ' . s(fullname($student));
        $number++;
    }

    $jumlahsiswa = count($studentlines);

    $message = "👩‍🏫 <b>Pengingat Wali Kelas</b>\n\n" .
        "Halo <b>" . s(fullname($wali)) . "</b>,\n\n" .
        "Berikut daftar siswa yang <b>belum mengerjakan / belum submit</b> tugas:\n\n" .
        "📚 Tugas: <b>" . s(format_string($assign->name)) . "</b>\n" .
        "🏫 Course: <b>" . s(format_string($assign->coursename)) . "</b>\n" .
        "⏰ Deadline: <b>" . s($duedate) . "</b>\n" .
        "📌 Status: <b>H-" . $offsetdays . "</b>\n" .
        "👥 Jumlah siswa belum submit: <b>" . $jumlahsiswa . "</b>\n\n" .
        implode("\n", $studentlines);

    self::send_to_all_telegram_links(
        (int)$wali->id,
        (int)$assign->course,
        'pengingat_tugas_wali',
        (int)$assign->id,
        0,
        format_string($assign->name) . ' - Wali Kelas',
        $scheduledat,
        $message,
        'wali'
    );
}

    protected static function send_assignment_reminder_to_teacher(
    \stdClass $teacher,
    array $students,
    \stdClass $assign,
    int $offsetdays,
    string $duedate,
    string $scheduledat
): void {
    if (!$students) {
        mtrace('[akademikmonitor] STOP guru: tidak ada siswa untuk dikirim.');
        return;
    }

    mtrace('[akademikmonitor] coba kirim ke guru: ' . fullname($teacher) . ' | userid=' . (int)$teacher->id);
    mtrace('[akademikmonitor] jumlah siswa belum submit untuk guru ini: ' . count($students));

    usort($students, function($a, $b) {
        return strcasecmp(fullname($a), fullname($b));
    });

    $studentlines = [];
    $number = 1;

    foreach ($students as $student) {
        $studentlines[] = $number . '. ' . s(fullname($student));
        $number++;
    }

    $jumlahsiswa = count($studentlines);

    $message = "👨‍🏫 <b>Pengingat Guru Mata Pelajaran</b>\n\n" .
        "Halo <b>" . s(fullname($teacher)) . "</b>,\n\n" .
        "Berikut daftar siswa pada course Anda yang <b>belum mengerjakan / belum submit</b> tugas:\n\n" .
        "📚 Tugas: <b>" . s(format_string($assign->name)) . "</b>\n" .
        "🏫 Course: <b>" . s(format_string($assign->coursename)) . "</b>\n" .
        "⏰ Deadline: <b>" . s($duedate) . "</b>\n" .
        "📌 Status: <b>H-" . $offsetdays . "</b>\n" .
        "👥 Jumlah siswa belum submit: <b>" . $jumlahsiswa . "</b>\n\n" .
        implode("\n", $studentlines);

    self::send_to_all_telegram_links(
        (int)$teacher->id,
        (int)$assign->course,
        'pengingat_tugas_guru',
        (int)$assign->id,
        0,
        format_string($assign->name) . ' - Guru',
        $scheduledat,
        $message,
        'guru'
    );
}

    protected static function send_to_all_telegram_links(
    int $userid,
    int $courseid,
    string $rulecode,
    int $assignid,
    int $eventid,
    string $contexttitle,
    string $scheduledat,
    string $message,
    string $label = 'user',
    ?string $pdf = null,
    ?string $excel = null
): void {
    $links = notif_service::get_user_links($userid);

    if (!$links) {
        mtrace('[akademikmonitor] STOP ' . $label . ': belum ada data telegram_user_link.');
        return;
    }

    foreach ($links as $link) {
        $chatid = trim((string)($link->telegram_chat_id ?? ''));

        if ($chatid === '') {
            mtrace('[akademikmonitor] SKIP ' . $label . ': telegram_chat_id kosong.');
            continue;
        }

        if ((string)($link->is_linked ?? '') !== '1') {
            mtrace('[akademikmonitor] SKIP ' . $label . ': is_linked bukan 1. Nilai sekarang=' . (string)($link->is_linked ?? ''));
            continue;
        }

        if (notif_service::has_log_been_sent_to_chat(
            $userid,
            $rulecode,
            $assignid,
            $eventid,
            $scheduledat,
            $chatid
        )) {
            mtrace('[akademikmonitor] SKIP ' . $label . ': chat_id ini sudah pernah dikirimi.');
            continue;
        }

        $send = notif_service::send_telegram($chatid, $message);

        mtrace(
            '[akademikmonitor] hasil kirim ' . $label .
            ' ke chat_id ' . $chatid .
            ': ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL')
        );

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error ' . $label . ': ' . ($send['message'] ?? '-'));
        }

if (
    $send['ok']
    && $pdf
    && file_exists($pdf)
) {

    notif_service::send_telegram_document(
        $chatid,
        $pdf,
        '📄 Laporan Presensi PDF'
    );
}

if (
    $send['ok']
    && $excel
    && file_exists($excel)
) {

    notif_service::send_telegram_document(
        $chatid,
        $excel,
        '📊 Laporan Presensi Excel'
    );
}
       
        self::safe_save_delivery_log(
            $userid,
            $courseid,
            $rulecode,
            $assignid,
            $eventid,
            $contexttitle,
            $scheduledat,
            $chatid,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }
}

    protected static function has_student_submitted_assignment(int $assignid, int $studentid): bool {
        global $DB;

        return $DB->record_exists('assign_submission', [
            'assignment' => $assignid,
            'userid' => $studentid,
            'latest' => 1,
            'status' => 'submitted',
        ]);
    }

    /* ============================================================
     * 2. EVENT NILAI DI BAWAH KKTP
     * ============================================================ */

protected static function process_event_kktp(\stdClass $rule): void {
    global $DB;

    $offsetdays = (int)($rule->offset_days ?? 0);
    $sendtime = trim((string)($rule->send_time ?? '07:00:00'));
    $keyword = trim((string)($rule->event_keyword ?? ''));
    $recipientconfig = (string)($rule->recipients ?? '');

    mtrace('[akademikmonitor] Rule nilai_kktp mulai.');
    mtrace('[akademikmonitor] offset_days = ' . $offsetdays);
    mtrace('[akademikmonitor] send_time = ' . $sendtime);
    mtrace('[akademikmonitor] event_keyword = ' . $keyword);
    mtrace('[akademikmonitor] recipients = ' . $recipientconfig);

    if (!self::is_now_in_send_window($sendtime)) {
        mtrace('[akademikmonitor] STOP nilai_kktp: belum masuk jam kirim.');
        return;
    }

    $sendtosiswa = self::has_recipient($recipientconfig, ['siswa', 'student']);
    $sendtowali = self::has_recipient($recipientconfig, ['wali', 'wali kelas', 'walikelas']);
    $sendtoguru = self::has_recipient($recipientconfig, ['guru', 'teacher']);

    mtrace('[akademikmonitor] target siswa = ' . ($sendtosiswa ? 'YA' : 'TIDAK'));
    mtrace('[akademikmonitor] target wali = ' . ($sendtowali ? 'YA' : 'TIDAK'));
    mtrace('[akademikmonitor] target guru = ' . ($sendtoguru ? 'YA' : 'TIDAK'));

    if (!$sendtosiswa && !$sendtowali && !$sendtoguru) {
        mtrace('[akademikmonitor] STOP nilai_kktp: tidak punya target penerima.');
        return;
    }

    /*
     * Event diambil dari kalender Moodle.
     *
     * Perubahan penting:
     * - Sebelumnya hanya mengambil event yang punya courseid > 0.
     * - Sekarang site event juga dibaca.
     *
     * Kalau event punya courseid:
     *   event hanya dipakai untuk course itu.
     *
     * Kalau event tidak punya courseid / courseid = 0:
     *   event dianggap event global dari admin,
     *   lalu sistem mengecek semua generated course Akademik Monitor.
     */
    $start = strtotime('today +' . $offsetdays . ' day');
    $end = strtotime('tomorrow +' . $offsetdays . ' day') - 1;

    mtrace('[akademikmonitor] range event mulai = ' . date('Y-m-d H:i:s', $start));
    mtrace('[akademikmonitor] range event akhir = ' . date('Y-m-d H:i:s', $end));

    $params = [
        'starttime' => $start,
        'endtime' => $end,
    ];

    $select = 'timestart >= :starttime AND timestart <= :endtime';

    if ($keyword !== '') {
        $select .= ' AND (' .
            $DB->sql_like('name', ':kw1', false) .
            ' OR ' .
            $DB->sql_like('description', ':kw2', false) .
            ')';

        $params['kw1'] = '%' . $DB->sql_like_escape($keyword) . '%';
        $params['kw2'] = '%' . $DB->sql_like_escape($keyword) . '%';
    }

    $events = $DB->get_records_select(
        'event',
        $select,
        $params,
        'timestart ASC',
        'id, name, description, timestart, courseid'
    );

    mtrace('[akademikmonitor] jumlah event nilai_kktp ditemukan = ' . count($events));

    if (!$events) {
        mtrace('[akademikmonitor] STOP nilai_kktp: tidak ada event cocok.');
        return;
    }

    foreach ($events as $event) {
        mtrace('[akademikmonitor] proses event: ' . format_string($event->name));
        mtrace('[akademikmonitor] eventid = ' . (int)$event->id);
        mtrace('[akademikmonitor] event courseid = ' . (int)($event->courseid ?? 0));

        $targetcourses = self::get_kktp_target_courses_for_event($event);

        mtrace('[akademikmonitor] jumlah target course KKTP = ' . count($targetcourses));

        if (!$targetcourses) {
            mtrace('[akademikmonitor] SKIP event: tidak ada target course untuk dicek.');
            continue;
        }

        $scheduledat = date(
            'Y-m-d H:i:s',
            strtotime(date('Y-m-d', (int)$event->timestart) . ' ' . $sendtime)
        );

        $eventdate = userdate((int)$event->timestart, '%d %B %Y %H:%M');

        foreach ($targetcourses as $course) {
            $courseid = (int)$course->id;

            /*
             * Clone event supaya courseid dan coursename sesuai course yang sedang dicek.
             * Ini penting untuk pesan, log, guru mapel, dan wali kelas.
             */
            $eventcourse = clone $event;
            $eventcourse->courseid = $courseid;
            $eventcourse->coursename = format_string($course->fullname);

            mtrace('[akademikmonitor] cek course KKTP: ' . $eventcourse->coursename);
            mtrace('[akademikmonitor] courseid = ' . $courseid);

            $kktpinfo = self::get_course_kktp_info($courseid);

            if (!$kktpinfo) {
                mtrace('[akademikmonitor] SKIP course: KKTP course belum ditemukan. courseid=' . $courseid);
                continue;
            }

            if ((float)$kktpinfo->kktp <= 0) {
                mtrace('[akademikmonitor] SKIP course: KKTP course masih 0/kosong. courseid=' . $courseid);
                continue;
            }

            $students = self::get_course_students($courseid);

            mtrace('[akademikmonitor] jumlah siswa course event = ' . count($students));

            if (!$students) {
                mtrace('[akademikmonitor] SKIP course: tidak ada siswa pada course ini.');
                continue;
            }

            $studentids = array_map(function($student) {
                return (int)$student->id;
            }, $students);

            $grades = self::get_course_total_grades($courseid, $studentids);

            if (!$grades) {
                mtrace('[akademikmonitor] SKIP course: belum ada nilai course total untuk siswa.');
                continue;
            }

            $underkktp = [];

            foreach ($students as $student) {
                $userid = (int)$student->id;

                if (!array_key_exists($userid, $grades)) {
                    mtrace('[akademikmonitor] SKIP siswa nilai kosong: ' . fullname($student) . ' | userid=' . $userid);
                    continue;
                }

                $grade = $grades[$userid];

                if ($grade === null) {
                    mtrace('[akademikmonitor] SKIP siswa nilai null: ' . fullname($student) . ' | userid=' . $userid);
                    continue;
                }

                if ((float)$grade < (float)$kktpinfo->kktp) {
                    $underkktp[$userid] = [
                        'user' => $student,
                        'grade' => (float)$grade,
                        'kktp' => (float)$kktpinfo->kktp,
                        'mapel' => (string)$kktpinfo->nama_mapel,
                    ];

                    mtrace('[akademikmonitor] siswa di bawah KKTP: ' . fullname($student) .
                        ' | nilai=' . self::format_number($grade) .
                        ' | kktp=' . self::format_number($kktpinfo->kktp)
                    );
                }
            }

            mtrace('[akademikmonitor] jumlah siswa nilai < KKTP pada course ini = ' . count($underkktp));

            if (!$underkktp) {
                mtrace('[akademikmonitor] SKIP course: tidak ada siswa nilai di bawah KKTP.');
                continue;
            }

            if ($sendtosiswa) {
                foreach ($underkktp as $item) {
                    self::send_event_kktp_to_student(
                        $item,
                        $eventcourse,
                        $kktpinfo,
                        $offsetdays,
                        $eventdate,
                        $scheduledat
                    );
                }
            }

            if ($sendtowali) {
                $walimap = [];

                foreach ($underkktp as $item) {
                    $student = $item['user'];

                    $walis = self::get_walikelas_for_student_in_course(
                        (int)$student->id,
                        $courseid
                    );

                    mtrace('[akademikmonitor] jumlah wali untuk siswa ' . fullname($student) . ' = ' . count($walis));

                    foreach ($walis as $wali) {
                        $waliid = (int)$wali->id;

                        if (!isset($walimap[$waliid])) {
                            $walimap[$waliid] = [
                                'user' => $wali,
                                'items' => [],
                            ];
                        }

                        $walimap[$waliid]['items'][(int)$student->id] = $item;
                    }
                }

                mtrace('[akademikmonitor] jumlah wali event_kktp yang akan dikirimi = ' . count($walimap));

                foreach ($walimap as $data) {
                    self::send_event_kktp_to_wali(
                        $data['user'],
                        array_values($data['items']),
                        $eventcourse,
                        $kktpinfo,
                        $offsetdays,
                        $eventdate,
                        $scheduledat
                    );
                }
            }

            if ($sendtoguru) {
                $teachers = self::get_course_teachers($courseid);

                mtrace('[akademikmonitor] jumlah guru event_kktp yang akan dikirimi = ' . count($teachers));

                foreach ($teachers as $teacher) {
                    self::send_event_kktp_to_teacher(
                        $teacher,
                        array_values($underkktp),
                        $eventcourse,
                        $kktpinfo,
                        $offsetdays,
                        $eventdate,
                        $scheduledat
                    );
                }
            }
        }
    }
}

    protected static function send_event_kktp_to_student(
    array $item,
    \stdClass $event,
    \stdClass $kktpinfo,
    int $offsetdays,
    string $eventdate,
    string $scheduledat
): void {
    $student = $item['user'];
    $grade = (float)$item['grade'];
    $kktp = (float)$item['kktp'];

    mtrace('[akademikmonitor] coba kirim event_kktp ke siswa: ' . fullname($student) . ' | userid=' . (int)$student->id);

    $logrulecode = 'pengingat_event_kktp_siswa_c' . (int)$event->courseid;

    $message = "📅 <b>Pengingat Event Akademik</b>\n\n" .
        "Halo <b>" . s(fullname($student)) . "</b>,\n\n" .
        "Akan ada event <b>" . s(format_string($event->name)) . "</b> pada:\n" .
        "🗓️ <b>" . s($eventdate) . "</b>\n\n" .
        "Nilai Anda pada mapel/course berikut masih di bawah KKTP:\n\n" .
        "📚 Mapel: <b>" . s($kktpinfo->nama_mapel) . "</b>\n" .
        "🏫 Course: <b>" . s(format_string($event->coursename)) . "</b>\n" .
        "📊 Nilai Anda: <b>" . self::format_number($grade) . "</b>\n" .
        "🎯 KKTP: <b>" . self::format_number($kktp) . "</b>\n" .
        "⏰ Status event: <b>H-" . $offsetdays . "</b>\n\n" .
        "Mohon segera melakukan persiapan dan perbaikan belajar.";

    self::send_to_all_telegram_links(
        (int)$student->id,
        (int)$event->courseid,
        $logrulecode,
        0,
        (int)$event->id,
        format_string($event->name) . ' - Nilai < KKTP',
        $scheduledat,
        $message,
        'event_kktp siswa'
    );
}

    protected static function send_event_kktp_to_wali(
    \stdClass $wali,
    array $items,
    \stdClass $event,
    \stdClass $kktpinfo,
    int $offsetdays,
    string $eventdate,
    string $scheduledat
): void {
    if (!$items) {
        return;
    }

    mtrace('[akademikmonitor] coba kirim event_kktp ke wali: ' . fullname($wali) . ' | userid=' . (int)$wali->id);
    mtrace('[akademikmonitor] jumlah siswa nilai < KKTP untuk wali ini: ' . count($items));

    $logrulecode = 'pengingat_event_kktp_wali_c' . (int)$event->courseid;

    usort($items, function($a, $b) {
        return strcasecmp(fullname($a['user']), fullname($b['user']));
    });

    $lines = [];
    $number = 1;

    foreach ($items as $item) {
        $student = $item['user'];
        $lines[] = $number . '. ' . s(fullname($student)) . ' - Nilai: ' . self::format_number($item['grade']);
        $number++;
    }

    $message = "👩‍🏫 <b>Pengingat Wali Kelas - Event Akademik</b>\n\n" .
        "Halo <b>" . s(fullname($wali)) . "</b>,\n\n" .
        "Akan ada event <b>" . s(format_string($event->name)) . "</b> pada:\n" .
        "🗓️ <b>" . s($eventdate) . "</b>\n\n" .
        "Berikut anak wali Anda yang nilainya masih di bawah KKTP:\n\n" .
        "📚 Mapel: <b>" . s($kktpinfo->nama_mapel) . "</b>\n" .
        "🏫 Course: <b>" . s(format_string($event->coursename)) . "</b>\n" .
        "🎯 KKTP: <b>" . self::format_number($kktpinfo->kktp) . "</b>\n" .
        "⏰ Status event: <b>H-" . $offsetdays . "</b>\n" .
        "👥 Jumlah siswa: <b>" . count($items) . "</b>\n\n" .
        implode("\n", $lines);

    self::send_to_all_telegram_links(
        (int)$wali->id,
        (int)$event->courseid,
        $logrulecode,
        0,
        (int)$event->id,
        format_string($event->name) . ' - Wali Nilai < KKTP',
        $scheduledat,
        $message,
        'event_kktp wali'
    );
}

    protected static function send_event_kktp_to_teacher(
    \stdClass $teacher,
    array $items,
    \stdClass $event,
    \stdClass $kktpinfo,
    int $offsetdays,
    string $eventdate,
    string $scheduledat
): void {
    if (!$items) {
        return;
    }

    mtrace('[akademikmonitor] coba kirim event_kktp ke guru: ' . fullname($teacher) . ' | userid=' . (int)$teacher->id);
    mtrace('[akademikmonitor] jumlah siswa nilai < KKTP untuk guru ini: ' . count($items));

    $logrulecode = 'pengingat_event_kktp_guru_c' . (int)$event->courseid;

    usort($items, function($a, $b) {
        return strcasecmp(fullname($a['user']), fullname($b['user']));
    });

    $lines = [];
    $number = 1;

    foreach ($items as $item) {
        $student = $item['user'];
        $lines[] = $number . '. ' . s(fullname($student)) . ' - Nilai: ' . self::format_number($item['grade']);
        $number++;
    }

    $message = "👨‍🏫 <b>Pengingat Guru Mapel - Event Akademik</b>\n\n" .
        "Halo <b>" . s(fullname($teacher)) . "</b>,\n\n" .
        "Akan ada event <b>" . s(format_string($event->name)) . "</b> pada:\n" .
        "🗓️ <b>" . s($eventdate) . "</b>\n\n" .
        "Berikut siswa pada course Anda yang nilainya masih di bawah KKTP:\n\n" .
        "📚 Mapel: <b>" . s($kktpinfo->nama_mapel) . "</b>\n" .
        "🏫 Course: <b>" . s(format_string($event->coursename)) . "</b>\n" .
        "🎯 KKTP: <b>" . self::format_number($kktpinfo->kktp) . "</b>\n" .
        "⏰ Status event: <b>H-" . $offsetdays . "</b>\n" .
        "👥 Jumlah siswa: <b>" . count($items) . "</b>\n\n" .
        implode("\n", $lines);

    self::send_to_all_telegram_links(
        (int)$teacher->id,
        (int)$event->courseid,
        $logrulecode,
        0,
        (int)$event->id,
        format_string($event->name) . ' - Guru Nilai < KKTP',
        $scheduledat,
        $message,
        'event_kktp guru'
    );
}

protected static function get_course_kktp_info(int $courseid): ?\stdClass {
    global $DB;

    $courseid = (int)$courseid;

    if ($courseid <= 0) {
        return null;
    }

    /*
     * Tabel course_mapel di plugin ini tidak selalu punya kolom id.
     * Karena itu jangan SELECT cm.id dan jangan ORDER BY cm.id.
     *
     * Yang dibutuhkan untuk mencari KKTP hanya:
     * - cm.id_course
     * - cm.id_kurikulum_mapel
     * - km.kktp
     * - mp.nama_mapel
     */
    $sql = "SELECT cm.id_course,
                   cm.id_kurikulum_mapel,
                   km.id_mapel,
                   km.kktp,
                   mp.nama_mapel AS nama_mapel
              FROM {course_mapel} cm
              JOIN {kurikulum_mapel} km ON km.id = cm.id_kurikulum_mapel
         LEFT JOIN {mata_pelajaran} mp ON mp.id = km.id_mapel
             WHERE cm.id_course = :courseid";

    $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, 1);

    if (!$records) {
        return null;
    }

    $record = reset($records);
    $record->kktp = (float)($record->kktp ?? 0);

    if (empty($record->nama_mapel)) {
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id, fullname',
            IGNORE_MISSING
        );

        $record->nama_mapel = $course ? format_string($course->fullname) : '-';
    }

    return $record;
}
protected static function get_course_total_grades(
    int $courseid,
    array $userids
): array {
    global $DB;

    $courseid = (int)$courseid;

    $userids = array_values(
        array_unique(
            array_filter(
                array_map('intval', $userids)
            )
        )
    );

    if ($courseid <= 0 || !$userids) {
        return [];
    }

    // Ambil grade item yang sama dengan Monitoring Kelas.
    $gradeitem = $DB->get_record(
        'grade_items',
        [
            'courseid' => $courseid,
            'idnumber' => 'am_nilai_akhir',
        ],
        'id',
        IGNORE_MISSING
    );

    if (!$gradeitem) {

        mtrace(
            '[akademikmonitor] grade item am_nilai_akhir tidak ditemukan. courseid=' .
            $courseid
        );

        return [];
    }

    [$insql, $params] =
        $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'uid'
        );

    $params['itemid'] =
        (int)$gradeitem->id;

    $records =
        $DB->get_records_sql(
            "SELECT userid,
                    finalgrade
               FROM {grade_grades}
              WHERE itemid = :itemid
                AND userid {$insql}",
            $params
        );

    $grades = [];

    foreach ($records as $record) {

        $grades[(int)$record->userid] =
            $record->finalgrade === null
                ? null
                : (float)$record->finalgrade;
    }

    return $grades;
}
    // protected static function get_course_total_grades(int $courseid, array $userids): array {
    //     global $DB;

    //     $courseid = (int)$courseid;
    //     $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));

    //     if ($courseid <= 0 || !$userids) {
    //         return [];
    //     }

    //     $gradeitem = $DB->get_record(
    //         'grade_items',
    //         [
    //             'courseid' => $courseid,
    //             'itemtype' => 'course',
    //         ],
    //         'id, courseid, grademax',
    //         IGNORE_MISSING
    //     );

    //     if (!$gradeitem) {
    //         mtrace('[akademikmonitor] grade item course total tidak ditemukan. courseid=' . $courseid);
    //         return [];
    //     }

    //     [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
    //     $params['itemid'] = (int)$gradeitem->id;

    //     $sql = "SELECT gg.userid,
    //                    gg.finalgrade
    //               FROM {grade_grades} gg
    //              WHERE gg.itemid = :itemid
    //                AND gg.userid {$insql}";

    //     $records = $DB->get_records_sql($sql, $params);

    //     if (!$records) {
    //         return [];
    //     }

    //     $grades = [];

    //     foreach ($records as $record) {
    //         $grades[(int)$record->userid] = $record->finalgrade === null
    //             ? null
    //             : (float)$record->finalgrade;
    //     }

    //     return $grades;
    // }

    /* ============================================================
     * 3. PENGINGAT EVENT BIASA
     * ============================================================ */

    protected static function process_pengingat_event(\stdClass $rule): void {
    global $DB;

    $offsetdays = (int)($rule->offset_days ?? 0);
    $sendtime = trim((string)($rule->send_time ?? '07:00:00'));
    $keyword = trim((string)($rule->event_keyword ?? ''));

    mtrace('[akademikmonitor] Rule pengingat_event mulai.');

    if (!self::is_now_in_send_window($sendtime)) {
        mtrace('[akademikmonitor] STOP event: belum masuk jam kirim.');
        return;
    }

    $start = strtotime('today +' . $offsetdays . ' day');
    $end = strtotime('tomorrow +' . $offsetdays . ' day') - 1;

    $params = [
        'starttime' => $start,
        'endtime' => $end,
    ];

    $select = 'timestart >= :starttime AND timestart <= :endtime';

    if ($keyword !== '') {
        $select .= ' AND (' .
            $DB->sql_like('name', ':kw1', false) .
            ' OR ' .
            $DB->sql_like('description', ':kw2', false) .
            ')';

        $params['kw1'] = '%' . $DB->sql_like_escape($keyword) . '%';
        $params['kw2'] = '%' . $DB->sql_like_escape($keyword) . '%';
    }

    $events = $DB->get_records_select(
        'event',
        $select,
        $params,
        'timestart ASC',
        'id, name, description, timestart, courseid'
    );

    mtrace('[akademikmonitor] jumlah event ditemukan = ' . count($events));

    if (!$events) {
        mtrace('[akademikmonitor] Tidak ada event kalender untuk pengingat.');
        return;
    }

    foreach ($events as $event) {
        $recipients = self::resolve_event_recipients($event, (string)($rule->recipients ?? ''));

        $scheduledat = date(
            'Y-m-d H:i:s',
            strtotime(date('Y-m-d', (int)$event->timestart) . ' ' . $sendtime)
        );

        $eventdate = userdate((int)$event->timestart, '%d %B %Y %H:%M');

        foreach ($recipients as $user) {
            $message = "📅 <b>Pengingat Agenda</b>\n\n" .
                "Halo <b>" . s(fullname($user)) . "</b>,\n" .
                "Ada agenda <b>" . s(format_string($event->name)) . "</b> yang akan berlangsung <b>H-" . $offsetdays . "</b>.\n\n" .
                "🗓️ Waktu: <b>" . s($eventdate) . "</b>";

            self::send_to_all_telegram_links(
                (int)$user->id,
                (int)($event->courseid ?? 0),
                'pengingat_event',
                0,
                (int)$event->id,
                format_string($event->name),
                $scheduledat,
                $message,
                'event'
            );
        }
    }
}

    /* ============================================================
     * 4. HELPER
     * ============================================================ */

protected static function get_course_students(int $courseid): array {
    $context = \context_course::instance($courseid);

    $students = get_enrolled_users(
        $context,
        '',
        0,
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email, u.deleted, u.suspended'
    );

    if (!$students) {
        return [];
    }

    $studentrole = self::get_student_role();

    if (!$studentrole) {
        mtrace('[akademikmonitor] Role student tidak ditemukan.');
        return [];
    }

    $out = [];

    foreach ($students as $user) {
        if (!empty($user->deleted) || !empty($user->suspended)) {
            continue;
        }

        $userid = (int)$user->id;

        $isstudent = user_has_role_assignment(
            $userid,
            (int)$studentrole->id,
            (int)$context->id
        );

        if (!$isstudent) {
            continue;
        }

        $out[] = $user;
    }

    return $out;
}

    protected static function get_course_teachers(int $courseid): array {
        $context = \context_course::instance($courseid);

        $users = get_enrolled_users(
            $context,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email, u.deleted, u.suspended'
        );

        if (!$users) {
            return [];
        }

        $teacherroleids = self::get_teacher_role_ids();

        if (!$teacherroleids) {
            mtrace('[akademikmonitor] Role guru tidak ditemukan.');
            return [];
        }

        $out = [];

        foreach ($users as $user) {
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }

            foreach ($teacherroleids as $roleid) {
                if (user_has_role_assignment((int)$user->id, (int)$roleid, (int)$context->id)) {
                    $out[(int)$user->id] = $user;
                    break;
                }
            }
        }

        return array_values($out);
    }

protected static function get_walikelas_for_student_in_course(int $studentid, int $courseid): array {
    global $DB;

    $studentid = (int)$studentid;
    $courseid = (int)$courseid;

    if ($studentid <= 0 || $courseid <= 0) {
        return [];
    }

    /*
     * Wali kelas di plugin ini tidak wajib punya role khusus bernama walikelas.
     * Wali kelas utama disimpan di tabel kelas.id_user.
     *
     * Alurnya:
     * course Moodle -> idnumber course -> id kelas -> kelas.id_user -> user wali kelas.
     */
    $kelasid = self::get_kelasid_from_courseid($courseid);

    if ($kelasid <= 0) {
        mtrace('[akademikmonitor] STOP wali: kelasid tidak ditemukan dari courseid=' . $courseid);
        return [];
    }

    $kelas = $DB->get_record(
        'kelas',
        ['id' => $kelasid],
        'id, nama, tingkat, id_user',
        IGNORE_MISSING
    );

    if (!$kelas) {
        mtrace('[akademikmonitor] STOP wali: data kelas tidak ditemukan. kelasid=' . $kelasid);
        return [];
    }

    $waliid = (int)($kelas->id_user ?? 0);

    if ($waliid <= 0) {
        mtrace('[akademikmonitor] STOP wali: kelas.id_user kosong. kelasid=' . $kelasid);
        return [];
    }

    $wali = $DB->get_record(
        'user',
        ['id' => $waliid],
        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email, deleted, suspended',
        IGNORE_MISSING
    );

    if (!$wali) {
        mtrace('[akademikmonitor] STOP wali: user wali kelas tidak ditemukan. userid=' . $waliid);
        return [];
    }

    if (!empty($wali->deleted) || !empty($wali->suspended)) {
        mtrace('[akademikmonitor] STOP wali: user wali kelas deleted/suspended. userid=' . $waliid);
        return [];
    }

    return [(int)$wali->id => $wali];
}
protected static function get_kelasid_from_courseid(int $courseid): int {
    global $DB;

    $courseid = (int)$courseid;

    if ($courseid <= 0) {
        return 0;
    }

    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id, idnumber',
        IGNORE_MISSING
    );

    if (!$course || empty($course->idnumber)) {
        return 0;
    }

    $idnumber = trim((string)$course->idnumber);

    /*
     * Format baru:
     * AM-TA{id_tahunajaran}-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
     *
     * Format lama:
     * AM-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
     */
    if (preg_match('/-K(\d+)-/', $idnumber, $matches)) {
        return (int)$matches[1];
    }

    if (preg_match('/^AM-K(\d+)-/', $idnumber, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}
protected static function get_kktp_target_courses_for_event(\stdClass $event): array {
    global $DB;

    $eventcourseid = (int)($event->courseid ?? 0);

    /*
     * Konsep:
     *
     * 1. Kalau event dibuat di course hasil generate Akademik Monitor,
     *    maka sistem hanya mengecek course itu saja.
     *
     * 2. Kalau event dibuat sebagai site event / event global kalender Moodle,
     *    biasanya courseid bisa 0 atau bisa mengarah ke frontpage/site course.
     *    Dalam kondisi ini, sistem mengecek semua generated course Akademik Monitor.
     *
     * Kenapa tidak cukup cek courseid > 0?
     * Karena site event Moodle bisa saja tetap punya courseid frontpage.
     * Jadi yang dicek bukan hanya courseid, tapi juga idnumber course-nya.
     */
    if ($eventcourseid > 0) {
        $course = $DB->get_record(
            'course',
            ['id' => $eventcourseid],
            'id, fullname, idnumber, visible',
            IGNORE_MISSING
        );

        if ($course && self::is_generated_akademikmonitor_course($course)) {
            if (isset($course->visible) && (int)$course->visible === 0) {
                mtrace('[akademikmonitor] target course event tidak visible. courseid=' . $eventcourseid);
                return [];
            }

            return [(int)$course->id => $course];
        }

        /*
         * Kalau courseid ada tapi bukan course generated Akademik Monitor,
         * event ini dianggap global/site event.
         */
        mtrace('[akademikmonitor] event bukan course generated Akademik Monitor, diproses sebagai event global. courseid=' . $eventcourseid);
    }

    /*
     * Event global:
     * Ambil semua course hasil generate Akademik Monitor.
     */
    $likegeneratednew = $DB->sql_like('idnumber', ':newpattern', false);
    $likegeneratedold = $DB->sql_like('idnumber', ':oldpattern', false);

    $select = "idnumber <> ''
               AND ({$likegeneratednew} OR {$likegeneratedold})";

    $params = [
        'newpattern' => 'AM-TA%-K%-KM%-S%',
        'oldpattern' => 'AM-K%-KM%-S%',
    ];

    $courses = $DB->get_records_select(
        'course',
        $select,
        $params,
        'fullname ASC',
        'id, fullname, idnumber, visible'
    );

    if (!$courses) {
        mtrace('[akademikmonitor] tidak ada generated course Akademik Monitor untuk event global KKTP.');
        return [];
    }

    $out = [];

    foreach ($courses as $course) {
        if (isset($course->visible) && (int)$course->visible === 0) {
            continue;
        }

        $out[(int)$course->id] = $course;
    }

    return $out;
}
protected static function is_generated_akademikmonitor_course(\stdClass $course): bool {
    $idnumber = trim((string)($course->idnumber ?? ''));

    if ($idnumber === '') {
        return false;
    }

    if (preg_match('/^AM-TA\d+-K\d+-KM\d+-S\d+$/', $idnumber)) {
        return true;
    }

    if (preg_match('/^AM-K\d+-KM\d+-S\d+$/', $idnumber)) {
        return true;
    }

    return false;
}
protected static function resolve_event_recipients(\stdClass $event, string $recipientconfig): array {
    $users = [];

    /*
     * Konsep:
     *
     * 1. Kalau event dibuat di course hasil generate Akademik Monitor,
     *    maka notifikasi hanya dikirim ke course tersebut.
     *
     * 2. Kalau event dibuat dari kalender admin / site event,
     *    Moodle biasanya memberi courseid = 1 atau course frontpage.
     *    Course seperti itu tidak punya pola idnumber Akademik Monitor,
     *    jadi harus dianggap sebagai event global sekolah.
     *
     * 3. Event global sekolah dikirim ke semua user yang terkait
     *    dengan semua generated course Akademik Monitor.
     */
    $courses = self::get_event_target_courses($event);

    mtrace('[akademikmonitor] jumlah target course pengingat_event = ' . count($courses));

    foreach ($courses as $course) {
        $courseid = (int)$course->id;

        mtrace('[akademikmonitor] target pengingat_event course: ' . $course->fullname . ' | courseid=' . $courseid);

        if (self::has_recipient($recipientconfig, ['siswa', 'student'])) {
            $users = array_merge($users, self::get_course_students($courseid));
        }

        if (self::has_recipient($recipientconfig, ['guru', 'teacher'])) {
            $users = array_merge($users, self::get_course_teachers($courseid));
        }

        if (self::has_recipient($recipientconfig, ['wali', 'wali kelas', 'walikelas'])) {
            $users = array_merge($users, self::get_walikelas_by_course($courseid));
        }
    }

    /*
     * Karena event global membaca banyak course, user bisa dobel.
     * Contoh:
     * - siswa yang mengambil beberapa mapel
     * - wali kelas yang muncul di banyak course kelasnya
     * - guru yang mengajar beberapa course
     *
     * Maka disatukan berdasarkan user id.
     */
    $unique = [];

    foreach ($users as $user) {
        $unique[(int)$user->id] = $user;
    }

    mtrace('[akademikmonitor] jumlah penerima unik pengingat_event = ' . count($unique));

    return array_values($unique);
}
protected static function get_event_target_courses(\stdClass $event): array {
    global $DB;

    $eventcourseid = (int)($event->courseid ?? 0);

    /*
     * Kalau event dibuat di course mapel yang dihasilkan plugin,
     * notifikasi cukup menargetkan course itu saja.
     */
    if ($eventcourseid > 0) {
        $course = $DB->get_record(
            'course',
            ['id' => $eventcourseid],
            'id, fullname, idnumber, visible',
            IGNORE_MISSING
        );

        if ($course && self::is_generated_akademikmonitor_course($course)) {
            if (isset($course->visible) && (int)$course->visible === 0) {
                mtrace('[akademikmonitor] target event course tidak visible. courseid=' . $eventcourseid);
                return [];
            }

            return [(int)$course->id => $course];
        }

        /*
         * Kalau courseid ada tapi bukan generated course Akademik Monitor,
         * contohnya courseid=1 dari site/frontpage calendar,
         * maka event dianggap global sekolah.
         */
        mtrace('[akademikmonitor] event pengingat biasa dianggap global. courseid=' . $eventcourseid);
    }

    /*
     * Event global:
     * ambil semua course hasil generate plugin Akademik Monitor.
     */
    $likegeneratednew = $DB->sql_like('idnumber', ':newpattern', false);
    $likegeneratedold = $DB->sql_like('idnumber', ':oldpattern', false);

    $select = "idnumber <> ''
               AND ({$likegeneratednew} OR {$likegeneratedold})";

    $params = [
        'newpattern' => 'AM-TA%-K%-KM%-S%',
        'oldpattern' => 'AM-K%-KM%-S%',
    ];

    $courses = $DB->get_records_select(
        'course',
        $select,
        $params,
        'fullname ASC',
        'id, fullname, idnumber, visible'
    );

    if (!$courses) {
        mtrace('[akademikmonitor] tidak ada generated course Akademik Monitor untuk pengingat_event global.');
        return [];
    }

    $out = [];

    foreach ($courses as $course) {
        if (isset($course->visible) && (int)$course->visible === 0) {
            continue;
        }

        $out[(int)$course->id] = $course;
    }

    return $out;
}
protected static function get_walikelas_by_course(int $courseid): array {
    global $DB;

    $kelasid = self::get_kelasid_from_courseid($courseid);

    if ($kelasid <= 0) {
        mtrace('[akademikmonitor] STOP wali event: kelasid tidak ditemukan dari courseid=' . $courseid);
        return [];
    }

    $kelas = $DB->get_record(
        'kelas',
        ['id' => $kelasid],
        'id, id_user',
        IGNORE_MISSING
    );

    if (!$kelas || empty($kelas->id_user)) {
        mtrace('[akademikmonitor] STOP wali event: kelas.id_user kosong. kelasid=' . $kelasid);
        return [];
    }

    $wali = $DB->get_record(
        'user',
        ['id' => (int)$kelas->id_user],
        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email, deleted, suspended',
        IGNORE_MISSING
    );

    if (!$wali) {
        return [];
    }

    if (!empty($wali->deleted) || !empty($wali->suspended)) {
        return [];
    }

    return [(int)$wali->id => $wali];
}
    protected static function get_student_role(): ?\stdClass {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'student']);

        return $role ?: null;
    }

protected static function get_teacher_role_ids(): array {
    global $DB;

    /*
     * Guru mapel harus diambil dari role editingteacher saja.
     *
     * Kenapa tidak pakai teacher?
     * Karena di plugin ini role teacher dipakai untuk wali kelas viewer.
     * Kalau teacher ikut dihitung sebagai guru, wali kelas akan salah terbaca
     * sebagai guru mapel di semua course kelasnya.
     */
    $role = $DB->get_record(
        'role',
        ['shortname' => 'editingteacher'],
        'id, shortname',
        IGNORE_MISSING
    );

    if (!$role) {
        return [];
    }

    return [(int)$role->id];
}



    protected static function has_recipient(string $recipientconfig, array $needles): bool {
        $recipientconfig = strtolower(trim($recipientconfig));

        if ($recipientconfig === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if (strpos($recipientconfig, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

protected static function is_now_in_send_window(string $sendtime): bool {

    $now = time();

    $today = date('Y-m-d', $now);

    $target = strtotime($today . ' ' . $sendtime);

    if (!$target) {
        return false;
    }

    $diff = $now - $target;

    return $diff >= 0 && $diff <= 3600;
}

    protected static function format_number($number): string {
        if ($number === null || $number === '') {
            return '-';
        }

        $number = (float)$number;

        if (floor($number) == $number) {
            return (string)(int)$number;
        }

        return number_format($number, 2, ',', '.');
    }

// protected static function send_report_files_to_user(
//     int $userid,
//     string $pdf,
//     string $excel
// ): void {

//     $links = notif_service::get_user_links($userid);

//     if (!$links) {
//         return;
//     }

//     foreach ($links as $link) {

//         if (empty($link->telegram_chat_id)) {
//             continue;
//         }

//         if ((string)$link->is_linked !== '1') {
//             continue;
//         }

//         notif_service::send_telegram_document(
//             $link->telegram_chat_id,
//             $pdf,
//             '📄 Laporan Presensi PDF'
//         );

//         notif_service::send_telegram_document(
//             $link->telegram_chat_id,
//             $excel,
//             '📊 Laporan Presensi Excel'
//         );
//     }
// }

    protected static function safe_save_delivery_log(
        int $userid,
        int $courseid,
        string $rulecode,
        int $assignid,
        int $eventid,
        string $contexttitle,
        string $scheduledat,
        string $chatid,
        string $messagepreview,
        string $status,
        string $errormessage = ''
    ): void {
        try {
            notif_service::save_delivery_log(
                $userid,
                $courseid,
                $rulecode,
                $assignid,
                $eventid,
                $contexttitle,
                $scheduledat,
                $chatid,
                $messagepreview,
                $status,
                $errormessage
            );
        } catch (\Throwable $e) {
            mtrace('[akademikmonitor] Gagal simpan log pengiriman: ' . $e->getMessage());
        }
    }
}