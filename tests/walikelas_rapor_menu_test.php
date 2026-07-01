<?php
// File: local/akademikmonitor/tests/walikelas_rapor_menu_test.php
// Tujuan: menguji menu Wali Kelas > Rapor.
// Area uji: catatan akademik, kokurikuler, keputusan kenaikan kelas, ketidakhadiran, dan format tanggal.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\rapor_service;

final class local_akademikmonitor_walikelas_rapor_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat siswa, wali, dan group kelas.
     */
    private function create_fixture(): array {
        $wali = $this->getDataGenerator()->create_user(['firstname' => 'Wali', 'lastname' => 'Kelas']);
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Siswa', 'lastname' => 'Rapor']);
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'X RPL 1']);

        return [(int)$student->id, (int)$wali->id, (int)$group->id];
    }

    /**
     * CREATE: menguji simpan catatan akademik wali kelas.
     */
    public function test_save_catatan_creates_record(): void {
        [$studentid, $waliid, $groupid] = $this->create_fixture();

        rapor_service::save_catatan($studentid, $groupid, 1, 'Catatan awal', $waliid);
        $record = rapor_service::get_catatan($studentid, $groupid, 1);

        $this->assertSame('Catatan awal', $record->catatan);
        $this->assertSame($waliid, (int)$record->id_wali_kelas);
    }

    /**
     * UPDATE: menguji simpan catatan kedua mengupdate record lama, bukan membuat duplikat.
     */
    public function test_save_catatan_updates_existing_record(): void {
        global $DB;

        [$studentid, $waliid, $groupid] = $this->create_fixture();

        rapor_service::save_catatan($studentid, $groupid, 1, 'Catatan lama', $waliid);
        rapor_service::save_catatan($studentid, $groupid, 1, 'Catatan baru', $waliid);

        $record = rapor_service::get_catatan($studentid, $groupid, 1);

        $this->assertSame('Catatan baru', $record->catatan);
        $this->assertSame(1, $DB->count_records('rapor_catatan_akademik', [
            'id_siswa' => $studentid,
            'id_kelas' => $groupid,
            'semester' => 1,
        ]));
    }

    /**
     * UPDATE: menguji simpan kokurikuler tanpa menghapus catatan akademik.
     */
    public function test_save_kokurikuler_updates_only_kokurikuler_field(): void {
        [$studentid, $waliid, $groupid] = $this->create_fixture();

        rapor_service::save_catatan($studentid, $groupid, 1, 'Catatan akademik', $waliid);
        rapor_service::save_kokurikuler($studentid, $groupid, 1, 'Projek P5', $waliid);

        $record = rapor_service::get_catatan($studentid, $groupid, 1);

        $this->assertSame('Catatan akademik', $record->catatan);
        $this->assertSame('Projek P5', $record->kokurikuler);
    }

    /**
     * CREATE/UPDATE: menguji simpan keputusan kenaikan kelas.
     */
    public function test_save_kenaikan_kelas_creates_and_updates_decision(): void {
        [$studentid, $waliid, $groupid] = $this->create_fixture();

        rapor_service::save_kenaikan_kelas($studentid, $groupid, 'Naik ke kelas XI', $waliid);
        $first = rapor_service::get_kenaikan_kelas($studentid, $groupid);
        $this->assertSame('Naik ke kelas XI', $first->keputusan);

        rapor_service::save_kenaikan_kelas($studentid, $groupid, 'Tinggal di kelas X', $waliid);
        $second = rapor_service::get_kenaikan_kelas($studentid, $groupid);
        $this->assertSame('Tinggal di kelas X', $second->keputusan);
    }

    /**
     * CREATE/UPDATE: menguji ketidakhadiran manual rapor.
     */
    public function test_save_and_reset_ketidakhadiran_manual(): void {
        [$studentid, $waliid, $groupid] = $this->create_fixture();

        rapor_service::save_ketidakhadiran($studentid, $groupid, 1, 2, 3, 4, $waliid);
        $absen = rapor_service::get_ketidakhadiran($studentid, $groupid, 1);

        $this->assertSame(2, (int)$absen->sakit);
        $this->assertSame(3, (int)$absen->izin);
        $this->assertSame(4, (int)$absen->alfa);

        rapor_service::reset_ketidakhadiran_manual($studentid, $groupid, 1);
        $reset = rapor_service::get_ketidakhadiran($studentid, $groupid, 1);

        $this->assertSame(0, (int)$reset->sakit);
        $this->assertSame(0, (int)$reset->izin);
        $this->assertSame(0, (int)$reset->alfa);
    }

    /**
     * FORMAT: menguji format tanggal Indonesia untuk halaman/PDF rapor.
     */
    public function test_format_tanggal_indo(): void {
        $this->assertSame('13 Mei 2026', rapor_service::format_tanggal_indo('2026-05-13'));
        $this->assertSame('-', rapor_service::format_tanggal_indo(null));
    }
}
