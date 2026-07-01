<?php
// File: local/akademikmonitor/tests/admin_mitra_dudi_menu_test.php
// Tujuan: menguji menu Admin > Mitra DU/DI.
// Area uji: create, read/filter aktif/arsip, update, toggle arsip/aktif, dan import.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\mitra_service;

final class local_akademikmonitor_admin_mitra_dudi_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * CREATE: menguji tambah mitra DU/DI dan default status aktif.
     */
    public function test_create_mitra_trims_input_and_defaults_active(): void {
        global $DB;

        $id = mitra_service::create('  PT Industri  ', '  Banyuwangi  ', '  081234  ');
        $record = $DB->get_record('mitra_dudi', ['id' => $id], '*', MUST_EXIST);

        $this->assertSame('PT Industri', $record->nama);
        $this->assertSame('Banyuwangi', $record->alamat);
        $this->assertSame('081234', $record->kontak);
        $this->assertSame('1', (string)$record->is_active);
    }

    /**
     * VALIDATE: menguji nama mitra wajib diisi.
     */
    public function test_create_mitra_requires_name(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Nama mitra wajib diisi');

        mitra_service::create('   ');
    }

    /**
     * READ/FILTER: menguji daftar mitra aktif dan arsip tidak bercampur.
     */
    public function test_list_mitra_filters_active_and_archived(): void {
        global $DB;

        $activeid = mitra_service::create('PT Aktif', 'A', '1');
        $archivedid = mitra_service::create('PT Arsip', 'B', '2');
        $DB->set_field('mitra_dudi', 'is_active', 0, ['id' => $archivedid]);

        $active = mitra_service::list_mitra('active');
        $archived = mitra_service::list_mitra('archived');

        $this->assertContains((int)$activeid, array_column($active, 'id'));
        $this->assertNotContains((int)$archivedid, array_column($active, 'id'));
        $this->assertContains((int)$archivedid, array_column($archived, 'id'));
    }

    /**
     * UPDATE: menguji edit nama, alamat, dan kontak mitra.
     */
    public function test_update_mitra_changes_fields(): void {
        global $DB;

        $id = mitra_service::create('PT Lama', 'Alamat Lama', '111');
        mitra_service::update($id, 'PT Baru', 'Alamat Baru', '222');

        $record = $DB->get_record('mitra_dudi', ['id' => $id], '*', MUST_EXIST);

        $this->assertSame('PT Baru', $record->nama);
        $this->assertSame('Alamat Baru', $record->alamat);
        $this->assertSame('222', $record->kontak);
    }

    /**
     * TOGGLE/ARSIP: menguji arsipkan dan aktifkan kembali mitra.
     */
    public function test_toggle_mitra_archives_and_restores(): void {
        global $DB;

        $id = mitra_service::create('PT Toggle', 'A', '1');

        $this->assertSame(0, mitra_service::toggle($id));
        $this->assertSame('0', (string)$DB->get_field('mitra_dudi', 'is_active', ['id' => $id]));

        $this->assertSame(1, mitra_service::toggle($id));
        $this->assertSame('1', (string)$DB->get_field('mitra_dudi', 'is_active', ['id' => $id]));
    }

    /**
     * IMPORT: menguji import mitra menghitung sukses, gagal, duplikat file, dan duplikat database.
     */
    public function test_import_mitra_counts_success_and_failures(): void {
        mitra_service::create('PT Existing', 'X', '0');

        $result = mitra_service::import([
            ['nama' => 'PT Satu', 'alamat' => 'A', 'kontak' => '1'],
            ['nama' => '', 'alamat' => 'B', 'kontak' => '2'],
            ['nama' => 'PT Dua', 'alamat' => 'C', 'kontak' => '3'],
            ['nama' => 'PT Dua', 'alamat' => 'D', 'kontak' => '4'],
            ['nama' => 'PT Existing', 'alamat' => 'E', 'kontak' => '5'],
        ]);

        $this->assertSame(2, $result['success']);
        $this->assertSame(3, $result['failed']);
        $this->assertCount(3, $result['errors']);
    }
}
