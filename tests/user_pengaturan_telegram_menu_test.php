<?php
// File: local/akademikmonitor/tests/user_pengaturan_telegram_menu_test.php
// Tujuan: menguji menu Semua User > Pengaturan Notifikasi Telegram.
// Area uji: connect Telegram, update koneksi, status linked, dan URL /start.

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\notif_service;

final class local_akademikmonitor_user_pengaturan_telegram_menu_test extends \advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * READ: menguji user awalnya belum terhubung Telegram.
     */
    public function test_user_initially_not_connected_to_telegram(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(notif_service::get_user_link((int)$user->id));
        $this->assertFalse(notif_service::is_user_connected((int)$user->id));
    }

    /**
     * CREATE: menguji webhook /start berhasil membuat koneksi user ke Telegram.
     */
    public function test_save_user_link_creates_telegram_connection(): void {
        $user = $this->getDataGenerator()->create_user();

        $link = notif_service::save_user_link((int)$user->id, '123456789', 'username_telegram');

        $this->assertSame((int)$user->id, (int)$link->moodle_userid);
        $this->assertSame('123456789', $link->telegram_chat_id);
        $this->assertSame('username_telegram', $link->telegram_username);
        $this->assertSame('1', (string)$link->is_linked);
        $this->assertTrue(notif_service::is_user_connected((int)$user->id));
    }

    /**
     * UPDATE: menguji koneksi Telegram user diperbarui jika chat id atau username berubah.
     */
    public function test_save_user_link_updates_existing_telegram_connection(): void {
        $user = $this->getDataGenerator()->create_user();

        $first = notif_service::save_user_link((int)$user->id, '111', 'olduser');
        $second = notif_service::save_user_link((int)$user->id, '222', 'newuser');

        $this->assertSame((int)$first->id, (int)$second->id);
        $this->assertSame('222', $second->telegram_chat_id);
        $this->assertSame('newuser', $second->telegram_username);
    }

    /**
     * LINK: menguji URL connect Telegram memakai username bot dan id user.
     */
    public function test_build_telegram_connect_url_uses_bot_username_and_userid(): void {
        $user = $this->getDataGenerator()->create_user();

        notif_service::save_setting('token-test', 'bot_sekolah', 1, '2026-05-13 10:00:00');

        $url = notif_service::build_telegram_connect_url((int)$user->id);

        $this->assertSame('https://t.me/bot_sekolah?start=' . $user->id, $url);
    }

    /**
     * VALIDATE: menguji URL connect kosong kalau username bot belum disimpan.
     */
    public function test_build_telegram_connect_url_empty_when_bot_username_missing(): void {
        $user = $this->getDataGenerator()->create_user();

        notif_service::save_setting('token-test', '', 1, '2026-05-13 10:00:00');

        $this->assertSame('', notif_service::build_telegram_connect_url((int)$user->id));
    }
}
