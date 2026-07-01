<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    /*
     * Hak akses utama admin plugin.
     *
     * Dipakai oleh:
     * - settings.php untuk menampilkan menu Akademik & Monitoring
     * - halaman admin plugin
     */
    'local/akademikmonitor:manage' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    /*
     * Hak akses untuk menghubungkan akun Telegram.
     *
     * Karena Telegram dipakai oleh siswa, guru, dan wali kelas,
     * lebih aman diberikan ke authenticated user.
     */
    'local/akademikmonitor:connecttelegram' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    /*
     * Hak akses melihat rapor.
     *
     * Wali kelas di plugin kamu bukan jenis user khusus.
     * Jadi akses wali kelas tetap dicek dari kelas.id_user di service/page,
     * sedangkan capability ini hanya izin dasar untuk guru.
     */
    'local/akademikmonitor:viewrapor' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];