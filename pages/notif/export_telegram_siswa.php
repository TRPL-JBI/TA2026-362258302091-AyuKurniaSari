<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

global $CFG, $DB;

require_once($CFG->libdir . '/excellib.class.php');

use local_akademikmonitor\service\notif_service;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

/**
 * Mengambil beberapa custom profile field berdasarkan shortname.
 *
 * Kenapa fungsi ini dibuat:
 * - NISN biasanya tidak disimpan di tabel core user Moodle.
 * - Di plugin kamu, NISN sering dibaca dari custom profile field dengan shortname "nisn".
 * - Fungsi ini dibuat supaya export tetap rapi dan tidak perlu query berulang untuk setiap siswa.
 */
function local_akademikmonitor_export_profile_fields(array $userids, array $shortnames): array {
    global $DB;

    $userids = array_values(array_unique(array_map('intval', $userids)));
    $shortnames = array_values(array_unique(array_filter(array_map('trim', $shortnames))));

    if (empty($userids) || empty($shortnames)) {
        return [];
    }

    [$fieldsql, $fieldparams] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'field');
    $fields = $DB->get_records_select(
        'user_info_field',
        "shortname {$fieldsql}",
        $fieldparams,
        '',
        'id, shortname'
    );

    if (empty($fields)) {
        return [];
    }

    $fieldids = [];
    $fieldidtoshn = [];

    foreach ($fields as $field) {
        $fieldids[] = (int)$field->id;
        $fieldidtoshn[(int)$field->id] = (string)$field->shortname;
    }

    [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
    [$fieldidsql, $fieldidparams] = $DB->get_in_or_equal($fieldids, SQL_PARAMS_NAMED, 'profilefield');

    $params = array_merge($userparams, $fieldidparams);

    $records = $DB->get_records_select(
        'user_info_data',
        "userid {$usersql} AND fieldid {$fieldidsql}",
        $params,
        '',
        'id, userid, fieldid, data'
    );

    $result = [];

    foreach ($records as $record) {
        $userid = (int)$record->userid;
        $shortname = $fieldidtoshn[(int)$record->fieldid] ?? '';

        if ($shortname === '') {
            continue;
        }

        if (!isset($result[$userid])) {
            $result[$userid] = [];
        }

        $result[$userid][$shortname] = trim((string)$record->data);
    }

    return $result;
}

/**
 * Mengambil nilai pertama yang tidak kosong.
 *
 * Kenapa fungsi ini dibuat:
 * - Nomor telepon bisa berasal dari user.phone1, user.phone2, atau custom profile field.
 * - Supaya export tetap fleksibel walaupun sumber data nomor telepon berbeda-beda.
 */
function local_akademikmonitor_export_first_not_empty(array $values): string {
    foreach ($values as $value) {
        $value = trim((string)$value);

        if ($value !== '') {
            return $value;
        }
    }

    return '-';
}

$setting = notif_service::get_setting();

if (empty($setting->bot_username)) {
    throw new \Exception(
        'Bot Telegram belum dikonfigurasi. Isi token bot terlebih dahulu pada halaman Pengaturan Notifikasi.'
    );
}

$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

if (!$studentroleid) {
    throw new \Exception(
        'Role student tidak ditemukan pada sistem Moodle.'
    );
}

$studentroleid = (int)$studentroleid;
/*
 * Data siswa diambil dari user Moodle yang terindikasi sebagai siswa.
 *
 * Kriteria yang digunakan:
 * - User memiliki role student di Moodle, atau
 * - User memiliki custom profile field NISN, atau
 * - User memiliki idnumber.
 *
 * Export ini bertujuan untuk membagikan link Telegram ke semua akun siswa.
 * Namun, notifikasi tetap hanya akan terkirim jika siswa tersebut terhubung
 * dengan course/kelas yang diproses oleh sistem.
 */
