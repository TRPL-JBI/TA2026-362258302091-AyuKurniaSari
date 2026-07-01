<?php
defined('MOODLE_INTERNAL') || die();
function local_akademikmonitor_extend_navigation_frontpage(
    navigation_node $navigation
) {
    \local_akademikmonitor\navigation\views\primary::extend($navigation);
}
function local_akademikmonitor_extend_navigation(global_navigation $nav) {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Menu khusus wali kelas.
    if (local_akademikmonitor_is_wali_kelas_user((int)$USER->id)) {

        $url = new moodle_url(
            '/local/akademikmonitor/pages/walikelas/dashboard.php'
        );

        $nav->add(
            'Monitoring Siswa',
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'monitoringsiswa',
            new pix_icon('i/report', '')
        );
    }
        // ── Menu Capaian & Tujuan Pembelajaran (guru) ────────────────────────────
    // Muncul di sebelah "Monitoring Siswa" untuk semua guru yang mengajar
    // minimal satu course (editingteacher / teacher).
if (
    local_akademikmonitor_is_guru_user((int)$USER->id)
){

        
        $nav->add(
            'Capaian & Tujuan Pembelajaran',
            new moodle_url('/local/akademikmonitor/pages/guru/tp_pilih_course.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'guru_cp_tp_akademikmonitor',
            new pix_icon('i/edit', '')
        );
    }
}

/**
 * Menambahkan menu ke user dropdown (avatar).
 *
 * Konsep plugin:
 * - Pengaturan Notifikasi muncul untuk semua user yang login.
 * - Monitoring Siswa muncul jika user terdaftar sebagai wali kelas
 *   pada tabel kelas plugin.
 *
 * Kenapa tidak pakai user_has_role_assignment($user->id, 9)?
 * Karena ID role bisa berubah ketika pindah Moodle / database baru.
 * Di Moodle lama mungkin role wali kelas ID-nya 9, tetapi di Moodle baru
 * belum tentu sama. Selain itu, pada konsep plugin ini wali kelas ditentukan
 * dari rombel, bukan dari role global Moodle.
 */
function local_akademikmonitor_extend_navigation_user_settings(
    navigation_node $navigation,
    stdClass $user,
    context_user $usercontext,
    stdClass $course,
    context $context
) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $navigation->add(
        'Pengaturan Notifikasi',
        new moodle_url('/local/akademikmonitor/pages/telegram/index.php'),
        navigation_node::TYPE_SETTING,
        null,
        'telegramconnect',
        new pix_icon('i/settings', '')
    );

    if (local_akademikmonitor_is_wali_kelas_user((int)$user->id)) {
        $navigation->add(
            'Monitoring siswa',
            new moodle_url('/local/akademikmonitor/pages/walikelas/dashboard.php'),
            navigation_node::TYPE_SETTING,
            null,
            'walikelasdashboard',
            new pix_icon('i/dashboard', '')
        );
    }
}

/**
 * Mengecek apakah user adalah wali kelas berdasarkan data rombel.
 *
 * Sumber paling valid untuk plugin kamu adalah tabel kelas,
 * karena wali kelas dipilih saat membuat/mengedit rombel.
 *
 * Kalau user ada di kolom kelas.id_user, berarti dia adalah wali kelas
 * untuk minimal satu rombel.
 */
function local_akademikmonitor_is_wali_kelas_user(int $userid): bool {
    global $DB;

    if ($userid <= 0) {
        return false;
    }

    return $DB->record_exists('kelas', ['id_user' => $userid]);
}
/**
 * Mengecek apakah user adalah guru (editingteacher / teacher)
 * yang mengajar minimal satu course di Moodle.
 *
 * Cara cek: cari role dengan archetype 'editingteacher' atau 'teacher'
 * lalu lihat apakah user punya role assignment tersebut di context course.
 *
 * Kenapa tidak pakai ID role langsung?
 * Karena ID role bisa berbeda antar instalasi Moodle.
 * Pakai archetype lebih aman dan portable.
 */
function local_akademikmonitor_is_guru_user(int $userid): bool {
    global $DB;

    if ($userid <= 0) {
        return false;
    }

    // Ambil semua role dengan archetype editingteacher atau teacher.
    $roles = $DB->get_records_list('role', 'archetype', ['editingteacher', 'teacher'], '', 'id');

    if (empty($roles)) {
        return false;
    }

    $roleids = array_keys($roles);

    // Cek apakah user punya salah satu role tersebut di context course manapun.
    [$insql, $inparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'roleid');

    $inparams['userid']      = $userid;
    $inparams['contextlevel'] = CONTEXT_COURSE;

    $sql = "SELECT ra.id
              FROM {role_assignments} ra
              JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid = :userid
               AND ra.roleid {$insql}
               AND ctx.contextlevel = :contextlevel";

    return $DB->record_exists_sql($sql, $inparams);
}

