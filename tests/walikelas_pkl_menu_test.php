<?php
// File: local/akademikmonitor/tests/walikelas_pkl_menu_test.php
// Tujuan: menguji menu Wali Kelas > PKL.
// Area uji: PKL hanya kelas XII, simpan data, update data, dan baca data PKL siswa.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\pkl_service;

final class local_akademikmonitor_walikelas_pkl_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat group yang terhubung ke kelas plugin melalui idnumber course.
     * Ini penting karena pkl_service::save() memvalidasi kelas XII dari group/course.
     */
    private function create_group_for_tingkat(string $tingkat): array {
        global $DB;

        $wali = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $tahunid = $DB->insert_record('tahun_ajaran', (object)['tahun_ajaran' => '2025/2026']);
        $jurusanid = $DB->insert_record('jurusan', (object)['nama_jurusan' => 'RPL', 'kode_jurusan' => 10]);
        $kelascustomid = $DB->insert_record('kelas', (object)[
            'nama' => '1',
            'tingkat' => $tingkat,
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunid,
            'id_user' => $wali->id,
        ]);

        $course = $this->getDataGenerator()->create_course([
            'fullname' => '[kejuruan] PKL - ' . $tingkat . ' RPL 1 - Ganjil',
            'shortname' => 'PKL-' . $tingkat . '-' . $kelascustomid,
            'idnumber' => 'AM-TA' . $tahunid . '-K' . $kelascustomid . '-KM1-S1',
        ]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => $tingkat . ' RPL 1']);

        return [(int)$student->id, (int)$group->id];
    }

    /**
     * Helper membuat mitra aktif.
     */
    private function create_mitra(string $name = 'PT Industri'): int {
        global $DB;

        return (int)$DB->insert_record('mitra_dudi', (object)[
            'nama' => $name,
            'alamat' => 'Banyuwangi',
            'kontak' => '08123',
            'is_active' => '1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * CREATE: menguji PKL bisa disimpan untuk kelas XII.
     */
    public function test_save_pkl_allowed_for_kelas_xii(): void {
        global $DB;

        [$studentid, $groupid] = $this->create_group_for_tingkat('XII');
        $mitraid = $this->create_mitra();

        pkl_service::save($studentid, $groupid, $mitraid, 1, '2026-01-01', '2026-03-01', 'A');

        $this->assertTrue($DB->record_exists('pkl', [
            'id_siswa' => $studentid,
            'id_kelas' => $groupid,
            'semester' => 1,
        ]));
    }

    /**
     * VALIDATE: menguji PKL ditolak untuk kelas X.
     */
    public function test_save_pkl_rejected_for_kelas_x(): void {
        [$studentid, $groupid] = $this->create_group_for_tingkat('X');
        $mitraid = $this->create_mitra();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Fitur PKL hanya tersedia untuk kelas XII');

        pkl_service::save($studentid, $groupid, $mitraid, 1, '2026-01-01', '2026-03-01', 'A');
    }

    /**
     * VALIDATE: menguji PKL ditolak untuk kelas XI.
     */
    public function test_save_pkl_rejected_for_kelas_xi(): void {
        [$studentid, $groupid] = $this->create_group_for_tingkat('XI');
        $mitraid = $this->create_mitra();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Fitur PKL hanya tersedia untuk kelas XII');

        pkl_service::save($studentid, $groupid, $mitraid, 1, '2026-01-01', '2026-03-01', 'A');
    }

    /**
     * UPDATE: menguji simpan ulang PKL siswa/kelas/semester yang sama mengupdate record, bukan insert duplikat.
     */
    public function test_save_pkl_updates_same_student_class_semester(): void {
        global $DB;

        [$studentid, $groupid] = $this->create_group_for_tingkat('XII');
        $mitra1 = $this->create_mitra('PT Lama');
        $mitra2 = $this->create_mitra('PT Baru');

        pkl_service::save($studentid, $groupid, $mitra1, 1, '2026-01-01', '2026-03-01', 'B');
        pkl_service::save($studentid, $groupid, $mitra2, 1, '2026-04-01', '2026-06-01', 'A');

        $records = $DB->get_records('pkl', ['id_siswa' => $studentid, 'id_kelas' => $groupid, 'semester' => 1]);
        $this->assertCount(1, $records);

        $record = reset($records);
        $this->assertSame('A', $record->nilai);
        $this->assertSame('2026-04-01', $record->waktu_mulai);
    }

    /**
     * READ: menguji get_pkl_siswa mengembalikan identitas mitra.
     */
    public function test_get_pkl_siswa_returns_mitra_identity(): void {
        [$studentid, $groupid] = $this->create_group_for_tingkat('XII');
        $mitraid = $this->create_mitra();

        pkl_service::save($studentid, $groupid, $mitraid, 1, '2026-01-01', '2026-03-01', 'A');
        $rows = pkl_service::get_pkl_siswa($studentid, $groupid, 1);

        $this->assertCount(1, $rows);
        $this->assertSame('PT Industri', $rows[0]->nama);
        $this->assertSame('A', $rows[0]->nilai);
    }
}
