<?php
require_once(__DIR__ . '/../../../../../config.php');

global $CFG;

require_once($CFG->dirroot . '/local/akademikmonitor/lib/dompdf/autoload.inc.php');

use Dompdf\Dompdf;
use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\rapor_service;
use local_akademikmonitor\service\walikelas\access_service;

require_login();
require_sesskey();

$userid = required_param('userid', PARAM_INT);
$kelasid = required_param('kelasid', PARAM_INT);

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();
access_service::require_rapor_access(
    $userid,
    $kelasid,
    $tahunajaranid,
    false
);

$data = rapor_service::get_export_data($userid, $kelasid, $semester, $tahunajaranid);
$schoolconfig = rapor_service::get_school_profile_config();

/*
 * Kirim juga $kelasid ke detail page data.
 * Kalau tidak dikirim, service bisa mengambil group pertama siswa,
 * dan itu sering salah ketika siswa masuk banyak course hasil generate.
 */
$detailrapor = rapor_service::get_detail_page_data($userid, $semester, $tahunajaranid, $kelasid);
$datadiri = $detailrapor['template'] ?? [];

$user = $data['user'];
$profile = $data['profile'];
$course = $data['course'];

$kelasrapor = $data['kelas_rapor'] ?? [];

$jurusan = $kelasrapor['program_keahlian'] ?? ($data['jurusan'] ?? '-');
$namakelas = $kelasrapor['kelas_label'] ?? '-';
$fase = $kelasrapor['fase'] ?? '-';

$nilaiakademik = $data['nilai_akademik'] ?? [];
$ringkasanranking = $data['ringkasan_ranking'] ?? [
    'jumlah' => 0,
    'ranking' => 0,
    'total_siswa' => 0,
];

$showpkl = !empty($data['show_pkl']);
$pkl = $showpkl ? ($data['pkl'] ?? []) : [];
$ekskul = $data['ekskul'] ?? [];
$absen = $data['absen'];
$catatan = $data['catatan'];
$kokurikuler = trim((string)($catatan->kokurikuler ?? ''));
if ($kokurikuler === '') {
    $kokurikuler = '-';
}
$keputusan = $data['keputusan'];
$walikelas = $data['walikelas'] ?? [
    'nama' => '-',
    'npa' => '-',
];

$namasekolah = $schoolconfig['namasekolah'] ?? 'SMKS PGRI 2 Giri Banyuwangi';
$alamatsekolah = $schoolconfig['alamatsekolah'] ?? '-';
$kotattd = $schoolconfig['kotattd'] ?? 'Banyuwangi';
$namakepalasekolah = $schoolconfig['namakepalasekolah'] ?? 'Wahyudi, ST';
$npakepalasekolah = $schoolconfig['npakepalasekolah'] ?? '1333.1.800.166';
$semesterdefault = $data['semester_label'] ?? period_filter_service::get_semester_label($semester);
$tahunpelajarandefault = $data['tahunajaran_label'] ?? period_filter_service::get_tahunajaran_label($tahunajaranid);

/*
 * Tahun pada sampul rapor mengikuti tahun masuk / tahun diterima siswa,
 * bukan tahun ajaran yang sedang dicetak.
 *
 * Contoh:
 * - Siswa diterima tanggal 15 Juli 2024 -> sampul: TAHUN 2024
 * - Rapor dicetak tahun ajaran 2025/2026 atau 2026/2027 tetap: TAHUN 2024
 */
$tahuncoverdefault = '';

$tanggaldterima = trim((string)($datadiri['tanggal_diterima'] ?? ''));

if ($tanggaldterima !== '' && $tanggaldterima !== '-') {
    /*
     * Ambil angka tahun 4 digit dari berbagai format tanggal:
     * - 2024-07-15
     * - 15-07-2024
     * - 15 Juli 2024
     * - Banyuwangi, 15 Juli 2024
     */
    if (preg_match('/\b(19|20)\d{2}\b/', $tanggaldterima, $matches)) {
        $tahuncoverdefault = $matches[0];
    }
}

/*
 * Fallback kalau tanggal diterima belum diisi.
 * Ini hanya cadangan supaya sampul tidak kosong.
 * Idealnya data tanggal diterima siswa tetap dilengkapi.
 */
if ($tahuncoverdefault === '') {
    $tahunajaranlabel = trim((string)$tahunpelajarandefault);

    if (preg_match('/\b(19|20)\d{2}\b/', $tahunajaranlabel, $matches)) {
        $tahuncoverdefault = $matches[0];
    }
}

