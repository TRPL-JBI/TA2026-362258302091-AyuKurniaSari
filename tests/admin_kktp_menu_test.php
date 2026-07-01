<?php
// File: local/akademikmonitor/tests/admin_kktp_menu_test.php
// Tujuan: menguji menu Admin > Pengaturan KKTP.
// Area uji: list/filter KKTP, update KKTP bulk, dan opsi dropdown nilai KKTP.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\kktp_service;

final class local_akademikmonitor_admin_kktp_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        // resetAfterTest(true) membuat data yang dibuat test ini dibersihkan ulang oleh Moodle.
        // Jadi test tidak mengotori database test lain.
        $this->resetAfterTest(true);
    }

    /**
     * Helper membuat 1 data kurikulum_mapel.
     * Kenapa perlu helper? Karena KKTP milik tabel kurikulum_mapel, bukan tabel terpisah.
     */
    private function create_kurikulum_mapel(string $mapelname, string $tingkat, int $kktp): int {
        global $DB;

        $tahunid = $DB->insert_record('tahun_ajaran', (object)['tahun_ajaran' => '2025/2026']);
        $kurikulumid = $DB->insert_record('kurikulum', (object)['nama' => 'Kurikulum Merdeka', 'is_active' => '1']);
        $jurusanid = $DB->insert_record('jurusan', (object)['nama_jurusan' => 'RPL', 'kode_jurusan' => 10]);
        $mapelid = $DB->insert_record('mata_pelajaran', (object)['nama_mapel' => $mapelname]);
        $kjid = $DB->insert_record('kurikulum_jurusan', (object)[
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunid,
        ]);

        return (int)$DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kjid,
            'id_mapel' => $mapelid,
            'jam_pelajaran' => '2',
            'tingkat_kelas' => $tingkat,
            'kktp' => $kktp,
        ]);
    }

    /**
     * READ/FILTER: menguji daftar KKTP hanya menampilkan mapel sesuai jurusan dan tingkat.
     */
    public function test_list_kktp_filters_by_jurusan_and_tingkat(): void {
        global $DB;

        $tahunid = $DB->insert_record('tahun_ajaran', (object)['tahun_ajaran' => '2025/2026']);
        $kurikulumid = $DB->insert_record('kurikulum', (object)['nama' => 'Kurikulum Merdeka', 'is_active' => '1']);
        $jurusanid = $DB->insert_record('jurusan', (object)['nama_jurusan' => 'RPL', 'kode_jurusan' => 10]);
        $kjid = $DB->insert_record('kurikulum_jurusan', (object)[
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunid,
        ]);

        $mapelx = $DB->insert_record('mata_pelajaran', (object)['nama_mapel' => 'Bahasa Indonesia']);
        $mapelxi = $DB->insert_record('mata_pelajaran', (object)['nama_mapel' => 'Matematika']);

        $kmx = $DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kjid,
            'id_mapel' => $mapelx,
            'jam_pelajaran' => '2',
            'tingkat_kelas' => 'X',
            'kktp' => 75,
        ]);

        $DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kjid,
            'id_mapel' => $mapelxi,
            'jam_pelajaran' => '2',
            'tingkat_kelas' => 'XI',
            'kktp' => 80,
        ]);

        $rows = kktp_service::list_kktp((int)$jurusanid, 'X');

        $this->assertCount(1, $rows);
        $this->assertSame((int)$kmx, (int)$rows[0]['kmid']);
        $this->assertSame('Bahasa Indonesia', $rows[0]['mapel']);
        $this->assertSame(75, (int)$rows[0]['kktp']);
    }

    /**
     * UPDATE: menguji simpan KKTP bulk dan validasi batas nilai 0 sampai 100.
     */
    public function test_update_bulk_saves_and_clamps_kktp_value(): void {
        global $DB;

        $km1 = $this->create_kurikulum_mapel('Produktif RPL', 'X', 70);
        $km2 = $this->create_kurikulum_mapel('Bahasa Inggris', 'X', 70);

        // 120 harus menjadi 100, -5 harus menjadi 0.
        kktp_service::update_bulk([
            $km1 => 120,
            $km2 => -5,
            0 => 90, // id tidak valid, harus diabaikan.
        ]);

        $this->assertSame(100, (int)$DB->get_field('kurikulum_mapel', 'kktp', ['id' => $km1]));
        $this->assertSame(0, (int)$DB->get_field('kurikulum_mapel', 'kktp', ['id' => $km2]));
    }

    /**
     * OPTION: menguji opsi dropdown KKTP berisi 0-100 dan nilai selected sesuai input.
     */
    public function test_build_kktp_options_marks_selected_value(): void {
        $options = kktp_service::build_kktp_options(75);
        $selected = array_values(array_filter($options, static fn($row) => !empty($row['selected'])));

        $this->assertCount(101, $options);
        $this->assertCount(1, $selected);
        $this->assertSame(75, (int)$selected[0]['value']);
    }
}
