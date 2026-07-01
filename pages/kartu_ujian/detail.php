<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $DB, $PAGE, $OUTPUT, $CFG;

use local_akademikmonitor\service\kartu_ujian_service;

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title('Detail Kartu Ujian');
$PAGE->set_heading('Detail Kartu Ujian');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_kudetail_admin_urls(): array {
    return [
        'is_dashboard'=>false,'is_tahun_ajaran'=>false,'is_kurikulum'=>false,
        'is_manajemen_jurusan'=>false,'is_manajemen_kelas'=>false,
        'is_mata_pelajaran'=>false,'is_matpel'=>false,'is_kktp'=>false,
        'is_notif'=>false,'is_ekskul'=>false,'is_mitra'=>false,'is_kartu_ujian'=>true,
        'dashboard_url'         =>(new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url'      =>(new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url'         =>(new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' =>(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url'   =>(new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url'    =>(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url'            =>(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url'              =>(new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url'             =>(new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url'            =>(new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url'             =>(new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
        'kartu_ujian_url'       =>(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
    ];
}
/**
 * Mengambil NISN siswa dari custom profile field Moodle.
 *
 * Kenapa tidak cukup pakai user.idnumber?
 * Karena di Moodle, NISN biasanya disimpan sebagai custom profile field,
 * bukan selalu di kolom idnumber bawaan user.
 */
function local_akademikmonitor_kudetail_get_nisn(int $userid, string $fallback = '-'): string {
    global $DB;

    if ($userid <= 0) {
        return $fallback !== '' ? $fallback : '-';
    }

    $shortnames = [
        'nisn',
        'NISN',
        'nomor_induk_siswa_nasional',
        'nomorinduksiswanasional',
    ];

    foreach ($shortnames as $shortname) {
        $value = $DB->get_field_sql("
            SELECT d.data
              FROM {user_info_data} d
              JOIN {user_info_field} f ON f.id = d.fieldid
             WHERE d.userid = :userid
               AND f.shortname = :shortname
        ", [
            'userid' => $userid,
            'shortname' => $shortname,
        ]);

        $value = trim((string)$value);

        if ($value !== '') {
            return $value;
        }
    }

    $fallback = trim($fallback);

    return $fallback !== '' ? $fallback : '-';
}
$action = optional_param('action', '', PARAM_ALPHA);
$allowuserid = optional_param('userid', 0, PARAM_INT);

if ($action === 'publish') {
    require_sesskey();

    $DB->set_field('kartu_ujian', 'status', 'published', ['id' => $id]);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
        'Kartu ujian berhasil dipublikasikan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'unpublish') {
    require_sesskey();

    $DB->set_field('kartu_ujian', 'status', 'draft', ['id' => $id]);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
        'Kartu ujian kembali ke draft.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

/*
 * Loloskan manual siswa oleh admin.
 *
 * Dipakai untuk kasus nilai siswa masih di bawah KKTP,
 * tetapi berdasarkan pertimbangan guru/admin siswa tetap diizinkan mendapat kartu ujian.
 *
 * Teknisnya:
 * - siswa dimasukkan ke tabel {kartu_ujian_siswa}
 * - tabel itu menjadi tanda bahwa kartu siswa boleh dicetak
 */
if ($action === 'allow' && $allowuserid > 0) {
    require_sesskey();

    // Ambil kartu ujian dulu supaya kita tahu kelasnya.
    $kucheck = $DB->get_record('kartu_ujian', ['id' => $id], 'id, id_kelas', MUST_EXIST);

    // Pastikan user ini memang siswa/peserta di kelas tersebut.
    if (!$DB->record_exists('peserta_kelas', [
        'id_kelas' => (int)$kucheck->id_kelas,
        'id_user' => $allowuserid,
    ])) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
            'Siswa tidak terdaftar pada kelas kartu ujian ini.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $exists = $DB->record_exists('kartu_ujian_siswa', [
        'id_kartu_ujian' => $id,
        'id_user' => $allowuserid,
    ]);

    if (!$exists) {
        $rec = new stdClass();
        $rec->id_kartu_ujian = $id;
        $rec->id_user = $allowuserid;
        $rec->timecreated = time();

        $DB->insert_record('kartu_ujian_siswa', $rec);
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
        'Siswa berhasil diloloskan secara manual oleh admin.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/*
 * Batalkan lolos manual.
 *
 * Ini menghapus kartu siswa dari {kartu_ujian_siswa}.
 * Kalau siswa sebenarnya tidak memenuhi KKTP, maka setelah dibatalkan dia kembali tidak bisa download.
 */
if ($action === 'revoke' && $allowuserid > 0) {
    require_sesskey();

    $DB->delete_records('kartu_ujian_siswa', [
        'id_kartu_ujian' => $id,
        'id_user' => $allowuserid,
    ]);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
        'Lolos manual siswa berhasil dibatalkan.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$sql = "
    SELECT
        ku.*,
        k.nama AS nama_kelas,
        k.tingkat,
        k.id_user AS walikelas_id,
        j.nama_jurusan,
        ta.tahun_ajaran AS tahun_label,
        COALESCE(kurku.nama, kurrel.nama) AS nama_kurikulum,
        u.firstname AS wk_first,
        u.lastname AS wk_last
    FROM {kartu_ujian} ku
    JOIN {kelas} k
        ON k.id = ku.id_kelas
    JOIN {jurusan} j
        ON j.id = k.id_jurusan
    LEFT JOIN {tahun_ajaran} ta
        ON ta.id = ku.id_tahun_ajaran
    LEFT JOIN {kurikulum} kurku
        ON kurku.id = ku.id_kurikulum
    LEFT JOIN {kurikulum_jurusan} kj
        ON kj.id_jurusan = k.id_jurusan
       AND kj.id_tahun_ajaran = k.id_tahun_ajaran
    LEFT JOIN {kurikulum} kurrel
        ON kurrel.id = kj.id_kurikulum
    LEFT JOIN {user} u
        ON u.id = k.id_user
    WHERE ku.id = :id
";

$ku = $DB->get_record_sql($sql, ['id' => $id], MUST_EXIST);

$walikelasname = trim(($ku->wk_first ?? '') . ' ' . ($ku->wk_last ?? '')) ?: '-';
$semesterku = kartu_ujian_service::normalize_semester($ku->semester ?? '');
$tahunajaranid = (int)$ku->id_tahun_ajaran;
$kelasid = (int)$ku->id_kelas;
$courseids = kartu_ujian_service::get_courseids($kelasid, $tahunajaranid, $semesterku);

$namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$pesertasql = "SELECT pk.id AS pkid,
                      pk.id_user,
                      u.idnumber,
                      {$namefields},
                      pk.id_role
                 FROM {peserta_kelas} pk
                 JOIN {user} u ON u.id = pk.id_user
                WHERE pk.id_kelas = :kelasid
                  AND u.deleted = 0
                  AND (pk.id_role IS NULL OR pk.id_role NOT IN (
                       SELECT r.id
                         FROM {role} r
                        WHERE r.shortname = 'editingteacher'
                           OR r.shortname = 'teacher'
                  ))
             ORDER BY u.lastname ASC, u.firstname ASC";
$peserta = $DB->get_records_sql($pesertasql, ['kelasid' => $kelasid]);

$siswarows = [];
$no = 1;
$generatedcount = 0;
$eligiblecount = 0;
$noteligiblecount = 0;

foreach ($peserta as $p) {
    $userid = (int)$p->id_user;
    $fullname = fullname($p);

    $eligibility = kartu_ujian_service::get_eligibility($userid, $kelasid, $tahunajaranid, $semesterku);
    $layak = !empty($eligibility['eligible']);

    if ($layak) {
        $eligiblecount++;
    } else {
        $noteligiblecount++;
    }

    $has_kartu = $DB->record_exists('kartu_ujian_siswa', [
        'id_kartu_ujian' => $id,
        'id_user' => $userid,
    ]);

    if ($has_kartu) {
        $generatedcount++;
    }

    $ismanualallowed = (!$layak && $has_kartu);

    /*
     * Ambil NISN dari custom profile field.
     * Kalau belum ada, fallback ke idnumber user.
     */
    $nisn = local_akademikmonitor_kudetail_get_nisn($userid, (string)($p->idnumber ?? '-'));

    /*
     * Siapkan detail mapel yang nilainya di bawah KKTP.
     * Ini supaya halaman detail tidak cuma menampilkan kalimat panjang,
     * tapi menampilkan mapel, nilai, dan KKTP secara jelas.
     */
    $belowkktprows = [];

    foreach (($eligibility['mapel_di_bawah_kktp'] ?? []) as $item) {
        $nilai = $item['nilai'] ?? null;

        $belowkktprows[] = [
            'mapel' => format_string((string)($item['mapel'] ?? '-')),
            'nilai' => $nilai === null ? 'Belum ada nilai' : $nilai,
            'kktp' => $item['kktp'] ?? '-',
        ];
    }

    /*
     * Siapkan mapel yang nilainya belum lengkap.
     * Nilai kosong juga tetap dianggap belum memenuhi syarat.
     */
    $nilaiBelumLengkapRows = [];

    foreach (($eligibility['nilai_belum_lengkap'] ?? []) as $mapel) {
        $nilaiBelumLengkapRows[] = [
            'mapel' => format_string((string)$mapel),
        ];
    }

    $siswarows[] = [
        'no' => $no++,
        'userid' => $userid,
        'fullname' => format_string($fullname),

        /*
         * Kolom ini sekarang khusus NISN.
         */
        'nisn' => s($nisn),

        /*
         * Layak otomatis karena semua nilai memenuhi KKTP.
         */
        'layak' => $layak,

        /*
         * Tidak layak dan belum diloloskan admin.
         */
        'tidak_layak' => (!$layak && !$has_kartu),

        /*
         * Nilai belum memenuhi KKTP, tapi admin sudah memberi izin.
         */
        'manual_allowed' => $ismanualallowed,

        'has_kartu' => $has_kartu,
        'status_label' => $layak ? 'Layak Ujian' : ($ismanualallowed ? 'Diloloskan Admin' : 'Tidak Layak'),

        /*
         * Alasan ringkas.
         */
        'alasan' => s($eligibility['reason_text']),

        /*
         * Detail alasan.
         */
        'below_kktp' => $belowkktprows,
        'has_below_kktp' => !empty($belowkktprows),
        'nilai_belum_lengkap_rows' => $nilaiBelumLengkapRows,
        'has_nilai_belum_lengkap' => !empty($nilaiBelumLengkapRows),

        'download_url' => $has_kartu
            ? (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/download.php', [
                'kid' => $id,
                'uid' => $userid,
                'sesskey' => sesskey(),
            ]))->out(false)
            : '',

        'allow_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', [
            'id' => $id,
            'action' => 'allow',
            'userid' => $userid,
            'sesskey' => sesskey(),
        ]))->out(false),

        'revoke_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', [
            'id' => $id,
            'action' => 'revoke',
            'userid' => $userid,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

$ispublished = $ku->status === 'published';

$templatecontext = array_merge(local_akademikmonitor_kudetail_admin_urls(), [
    'ku_id' => (int)$ku->id,
    'nama_ujian' => format_string($ku->nama_ujian),
    'nama_kelas' => format_string($ku->nama_kelas),
    'nama_jurusan' => format_string($ku->nama_jurusan),
    'tahun_ajaran' => format_string($ku->tahun_label ?? '-'),
    'semester' => format_string($ku->semester),
    'nama_kurikulum'=> format_string($ku->nama_kurikulum ?? '-'),
    'penandatangan' => format_string($ku->penandatangan ?? '-'),
    'wali_kelas' => $walikelasname,
    'status' => $ku->status,
    'is_published' => $ispublished,
    'is_draft' => !$ispublished,
    'siswa' => $siswarows,
    'has_siswa' => !empty($siswarows),
    'course_count' => count($courseids),
    'generated_count' => $generatedcount,
    'eligible_count' => $eligiblecount,
    'not_eligible_count' => $noteligiblecount,
    'publish_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id, 'action' => 'publish', 'sesskey' => sesskey()]))->out(false),
    'unpublish_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id, 'action' => 'unpublish', 'sesskey' => sesskey()]))->out(false),
    'generate_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/generate.php', ['id' => $id, 'sesskey' => sesskey()]))->out(false),
    'edit_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/form.php', ['id' => $id]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
    'sesskey' => sesskey(),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kartu_ujian_detail', $templatecontext);
echo $OUTPUT->footer();