if ($tahuncoverdefault === '') {
    $tahuncoverdefault = date('Y');
}

// $namakelas = $course->fullname ?? '-';
$tanggalunduh = rapor_service::format_tanggal_indo(date('Y-m-d'));

$coverlogosrc = $schoolconfig['coverlogosrc'] ?? '';
$watermarksrc = $schoolconfig['watermarksrc'] ?? '';

/*
 * Fallback terakhir.
 *
 * Ini menjaga agar export PDF lama tetap aman walaupun admin belum upload
 * logo sampul atau watermark dari halaman pengaturan plugin.
 */
if ($coverlogosrc === '' || $watermarksrc === '') {
    $fallbacklogo = '';

    foreach (['logo.jpg', 'logo.jpeg', 'logo.png'] as $filename) {
        $path = $CFG->dirroot . '/local/akademikmonitor/pix/' . $filename;

        if (!file_exists($path)) {
            continue;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimetype = ($extension === 'png') ? 'image/png' : 'image/jpeg';

        $fallbacklogo = 'data:' . $mimetype . ';base64,' . base64_encode(file_get_contents($path));
        break;
    }

    if ($coverlogosrc === '') {
        $coverlogosrc = $fallbacklogo;
    }

    if ($watermarksrc === '') {
        $watermarksrc = $coverlogosrc;
    }
}

$jumlahnilai = number_format((float)($ringkasanranking['jumlah'] ?? 0), 0, ',', '.');
$peringkat = (int)($ringkasanranking['ranking'] ?? 0);
$totalsiswa = (int)($ringkasanranking['total_siswa'] ?? 0);

$html = '
<style>
    body {
        font-family: "Times New Roman", serif;
        font-size: 12px;
        color: #000;
    }

    .watermark {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.08;
        z-index: -1;
    }

    .page-break {
        page-break-before: always;
    }

    /*
     * KHUSUS COVER:
     * Jangan pakai height:100% dan jangan pakai min-height:950px.
     * Di Dompdf, itu membuat border cover dianggap lebih tinggi dari 1 halaman,
     * sehingga sisa garis border turun ke halaman berikutnya.
     */
    .cover-page {
        width: 100%;
        position: relative;
        overflow: hidden;
    }

    .cover-box {
        border: 3px solid #000;
        border-radius: 40px;
        padding: 40px;
        height: 850px;
        position: relative;
        text-align: center;
        box-sizing: border-box;
        overflow: hidden;
        page-break-inside: avoid;
    }

    .cover-logo img {
        width: 100px;
        margin-bottom: 20px;
    }

    .cover-title p {
        margin: 5px 0;
        font-weight: bold;
    }

    .cover-title p:nth-child(1) {
        font-size: 16px;
    }

    .cover-title p:nth-child(2) {
        font-size: 15px;
    }

    .cover-title p:nth-child(3),
    .cover-title p:nth-child(4) {
        font-size: 14px;
    }

    .cover-nama-label {
        margin-top: 200px;
        margin-bottom: 10px;
        font-weight: bold;
    }

    .cover-box-input {
        border: 2px solid #000;
        padding: 8px;
        width: 60%;
        margin: 10px auto;
        font-weight: bold;
    }

    .cover-footer {
        position: absolute;
        bottom: 35px;
        left: 0;
        right: 0;
        text-align: center;
        font-weight: bold;
        line-height: 1.5;
    }

    .title {
        text-align: center;
        margin-bottom: 12px;
        font-size: 16px;
        font-weight: bold;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }

    td,
    th {
        border: 1px solid #000;
        padding: 4px 6px;
        vertical-align: top;
    }

    .no-border,
    .no-border td,
    .no-border th {
        border: none !important;
    }

    .label {
        width: 140px;
    }

    h3 {
        margin-top: 15px;
        margin-bottom: 5px;
    }

    .text-center {
        text-align: center;
    }

    .signature-wrap {
        width: 100%;
        margin-top: 8px;
    }

    .signature-right {
        width: 45%;
        margin-left: auto;
        text-align: center;
    }

    .signature-meta {
        width: auto;
        margin: 0 auto 4px auto;
    }

    .signature-meta td {
        border: none !important;
        padding: 0 8px 4px 0;
        text-align: left;
    }

    .signature-space {
        height: 72px;
    }

    .signature-name {
        font-weight: bold;
        text-decoration: underline;
    }

    .datadiri-title {
        text-align: center;
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 14px;
    }

    .datadiri-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
    }

    .datadiri-table td {
        border: none !important;
        padding: 2px 4px;
        vertical-align: top;
        line-height: 1.45;
    }

    .datadiri-no {
        width: 28px;
    }

    .datadiri-label {
        width: 230px;
    }

    .datadiri-sep {
        width: 10px;
        text-align: center;
    }

    .datadiri-sub {
        padding-left: 22px;
    }

    .bottom-identitas {
        width: 70%;
        margin: 22px auto 0 auto;
        border-collapse: collapse;
    }

    .bottom-identitas td {
        border: none !important;
        vertical-align: top;
    }

    .photo-cell {
        width: 42%;
        text-align: center;
    }

    .ttd-cell {
        width: 58%;
        text-align: center;
    }

    .photo-box {
        width: 120px;
        height: 150px;
        border: 2px solid #000;
        text-align: center;
        margin: 0 auto;
        font-size: 13px;
        line-height: 1.6;
        box-sizing: border-box;
    }

    .photo-box-text {
        margin-top: 38px;
    }

    .kepsek-wrap {
        width: 100%;
        text-align: center;
    }

    .kepsek-space {
        height: 72px;
    }

    .kepsek-name {
        font-weight: bold;
        text-decoration: underline;
    }

    .kelompok-mapel-row td {
    font-weight: bold;
    background: #f2f2f2;
    }
