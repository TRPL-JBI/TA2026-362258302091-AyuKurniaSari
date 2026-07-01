<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class notif_service {

    public static function get_setting(): \stdClass {
        global $DB;

        $recs = $DB->get_records('setting_telegram', null, 'id ASC', '*', 0, 1);
        $rec = $recs ? reset($recs) : null;

        return $rec ?: (object)[
            'id' => 0,
            'bot_token' => '',
            'bot_username' => '',
            'is_enabled' => 0,
            'token_verified_at' => '',
            'timecreated' => 0,
            'timemodified' => 0,
        ];
    }

    public static function save_setting(string $token, string $username, int $enabled, string $verifiedat): void {
        global $DB;

        $token = trim($token);
        $username = trim($username);
        $now = time();

        $rec = self::get_setting();

        if (!empty($rec->id)) {
            $rec->bot_token = $token;
            $rec->bot_username = $username;
            $rec->is_enabled = $enabled ? 1 : 0;
            $rec->token_verified_at = $verifiedat;
            $rec->timemodified = $now;

            $DB->update_record('setting_telegram', $rec);
            return;
        }

        $DB->insert_record('setting_telegram', (object)[
            'bot_token' => $token,
            'bot_username' => $username,
            'is_enabled' => $enabled ? 1 : 0,
            'token_verified_at' => $verifiedat,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Rule yang ditampilkan di halaman admin.
     *
     * Final:
     * - Pengingat deadline tugas.
     * - Nilai di bawah KKTP menjelang ujian.
     *
     * Rule pengingat_event tetap ada di database, tetapi tidak ditampilkan di UI.
     */
    public static function get_visible_rule_codes(): array {
        return [
            'pengingat_tugas',
            'nilai_kktp',
        ];
    }

    /**
     * Label rule agar admin tidak melihat nama teknis database.
     */
    public static function get_rule_label(string $rulekode): string {
        switch ($rulekode) {
            case 'pengingat_tugas':
                return 'Pengingat Deadline Tugas';

            case 'nilai_kktp':
                return 'Nilai di Bawah KKTP Menjelang Ujian';

            case 'pengingat_event':
                return 'Pengingat Event';
            case 'kehadiran_guru_harian':
                return 'Laporan Kehadiran Guru Harian';

            case 'kehadiran_siswa_harian':
                return 'Laporan Kehadiran Siswa Harian';

            case 'kehadiran_siswa_mingguan':
                return 'Laporan Kehadiran Siswa Mingguan';

            default:
                return $rulekode;
        }
    }

    /**
     * Keyword sistem dikunci.
     *
     * Admin tidak boleh mengubah keyword agar tidak salah setting.
     *
     * - pengingat_tugas => deadline
     * - nilai_kktp      => ujian
     */
    public static function get_fixed_event_keyword(string $rulekode): string {
        switch ($rulekode) {
            case 'pengingat_tugas':
                return 'deadline';

            case 'nilai_kktp':
                return 'ujian';

            case 'pengingat_event':
                return 'ujian';
            case 'kehadiran_guru_harian':
                return 'presensi_guru';

            case 'kehadiran_siswa_harian':
                return 'presensi_siswa_harian';

            case 'kehadiran_siswa_mingguan':
                return 'presensi_siswa_mingguan';

            default:
                return '';
        }
    }

    /**
     * Memastikan rule default tersedia.
     *
     * Ini bukan dummy data akademik.
     * Ini adalah konfigurasi default notifikasi agar halaman bisa langsung dipakai.
     */
    public static function ensure_default_rules(): void {
        global $DB;

        $now = time();

        if (!$DB->record_exists('setting_telegram', [])) {
            $DB->insert_record('setting_telegram', (object)[
                'bot_token' => '',
                'bot_username' => '',
                'is_enabled' => '0',
                'token_verified_at' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $defaults = [
            [
                'rule_kode' => 'pengingat_tugas',
                'is_enabled' => '1',
                'offset_days' => '1',
                'send_time' => '08:00:00',
                'recipients' => 'Siswa, Guru, Wali Kelas',
                'event_keyword' => self::get_fixed_event_keyword('pengingat_tugas'),
            ],
            [
                'rule_kode' => 'nilai_kktp',
                'is_enabled' => '1',
                'offset_days' => '7',
                'send_time' => '08:00:00',
                'recipients' => 'Siswa, Guru, Wali Kelas',
                'event_keyword' => self::get_fixed_event_keyword('nilai_kktp'),
            ],
            [
                'rule_kode' => 'kehadiran_guru_harian',
                'is_enabled' => '1',
                'offset_days' => '0',
                'send_time' => '16:00:00',
                'recipients' => 'Admin, Kepala Sekolah',
                'event_keyword' => self::get_fixed_event_keyword('kehadiran_guru_harian'),
            ],

            [
                'rule_kode' => 'kehadiran_siswa_harian',
                'is_enabled' => '1',
                'offset_days' => '0',
                'send_time' => '15:00:00',
                'recipients' => 'Siswa',
                'event_keyword' => self::get_fixed_event_keyword('kehadiran_siswa_harian'),
            ],

            [
                'rule_kode' => 'kehadiran_siswa_mingguan',
                'is_enabled' => '1',
                'offset_days' => '0',
                'send_time' => '18:00:00',
                'recipients' => 'Siswa',
                'event_keyword' => self::get_fixed_event_keyword('kehadiran_siswa_mingguan'),
            ],
            [
                'rule_kode' => 'pengingat_event',
                'is_enabled' => '0',
                'offset_days' => '7',
                'send_time' => '08:00:00',
                'recipients' => 'Siswa, Guru, Wali Kelas',
                'event_keyword' => self::get_fixed_event_keyword('pengingat_event'),
            ],
        ];

        foreach ($defaults as $default) {
            $existing = $DB->get_record('notif_rule', ['rule_kode' => $default['rule_kode']]);

            if ($existing) {
                /*
                 * Keyword tetap dikunci oleh sistem.
                 * Jadi kalau sebelumnya pernah diubah jadi uts/uas/remidi,
                 * sistem mengembalikan sesuai aturan final.
                 */
                $existing->event_keyword = $default['event_keyword'];

                /*
                 * pengingat_event disembunyikan dari UI dan dimatikan.
                 * kehadiran_siswa_harian dipertahankan sebagai legacy, tetapi
                 * laporan resminya sekarang dikirim mingguan.
                 */
                if ($default['rule_kode'] === 'pengingat_event') {
                    $existing->is_enabled = '0';
                }

                $existing->timemodified = $now;
                $DB->update_record('notif_rule', $existing);
                continue;
            }

            $record = (object)$default;
            $record->timecreated = $now;
            $record->timemodified = $now;

            $DB->insert_record('notif_rule', $record);
        }
    }

    /**
     * Mengambil rule notifikasi yang tampil di halaman admin.
     */
    public static function list_rules(): array {
        global $DB;

        self::ensure_default_rules();

        $codes = [
            'pengingat_tugas',
            'nilai_kktp',

            'kehadiran_guru_harian',
            'kehadiran_siswa_harian',
            'kehadiran_siswa_mingguan',
        ];
        [$insql, $params] = $DB->get_in_or_equal($codes, SQL_PARAMS_NAMED, 'rule');

        $rows = $DB->get_records_select(
            'notif_rule',
            "rule_kode {$insql}",
            $params,
            'id ASC'
        );

        $out = [];

        foreach ($rows as $r) {
            $isactive = !empty($r->is_enabled);
            $keyword = self::get_fixed_event_keyword((string)$r->rule_kode);
            $islaporan = in_array($r->rule_kode, [
                'kehadiran_guru_harian',
                'kehadiran_siswa_harian',
                'kehadiran_siswa_mingguan'
            ]);

            // if ($r->rule_kode === 'pengingat_tugas') {
            //     $keywordlabel = 'Deadline tugas';
            //     $keywordhelp = 'Sistem otomatis membaca deadline assignment Moodle.';
            // } else {
            //     $keywordlabel = 'ujian';
            //     $keywordhelp = 'Sistem membaca event kalender yang namanya mengandung kata "ujian", misalnya Ujian Tengah Semester atau Ujian Akhir Semester.';
            // }
            switch ($r->rule_kode) {

                case 'pengingat_tugas':
                    $keywordlabel = 'Deadline tugas';
                    $keywordhelp = 'Sistem otomatis membaca deadline assignment Moodle.';
                    break;

                case 'nilai_kktp':
                    $keywordlabel = 'ujian';
                    $keywordhelp = 'Sistem membaca event kalender ujian.';
                    break;

                case 'kehadiran_guru_harian':
                    $keywordlabel = 'Setiap Hari';
                    $keywordhelp = 'Mengirim laporan presensi guru harian.';
                    break;

                case 'kehadiran_siswa_harian':
                    $keywordlabel = 'Setiap Hari';
                    $keywordhelp = 'Mengirim laporan presensi siswa harian.';
                    break;

                case 'kehadiran_siswa_mingguan':

                    $hari = [
                        1 => 'Senin',
                        2 => 'Selasa',
                        3 => 'Rabu',
                        4 => 'Kamis',
                        5 => 'Jumat',
                        6 => 'Sabtu',
                        7 => 'Minggu'
                    ];

                    $keywordlabel = $hari[(int)$r->offset_days] ?? 'Sabtu';

                    $keywordhelp = 'Hari pengiriman laporan mingguan.';
                    break;

                default:
                    $keywordlabel = '-';
                    $keywordhelp = '-';
            }

            $schedulelabel = '';

            if ($r->rule_kode === 'kehadiran_guru_harian') {
                $schedulelabel = 'Setiap Hari';
            }

            if ($r->rule_kode === 'kehadiran_siswa_harian') {
                $schedulelabel = 'Setiap Hari';
            }

            if ($r->rule_kode === 'kehadiran_siswa_mingguan') {

                $hari = [
                    1 => 'Senin',
                    2 => 'Selasa',
                    3 => 'Rabu',
                    4 => 'Kamis',
                    5 => 'Jumat',
                    6 => 'Sabtu',
                    7 => 'Minggu'
                ];

                $days = str_split((string)$r->offset_days);

                if (count($days) >= 7) {

                    $schedulelabel = 'Setiap Hari';

                } else if (count($days) > 1) {

                    $labels = [];

                    foreach ($days as $day) {
                        if (isset($hari[(int)$day])) {
                            $labels[] = $hari[(int)$day];
                        }
                    }

                    $schedulelabel = implode(', ', $labels);

                } else {

                    $schedulelabel =
                        $hari[(int)reset($days)] ?? 'Sabtu';
                }
            }
            $out[] = [
                'id' => (int)$r->id,
                'rule_kode' => (string)$r->rule_kode,
                'label' => self::get_rule_label((string)$r->rule_kode),
                'send_time' => $r->send_time ?: '08:00:00',
                'offset_days' => $r->offset_days ?: '1',
                'event_keyword' => $keyword,
                'keyword_label' => $keywordlabel,
                'keyword_help' => $keywordhelp,
                'schedule_label' => $schedulelabel,
                'recipients' => $r->recipients ?: '',
                'recipients_raw' => $r->recipients ?: '',
                'is_enabled' => $isactive,
                'badge_class' => $isactive ? 'on' : 'off',
                'badge_text' => $isactive ? 'aktif' : 'nonaktif',
                'toggle_text' => $isactive ? 'nonaktif' : 'aktif',
                'is_laporan' => $islaporan,
            ];
        }

        $notifikasi = [];
        $laporan = [];

        foreach ($out as $item) {

            if (!empty($item['is_laporan'])) {
                $laporan[] = $item;
            } else {
                $notifikasi[] = $item;
            }
        }

        return [
            'rules' => $notifikasi,
            'reports' => $laporan
        ];
    }

    /**
     * Update rule notifikasi.
     *
     * Keyword tidak boleh berasal dari input admin.
     * Keyword dikunci oleh sistem:
     * - pengingat_tugas => deadline
     * - nilai_kktp => ujian
     */
    public static function update_rule(
        int $id,
        string $offsetdays,
        string $sendtime,
        string $recipients
    ): void {
        global $DB;

        $rec = $DB->get_record('notif_rule', ['id' => $id], '*', MUST_EXIST);

        $offsetdays = trim($offsetdays);
        $sendtime = trim($sendtime);
        $recipients = trim($recipients);

       

        if ($sendtime === '') {
            $sendtime = '08:00:00';
        }

       $offsetdays = trim($offsetdays);

        if ($offsetdays === '') {
            $offsetdays = '1';
        }

        $rec->offset_days = $offsetdays;
        $rec->send_time = $sendtime;
        $rec->event_keyword = self::get_fixed_event_keyword((string)$rec->rule_kode);
        $rec->recipients = $recipients;
        $rec->timemodified = time();

        $DB->update_record('notif_rule', $rec);
    }

    public static function toggle_rule(int $id): int {
        global $DB;

        $current = (int)$DB->get_field('notif_rule', 'is_enabled', ['id' => $id], MUST_EXIST);
        $new = $current ? 0 : 1;

        $DB->set_field('notif_rule', 'is_enabled', $new, ['id' => $id]);
        $DB->set_field('notif_rule', 'timemodified', time(), ['id' => $id]);

        return $new;
    }

    public static function check_telegram_token(string $token): array {
        global $CFG;

        $token = trim($token);

        if ($token === '') {
            return [
                'ok' => false,
                'username' => '',
                'message' => 'Token bot tidak boleh kosong.',
            ];
        }

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $url = 'https://api.telegram.org/bot' . $token . '/getMe';

        $resp = $curl->get($url);

        if (!$resp) {
            return [
                'ok' => false,
                'username' => '',
                'message' => 'Tidak ada respon dari Telegram. Cek koneksi internet server / laptop.',
            ];
        }

        $json = json_decode($resp, true);

        if (!is_array($json) || empty($json['ok'])) {
            return [
                'ok' => false,
                'username' => '',
                'message' => $json['description'] ?? 'Token tidak valid / response Telegram tidak sesuai.',
            ];
        }

        return [
            'ok' => true,
            'username' => $json['result']['username'] ?? '',
            'message' => 'Koneksi Telegram berhasil.',
        ];
    }

public static function get_user_link(int $userid): ?\stdClass {
    global $DB;

    /*
     * Fungsi ini tetap dipertahankan agar kode lama yang hanya butuh 1 data
     * tidak langsung rusak. Karena sekarang satu user bisa punya banyak Telegram,
     * fungsi ini hanya mengambil data terbaru.
     */
    $records = $DB->get_records(
        'telegram_user_link',
        ['moodle_userid' => $userid],
        'timemodified DESC, id DESC',
        '*',
        0,
        1
    );

    if (!$records) {
        return null;
    }

    return reset($records);
}

public static function get_user_links(int $userid): array {
    global $DB;

    /*
     * Fungsi baru untuk mengambil semua Telegram yang terhubung
     * ke satu user Moodle.
     *
     * Ini yang nanti dipakai oleh cron pengiriman notifikasi.
     */
    return $DB->get_records_select(
        'telegram_user_link',
        "moodle_userid = :userid
         AND is_linked = :linked
         AND telegram_chat_id IS NOT NULL
         AND telegram_chat_id <> ''",
        [
            'userid' => $userid,
            'linked' => '1',
        ],
        'timecreated ASC, id ASC'
    );
}

public static function save_user_link(int $userid, string $chatid, string $username = ''): \stdClass {
    global $DB;

    $userid = (int)$userid;
    $chatid = trim($chatid);
    $username = trim($username);
    $now = time();

    /*
     * Validasi dasar.
     * Kalau userid atau chatid kosong, data tidak boleh disimpan.
     */
    if ($userid <= 0 || $chatid === '') {
        throw new \Exception('Data user Moodle atau chat ID Telegram tidak valid.');
    }

    /*
     * Perubahan penting:
     * Data existing dicek berdasarkan kombinasi moodle_userid + telegram_chat_id.
     *
     * Kenapa bukan hanya moodle_userid?
     * Karena kalau hanya moodle_userid, maka Telegram wali murid akan menimpa
     * Telegram siswa.
     *
     * Dengan kombinasi ini:
     * - Telegram yang sama klik ulang link → data diperbarui.
     * - Telegram berbeda klik link user yang sama → dibuat baris baru.
     */
    $existing = $DB->get_record('telegram_user_link', [
        'moodle_userid' => $userid,
        'telegram_chat_id' => $chatid,
    ]);

    if ($existing) {
        $existing->telegram_username = $username;
        $existing->is_linked = '1';
        $existing->linked_at = date('Y-m-d H:i:s', $now);
        $existing->timemodified = $now;

        $DB->update_record('telegram_user_link', $existing);

        return $DB->get_record('telegram_user_link', ['id' => $existing->id], '*', MUST_EXIST);
    }

    $id = $DB->insert_record('telegram_user_link', (object)[
        'moodle_userid' => $userid,
        'telegram_chat_id' => $chatid,
        'telegram_username' => $username,
        'is_linked' => '1',
        'linked_at' => date('Y-m-d H:i:s', $now),
        'timecreated' => $now,
        'timemodified' => $now,
    ]);

    return $DB->get_record('telegram_user_link', ['id' => $id], '*', MUST_EXIST);
}

public static function is_user_connected(int $userid): bool {
    /*
     * Sekarang status terhubung dianggap benar kalau minimal ada
     * satu Telegram aktif untuk user tersebut.
     */
    return count(self::get_user_links($userid)) > 0;
}

    public static function build_telegram_connect_url(int $userid): string {
        $setting = self::get_setting();
        $username = trim((string)($setting->bot_username ?? ''));

        if ($username === '') {
            return '';
        }

        return 'https://t.me/' . $username . '?start=' . $userid;
    }

    public static function send_telegram(string $chatid, string $message): array {
        global $CFG;

        $setting = self::get_setting();

        if (empty($setting->bot_token)) {
            return [
                'ok' => false,
                'message' => 'Bot token belum disimpan.',
                'raw' => null,
            ];
        }

        if ((int)$setting->is_enabled !== 1) {
            return [
                'ok' => false,
                'message' => 'Bot Telegram belum diaktifkan.',
                'raw' => null,
            ];
        }

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $url = 'https://api.telegram.org/bot' . $setting->bot_token . '/sendMessage';

        $params = [
            'chat_id' => $chatid,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];

        $resp = $curl->post($url, $params);

        if (!$resp) {
            return [
                'ok' => false,
                'message' => 'Gagal mengirim pesan ke Telegram.',
                'raw' => null,
            ];
        }

        $json = json_decode($resp, true);

        if (!is_array($json) || empty($json['ok'])) {
            return [
                'ok' => false,
                'message' => $json['description'] ?? 'Response Telegram tidak valid.',
                'raw' => $json,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Pesan berhasil dikirim.',
            'raw' => $json,
        ];
    }

    public static function has_log_been_sent(
        int $userid,
        string $rulecode,
        int $assignid,
        int $eventid,
        string $scheduledat
    ): bool {
        global $DB;

        $params = [
            'moodle_userid' => $userid,
            'rule_code' => $rulecode,
            'scheduled_at' => $scheduledat,
            'status' => 'sent',
        ];

        if ($assignid > 0) {
            $params['assignid'] = $assignid;
        }

        if ($eventid > 0) {
            $params['eventid'] = $eventid;
        }

        return $DB->record_exists('log_pengiriman_pesan', $params);
    }
public static function has_log_been_sent_to_chat(
    int $userid,
    string $rulecode,
    int $assignid,
    int $eventid,
    string $scheduledat,
    string $chatid
): bool {
    global $DB;

    $params = [
        'moodle_userid' => $userid,
        'rule_code' => $rulecode,
        'scheduled_at' => $scheduledat,
        'telegram_chat_id' => $chatid,
        'status' => 'sent',
    ];

    if ($assignid > 0) {
        $params['assignid'] = $assignid;
    }

    if ($eventid > 0) {
        $params['eventid'] = $eventid;
    }

    return $DB->record_exists('log_pengiriman_pesan', $params);
}
    protected static function cut_text(string $text, int $maxlength = 255): string {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxlength, 'UTF-8');
        }

        return substr($text, 0, $maxlength);
    }

    public static function save_delivery_log(
        int $userid,
        int $courseid,
        string $rulecode,
        int $assignid,
        int $eventid,
        string $contexttitle,
        string $scheduledat,
        string $chatid,
        string $messagepreview,
        string $status,
        string $errormessage = ''
    ): void {
        global $DB;

        $now = time();

        $record = (object)[
            'moodle_userid' => $userid,
            'courseid' => $courseid > 0 ? $courseid : null,
            'rule_code' => $rulecode,
            'assignid' => $assignid > 0 ? $assignid : null,
            'eventid' => $eventid > 0 ? $eventid : null,
            'context_title' => self::cut_text($contexttitle, 255),
            'scheduled_at' => $scheduledat,
            'sent_at' => date('Y-m-d H:i:s', $now),
            'status' => self::cut_text($status, 50),
            'telegram_chat_id' => self::cut_text($chatid, 100),
            'message_preview' => self::cut_text(strip_tags($messagepreview), 255),
            'error_message' => self::cut_text($errormessage, 255),
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $DB->insert_record('log_pengiriman_pesan', $record);
    }

public static function set_webhook(string $token): array {
    global $CFG;

    require_once($CFG->libdir . '/filelib.php');

    if (stripos($CFG->wwwroot, 'https://') !== 0) {
        return [
            'ok' => false,
            'message' => 'Webhook Telegram wajib HTTPS'
        ];
    }

    $webhookurl = $CFG->wwwroot .
        '/local/akademikmonitor/telegram/webhook.php';

    $url = "https://api.telegram.org/bot{$token}/setWebhook";

    $payload = [
        'url' => $webhookurl
    ];

    $curl = new \curl();

    $options = [
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_SSL_VERIFYPEER' => false,
        'CURLOPT_TIMEOUT' => 30,
    ];
    

    $response = $curl->post($url, $payload, $options);

    if (!$response) {
        return [
            'ok' => false,
            'message' => 'Telegram API tidak memberi response'
        ];
    }

    $result = json_decode($response, true);

    if (!is_array($result)) {
        return [
            'ok' => false,
            'message' => 'Response Telegram tidak valid',
            'raw' => $response
        ];
    }

    return $result;
}


public static function get_connected_telegram_users(): array {
    global $DB;

    $sql = "
        SELECT
            tul.id,
            tul.telegram_username,
            tul.telegram_chat_id,
            tul.is_linked,
            tul.linked_at,

            u.id AS userid,
            u.firstname,
            u.lastname,
            u.email

        FROM {telegram_user_link} tul

        JOIN {user} u
             ON u.id = tul.moodle_userid

        WHERE tul.is_linked = 1

        ORDER BY tul.linked_at DESC
    ";

    $records = $DB->get_records_sql($sql);

    foreach ($records as $record) {
        $record->fullname = fullname($record);
    }

    return $records;
}
public static function send_telegram_document(
    string $chatid,
    string $filepath,
    string $caption = ''
): array {

    $setting = self::get_setting();

    if (empty($setting->bot_token)) {
        return [
            'success' => false,
            'message' => 'Token kosong'
        ];
    }

    $url =
        'https://api.telegram.org/bot' .
        $setting->bot_token .
        '/sendDocument';

    $postfields = [
        'chat_id' => $chatid,
        'caption' => $caption,
        'document' =>
            new \CURLFile($filepath)
    ];

    $ch = curl_init();

    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$postfields);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);

    $response = curl_exec($ch);

    curl_close($ch);

    return [
        'success' => true,
        'response' => $response
    ];
}
}