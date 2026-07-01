<?php
defined('MOODLE_INTERNAL') || die();

/** @var admin_root $ADMIN */
/** @var bool $hassiteconfig */

if ($hassiteconfig) {

    // Menu utama plugin.
    // Menggunakan capability plugin agar pengelolaan akses
    // konsisten melalui local/akademikmonitor:manage.
    
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_akademikmonitor',
        'Akademik & Monitoring',
        new moodle_url('/local/akademikmonitor/pages/index.php'),
        'local/akademikmonitor:manage'
    ));

    // Halaman pengaturan plugin.
    $settings = new admin_settingpage(
        'local_akademikmonitor_settings',
        'Pengaturan Akademik & Monitoring',
        'local/akademikmonitor:manage'
    );

    // Identitas sekolah.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/school_identity_heading',
        'Identitas Sekolah',
        'Pengaturan ini dipakai untuk rapor, export PDF, dan tampilan identitas sekolah.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/namasekolah',
        'Nama sekolah',
        'Contoh: SMKS PGRI 2 Giri Banyuwangi',
        'SMKS PGRI 2 Giri Banyuwangi',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_akademikmonitor/alamatsekolah',
        'Alamat sekolah',
        'Alamat sekolah untuk ditampilkan di rapor/PDF.',
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/kotattd',
        'Kota penandatanganan',
        'Contoh: Banyuwangi',
        'Banyuwangi',
        PARAM_TEXT
    ));


global $DB;

$teacheroptions = [];

$teachers = $DB->get_records_sql("
    SELECT DISTINCT u.id, CONCAT(u.firstname, ' ', u.lastname) AS fullname
    FROM {user} u
    JOIN {role_assignments} ra ON ra.userid = u.id
    JOIN {role} r ON r.id = ra.roleid
    WHERE r.shortname = 'editingteacher'
       OR r.shortname = 'teacher'
    ORDER BY fullname
");

foreach ($teachers as $teacher) {
    $teacheroptions[$teacher->id] = $teacher->fullname;
}

    // Kepala sekolah.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/headmaster_heading',
        'Kepala Sekolah',
        'Data kepala sekolah untuk kebutuhan tanda tangan dokumen.'
    ));

$settings->add(new admin_setting_configselect(
    'local_akademikmonitor/kepalasekolahuserid',
    'Kepala sekolah',
    'Pilih user guru yang menjadi kepala sekolah.',
    0,
    $teacheroptions
));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/npakepalasekolah',
        'NPA kepala sekolah',
        'Contoh: 1333.1.800.166',
        '1333.1.800.166',
        PARAM_TEXT
    ));

    // Default rapor.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/rapor_heading',
        'Default Rapor',
        'Dipakai jika data dinamis belum tersedia.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/semesterdefault',
        'Semester default',
        'Contoh: Ganjil atau Genap',
        'Ganjil',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/tahunpelajarandefault',
        'Tahun pelajaran default',
        'Contoh: 2025/2026',
        '2025/2026',
        PARAM_TEXT
    ));

    // $settings->add(new admin_setting_configtext(
    //     'local_akademikmonitor/tahuncoverdefault',
    //     'Tahun cover default',
    //     'Contoh: 2025',
    //     date('Y'),
    //     PARAM_INT
    // ));
    
    // Gambar rapor.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/rapor_image_heading',
        'Gambar Rapor',
        'Upload gambar yang dipakai pada export PDF rapor. Logo sampul dan watermark dipisah agar bisa memakai gambar berbeda.'
    ));

    $settings->add(new admin_setting_configstoredfile(
        'local_akademikmonitor/rapor_cover_logo',
        'Logo sampul rapor',
        'Upload logo yang tampil pada halaman sampul rapor. Format yang disarankan: PNG/JPG/JPEG.',
        'rapor_cover_logo',
        0,
        [
            'maxfiles' => 1,
            'accepted_types' => ['.png', '.jpg', '.jpeg'],
            'subdirs' => 0,
        ]
    ));

    $settings->add(new admin_setting_configstoredfile(
        'local_akademikmonitor/rapor_watermark',
        'Watermark rapor',
        'Upload gambar watermark yang tampil samar di halaman rapor. Bisa berbeda dari logo sampul.',
        'rapor_watermark',
        0,
        [
            'maxfiles' => 1,
            'accepted_types' => ['.png', '.jpg', '.jpeg'],
            'subdirs' => 0,
        ]
    ));

    $ADMIN->add('localplugins', $settings);
}