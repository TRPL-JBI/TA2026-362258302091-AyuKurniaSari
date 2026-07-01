<?php
namespace local_akademikmonitor\service\walikelas;

use local_akademikmonitor\service\period_filter_service;

defined('MOODLE_INTERNAL') || die();

class dashboard_service {

    public static function get_page_data(int $userid): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $semester = period_filter_service::get_selected_semester();
        $tahunajaranid = period_filter_service::get_selected_tahunajaranid();

        /*
         * Sidebar harus dikirim dengan userid dan tahunajaranid.
         * Ini penting supaya show_pkl_menu bisa dihitung berdasarkan
         * apakah wali kelas punya kelas XII atau tidak.
         */
        $data = common_service::get_sidebar_data('dashboard', $userid, $tahunajaranid);

        $kelasdata = [];
        $totalclasses = 0;
        $totalstudents = 0;

        $groups = common_service::get_group_walikelas_by_tahunajaran($userid, $tahunajaranid);

foreach ($groups as $group) {

    $siswas = common_service::get_siswa_group(
        (int)$group->id,
        $userid
    );

    $rows = [];

    // =========================
    // BULK FETCH COURSES
    // =========================
    $userids = array_map(
        fn($s) => (int)$s->id,
        $siswas
    );

    $coursesbyuser = [];

    if (!empty($userids)) {

        list($insql, $params) = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED
        );

        $sql = "
            SELECT
                ue.userid,
                c.id,
                c.fullname
            FROM {user_enrolments} ue
            JOIN {enrol} e
                ON e.id = ue.enrolid
            JOIN {course} c
                ON c.id = e.courseid
            WHERE ue.userid $insql
            AND c.id <> :siteid
            ORDER BY c.fullname ASC
        ";

        $params['siteid'] = SITEID;

        $records = $DB->get_recordset_sql($sql, $params);

        foreach ($records as $record) {

            $coursesbyuser[$record->userid][] = [
                'coursename' => (string)$record->fullname,
            ];
        }

        $records->close();
    }

    // =========================
    // LOOP SISWA
    // =========================
    foreach ($siswas as $siswa) {

        $courses = $coursesbyuser[$siswa->id] ?? [];

        $rows[] = [
            'nama' => fullname($siswa),
            'username' => (string)($siswa->username ?? '-'),
            'email' => (string)($siswa->email ?? '-'),
            'courses' => $courses,
            'has_courses' => !empty($courses),
        ];
    }

    $jumlahsiswa = count($siswas);

    $totalstudents += $jumlahsiswa;
    $totalclasses++;

    $kelasdata[] = [
        'nama' => (string)$group->name,
        'totalsiswa' => $jumlahsiswa,
        'siswa' => $rows,
        'has_siswa' => !empty($rows),
    ];
}

        /*
         * show_pkl_menu sudah dikirim dari common_service::get_sidebar_data().
         * Kalau nilainya true, berarti wali kelas punya kelas XII.
         * Kalau false, berarti PKL tidak boleh muncul di sidebar maupun card dashboard.
         */
        $showpkl = !empty($data['show_pkl_menu']);

        $cards = [
            [
                'title' => 'Monitoring Kelas',
                'desc' => 'Lihat data siswa dan pemantauan kelas yang Anda ampu.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php'))->out(false),
                'icon' => '📋',
            ],
            [
                'title' => 'Ekstrakurikuler Siswa',
                'desc' => 'Kelola data ekstrakurikuler siswa per kelas.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php'))->out(false),
                'icon' => '🏅',
            ],
        ];

        if ($showpkl) {
            $cards[] = [
                'title' => 'PKL Siswa',
                'desc' => 'Lihat dan kelola data PKL siswa beserta mitra DU/DI.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php'))->out(false),
                'icon' => '🏢',
            ];
        }

        $cards[] = [
            'title' => 'Raport',
            'desc' => 'Akses halaman raport siswa.',
            'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/rapor/index.php'))->out(false),
            'icon' => '📝',
        ];

        $data['welcometitle'] = 'Selamat Datang di Dashboard Wali Kelas';

        if ($showpkl) {
            $data['welcomesubtitle'] = 'Kelola monitoring kelas, ekstrakurikuler, PKL, dan raport siswa dari satu halaman.';
        } else {
            $data['welcomesubtitle'] = 'Kelola monitoring kelas, ekstrakurikuler, dan raport siswa dari satu halaman.';
        }

        $data['welcomedesc'] = 'Dashboard ini membantu wali kelas memantau data peserta didik, membuka menu utama dengan cepat, dan melihat ringkasan kelas yang diampu.';

        $data['summarycards'] = [
            [
                'label' => 'Total Kelas',
                'value' => $totalclasses,
                'icon' => '🏫',
            ],
            [
                'label' => 'Total Siswa',
                'value' => $totalstudents,
                'icon' => '👨‍🎓',
            ],
            [
                'label' => 'Menu Aktif',
                'value' => count($cards),
                'icon' => '🧩',
            ],
        ];

        $data['cards'] = $cards;
        $data['kelas'] = $kelasdata;
        $data['has_kelas'] = !empty($kelasdata);

        $data['selectedsemester'] = $semester;
        $data['selectedtahunajaranid'] = $tahunajaranid;
        $data['selected_tahunajaranid'] = $tahunajaranid;

        $data += period_filter_service::build_filter_data();

        $filterdata = period_filter_service::get_filter_ui_data(
            '/local/akademikmonitor/pages/walikelas/dashboard.php'
        );

        $data['periodfilter'] = $filterdata['periodfilter'] ?? [];

        return $data;
    }
}