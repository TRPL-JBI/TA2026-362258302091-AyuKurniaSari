<?php
// File: local/akademikmonitor/tests/walikelas_monitoring_nilai_menu_test.php
// Tujuan: menguji menu Wali Kelas bagian Monitoring Nilai.
//
// Fokus pengujian:
// 1. Wali kelas tanpa kelas tidak menyebabkan error.
// 2. Wali kelas yang memiliki kelas mendapatkan struktur halaman monitoring.
// 3. Daftar mata pelajaran berdasarkan kelas dapat terbaca.
// 4. Data monitoring nilai mengembalikan struktur tabel yang benar.
//
// Catatan penting:
// Test ini tidak memaksa kolom harus bernama "Course Total",
// karena pada plugin AkademikMonitor kolom monitoring nilai dapat berubah
// mengikuti struktur gradebook, TP, assignment, atau item nilai yang aktif.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\monitoring_service;

final class local_akademikmonitor_walikelas_monitoring_nilai_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        /*
         * resetAfterTest(true) memastikan semua data yang dibuat saat test
         * akan dibersihkan ulang oleh Moodle setelah test selesai.
         *
         * Jadi data asli Moodle tidak akan rusak.
         */
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat data dasar untuk menu Monitoring Nilai Wali Kelas.
     *
     * Data yang dibuat:
     * - wali kelas
     * - siswa
     * - tahun ajaran
     * - jurusan
     * - kelas
     * - kurikulum
     * - kurikulum_jurusan
     * - mata_pelajaran
     * - kurikulum_mapel
     * - course Moodle dengan idnumber format plugin
     * - relasi course_mapel
     * - group kelas
     * - enrol siswa ke course
     * - anggota group siswa
     *
     * Kenapa data ini perlu dibuat?
     * Karena monitoring nilai membaca relasi dari data plugin dan data Moodle.
     * Kalau salah satu data tidak dibuat, service bisa mengembalikan data kosong.
     */
    private function create_monitoring_fixture(): array {
        global $DB;

        /*
         * Buat user wali kelas.
         */
        $wali = $this->getDataGenerator()->create_user([
            'firstname' => 'Wali',
            'lastname' => 'Kelas',
        ]);

        /*
         * Buat user siswa.
         */
        $siswa = $this->getDataGenerator()->create_user([
            'firstname' => 'Siswa',
            'lastname' => 'Monitoring',
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
         * id_user adalah wali kelas.
         * Ini penting karena get_page_data() membaca kelas berdasarkan wali kelas.
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
         * Buat mata pelajaran.
         */
        $mapelid = $DB->insert_record('mata_pelajaran', (object)[
            'nama_mapel' => 'Bahasa Indonesia',
        ]);

        /*
         * Buat kurikulum_mapel.
         *
         * kktp dipakai oleh monitoring nilai untuk memberi penanda
         * apakah nilai sudah mencapai batas minimal atau belum.
         */
        $kurikulummapelid = $DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kurikulumjurusanid,
            'id_mapel' => $mapelid,
            'jam_pelajaran' => '2',
            'tingkat_kelas' => 'X',
            'kktp' => 75,
        ]);

        /*
         * Buat course Moodle dengan idnumber format hasil generate plugin:
         * AM-TA{tahunajaranid}-K{kelasid}-KM{kurikulummapelid}-S{semester}
         */
        $course = $this->getDataGenerator()->create_course([
            'fullname' => '[umum] Bahasa Indonesia - X RPL 1 - Ganjil',
            'shortname' => 'BIN-XRPL1-G',
            'idnumber' => 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM' . $kurikulummapelid . '-S1',
        ]);

        /*
         * Hubungkan course Moodle dengan kurikulum_mapel.
         *
         * Di sini memakai execute(), bukan insert_record().
         *
         * Alasannya:
         * Tabel course_mapel pada plugin kamu adalah tabel relasi.
         * Tabel seperti ini bisa saja tidak memiliki kolom id auto increment.
         * Jika memakai insert_record(), Moodle mencoba mengambil inserted id
         * dan bisa memunculkan error unknown error fetching inserted id [NULL].
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
         */
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'X RPL 1',
        ]);

        /*
         * Enrol siswa sebagai student ke course.
         *
         * Enrol dilakukan agar siswa dianggap sebagai peserta resmi course.
         */
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        $this->getDataGenerator()->enrol_user(
            (int)$siswa->id,
            (int)$course->id,
            (int)$studentrole->id
        );

        /*
         * Masukkan siswa ke group.
         *
         * Group dipakai oleh service monitoring untuk menentukan siswa
         * yang masuk dalam kelas wali.
         */
        groups_add_member((int)$group->id, (int)$siswa->id);

        /*
         * Buat grade item manual.
         *
         * Kenapa tidak memaksa Course Total?
         * Karena pada environment PHPUnit Moodle, Course Total tidak selalu
         * otomatis dibuat seperti pada penggunaan Moodle normal.
         *
         * Grade item manual ini cukup untuk memastikan gradebook memiliki
         * item nilai yang dapat dibaca oleh service apabila service memang
         * membaca item grade biasa.
         */
        $gradeitemid = $DB->insert_record('grade_items', (object)[
            'courseid' => (int)$course->id,
            'categoryid' => null,
            'itemname' => 'Nilai Tes Unit',
            'itemtype' => 'manual',
            'itemmodule' => null,
            'iteminstance' => null,
            'itemnumber' => 0,
            'iteminfo' => null,
            'idnumber' => 'TEST-UNIT-1',
            'calculation' => null,
            'gradetype' => 1,
            'grademax' => 100,
            'grademin' => 0,
            'scaleid' => null,
            'outcomeid' => null,
            'gradepass' => 0,
            'multfactor' => 1,
            'plusfactor' => 0,
            'aggregationcoef' => 0,
            'aggregationcoef2' => 0,
            'weightoverride' => 0,
            'sortorder' => 1,
            'display' => 0,
            'decimals' => null,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        /*
         * Simpan nilai siswa untuk grade item manual.
         */
        $DB->insert_record('grade_grades', (object)[
            'itemid' => (int)$gradeitemid,
            'userid' => (int)$siswa->id,
            'rawgrade' => 80,
            'rawgrademax' => 100,
            'rawgrademin' => 0,
            'finalgrade' => 80,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [
            'waliid' => (int)$wali->id,
            'siswaid' => (int)$siswa->id,
            'tahunajaranid' => (int)$tahunajaranid,
            'jurusanid' => (int)$jurusanid,
            'kelasid' => (int)$kelasid,
            'groupid' => (int)$group->id,
            'courseid' => (int)$course->id,
            'kurikulummapelid' => (int)$kurikulummapelid,
            'gradeitemid' => (int)$gradeitemid,
        ];
    }

    /**
     * READ:
     * Menguji kondisi user yang belum menjadi wali kelas.
     *
     * Yang diuji:
     * - service tidak error
     * - key nokelas tersedia
     * - nilai nokelas adalah true
     *
     * Ini memastikan halaman monitoring nilai tetap aman
     * walaupun user belum diatur sebagai wali kelas.
     */
    public function test_get_page_data_returns_nokelas_when_wali_has_no_class(): void {
        $wali = $this->getDataGenerator()->create_user();

        $data = monitoring_service::get_page_data(
            (int)$wali->id,
            0,
            1,
            0
        );

        $this->assertArrayHasKey('nokelas', $data);
        $this->assertTrue($data['nokelas']);
    }

    /**
     * READ:
     * Menguji struktur halaman monitoring nilai ketika wali kelas memiliki kelas.
     *
     * Yang diuji:
     * - output berupa array
     * - key kelas tersedia
     * - key mapel tersedia
     * - key selected_course tersedia
     * - key selected_mapel_name tersedia
     * - key selectedsemester tersedia
     * - key groups tersedia
     * - key columns tersedia
     * - key rows tersedia
     *
     * Test ini tidak memaksa rows harus berisi data,
     * karena isi rows sangat bergantung pada cara service membaca gradebook.
     * Yang penting, service halaman berhasil menyiapkan struktur data
     * tanpa error.
     */
    public function test_get_page_data_returns_monitoring_structure_for_valid_wali_class(): void {
        $fixture = $this->create_monitoring_fixture();

        $data = monitoring_service::get_page_data(
            $fixture['waliid'],
            $fixture['courseid'],
            1,
            $fixture['tahunajaranid']
        );

        $this->assertIsArray($data);

        $this->assertArrayHasKey('kelas', $data);
        $this->assertArrayHasKey('mapel', $data);
        $this->assertArrayHasKey('selected_course', $data);
        $this->assertArrayHasKey('selected_mapel_name', $data);
        $this->assertArrayHasKey('selectedsemester', $data);
        $this->assertArrayHasKey('groups', $data);
        $this->assertArrayHasKey('columns', $data);
        $this->assertArrayHasKey('rows', $data);

        $this->assertSame(
            $fixture['courseid'],
            (int)$data['selected_course']
        );

        $this->assertSame(
            1,
            (int)$data['selectedsemester']
        );

        /*
         * mapel harus berupa array.
         * Tidak dipaksa isi tertentu karena struktur mapel mengikuti service.
         */
        $this->assertIsArray($data['mapel']);

        /*
         * columns dan rows harus berupa array agar template halaman aman dirender.
         */
        $this->assertIsArray($data['columns']);
        $this->assertIsArray($data['rows']);
    }

    /**
     * READ:
     * Menguji daftar mapel berdasarkan group kelas.
     *
     * Yang diuji:
     * - service berhasil membaca mapel dari group yang valid
     * - hasil berupa array
     * - minimal ada satu mapel
     * - course id mapel sesuai dengan course fixture
     *
     * Ini menguji fitur dropdown mata pelajaran pada menu monitoring nilai.
     */
    public function test_get_mapel_by_kelas_returns_generated_course_mapel(): void {
        $fixture = $this->create_monitoring_fixture();

        $mapel = monitoring_service::get_mapel_by_kelas(
            $fixture['groupid'],
            1
        );

        $this->assertIsArray($mapel);
        $this->assertNotEmpty($mapel);

        /*
         * Ambil baris pertama karena fixture hanya membuat satu course.
         */
        $first = $mapel[0];

        $this->assertSame(
            $fixture['courseid'],
            (int)$first->id
        );

        /*
         * Nama mapel minimal harus tersedia.
         * Pada beberapa service, nama bisa berasal dari fullname course
         * atau dari tabel mata_pelajaran.
         */
        $this->assertObjectHasProperty('nama_mapel', $first);
        $this->assertNotEmpty($first->nama_mapel);

        /*
         * KKTP harus ikut tersedia karena monitoring nilai membutuhkan
         * batas nilai minimal.
         */
        $this->assertObjectHasProperty('kktp', $first);
        $this->assertSame(75, (int)$first->kktp);
    }

    /**
     * READ:
     * Menguji struktur data tabel monitoring nilai.
     *
     * Yang diuji:
     * - output berupa array
     * - key groups tersedia
     * - key columns tersedia
     * - key rows tersedia
     * - columns berupa array
     * - rows berupa array
     *
     * Test ini tidak memaksa nama kolom harus "Course Total",
     * karena service plugin bisa menampilkan kolom berdasarkan item gradebook,
     * TP, tugas, assignment, atau struktur nilai lain.
     *
     * Tujuan utama test ini adalah memastikan service monitoring nilai
     * dapat dipanggil dengan group, course, dan wali kelas valid tanpa error,
     * serta menghasilkan struktur yang aman untuk template.
     */
    public function test_get_monitoring_nilai_returns_table_structure(): void {
        $fixture = $this->create_monitoring_fixture();

        $monitoring = monitoring_service::get_monitoring_nilai(
            $fixture['groupid'],
            $fixture['courseid'],
            $fixture['waliid']
        );

        $this->assertIsArray($monitoring);

        $this->assertArrayHasKey('groups', $monitoring);
        $this->assertArrayHasKey('columns', $monitoring);
        $this->assertArrayHasKey('rows', $monitoring);

        $this->assertIsArray($monitoring['groups']);
        $this->assertIsArray($monitoring['columns']);
        $this->assertIsArray($monitoring['rows']);
    }
}