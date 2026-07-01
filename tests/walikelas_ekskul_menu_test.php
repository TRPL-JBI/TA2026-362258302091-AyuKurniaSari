<?php
// File: local/akademikmonitor/tests/walikelas_ekskul_menu_test.php
// Tujuan: menguji menu Wali Kelas > Ekstrakurikuler Siswa.
// Area uji: save predikat, update predikat, read daftar ekskul siswa, dan keterangan predikat.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\ekskul_service;

final class local_akademikmonitor_walikelas_ekskul_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat siswa, group kelas, dan master ekskul aktif.
     */
    private function create_fixture(): array {
        global $DB;

        $student = $this->getDataGenerator()->create_user(['firstname' => 'Siswa', 'lastname' => 'Satu']);
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'X RPL 1']);
        $ekskulid = $DB->insert_record('ekskul', (object)[
            'nama' => 'Pramuka',
            'id_pembina' => 0,
            'is_active' => '1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [(int)$student->id, (int)$group->id, (int)$ekskulid];
    }

    /**
     * CREATE: menguji wali kelas menyimpan nilai ekstrakurikuler siswa.
     */
    public function test_save_ekskul_inserts_new_record(): void {
        global $DB;

        [$studentid, $groupid, $ekskulid] = $this->create_fixture();

        ekskul_service::save($studentid, $groupid, $ekskulid, 1, 'A');

        $record = $DB->get_record('ekskul_rapor', [
            'id_siswa' => $studentid,
            'id_kelas' => $groupid,
            'id_ekskul' => $ekskulid,
            'semester' => 1,
        ], '*', MUST_EXIST);

        $this->assertSame('A', $record->predikat);
    }

    /**
     * UPDATE: menguji simpan ulang ekskul periode yang sama tidak membuat duplikat.
     */
    public function test_save_ekskul_updates_existing_record_without_duplicate(): void {
        global $DB;

        [$studentid, $groupid, $ekskulid] = $this->create_fixture();

        ekskul_service::save($studentid, $groupid, $ekskulid, 1, 'B');
        ekskul_service::save($studentid, $groupid, $ekskulid, 1, 'A');

        $records = $DB->get_records('ekskul_rapor', [
            'id_siswa' => $studentid,
            'id_kelas' => $groupid,
            'id_ekskul' => $ekskulid,
            'semester' => 1,
        ]);

        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame('A', $record->predikat);
    }

    /**
     * READ: menguji data ekskul siswa mengambil nama ekskul dan predikat.
     */
    public function test_get_ekskul_siswa_returns_saved_rows(): void {
        [$studentid, $groupid, $ekskulid] = $this->create_fixture();

        ekskul_service::save($studentid, $groupid, $ekskulid, 1, 'A');
        $rows = ekskul_service::get_ekskul_siswa($studentid, $groupid, 1);

        $this->assertCount(1, $rows);
        $this->assertSame('Pramuka', $rows[0]->nama);
        $this->assertSame('A', $rows[0]->predikat);
    }

    /**
     * KETERANGAN: menguji predikat diterjemahkan menjadi keterangan rapor.
     */
    public function test_get_keterangan_predikat(): void {
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan sangat baik', ekskul_service::get_keterangan_predikat('A'));
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan baik', ekskul_service::get_keterangan_predikat('B'));
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan cukup baik', ekskul_service::get_keterangan_predikat('C'));
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan kurang baik', ekskul_service::get_keterangan_predikat('D'));
        $this->assertSame('-', ekskul_service::get_keterangan_predikat('Z'));
    }
}
