<?php
// File: local/akademikmonitor/tests/admin_ekskul_menu_test.php
// Tujuan: menguji menu Admin > Ekstrakurikuler.
// Area uji: create, read/list, update, toggle aktif/nonaktif, dan parse pembina.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\ekskul_service;

final class local_akademikmonitor_admin_ekskul_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * CREATE: menguji tambah ekstrakurikuler baru oleh admin.
     */
    public function test_create_ekskul_defaults_to_active(): void {
        global $DB;

        $pembina = $this->getDataGenerator()->create_user(['firstname' => 'Budi', 'lastname' => 'Pembina']);

        $id = ekskul_service::create('  Pramuka  ', (int)$pembina->id);
        $record = $DB->get_record('ekskul', ['id' => $id], '*', MUST_EXIST);

        $this->assertSame('Pramuka', $record->nama);
        $this->assertSame((int)$pembina->id, (int)$record->id_pembina);
        $this->assertSame('1', (string)$record->is_active);
    }

    /**
     * READ: menguji daftar ekstrakurikuler menampilkan nama pembina dan status aktif.
     */
    public function test_list_ekskul_returns_pembina_name_and_badge(): void {
        $pembina = $this->getDataGenerator()->create_user(['firstname' => 'Siti', 'lastname' => 'Guru']);
        $id = ekskul_service::create('Futsal', (int)$pembina->id);

        $rows = ekskul_service::list_ekskul();
        $filtered = array_values(array_filter($rows, static fn($r) => (int)$r['id'] === (int)$id));

        $this->assertCount(1, $filtered);
        $this->assertSame('Futsal', $filtered[0]['nama']);
        $this->assertStringContainsString('Siti', $filtered[0]['pembina']);
        $this->assertTrue($filtered[0]['is_enabled']);
        $this->assertSame('aktif', $filtered[0]['badge_text']);
    }

    /**
     * UPDATE: menguji edit nama ekstrakurikuler dan pembina.
     */
    public function test_update_ekskul_changes_name_and_pembina(): void {
        global $DB;

        $pembina1 = $this->getDataGenerator()->create_user();
        $pembina2 = $this->getDataGenerator()->create_user();

        $id = ekskul_service::create('Basket', (int)$pembina1->id);
        ekskul_service::update($id, 'Basket Putra', (int)$pembina2->id);

        $record = $DB->get_record('ekskul', ['id' => $id], '*', MUST_EXIST);

        $this->assertSame('Basket Putra', $record->nama);
        $this->assertSame((int)$pembina2->id, (int)$record->id_pembina);
    }

    /**
     * TOGGLE: menguji tombol aktif/nonaktif ekstrakurikuler.
     */
    public function test_toggle_ekskul_changes_active_status(): void {
        global $DB;

        $pembina = $this->getDataGenerator()->create_user();
        $id = ekskul_service::create('PMR', (int)$pembina->id);

        ekskul_service::toggle($id);
        $this->assertSame('0', (string)$DB->get_field('ekskul', 'is_active', ['id' => $id]));

        ekskul_service::toggle($id);
        $this->assertSame('1', (string)$DB->get_field('ekskul', 'is_active', ['id' => $id]));
    }

    /**
     * PARSE: menguji input pembina bisa berupa user id atau teks username/nama.
     */
    public function test_parse_pembina_input_accepts_id_and_text(): void {
        $user = $this->getDataGenerator()->create_user([
            'username' => 'guru.ekskul',
            'firstname' => 'Guru',
            'lastname' => 'Ekskul',
        ]);

        $this->assertSame((int)$user->id, ekskul_service::parse_pembina_input((string)$user->id));
        $this->assertSame((int)$user->id, ekskul_service::parse_pembina_input('guru.ekskul'));
        $this->assertSame(0, ekskul_service::parse_pembina_input('tidak-ada-user-ini'));
    }
}
