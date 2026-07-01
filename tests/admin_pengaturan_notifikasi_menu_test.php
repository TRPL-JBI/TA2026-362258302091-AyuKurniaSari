<?php
// File: local/akademikmonitor/tests/admin_pengaturan_notifikasi_menu_test.php
// Tujuan: menguji menu Admin > Pengaturan Notifikasi.
// Area uji: setting bot, rule default, update rule, toggle rule, dan log pengiriman.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\notif_service;

final class local_akademikmonitor_admin_pengaturan_notifikasi_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * CREATE/DEFAULT: menguji rule default notifikasi dibuat otomatis.
     */
    public function test_ensure_default_rules_creates_setting_and_rules(): void {
        global $DB;

        notif_service::ensure_default_rules();

        $this->assertSame(1, $DB->count_records('setting_telegram'));
        $this->assertTrue($DB->record_exists('notif_rule', ['rule_kode' => 'pengingat_tugas']));
        $this->assertTrue($DB->record_exists('notif_rule', ['rule_kode' => 'nilai_kktp']));
        $this->assertTrue($DB->record_exists('notif_rule', ['rule_kode' => 'pengingat_event']));
    }

    /**
     * READ: menguji halaman admin hanya menampilkan rule visible.
     * Catatan: pengingat_event tetap ada untuk sistem, tapi tidak muncul di UI admin.
     */
    public function test_list_rules_hides_pengingat_event_from_admin_ui(): void {
        notif_service::ensure_default_rules();
        $rows = notif_service::list_rules();

        $codes = array_column($rows, 'rule_kode');

        $this->assertContains('pengingat_tugas', $codes);
        $this->assertContains('nilai_kktp', $codes);
        $this->assertNotContains('pengingat_event', $codes);
    }

    /**
     * UPDATE: menguji simpan token bot Telegram admin.
     */
    public function test_save_setting_inserts_then_updates_bot_config(): void {
        $setting = notif_service::get_setting();
        $this->assertSame(0, (int)$setting->id);

        notif_service::save_setting('token-1', 'bot_satu', 1, '2026-05-13 10:00:00');
        $saved = notif_service::get_setting();

        $this->assertSame('token-1', $saved->bot_token);
        $this->assertSame('bot_satu', $saved->bot_username);
        $this->assertSame('1', (string)$saved->is_enabled);

        notif_service::save_setting('token-2', 'bot_dua', 0, '2026-05-13 11:00:00');
        $updated = notif_service::get_setting();

        $this->assertSame((int)$saved->id, (int)$updated->id);
        $this->assertSame('token-2', $updated->bot_token);
        $this->assertSame('bot_dua', $updated->bot_username);
        $this->assertSame('0', (string)$updated->is_enabled);
    }

    /**
     * UPDATE: menguji edit rule admin dan keyword tetap dikunci sistem.
     */
    public function test_update_rule_saves_offset_time_recipients_and_locks_keyword(): void {
        global $DB;

        notif_service::ensure_default_rules();
        $rule = $DB->get_record('notif_rule', ['rule_kode' => 'nilai_kktp'], '*', MUST_EXIST);

        notif_service::update_rule((int)$rule->id, '3', '07:30:00', 'Siswa, Wali Kelas');
        $updated = $DB->get_record('notif_rule', ['id' => $rule->id], '*', MUST_EXIST);

        $this->assertSame('3', (string)$updated->offset_days);
        $this->assertSame('07:30:00', $updated->send_time);
        $this->assertSame('Siswa, Wali Kelas', $updated->recipients);
        $this->assertSame('ujian', $updated->event_keyword);
    }

    /**
     * TOGGLE: menguji aktif/nonaktif rule notifikasi.
     */
    public function test_toggle_rule_changes_enabled_status(): void {
        global $DB;

        notif_service::ensure_default_rules();
        $rule = $DB->get_record('notif_rule', ['rule_kode' => 'pengingat_tugas'], '*', MUST_EXIST);
        $before = (int)$rule->is_enabled;

        $after = notif_service::toggle_rule((int)$rule->id);

        $this->assertSame($before ? 0 : 1, $after);
        $this->assertSame((string)$after, (string)$DB->get_field('notif_rule', 'is_enabled', ['id' => $rule->id]));
    }

    /**
     * LOG: menguji penyimpanan log pengiriman dan pengecekan duplikasi log terkirim.
     */
    public function test_save_delivery_log_and_has_log_been_sent(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(notif_service::has_log_been_sent((int)$user->id, 'pengingat_tugas', 12, 0, '2026-05-13 08:00:00'));

        notif_service::save_delivery_log(
            (int)$user->id,
            0,
            'pengingat_tugas',
            12,
            0,
            'Tugas HTML',
            '2026-05-13 08:00:00',
            '123456',
            '<b>Pesan</b> test',
            'sent'
        );

        $this->assertSame(1, $DB->count_records('log_pengiriman_pesan'));
        $this->assertTrue(notif_service::has_log_been_sent((int)$user->id, 'pengingat_tugas', 12, 0, '2026-05-13 08:00:00'));
    }
}