$students = $DB->get_records_sql(
    "SELECT DISTINCT u.*
       FROM {user} u
  LEFT JOIN {role_assignments} ra
         ON ra.userid = u.id
        AND ra.roleid = :studentroleid
  LEFT JOIN {user_info_field} nif
         ON nif.shortname = :nisnfield
  LEFT JOIN {user_info_data} nid
         ON nid.userid = u.id
        AND nid.fieldid = nif.id
      WHERE u.deleted = 0
        AND u.suspended = 0
        AND u.id > 2
        AND (
            ra.id IS NOT NULL
            OR TRIM(COALESCE(nid.data, '')) <> ''
            OR TRIM(COALESCE(u.idnumber, '')) <> ''
        )
   ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC",
    [
        'studentroleid' => $studentroleid,
        'nisnfield' => 'nisn',
    ]
);

$userids = array_map(static function($user) {
    return (int)$user->id;
}, $students);

$profilemap = local_akademikmonitor_export_profile_fields($userids, [
    'nisn',
    'nis',
    'Telepon_Orang_Tua',
    'telepon_orang_tua',
]);

$filename = 'link_telegram_siswa_' . date('Ymd_His') . '.xls';

$workbook = new MoodleExcelWorkbook('-');
$workbook->send($filename);

$worksheet = $workbook->add_worksheet('Link Telegram Siswa');

$row = 0;

$worksheet->write($row, 0, 'DATA LINK TELEGRAM SISWA');
$row += 2;

$worksheet->write($row, 0, 'Bot Telegram');
$worksheet->write($row, 1, '@' . $setting->bot_username);
$row++;

$worksheet->write($row, 0, 'Tanggal Export');
$worksheet->write($row, 1, userdate(time()));
$row += 2;

$worksheet->write($row, 0, 'No');
$worksheet->write($row, 1, 'Nama Siswa');
$worksheet->write($row, 2, 'NISN');
$worksheet->write($row, 3, 'Nomor Telepon');
$worksheet->write($row, 4, 'Link Hubungkan Telegram');
$worksheet->write($row, 5, 'Status Telegram');
$row++;

$no = 1;

foreach ($students as $student) {
    $userid = (int)$student->id;
    $profile = $profilemap[$userid] ?? [];

    $nisn = local_akademikmonitor_export_first_not_empty([
        $profile['nisn'] ?? '',
        $profile['nis'] ?? '',
        $student->idnumber ?? '',
    ]);

    $phone = local_akademikmonitor_export_first_not_empty([
        $student->phone1 ?? '',
        $student->phone2 ?? '',
        $profile['Telepon_Orang_Tua'] ?? '',
        $profile['telepon_orang_tua'] ?? '',
        
    ]);

    $telegramurl = notif_service::build_telegram_connect_url($userid);

    $links = notif_service::get_user_links($userid);
    $status = 'Belum terhubung';

    if ($links) {
        $names = [];

        foreach ($links as $link) {
            $identity = trim((string)($link->telegram_username ?? ''));

            if ($identity !== '') {
                if (strpos($identity, ' ') === false && strpos($identity, '@') !== 0) {
                    $names[] = '@' . $identity;
                } else {
                    $names[] = $identity;
                }
            } elseif (!empty($link->telegram_chat_id)) {
                $names[] = 'Chat ID ' . $link->telegram_chat_id;
            }
        }

        $status = 'Sudah terhubung (' . count($links) . ' Telegram)';

        if ($names) {
            $status .= ': ' . implode(', ', $names);
        }
    }

    $worksheet->write($row, 0, $no);
    $worksheet->write($row, 1, fullname($student));
    if (method_exists($worksheet, 'write_string')) {
        $worksheet->write_string($row, 2, $nisn);
    } else {
        $worksheet->write($row, 2, $nisn);
    }
    $worksheet->write($row, 3, $phone);
    $worksheet->write($row, 4, $telegramurl);
    $worksheet->write($row, 5, $status);

    $row++;
    $no++;
}

if (empty($students)) {
    $worksheet->write($row, 0, '-');
    $worksheet->write($row, 1, 'Belum ada data siswa pada peserta kelas.');
}

$workbook->close();
exit;