<?php
// File: local/akademikmonitor/tests/admin_monitoring_presensi_siswa_menu_test.php
// Tujuan: menguji menu Admin > Monitoring Presensi Siswa.
// Area uji: struktur page data, filter tahun/semester/kelas/course, dan fallback jika plugin Attendance tidak ada.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\admin_presensi_service;

final class local_akademikmonitor_admin_monitoring_presensi_siswa_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat data minimal tahun ajaran, jurusan, kelas, dan course hasil generate.
     */
    private function create_generated_course_fixture(): array {
        global $DB;

        $tahunid = $DB->insert_record('tahun_ajaran', (object)['tahun_ajaran' => '2025/2026']);
        $jurusanid = $DB->insert_record('jurusan', (object)['nama_jurusan' => 'RPL', 'kode_jurusan' => 10]);
        $kelasid = $DB->insert_record('kelas', (object)[
            'nama' => '1',
            'tingkat' => 'X',
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunid,
            'id_user' => null,
        ]);

        $course = $this->getDataGenerator()->create_course([
            'fullname' => '[umum] Bahasa Indonesia - X RPL 1 - Ganjil',
            'shortname' => 'BIN-XRPL1-G',
            'idnumber' => 'AM-TA' . $tahunid . '-K' . $kelasid . '-KM1-S1',
        ]);

        return [(int)$tahunid, (int)$kelasid, (int)$course->id];
    }

    /**
     * READ/FALLBACK: menguji halaman tetap aman walaupun plugin Attendance belum terpasang.
     */
    public function test_get_page_data_safe_when_attendance_plugin_tables_missing(): void {
        [$tahunid, $kelasid, $courseid] = $this->create_generated_course_fixture();

        $data = admin_presensi_service::get_page_data($tahunid, 1, $kelasid, $courseid);

        $this->assertArrayHasKey('attendance_available', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('columns', $data);
        $this->assertSame($tahunid, (int)$data['selected_tahunajaranid']);
        $this->assertSame(1, (int)$data['selectedsemester']);
        $this->assertSame($kelasid, (int)$data['selected_kelasid']);
        $this->assertSame($courseid, (int)$data['selected_courseid']);
    }

    /**
     * FILTER: menguji semester tidak valid otomatis dinormalisasi ke semester 1 atau 2.
     */
    public function test_get_page_data_normalizes_invalid_semester(): void {
        [$tahunid, $kelasid, $courseid] = $this->create_generated_course_fixture();

        $data = admin_presensi_service::get_page_data($tahunid, 9, $kelasid, $courseid);

        $this->assertContains((int)$data['selectedsemester'], [1, 2]);
    }

    /**
     * SIDEBAR: menguji flag sidebar monitoring presensi siswa aktif.
     */
    public function test_sidebar_marks_monitoring_presensi_active(): void {
        $data = admin_presensi_service::get_admin_sidebar_data('monitoring_presensi');

        $this->assertTrue($data['is_monitoring_presensi']);
        $this->assertFalse($data['is_kktp']);
        $this->assertStringContainsString('/local/akademikmonitor/pages/presensi/index.php', $data['monitoring_presensi_url']);
    }
}
