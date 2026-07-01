<?php
namespace local_akademikmonitor\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Link tambahan pada halaman User preferences Moodle.
 *
 * Catatan penting:
 * - Menu Telegram ditampilkan untuk semua user yang login.
 * - Menu Wali Kelas tidak lagi memakai role id hardcode seperti role id 9.
 * - Deteksi Wali Kelas diambil dari tabel plugin {kelas}.id_user.
 * - Link diarahkan ke file yang memang ada di struktur plugin.
 */
class user_preferences {

    /**
     * Menambahkan link preferensi user.
     *
     * @param array $preferences Data preferences bawaan Moodle.
     * @return void
     */
    public static function extend_preferences(array &$preferences): void {
        global $USER;

        if (!isset($preferences['useraccount']) || !is_array($preferences['useraccount'])) {
            $preferences['useraccount'] = [];
        }

        // Menu Telegram aman untuk semua user yang login.
        self::add_useraccount_link(
            $preferences,
            'akademikmonitor_telegram',
            'Pengaturan Notifikasi',
            '/local/akademikmonitor/pages/telegram/index.php'
        );

        // Menu Wali Kelas hanya tampil kalau user terdaftar sebagai wali kelas
        // di tabel {kelas}.id_user, bukan berdasarkan role id yang bisa berbeda
        // di setiap instalasi Moodle.
        if (!self::is_wali_kelas((int)$USER->id)) {
            return;
        }

        self::add_useraccount_link(
            $preferences,
            'akademikmonitor_walikelas_dashboard',
            'Dashboard Wali Kelas',
            '/local/akademikmonitor/pages/walikelas/dashboard.php'
        );

        self::add_useraccount_link(
            $preferences,
            'akademikmonitor_walikelas_monitoring',
            'Monitoring Kelas',
            '/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php'
        );

        self::add_useraccount_link(
            $preferences,
            'akademikmonitor_walikelas_rapor',
            'Rapor Siswa',
            '/local/akademikmonitor/pages/walikelas/rapor/index.php'
        );

        self::add_useraccount_link(
            $preferences,
            'akademikmonitor_walikelas_ekskul',
            'Ekstrakurikuler Siswa',
            '/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php'
        );

        self::add_useraccount_link(
            $preferences,
            'akademikmonitor_walikelas_pkl',
            'PKL Siswa',
            '/local/akademikmonitor/pages/walikelas/pkl/pkl.php'
        );
    }

    /**
     * Helper kecil supaya penambahan link tidak ditulis berulang-ulang.
     *
     * Kenapa dibuat function sendiri?
     * Supaya setiap link memakai cara yang sama:
     * - preference key unik,
     * - label jelas,
     * - URL Moodle dibuat lewat moodle_url.
     *
     * @param array $preferences Data preferences Moodle.
     * @param string $key Key unik link.
     * @param string $label Teks yang tampil.
     * @param string $path Path internal Moodle.
     * @return void
     */
    private static function add_useraccount_link(
        array &$preferences,
        string $key,
        string $label,
        string $path
    ): void {
        $preferences['useraccount'][] = new \core_user\output\preferences\link_preference(
            $key,
            $label,
            new \moodle_url($path)
        );
    }

    /**
     * Mengecek apakah user adalah wali kelas pada data plugin.
     *
     * Kenapa tidak pakai user_has_role_assignment($userid, 9)?
     * Karena role id 9 belum tentu sama di semua Moodle. Di server lain,
     * role wali kelas bisa punya id berbeda, atau wali kelas bisa saja
     * dianggap sebagai tugas tambahan guru, bukan role global.
     *
     * Sumber yang paling sesuai untuk plugin ini adalah tabel {kelas}.id_user,
     * karena di situ wali kelas disimpan per rombel dan tahun ajaran.
     *
     * @param int $userid ID user Moodle.
     * @return bool
     */
    private static function is_wali_kelas(int $userid): bool {
        global $DB;

        if ($userid <= 0 || !isloggedin() || isguestuser()) {
            return false;
        }

        $dbman = $DB->get_manager();

        if (!$dbman->table_exists('kelas')) {
            return false;
        }

        $columns = $DB->get_columns('kelas');

        if (!isset($columns['id_user'])) {
            return false;
        }

        // Kalau tabel kelas punya id_tahun_ajaran dan plugin sudah punya tahun
        // ajaran aktif, prioritaskan pengecekan pada tahun ajaran aktif.
        // Ini membuat menu Wali Kelas mengikuti konsep wali kelas per periode.
        if (isset($columns['id_tahun_ajaran'])) {
            $tahunajaranid = self::get_active_tahunajaranid_safe();

            if ($tahunajaranid > 0 && $DB->record_exists('kelas', [
                'id_user' => $userid,
                'id_tahun_ajaran' => $tahunajaranid,
            ])) {
                return true;
            }
        }

        // Fallback: kalau tahun ajaran aktif belum diset, tetap tampilkan menu
        // selama user pernah/masih tercatat sebagai wali kelas.
        return $DB->record_exists('kelas', ['id_user' => $userid]);
    }

    /**
     * Mengambil tahun ajaran aktif tanpa membuat halaman preferences fatal error.
     *
     * Kenapa pakai method terpisah?
     * Karena halaman User preferences bisa dibuka oleh banyak jenis user.
     * Kalau service periode belum siap, fungsi ini cukup mengembalikan 0
     * sehingga pengecekan wali kelas tetap fallback ke {kelas}.id_user.
     *
     * @return int
     */
    private static function get_active_tahunajaranid_safe(): int {
        try {
            if (class_exists('\local_akademikmonitor\service\period_filter_service')) {
                return (int)\local_akademikmonitor\service\period_filter_service::get_active_tahunajaranid();
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return 0;
    }
}
