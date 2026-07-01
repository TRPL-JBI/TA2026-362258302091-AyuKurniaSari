<?php
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_akademikmonitor\service\notif_service;

/**
 * Webhook Telegram Akademik Monitor.
 *
 * Fungsi file ini:
 * 1. Menerima request dari Telegram.
 * 2. Membaca perintah /start {userid}.
 * 3. Menyimpan chat_id Telegram ke user Moodle tersebut.
 *
 * Catatan multi Telegram:
 * - Link Telegram tetap memakai userid Moodle.
 * - Yang membedakan akun Telegram adalah chat_id.
 * - Jika ayah, ibu, dan siswa membuka link yang sama, maka semua chat_id bisa tersimpan
 *   selama fungsi notif_service::save_user_link() mengecek berdasarkan moodle_userid + telegram_chat_id.
 */

function local_akademikmonitor_webhook_response(string $text = 'OK'): void {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

function local_akademikmonitor_webhook_get_username(array $message): string {
    $from = $message['from'] ?? [];

    if (!is_array($from)) {
        return '';
    }

    return trim((string)($from['username'] ?? ''));
}

function local_akademikmonitor_webhook_get_display_name(array $message): string {
    $from = $message['from'] ?? [];

    if (!is_array($from)) {
        return '';
    }

    $firstname = trim((string)($from['first_name'] ?? ''));
    $lastname = trim((string)($from['last_name'] ?? ''));

    return trim($firstname . ' ' . $lastname);
}

function local_akademikmonitor_webhook_send_message(
    string $chatid,
    string $message
): void {

    if ($chatid === '' || $message === '') {
        return;
    }

    try {

        notif_service::send_telegram($chatid, $message);

    } catch (\Throwable $e) {

        file_put_contents(
            __DIR__ . '/telegram_error_log.txt',
            date('Y-m-d H:i:s') . "\n" .
            $e->getMessage() . "\n\n",
            FILE_APPEND
        );
    }
}

$raw = file_get_contents('php://input');

if (!$raw) {
    local_akademikmonitor_webhook_response(
        "✅ Webhook Akademik Monitor aktif.\n" .
        "URL ini sudah bisa diakses.\n" .
        "Webhook menunggu request dari Telegram."
    );
}

$data = json_decode($raw, true);
$logfile = __DIR__ . '/telegram_log.txt';

if (file_exists($logfile) && filesize($logfile) > 5 * 1024 * 1024) {
    unlink($logfile);
}

file_put_contents(
    $logfile,
    date('Y-m-d H:i:s') . "\n" .
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) .
    "\n\n",
    FILE_APPEND
);

if (!is_array($data)) {
    local_akademikmonitor_webhook_response('OK');
}

/*
 * Telegram kadang mengirim update bukan hanya message.
 * Misalnya edited_message, callback_query, my_chat_member, dan lain-lain.
 * Untuk kebutuhan hubungkan akun, kita hanya butuh message biasa.
 */
if (empty($data['message']) || !is_array($data['message'])) {
    local_akademikmonitor_webhook_response('OK');
}

$message = $data['message'];

$chat = $message['chat'] ?? [];
if (!is_array($chat)) {
    local_akademikmonitor_webhook_response('OK');
}

$chatid = isset($chat['id']) ? trim((string)$chat['id']) : '';
$text = trim((string)($message['text'] ?? ''));

$username = local_akademikmonitor_webhook_get_username($message);
$displayname = local_akademikmonitor_webhook_get_display_name($message);

/*
 * Jika username Telegram kosong, simpan nama tampilan Telegram.
 * Ini berguna karena tidak semua wali murid punya username Telegram.
 */
$telegramidentity = $username !== '' ? $username : $displayname;

if ($chatid === '' || $text === '') {
    local_akademikmonitor_webhook_response('OK');
}

/*
 * Format valid dari link:
 * https://t.me/NamaBot?start=123
 *
 * Telegram akan mengirim pesan ke webhook sebagai:
 * /start 123
 *
 * Angka 123 adalah userid Moodle siswa/user yang akan dihubungkan.
 */
if (preg_match('/^\/start(?:@\w+)?\s+(\d+)$/', $text, $matches)) {
    $userid = (int)$matches[1];

    if ($userid <= 0) {
        local_akademikmonitor_webhook_send_message(
            $chatid,
            "❌ Link Telegram tidak valid.\n\nSilakan buka kembali link hubungkan Telegram dari Moodle."
        );

        local_akademikmonitor_webhook_response('OK');
    }

    try {
        notif_service::save_user_link($userid, $chatid, $telegramidentity);

        $jumlahterhubung = count(notif_service::get_user_links($userid));

        $reply = "✅ Akun Telegram berhasil dihubungkan ke sistem akademik.\n\n" .
            "Telegram ini sekarang dapat menerima notifikasi dari Moodle.\n" .
            "Jumlah Telegram yang terhubung ke akun ini: <b>" . $jumlahterhubung . "</b>.\n\n" .
            "Jika link ini dibuka oleh wali murid lain, Telegram wali murid tersebut juga dapat ikut terhubung.";

        local_akademikmonitor_webhook_send_message($chatid, $reply);
    } catch (\Throwable $e) {
        local_akademikmonitor_webhook_send_message(
            $chatid,
            "❌ Gagal menghubungkan akun Telegram.\n\n" .
            "Silakan coba lagi atau hubungi admin sekolah."
        );
    }

    local_akademikmonitor_webhook_response('OK');
}

/*
 * Jika user hanya mengetik /start tanpa userid, jangan langsung disimpan.
 * Karena sistem belum tahu akun Moodle mana yang harus dihubungkan.
 */
if ($text === '/start') {
    $reply = "Halo 👋\n\n" .
        "Silakan buka link hubungkan Telegram dari Moodle atau dari file Excel yang diberikan admin.\n\n" .
        "Link tersebut berisi kode akun Moodle sehingga sistem bisa menautkan Telegram dengan benar.";

    local_akademikmonitor_webhook_send_message($chatid, $reply);

    local_akademikmonitor_webhook_response('OK');
}

/*
 * Respon default untuk pesan selain /start.
 */
local_akademikmonitor_webhook_send_message(
    $chatid,
    "Pesan diterima.\n\n" .
    "Untuk menghubungkan Telegram, gunakan link yang diberikan dari Moodle atau admin sekolah."
);

local_akademikmonitor_webhook_response('OK');