</style>

<div class="watermark">
    ' . ($watermarksrc !== '' ? '<img src="' . $watermarksrc . '" width="300">' : '') . '
</div>

<div class="cover-page">
    <div class="cover-box">
        <div class="cover-logo">
            ' . ($coverlogosrc !== '' ? '<img src="' . $coverlogosrc . '">' : '') . '
        </div>

        <div class="cover-title">
            <p>LAPORAN</p>
            <p>PENCAPAIAN KOMPETENSI PESERTA DIDIK</p>
            <p>SEKOLAH MENENGAH KEJURUAN</p>
            <p>' . s($namasekolah) . '</p>
        </div>

        <div class="cover-nama-label">Nama Peserta Didik</div>
        <div class="cover-box-input">' . s(fullname($user)) . '</div>
        <div class="cover-box-input">' . s($profile->nisn ?? '-') . '</div>

        <div class="cover-footer">
            KEMENTERIAN PENDIDIKAN DAN KEBUDAYAAN<br>
            REPUBLIK INDONESIA<br>
            TAHUN ' . s((string)$tahuncoverdefault) . '
        </div>
    </div>
</div>';

/*
 * HALAMAN 2 - KETERANGAN TENTANG DIRI PESERTA DIDIK
 */
$html .= '
<div class="page-break">
    <div class="watermark">
        ' . ($watermarksrc !== '' ? '<img src="' . $watermarksrc . '" width="300">' : '') . '
    </div>

    <div class="datadiri-title">KETERANGAN TENTANG DIRI PESERTA DIDIK</div>

    <table class="datadiri-table">
        <tr>
            <td class="datadiri-no">1.</td>
            <td class="datadiri-label">Nama Peserta Didik</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['nama'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">2.</td>
            <td class="datadiri-label">Nomor Induk Siswa Nasional</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['nisn'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">3.</td>
            <td class="datadiri-label">Nomor Induk Sekolah</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['nis'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">4.</td>
            <td class="datadiri-label">Tempat Tanggal Lahir</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['tempatlahir'] ?? '-') . ', ' . s($datadiri['tanggal_lahir'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">5.</td>
            <td class="datadiri-label">Jenis Kelamin</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['jk'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">6.</td>
            <td class="datadiri-label">Agama</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['agama'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">7.</td>
            <td class="datadiri-label">Status Dalam Keluarga</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['status'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">8.</td>
            <td class="datadiri-label">Anak ke</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['anak_ke'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">9.</td>
            <td class="datadiri-label">Alamat Peserta Didik</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['alamat_peserta_didik'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">10.</td>
            <td class="datadiri-label">Nomor Telepon Rumah</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['telepon_rumah'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">11.</td>
            <td class="datadiri-label">Sekolah Asal</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['sekolah_asal'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">12.</td>
            <td class="datadiri-label">Diterima Di Sekolah Ini</td>
            <td class="datadiri-sep">:</td>
            <td></td>
        </tr>
        <tr>
            <td></td>
            <td class="datadiri-label datadiri-sub">a. Di Kelas</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['diterima_di_kelas'] ?? '-') . '</td>
        </tr>
        <tr>
            <td></td>
            <td class="datadiri-label datadiri-sub">b. Pada Tanggal</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['tanggal_diterima'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">13.</td>
            <td class="datadiri-label">Nama Orang Tua</td>
            <td class="datadiri-sep">:</td>
            <td></td>
        </tr>
        <tr>
            <td></td>
            <td class="datadiri-label datadiri-sub">a. Ayah</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['nama_ayah'] ?? '-') . '</td>
        </tr>
        <tr>
            <td></td>
            <td class="datadiri-label datadiri-sub">b. Ibu</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['nama_ibu'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">14.</td>
            <td class="datadiri-label">Alamat Orang Tua</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['alamat_orang_tua'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">15.</td>
            <td class="datadiri-label">Nomor Telepon Rumah</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['telepon_orang_tua'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">16.</td>
            <td class="datadiri-label">Pekerjaan Orang Tua</td>
            <td class="datadiri-sep">:</td>
            <td></td>
        </tr>
        <tr>
            <td></td>
            <td class="datadiri-label datadiri-sub">a. Ayah</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['pekerjaan_ayah'] ?? '-') . '</td>
        </tr>
        <tr>
            <td></td>
            <td class="datadiri-label datadiri-sub">b. Ibu</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['pekerjaan_ibu'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">17.</td>
            <td class="datadiri-label">Nama Wali Peserta Didik</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['nama_wali'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">18.</td>
            <td class="datadiri-label">Alamat Wali Peserta Didik</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['alamat_wali'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">19.</td>
            <td class="datadiri-label">Nomor Telepon Rumah</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['telepon_wali'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="datadiri-no">20.</td>
            <td class="datadiri-label">Pekerjaan Wali Peserta Didik</td>
            <td class="datadiri-sep">:</td>
            <td>' . s($datadiri['pekerjaan_wali'] ?? '-') . '</td>
        </tr>
    </table>

    <table class="bottom-identitas">
        <tr>
            <td class="photo-cell">
                <div class="photo-box">
                    <div class="photo-box-text">Pas Photo<br>3 x 4</div>
                </div>
            </td>
            <td class="ttd-cell">
                <div class="kepsek-wrap">
                    <div>' . s($kotattd) . ', ' . s($tanggalunduh) . '</div>
                    <div>Kepala Sekolah,</div>
                    <div class="kepsek-space"></div>
                    <div class="kepsek-name">' . s($namakepalasekolah) . '</div>
                    <div>NPA. ' . s($npakepalasekolah) . '</div>
                </div>
            </td>
        </tr>
    </table>
</div>';

/*
 * HALAMAN 3 - NILAI AKADEMIK
 */
$html .= '
<div class="page-break">
    <div class="title">LAPORAN HASIL BELAJAR PESERTA DIDIK</div>
    <div class="title">' . s($namasekolah) . '</div>

    <table class="no-border" style="margin-bottom:8px;">
        <tr>
            <td width="50%" style="vertical-align:top; padding:0; border:none;">
                <table>
                    <tr><td class="label">Nama</td><td>: ' . s(fullname($user)) . '</td></tr>
                    <tr><td>NISN</td><td>: ' . s($profile->nisn ?? '-') . '</td></tr>
                    <tr><td>NIS</td><td>: ' . s($profile->nis ?? '-') . '</td></tr>
                    <tr><td>Nama Sekolah</td><td>: ' . s($namasekolah) . '</td></tr>
                    <tr><td>Alamat</td><td>: ' . s($profile->alamat_peserta_didik ?? '-') . '</td></tr>
                </table>
            </td>
            <td width="50%" style="vertical-align:top; padding:0; border:none;">
                <table>
                    <tr><td class="label">Program Keahlian</td><td>: ' . s($jurusan) . '</td></tr>
                    <tr><td>Kelas</td><td>: ' . s($namakelas) . '</td></tr>
                    <tr><td>Fase</td><td>: ' . s($fase) . '</td></tr>
                    <tr><td>Semester</td><td>: ' . s($semesterdefault) . '</td></tr>
                    <tr><td>Tahun Pelajaran</td><td>: ' . s($tahunpelajarandefault) . '</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <h3>Nilai Akademik</h3>
    <table>
        <tr>
            <th style="width:38px; text-align:center;">No</th>
            <th style="width:230px; text-align:center;">Mata Pelajaran</th>
            <th style="width:70px; text-align:center;">Nilai</th>
            <th style="text-align:center;">Capaian Kompetensi</th>
        </tr>';

if (!empty($nilaiakademik)) {
    $kelompoknilai = [];

    foreach ($nilaiakademik as $item) {
        $key = (string)($item['kelompok_key'] ?? 'lainnya');
        $label = (string)($item['kelompok_label'] ?? 'Mata Pelajaran Lainnya');

        if (!isset($kelompoknilai[$key])) {
            $kelompoknilai[$key] = [
                'label' => $label,
                'items' => [],
            ];
        }

        $kelompoknilai[$key]['items'][] = $item;
    }

    $urutanKelompok = [
        'umum',
        'muatan_lokal',
        'kejuruan',
        'lainnya',
    ];

    foreach ($urutanKelompok as $kelompokkey) {
        if (empty($kelompoknilai[$kelompokkey]['items'])) {
            continue;
        }

        $html .= '
        <tr>
            <td colspan="4" style="font-weight:bold; background:#f2f2f2;">
                ' . s($kelompoknilai[$kelompokkey]['label']) . '
            </td>
        </tr>';

        foreach ($kelompoknilai[$kelompokkey]['items'] as $item) {
            $html .= '
            <tr>
                <td class="text-center">' . s($item['no'] ?? '-') . '</td>
                <td>' . s($item['mata_pelajaran'] ?? '-') . '</td>
                <td class="text-center">' . s($item['nilai_akhir'] ?? '-') . '</td>
                <td>
                    <div style="padding-bottom:8px; margin-bottom:8px; border-bottom:1px solid #000;">
                        ' . s($item['capaian_atas'] ?? '-') . '
                    </div>
                    <div>
                        ' . s($item['capaian_bawah'] ?? '-') . '
                    </div>
                </td>
            </tr>';
        }
    }
} else {
    $html .= '
        <tr>
            <td colspan="4" class="text-center">Belum ada data nilai akademik.</td>
        </tr>';
}

$html .= '
        <tr>
            <td colspan="2" style="text-align:center; font-weight:bold;">Jumlah</td>
            <td style="text-align:center;">' . s($jumlahnilai) . '</td>
            <td></td>
        </tr>
        <tr>
            <td colspan="2" style="text-align:center; font-weight:bold;">Peringkat</td>
            <td style="text-align:center;">' . s($peringkat > 0 ? (string)$peringkat : '-') . '</td>
            <td style="text-align:center;">dari ' . s($totalsiswa > 0 ? (string)$totalsiswa : '-') . ' Siswa</td>
        </tr>
    </table>

    <div class="signature-wrap">
        <div class="signature-right">
            <table class="signature-meta no-border">
                <tr><td>Diberikan di</td><td>: ' . s($kotattd) . '</td></tr>
                <tr><td>Tanggal</td><td>: ' . s($tanggalunduh) . '</td></tr>
            </table>
            <div>Wali Kelas</div>
            <div class="signature-space"></div>
            <div class="signature-name">' . s($walikelas['nama'] ?? '-') . '</div>
            <div>NPA ' . s($walikelas['npa'] ?? '-') . '</div>
        </div>
    </div>
</div>';

/*
 * HALAMAN 4 - CATATAN, PKL, EKSKUL, ABSEN, KENAIKAN KELAS
 */
$html .= '
<div class="page-break">
    <div class="title">LAPORAN HASIL BELAJAR PESERTA DIDIK</div>
    <div class="title">' . s($namasekolah) . '</div>

    <table class="no-border">
        <tr>
            <td width="50%">
                <table>
                    <tr><td class="label">Nama</td><td>: ' . s(fullname($user)) . '</td></tr>
                    <tr><td>NISN</td><td>: ' . s($profile->nisn ?? '-') . '</td></tr>
                    <tr><td>NIS</td><td>: ' . s($profile->nis ?? '-') . '</td></tr>
                    <tr><td>Nama Sekolah</td><td>: ' . s($namasekolah) . '</td></tr>
                    <tr><td>Alamat</td><td>: ' . s($profile->alamat_peserta_didik ?? '-') . '</td></tr>
                </table>
            </td>
            <td width="50%">
                <table>
                    <tr><td class="label">Program Keahlian</td><td>: ' . s($jurusan) . '</td></tr>
                    <tr><td>Kelas</td><td>: ' . s($namakelas) . '</td></tr>
                    <tr><td>Fase</td><td>: ' . s($fase) . '</td></tr>
                    <tr><td>Semester</td><td>: ' . s($semesterdefault) . '</td></tr>
                    <tr><td>Tahun Pelajaran</td><td>: ' . s($tahunpelajarandefault) . '</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <h3>Catatan Akademik</h3>
    <table>
        <tr>
            <td style="height:100px;">' . nl2br(s($catatan->catatan ?? '-')) . '</td>
        </tr>
    </table>

    <h3>Kokurikuler</h3>
    <table>
        <tr>
            <td style="height:100px;">' . nl2br(s($kokurikuler)) . '</td>
        </tr>
    </table>

';

if ($showpkl) {
    $html .= '
    <h3>Praktik Kerja Lapangan</h3>
    <table>
        <tr><th>No</th><th>Mitra</th><th>Nilai</th></tr>';

    if (!empty($pkl)) {
        $no = 1;
        foreach ($pkl as $item) {
            $html .= '
        <tr>
            <td class="text-center">' . s((string)$no++) . '</td>
            <td>' . s($item->nama ?? '-') . '</td>
            <td class="text-center">' . s($item->nilai ?? '-') . '</td>
        </tr>';
        }
    } else {
        $html .= '
        <tr>
            <td colspan="3" class="text-center">Belum ada data PKL.</td>
        </tr>';
    }

    $html .= '
    </table>';
}

$html .= '
    <h3>Ekstrakurikuler</h3>
    <table>
        <tr><th>No</th><th>Kegiatan</th><th>Predikat</th></tr>';

if (!empty($ekskul)) {
    $no = 1;
    foreach ($ekskul as $item) {
        $html .= '
        <tr>
            <td class="text-center">' . s((string)$no++) . '</td>
            <td>' . s($item->nama ?? '-') . '</td>
            <td class="text-center">' . s($item->predikat ?? '-') . '</td>
        </tr>';
    }
} else {
    $html .= '
        <tr>
            <td colspan="3" class="text-center">Belum ada data ekstrakurikuler.</td>
        </tr>';
}

$html .= '
    </table>

    <h3>Ketidakhadiran</h3>
    <table>
        <tr><td>Sakit</td><td>' . s((string)($absen->sakit ?? 0)) . '</td></tr>
        <tr><td>Izin</td><td>' . s((string)($absen->izin ?? 0)) . '</td></tr>
        <tr><td>Alfa</td><td>' . s((string)($absen->alfa ?? 0)) . '</td></tr>
    </table>

    <h3>Kenaikan Kelas</h3>
    <table>
        <tr><td style="height:60px;">' . s($keputusan->keputusan ?? '-') . '</td></tr>
    </table>
</div>';

$dompdf = new Dompdf();
$dompdf->set_option('isHtml5ParserEnabled', true);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

/*
 * Footer kecil di pojok kiri bawah setiap halaman.
 *
 * Format:
 * TIM IT SMKSI PGRI 2 GIRI | NISN SISWA | HALAMAN 1
 *
 * Footer dibuat memakai canvas Dompdf supaya muncul otomatis
 * di setiap halaman tanpa mengganggu layout rapor.
 */
$nisnfooter = trim((string)($profile->nisn ?? ''));

if ($nisnfooter === '') {
    $nisnfooter = trim((string)($datadiri['nisn'] ?? ''));
}

if ($nisnfooter === '') {
    $nisnfooter = '-';
}

$footercover = 'TIM IT SMKS PGRI 2 GIRI';
$footertextprefix = 'TIM IT SMKS PGRI 2 GIRI | ' . $nisnfooter . ' | HALAMAN ';

$canvas = $dompdf->getCanvas();

$canvas->page_script('
    /*
     * PAGE_NUM adalah nomor halaman asli PDF dari Dompdf.
     *
     * Halaman 1 = sampul.
     * Sampul hanya diberi tulisan TIM IT, tanpa NISN dan tanpa nomor halaman.
     *
     * Halaman 2 dan seterusnya:
     * Nomor halaman rapor dimulai dari 1.
     * Jadi halaman rapor = PAGE_NUM - 1.
     */
    if ($PAGE_NUM == 1) {
        $footertext = ' . var_export($footercover, true) . ';
    } else {
        $nomorhalamanrapor = $PAGE_NUM - 1;
        $footertext = ' . var_export($footertextprefix, true) . ' . $nomorhalamanrapor;
    }

    $pdf->text(
        28,
        810,
        $footertext,
        $fontMetrics->getFont("Times-Roman", "normal"),
        7,
        [0, 0, 0]
    );
');

while (ob_get_level()) {
    ob_end_clean();
}

$dompdf->stream('rapor_' . $userid . '.pdf', ['Attachment' => true]);
exit;