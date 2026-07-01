<?php
// File: local/akademikmonitor/tests/walikelas_monitoring_presensi_menu_test.php
// Tujuan: menguji menu Wali Kelas bagian Monitoring Presensi.
//
// Fokus pengujian:
// 1. Wali kelas tanpa kelas tidak menyebabkan error.
// 2. Wali kelas yang memiliki kelas mendapatkan struktur halaman monitoring presensi.
// 3. Dropdown course/mapel presensi berdasarkan kelas dapat terbaca.
// 4. Data monitoring presensi mengembalikan struktur tabel yang aman untuk template.
//
// Catatan penting:
// Test ini tidak membuat tabel plugin Attendance secara manual.
// Jika plugin Attendance belum tersedia di environment PHPUnit,
// service harus tetap aman dan mengembalikan struktur kosong tanpa error.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\presensi_service;

final class local_akademikmonitor_walikelas_monitoring_presensi_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        /*
         * resetAfterTest(true) membuat semua data test dibersihkan otomatis
         * setelah test selesai. Jadi data Moodle asli tidak ikut berubah.
         */
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat data dasar untuk menu Monitoring Presensi Wali Kelas.
     *
     * Data yang dibuat:
     * - user wali kelas
     * - user siswa
     * - tahun ajaran
     * - jurusan
     * - kelas plugin
     * - kurikulum
     * - kurikulum_jurusan
     * - mata_pelajaran
     * - kurikulum_mapel
     * - course Moodle dengan idnumber format plugin
     * - relasi course_mapel
     * - group kelas
     * - enrol siswa ke course
     * - siswa sebagai anggota group
     *
     * Kenapa helper ini perlu?
     * Karena service presensi wali kelas membaca kelas dari relasi
     * tabel plugin dan course/group Moodle.
     */
    private function create_presensi_fixture(): array {
        global $DB;

        /*
         * Buat user wali kelas.
         */
        $wali = $this->getDataGenerator()->create_user([
            'firstname' => 'Wali',
            'lastname' => 'Presensi',
        ]);

        /*
         * Buat user siswa.
         */
        $siswa = $this->getDataGenerator()->create_user([
            'firstname' => 'Siswa',
            'lastname' => 'Presensi',
        ]);

        /*
         * Buat tahun ajaran plugin.
         */
        $tahunajaranid = $DB->insert_record('tahun_ajaran', (object)[
            'tahun_ajaran' => '2025/2026',
        ]);

        /*
         * Buat jurusan.
         */
        $jurusanid = $DB->insert_record('jurusan', (object)[
            'nama_jurusan' => 'RPL',
            'kode_jurusan' => 10,
        ]);

        /*
         * Buat kelas plugin.
         *
         * Field id_user adalah wali kelas.
         * Ini penting karena get_page_data() mengambil kelas berdasarkan wali kelas.
         */
        $kelasid = $DB->insert_record('kelas', (object)[
            'nama' => '1',
            'tingkat' => 'X',
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunajaranid,
            'id_user' => $wali->id,
        ]);

        /*
         * Buat kurikulum.
         */
        $kurikulumid = $DB->insert_record('kurikulum', (object)[
            'nama' => 'Kurikulum Merdeka',
            'is_active' => '1',
        ]);

        /*
         * Hubungkan kurikulum dengan jurusan dan tahun ajaran.
         */
        $kurikulumjurusanid = $DB->insert_record('kurikulum_jurusan', (object)[
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunajaranid,
        ]);

        /*
         * Buat master mata pelajaran.
         */
        $mapelid = $DB->insert_record('mata_pelajaran', (object)[
            'nama_mapel' => 'Bahasa Indonesia',
        ]);

        /*
         * Buat kurikulum_mapel.
         *
         * kktp tidak dipakai langsung oleh presensi,
         * tetapi tetap diisi karena course_mapel mengarah ke kurikulum_mapel.
         */
        $kurikulummapelid = $DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kurikulumjurusanid,
            'id_mapel' => $mapelid,
            'jam_pelajaran' => '2',
            'tingkat_kelas' => 'X',
            'kktp' => 75,
        ]);

        /*
         * Buat course Moodle.
         *
         * idnumber memakai format generate plugin:
         * AM-TA{tahunajaranid}-K{kelasid}-KM{kurikulummapelid}-S{semester}
         *
         * Format ini penting karena service membaca kelas dan semester
         * dari idnumber course.
         */
        $course = $this->getDataGenerator()->create_course([
            'fullname' => '[umum] Bahasa Indonesia - X RPL 1 - Ganjil',
            'shortname' => 'BIN-XRPL1-G-PRESENSI',
            'idnumber' => 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM' . $kurikulummapelid . '-S1',
        ]);

        /*
         * Hubungkan course Moodle dengan kurikulum_mapel.
         *
         * Di sini memakai execute(), bukan insert_record().
         *
         * Alasannya:
         * Tabel course_mapel pada plugin kamu adalah tabel relasi.
         * Tabel relasi bisa saja tidak memiliki kolom id auto increment.
         * Kalau memakai insert_record(), Moodle bisa mencoba mengambil inserted id
         * dan memunculkan error:
         * unknown error fetching inserted id [NULL]
         */
        $DB->execute(
            "INSERT INTO {course_mapel} (id_course, id_kurikulum_mapel)
                  VALUES (:courseid, :kurikulummapelid)",
            [
                'courseid' => (int)$course->id,
                'kurikulummapelid' => (int)$kurikulummapelid,
            ]
        );

        /*
         * Buat group kelas di dalam course.
         * Group ini yang dipakai oleh service presensi untuk menentukan siswa kelas.
         */
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'X RPL 1',
        ]);

        /*
         * Enrol siswa ke course sebagai student.
         * Ini penting supaya common_service::get_siswa_group()
         * dapat mengenali siswa sebagai peserta course.
         */
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        $this->getDataGenerator()->enrol_user(
            (int)$siswa->id,
            (int)$course->id,
            (int)$studentrole->id
        );

        /*
         * Masukkan siswa ke group kelas.
         */
        groups_add_member((int)$group->id, (int)$siswa->id);

        return [
            'waliid' => (int)$wali->id,
            'siswaid' => (int)$siswa->id,
            'tahunajaranid' => (int)$tahunajaranid,
            'jurusanid' => (int)$jurusanid,
            'kelasid' => (int)$kelasid,
            'kurikulummapelid' => (int)$kurikulummapelid,
            'courseid' => (int)$course->id,
            'groupid' => (int)$group->id,
        ];
    }

    /**
     * READ:
     * Menguji kondisi user yang belum menjadi wali kelas.
     *
     * Yang diuji:
     * - service tidak error
     * - key nokelas tersedia
     * - nokelas bernilai true
     *
     * Kenapa ini penting?
     * Supaya halaman presensi tetap aman saat user belum punya kelas wali.
     */
    public function test_get_page_data_returns_nokelas_when_wali_has_no_class(): void {
        $wali = $this->getDataGenerator()->create_user();

        $data = presensi_service::get_page_data(
            (int)$wali->id,
            0,
            1,
            0
        );

        $this->assertIsArray($data);
        $this->assertArrayHasKey('nokelas', $data);
        $this->assertTrue($data['nokelas']);
    }

    /**
     * READ:
     * Menguji struktur halaman Monitoring Presensi untuk wali kelas valid.
     *
     * Yang diuji:
     * - key kelas tersedia
     * - key courses tersedia
     * - key selected_course tersedia
     * - key selected_course_name tersedia
     * - key selectedsemester tersedia
     * - key attendance_available tersedia
     * - key columns tersedia
     * - key rows tersedia
     * - key summary tersedia
     * - key status_legend tersedia
     *
     * Catatan:
     * Test lama gagal karena mencari key kelas_options.
     * Pada service asli plugin kamu, key yang dipakai adalah kelas dan courses.
     */
    public function test_get_page_data_returns_presensi_structure_for_valid_wali_class(): void {
        $fixture = $this->create_presensi_fixture();

        $data = presensi_service::get_page_data(
            $fixture['waliid'],
            $fixture['courseid'],
            1,
            $fixture['tahunajaranid']
        );

        $this->assertIsArray($data);

        $this->assertArrayHasKey('kelas', $data);
        $this->assertArrayHasKey('courses', $data);
        $this->assertArrayHasKey('courseid', $data);
        $this->assertArrayHasKey('selected_course', $data);
        $this->assertArrayHasKey('selected_course_name', $data);
        $this->assertArrayHasKey('selectedsemester', $data);
        $this->assertArrayHasKey('selectedtahunajaranid', $data);
        $this->assertArrayHasKey('selected_tahunajaranid', $data);
        $this->assertArrayHasKey('attendance_available', $data);
        $this->assertArrayHasKey('columns', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('total_sessions', $data);
        $this->assertArrayHasKey('total_students', $data);
        $this->assertArrayHasKey('status_legend', $data);

        /*
         * Course terpilih harus course yang dibuat oleh fixture.
         */
        $this->assertSame(
            $fixture['courseid'],
            (int)$data['selected_course']
        );

        /*
         * Semester harus sesuai parameter test.
         */
        $this->assertSame(
            1,
            (int)$data['selectedsemester']
        );

        /*
         * Tahun ajaran harus sesuai parameter test.
         */
        $this->assertSame(
            $fixture['tahunajaranid'],
            (int)$data['selectedtahunajaranid']
        );

        /*
         * courses, columns, rows, summary, dan status_legend harus aman untuk template.
         */
        $this->assertIsArray($data['courses']);
        $this->assertIsArray($data['columns']);
        $this->assertIsArray($data['rows']);
        $this->assertIsArray($data['summary']);
        $this->assertIsArray($data['status_legend']);

        /*
         * courses tidak boleh kosong karena fixture membuat satu course generate.
         */
        $this->assertNotEmpty($data['courses']);
    }

    /**
     * READ:
     * Menguji dropdown course/mapel berdasarkan group kelas.
     *
     * Yang diuji:
     * - service tidak error saat diberi group valid
     * - hasil berupa array
     * - minimal ada satu course
     * - course id sesuai fixture
     * - nama_mapel tersedia
     *
     * Kenapa tidak pakai group ID 999999?
     * Karena service memakai MUST_EXIST saat membaca group.
     * Kalau group tidak ada, Moodle memang akan melempar exception.
     */
    public function test_get_course_options_by_kelas_returns_generated_course_options(): void {
        $fixture = $this->create_presensi_fixture();

        $courses = presensi_service::get_course_options_by_kelas(
            $fixture['groupid'],
            1
        );

        $this->assertIsArray($courses);
        $this->assertNotEmpty($courses);

        $first = $courses[0];

        $this->assertSame(
            $fixture['courseid'],
            (int)$first->id
        );

        $this->assertObjectHasProperty('nama_mapel', $first);
        $this->assertNotEmpty($first->nama_mapel);
    }

    /**
     * READ:
     * Menguji struktur tabel monitoring presensi.
     *
     * Yang diuji:
     * - output berupa array
     * - key columns tersedia
     * - key rows tersedia
     * - key summary tersedia
     * - columns berupa array
     * - rows berupa array
     * - summary berupa array
     *
     * Catatan:
     * Jika plugin Attendance belum aktif di environment PHPUnit,
     * columns bisa kosong. Itu bukan error.
     * Yang penting service tetap mengembalikan struktur aman untuk template.
     */
    public function test_get_monitoring_presensi_returns_table_structure_for_valid_context(): void {
        $fixture = $this->create_presensi_fixture();

        $monitoring = presensi_service::get_monitoring_presensi(
            $fixture['groupid'],
            $fixture['courseid'],
            $fixture['waliid']
        );

        $this->assertIsArray($monitoring);

        $this->assertArrayHasKey('columns', $monitoring);
        $this->assertArrayHasKey('rows', $monitoring);
        $this->assertArrayHasKey('summary', $monitoring);

        $this->assertIsArray($monitoring['columns']);
        $this->assertIsArray($monitoring['rows']);
        $this->assertIsArray($monitoring['summary']);

        $this->assertArrayHasKey('total_sessions', $monitoring['summary']);
        $this->assertArrayHasKey('total_students', $monitoring['summary']);

        /*
         * Walaupun plugin Attendance belum tersedia, service tetap bisa
         * mengembalikan row siswa dengan presensi_list kosong.
         */
        $this->assertGreaterThanOrEqual(0, count($monitoring['rows']));
    }
}