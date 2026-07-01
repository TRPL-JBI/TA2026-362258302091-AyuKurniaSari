<?php
require_once(__DIR__ . '/../../../config.php');
global $CFG, $DB, $PAGE, $OUTPUT;
require_once($CFG->dirroot . '/user/lib.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB, $CFG, $PAGE, $OUTPUT;

$type = optional_param('type', 'siswa', PARAM_ALPHA);
if (!in_array($type, ['siswa', 'guru'], true)) {
    $type = 'siswa';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/import_users.php', ['type' => $type]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Import Data ' . ucfirst($type));
$PAGE->set_heading('Import Data ' . ucfirst($type));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_userimport_sidebar_urls(): array {
    return [
        'is_dashboard' => true,
        'is_tahun_ajaran' => false,
        'is_kurikulum' => false,
        'is_manajemen_jurusan' => false,
        'is_manajemen_kelas' => false,
        'is_mata_pelajaran' => false,
        'is_matpel' => false,
        'is_kktp' => false,
        'is_notif' => false,
        'is_ekskul' => false,
        'is_mitra' => false,
        'dashboard_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
    ];
}

function local_akademikmonitor_userimport_read_csv(string $filepath): array {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return [[], ['Tidak dapat membaca file CSV.']];
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return [[], ['CSV kosong atau formatnya tidak valid.']];
    }

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $line++;
        if (count(array_filter($data, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($header as $idx => $name) {
            if ($name !== '') {
                $row[$name] = trim((string)($data[$idx] ?? ''));
            }
        }
        $row['_line'] = $line;
        $rows[] = $row;
    }
    fclose($handle);

    return [$rows, []];
}

function local_akademikmonitor_userimport_clean_username(string $username): string {
    $username = core_text::strtolower(trim($username));
    $username = preg_replace('/\s+/', '', $username);
    return clean_param($username, PARAM_USERNAME);
}

$hasresult = false;
$issuccess = false;
$iserror = false;
$resultmsg = '';
$rowerrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $hasresult = true;
    $dupmode = optional_param('dupmode', 'skip', PARAM_ALPHA);
    $dupmode = in_array($dupmode, ['skip', 'update'], true) ? $dupmode : 'skip';

    if (empty($_FILES['csvfile']) || !empty($_FILES['csvfile']['error'])) {
        $iserror = true;
        $resultmsg = 'Upload gagal. Pastikan file CSV sudah dipilih.';
    } else {
        [$rows, $readerrors] = local_akademikmonitor_userimport_read_csv($_FILES['csvfile']['tmp_name']);
        foreach ($readerrors as $err) {
            $rowerrors[] = ['msg' => $err];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $line = (int)($row['_line'] ?? 0);
            $username = local_akademikmonitor_userimport_clean_username((string)($row['username'] ?? ''));
            $firstname = trim((string)($row['firstname'] ?? $row['nama_depan'] ?? ''));
            $lastname = trim((string)($row['lastname'] ?? $row['nama_belakang'] ?? ''));
            $fullname = trim((string)($row['nama_lengkap'] ?? $row['nama'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $password = (string)($row['password'] ?? '');

            if ($firstname === '' && $fullname !== '') {
                $parts = preg_split('/\s+/', $fullname, 2);
                $firstname = $parts[0] ?? $fullname;
                $lastname = $parts[1] ?? '-';
            }
            if ($lastname === '') {
                $lastname = '-';
            }
            if ($password === '') {
                $password = 'Changeme@1';
            }

            if ($username === '' || $firstname === '' || $email === '') {
                $skipped++;
                $rowerrors[] = ['msg' => "Baris {$line}: username, firstname/nama, dan email wajib diisi."];
                continue;
            }
            if (!validate_email($email)) {
                $skipped++;
                $rowerrors[] = ['msg' => "Baris {$line}: format email tidak valid ({$email})."];
                continue;
            }

            $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id], '*', IGNORE_MISSING);
            $emailowner = $DB->get_record('user', ['email' => $email, 'deleted' => 0], 'id, username', IGNORE_MULTIPLE);
            if ($emailowner && (!$existing || (int)$emailowner->id !== (int)$existing->id)) {
                $skipped++;
                $rowerrors[] = ['msg' => "Baris {$line}: email {$email} sudah digunakan oleh username {$emailowner->username}."];
                continue;
            }

            if ($existing) {
                if ($dupmode !== 'update') {
                    $skipped++;
                    continue;
                }
                $existing->firstname = core_text::substr($firstname, 0, 100);
                $existing->lastname = core_text::substr($lastname, 0, 100);
                $existing->email = core_text::substr($email, 0, 100);
                user_update_user($existing, false, false);
                $updated++;
                continue;
            }

            $user = new stdClass();
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->username = $username;
            $user->password = $password;
            $user->firstname = core_text::substr($firstname, 0, 100);
            $user->lastname = core_text::substr($lastname, 0, 100);
            $user->email = core_text::substr($email, 0, 100);
            $user->city = trim((string)($row['city'] ?? $row['kota'] ?? '')) ?: 'Banyuwangi';
            $user->country = trim((string)($row['country'] ?? 'ID')) ?: 'ID';
            $user->lang = current_language();
            $user->timecreated = time();
            $user->timemodified = time();

            try {
                user_create_user($user, true, false);
                $created++;
            } catch (Throwable $e) {
                $skipped++;
                $rowerrors[] = ['msg' => "Baris {$line}: gagal membuat user {$username}. " . $e->getMessage()];
            }
        }

        $issuccess = true;
        $resultmsg = "Import selesai. {$created} user dibuat, {$updated} diperbarui, {$skipped} dilewati.";
    }
}

$type_label = $type === 'guru' ? 'Guru' : 'Siswa';
$csvexample = $type === 'guru'
    ? "username,firstname,lastname,email,password\nguru1,Budi,Santoso,budi@example.com,Changeme@1"
    : "username,firstname,lastname,email,password\n1234567890,Ani,Saputri,ani@example.com,Changeme@1";

$templatecontext = array_merge(local_akademikmonitor_userimport_sidebar_urls(), [
    'type' => $type,
    'type_label' => $type_label,
    'is_siswa' => $type === 'siswa',
    'is_guru' => $type === 'guru',
    'is_walikelas' => false,
    'csv_example' => nl2br(s($csvexample)),
    'url_siswa' => (new moodle_url('/local/akademikmonitor/pages/import_users.php', ['type' => 'siswa']))->out(false),
    'url_guru' => (new moodle_url('/local/akademikmonitor/pages/import_users.php', ['type' => 'guru']))->out(false),
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/import_users.php', ['type' => $type]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
    'sesskey' => sesskey(),
    'has_result' => $hasresult,
    'is_success' => $issuccess,
    'is_error' => $iserror,
    'result_msg' => $resultmsg,
    'row_errors' => $rowerrors,
    'has_row_errors' => !empty($rowerrors),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/import_users', $templatecontext);
echo $OUTPUT->footer();