/**
 * Menambahkan link Kartu Ujian pada halaman profil user Moodle.
 *
 * Link ini hanya muncul kalau user tersebut punya kartu ujian
 * dan kartu ujiannya sudah dipublish.
 */
function local_akademikmonitor_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
) {
    global $DB, $USER;

    if (empty($user->id) || !isloggedin() || isguestuser()) {
        return;
    }

    $userid = (int)$user->id;
    $currentuserid = (int)($USER->id ?? 0);

    $context = context_user::instance($userid);

    /*
     * Yang boleh melihat:
     * - siswa itu sendiri
     * - admin/manager yang punya izin melihat detail user.
     */
    $canview = ($currentuserid === $userid) || has_capability('moodle/user:viewdetails', $context);

    if (!$canview) {
        return;
    }

    /*
     * Status yang dianggap boleh tampil di profil.
     *
     * "layak" wajib dimasukkan karena dari kasusmu,
     * kartu ujian siswa memakai status layak.
     */
    $allowedstatuses = [
        'published',
        'publish',
        'aktif',
        'terbit',
        'layak',
    ];

    $params = [
        'userid' => $userid,
    ];

    $statusconditions = [];

    /*
     * Cek kolom status di tabel kartu_ujian.
     * Ini untuk status publish secara global di data kartu ujian.
     */
    $kucolumns = $DB->get_columns('kartu_ujian');

    if (isset($kucolumns['status'])) {
        [$kuinsql, $kuparams] = $DB->get_in_or_equal(
            $allowedstatuses,
            SQL_PARAMS_NAMED,
            'profilekustatus'
        );

        $statusconditions[] = "LOWER(ku.status) {$kuinsql}";

        foreach ($kuparams as $key => $value) {
            $params[$key] = strtolower((string)$value);
        }
    }

    /*
     * Cek kolom status di tabel kartu_ujian_siswa.
     * Ini untuk status per siswa, misalnya "layak".
     */
    $kuscolumns = $DB->get_columns('kartu_ujian_siswa');

    if (isset($kuscolumns['status'])) {
        [$kusinsql, $kusparams] = $DB->get_in_or_equal(
            $allowedstatuses,
            SQL_PARAMS_NAMED,
            'profilekusstatus'
        );

        $statusconditions[] = "LOWER(kus.status) {$kusinsql}";

        foreach ($kusparams as $key => $value) {
            $params[$key] = strtolower((string)$value);
        }
    }

    /*
     * Kalau ada kolom status, kartu hanya tampil jika statusnya termasuk daftar allowed.
     * Kalau tidak ada kolom status, kartu tetap tampil selama relasi user ada di kartu_ujian_siswa.
     */
    $statussql = '';

    if (!empty($statusconditions)) {
        $statussql = ' AND (' . implode(' OR ', $statusconditions) . ')';
    }

    $sql = "SELECT kus.id
              FROM {kartu_ujian_siswa} kus
              JOIN {kartu_ujian} ku ON ku.id = kus.id_kartu_ujian
             WHERE kus.id_user = :userid
                   {$statussql}
          ORDER BY ku.id DESC";

    $haspublishedcard = $DB->record_exists_sql($sql, $params);

    if (!$haspublishedcard) {
        return;
    }

    /*
     * Tambahkan kategori khusus di halaman profil.
     */
    $category = new \core_user\output\myprofile\category(
        'akademikmonitor',
        'Akademik & Monitoring',
        null
    );

    $tree->add_category($category);

    /*
     * Tambahkan node/link Kartu Ujian.
     */
    $url = new moodle_url('/local/akademikmonitor/pages/kartu_ujian/profil_siswa.php', [
        'userid' => $userid,
    ]);

    $node = new \core_user\output\myprofile\node(
        'akademikmonitor',
        'kartuujian',
        'Kartu Ujian',
        null,
        $url
    );

    $tree->add_node($node);
}
/**
 * Menambahkan menu "Tujuan Pembelajaran Saya" di navigasi sidebar course
 * untuk guru — sebagai alternatif akses selain menu di frontpage.
 */
function local_akademikmonitor_extend_navigation_course_settings(
    navigation_node $navigation,
    stdClass $course
) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $coursecontext = context_course::instance($course->id);

    if (!has_capability('moodle/course:manageactivities', $coursecontext)) {
        return;
    }

    $url = new moodle_url(
        '/local/akademikmonitor/pages/guru/tp.php',
        ['courseid' => $course->id]
    );

    $navigation->add(
        'Tujuan Pembelajaran Saya',
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'guru_tp_akademikmonitor',
        new pix_icon('i/edit', '')
    );
}

function local_akademikmonitor_safe_excel_text(string $value): string {
    $value = trim($value);

    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}