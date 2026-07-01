<?php
// File: local/akademikmonitor/tests/admin_monitoring_presensi_guru_menu_test.php
// Tujuan: menguji menu Admin > Monitoring Presensi Guru.
// Area uji: struktur page data, filter guru, dan fallback jika plugin Attendance tidak ada.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\admin_presensi_guru_service;

final class local_akademikmonitor_admin_monitoring_presensi_guru_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat data minimal kelas, course, dan guru pengampu.
     */
    private function create_fixture(): array {
        global $DB;

        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Guru', 'lastname' => 'Mapel']);
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
            'fullname' => '[kejuruan] Pemrograman - X RPL 1 - Ganjil',
            'shortname' => 'PROG-XRPL1-G',
            'idnumber' => 'AM-TA' . $tahunid . '-K' . $kelasid . '-KM1-S1',
        ]);

        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, (int)$teacherrole->id);

        return [(int)$tahunid, (int)$kelasid, (int)$teacher->id];
    }

    /**
     * READ/FALLBACK: menguji halaman presensi guru tetap aman tanpa tabel Attendance.
     */
    public function test_get_page_data_safe_when_attendance_tables_missing(): void {
        [$tahunid, $kelasid, $teacherid] = $this->create_fixture();

        $data = admin_presensi_guru_service::get_page_data($tahunid, 1, $kelasid, $teacherid);

        $this->assertArrayHasKey('attendance_available', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertSame($tahunid, (int)$data['selected_tahunajaranid']);
        $this->assertSame(1, (int)$data['selectedsemester']);
        $this->assertSame($kelasid, (int)$data['selected_kelasid']);
        $this->assertSame($teacherid, (int)$data['selected_teacherid']);
    }

    /**
     * SIDEBAR: menguji flag sidebar monitoring presensi guru aktif.
     */
    public function test_sidebar_marks_monitoring_presensi_guru_active(): void {
        $data = admin_presensi_guru_service::get_admin_sidebar_data('monitoring_presensi_guru');

        $this->assertTrue($data['is_monitoring_presensi_guru']);
        $this->assertStringContainsString('/local/akademikmonitor/pages/presensi_guru/index.php', $data['monitoring_presensi_guru_url']);
    }
}
