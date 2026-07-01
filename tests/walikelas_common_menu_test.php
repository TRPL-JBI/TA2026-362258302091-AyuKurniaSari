<?php
// File: local/akademikmonitor/tests/walikelas_common_menu_test.php
// Tujuan: menguji helper umum menu Wali Kelas.
// Area uji: parsing idnumber course generate, validasi kelas XII, sidebar, dan mapping NISN.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\common_service;

final class local_akademikmonitor_walikelas_common_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * PARSE: menguji course idnumber format baru bisa dibaca menjadi tahun ajaran, kelas, dan semester.
     */
    public function test_get_generated_course_info_from_new_idnumber(): void {
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'AM-TA7-K12-KM34-S2',
        ]);

        $info = common_service::get_generated_course_info_from_courseid((int)$course->id);

        $this->assertSame(7, $info['tahunajaranid']);
        $this->assertSame(12, $info['kelasid']);
        $this->assertSame(2, $info['semester']);
    }

    /**
     * PARSE: menguji course idnumber format lama masih didukung untuk backward compatibility.
     */
    public function test_get_generated_course_info_from_old_idnumber(): void {
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => 'AM-K5-KM9-S1',
        ]);

        $info = common_service::get_generated_course_info_from_courseid((int)$course->id);

        $this->assertSame(0, $info['tahunajaranid']);
        $this->assertSame(5, $info['kelasid']);
        $this->assertSame(1, $info['semester']);
    }

    /**
     * VALIDATE: menguji deteksi tingkat XII untuk pembatasan PKL.
     */
    public function test_is_tingkat_xii_accepts_xii_and_12(): void {
        $this->assertTrue(common_service::is_tingkat_xii('XII'));
        $this->assertTrue(common_service::is_tingkat_xii('12'));
        $this->assertFalse(common_service::is_tingkat_xii('XI'));
        $this->assertFalse(common_service::is_tingkat_xii('X'));
    }

    /**
     * SIDEBAR: menguji menu PKL aktif saat active = pkl.
     */
    public function test_sidebar_data_marks_active_menu(): void {
        $data = common_service::get_sidebar_data('pkl', 0, 0);

        $this->assertTrue($data['is_pkl_siswa'] ?? false);
        $this->assertFalse($data['is_raport'] ?? false);
        $this->assertArrayHasKey('pkl_siswa_url', $data);
    }
}
