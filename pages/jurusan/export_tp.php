<?php

require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $CFG;

$kmid = required_param('kmid', PARAM_INT);
$cpid = optional_param('cpid', 0, PARAM_INT);
$filtercourseid = optional_param('filtercourseid', 0, PARAM_INT);

/**
 * Membersihkan nama file agar aman untuk header download.
 */
function local_akademikmonitor_export_tp_safe_filename(string $name): string {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
    $safe = trim((string)$safe, '_');
    return $safe !== '' ? $safe : 'export_tp';
}

/**
 * Mengubah indeks kolom angka menjadi alamat cell Excel.
 */
function local_akademikmonitor_export_tp_cell(int $col, int $row): string {
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
}
/**
 * Mencegah Excel Formula Injection.
 */
function local_akademikmonitor_safe_excel_text($value) {
    if (!is_string($value)) {
        return $value;
    }

    $value = trim($value);

    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}
/**
 * Mengambil nama kolom pertama yang tersedia dari object record.
 */
function local_akademikmonitor_export_tp_first_available_field(stdClass $record, array $fields, string $fallback = '-'): string {
    foreach ($fields as $field) {
        if (property_exists($record, $field) && trim((string)$record->{$field}) !== '') {
            return trim((string)$record->{$field});
        }
    }
    return $fallback;
}

function local_akademikmonitor_export_tp_teacher_name(int $courseid): string {
    global $DB;
    if ($courseid <= 0) {
        return '-';
    }
    $ctx = context_course::instance($courseid, IGNORE_MISSING);
    if (!$ctx) {
        return '-';
    }
    $teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, ra.id AS raid
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.contextid = :ctxid
            AND r.shortname = :roleshortname
          ORDER BY ra.id DESC",
        ['ctxid' => $ctx->id, 'roleshortname' => 'editingteacher'],
        0,
        1
    );
    if (!$teachers) {
        return '-';
    }
    return fullname(reset($teachers));
}

// ============================================================================
// 1. Ambil data utama.
// ============================================================================
$km = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);

$mapel = $DB->get_record('mata_pelajaran', ['id' => $km->id_mapel], '*', IGNORE_MISSING);
$nama_mapel_raw = $mapel ? (string)$mapel->nama_mapel : 'Mata Pelajaran';
$nama_mapel = trim(preg_replace('/^\[.*?\]\s*/', '', $nama_mapel_raw));
if ($nama_mapel === '') {
    $nama_mapel = $nama_mapel_raw ?: 'Mata Pelajaran';
}

$nama_sekolah = get_config('local_akademikmonitor', 'nama_sekolah') ?: 'SMK PGRI 2 Giri';
$nama_jurusan = '-';
$nama_kurikulum = '-';
$tahun_ajaran = '-';

$kj = $DB->get_record('kurikulum_jurusan', ['id' => $km->id_kurikulum_jurusan], '*', IGNORE_MISSING);
if ($kj) {
    $jur = $DB->get_record('jurusan', ['id' => $kj->id_jurusan], '*', IGNORE_MISSING);
    if ($jur) {
        $nama_jurusan = local_akademikmonitor_export_tp_first_available_field($jur, ['nama_jurusan', 'nama'], '-');
    }

    $kur = $DB->get_record('kurikulum', ['id' => $kj->id_kurikulum], '*', IGNORE_MISSING);
    if ($kur) {
        $nama_kurikulum = local_akademikmonitor_export_tp_first_available_field($kur, ['nama', 'nama_kurikulum'], '-');
    }

    $ta = $DB->get_record('tahun_ajaran', ['id' => $kj->id_tahun_ajaran], '*', IGNORE_MISSING);
    if ($ta) {
        $tahun_ajaran = local_akademikmonitor_export_tp_first_available_field($ta, ['tahun_ajaran', 'nama'], '-');
    }
}

$tingkat = strtoupper(trim((string)($km->tingkat_kelas ?? 'X')));
$course_name = '';
$teacher_name = '';
if ($filtercourseid > 0 && $DB->record_exists('course', ['id' => $filtercourseid])) {
    $course_name = (string)$DB->get_field('course', 'fullname', ['id' => $filtercourseid]);
    $teacher_name = local_akademikmonitor_export_tp_teacher_name($filtercourseid);
}

// JP per konten = jam_pelajaran langsung dari setting mata pelajaran.
// Total JP nanti dihitung dari JP per konten x jumlah konten unik.
$jp_per_konten_nilai = (int)($km->jam_pelajaran ?? 0);
$jp_km = $jp_per_konten_nilai > 0 ? (string)$jp_per_konten_nilai : '-';

$kktp = (int)($km->kktp ?? 0);

$fase_map = [
    'X' => 'E',
    'XI' => 'F',
    'XII' => 'F',
    'XIII' => 'F',
];
$fase = 'Fase ' . ($fase_map[$tingkat] ?? 'E') . ' / Kelas ' . $tingkat;

$tp_cols = $DB->get_columns('tujuan_pembelajaran');
$has_konten = isset($tp_cols['konten']);
$has_id_course = isset($tp_cols['id_course']);

$cp_cols = $DB->get_columns('capaian_pembelajaran');
$has_cp_elemen = isset($cp_cols['elemen']);

$km_cols = $DB->get_columns('kurikulum_mapel');
$has_km_elemen = isset($km_cols['elemen']);

if ($cpid > 0) {
    $singlecp = $DB->get_record('capaian_pembelajaran', [
        'id' => $cpid,
        'id_kurikulum_mapel' => $kmid,
    ], '*', MUST_EXIST);
    $cps = [$singlecp->id => $singlecp];
} else {
    $cps = $DB->get_records('capaian_pembelajaran', ['id_kurikulum_mapel' => $kmid], 'id ASC');
}

// ============================================================================
// 2. Hitung JP per konten.
//    Gunakan get_fieldset_sql karena kolom tp.konten tidak dijamin unik.
// ============================================================================
$konten_values_export = [];
if ($has_konten) {
    $whereparts = [
        'cp.id_kurikulum_mapel = :kmid',
        "tp.konten IS NOT NULL",
        "tp.konten <> ''",
    ];
    $params = ['kmid' => $kmid];

    if ($cpid > 0) {
        $whereparts[] = 'cp.id = :cpid';
        $params['cpid'] = $cpid;
    }
    if ($has_id_course && $filtercourseid > 0) {
        $whereparts[] = 'tp.id_course = :filtercourseid';
        $params['filtercourseid'] = $filtercourseid;
    }

    $konten_values_export = $DB->get_fieldset_sql(
        "SELECT DISTINCT tp.konten
           FROM {tujuan_pembelajaran} tp
           JOIN {capaian_pembelajaran} cp ON cp.id = tp.id_capaian_pembelajaran
          WHERE " . implode(' AND ', $whereparts),
        $params
    );
}

$konten_unik_export = [];
foreach ($konten_values_export as $konten) {
    $k = trim((string)$konten);
    if ($k !== '') {
        $konten_unik_export[$k] = true;
    }
}

$jumlah_konten_export = count($konten_unik_export);

// JP per konten = jam_pelajaran langsung, bukan dibagi jumlah konten.
$jp_per_konten_export = $jp_per_konten_nilai > 0 ? $jp_per_konten_nilai : null;

// Total JP = JP per konten x jumlah konten unik.
$total_jp_km = ($jp_per_konten_export !== null && $jumlah_konten_export > 0)
    ? $jp_per_konten_export * $jumlah_konten_export
    : $jp_per_konten_nilai;

// ============================================================================
// 3. Bangun struktur data CP dan TP.
// ============================================================================
$cp_groups = [];
$no_konten = 0;
$elemen_parts = [];

foreach ($cps as $cp) {
    if ($has_cp_elemen && trim((string)($cp->elemen ?? '')) !== '') {
        $elemen_parts[trim((string)$cp->elemen)] = true;
    }

    $tpwhere = ['id_capaian_pembelajaran' => $cp->id];
    if ($has_id_course && $filtercourseid > 0) {
        $tpwhere['id_course'] = $filtercourseid;
    }
    $tps = $DB->get_records('tujuan_pembelajaran', $tpwhere, 'id ASC');
    if (empty($tps)) {
        continue;
    }

    $no_konten++;

    $cp_row = [
        'no_konten' => $no_konten,
        'cp_deskripsi' => trim((string)($cp->deskripsi ?? '')),
        'tps' => [],
    ];

    foreach ($tps as $tp) {
        $konten_val = $has_konten ? trim((string)($tp->konten ?? '')) : '';

        $cp_row['tps'][] = [
            'konten' => $konten_val,
            'kompetensi' => trim((string)($tp->kompetensi ?? '')),
            'dpl' => trim((string)($tp->dpl ?? '')),
            'atp' => trim((string)($tp->atp ?? '')),
            'deskripsi' => trim((string)($tp->deskripsi ?? '')),
            'jp' => $jp_per_konten_export,
        ];
    }

    $cp_groups[] = $cp_row;
}

if (!empty($elemen_parts)) {
    $elemen_header = implode(' | ', array_keys($elemen_parts));
} else if ($has_km_elemen && trim((string)($km->elemen ?? '')) !== '') {
    $elemen_header = trim((string)$km->elemen);
} else {
    $elemen_header = '-';
}

$cp_header_parts = array_filter(array_map(static function(array $group): string {
    return trim((string)$group['cp_deskripsi']);
}, $cp_groups));

if (empty($cp_header_parts)) {
    $cp_header = '-';
} else if (count($cp_header_parts) === 1) {
    $cp_header = reset($cp_header_parts);
} else {
    $cp_header = implode(' | ', $cp_header_parts);
}

// ============================================================================
// 4. Cek PhpSpreadsheet. Jika tidak ada, fallback ke CSV.
// ============================================================================
$spreadsheet_autoload = null;
foreach ([
    $CFG->dirroot . '/local/akademikmonitor/vendor/autoload.php',
    $CFG->dirroot . '/vendor/autoload.php',
] as $candidate) {
    if (file_exists($candidate)) {
        $spreadsheet_autoload = $candidate;
        break;
    }
}

$has_phpspreadsheet = false;
if ($spreadsheet_autoload) {
    require_once($spreadsheet_autoload);
    $has_phpspreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
}

// ============================================================================
// 5A. Export XLSX.
// ============================================================================
if ($has_phpspreadsheet) {
    $FILL_SOLID = \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID;
    $BDR_THIN = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
    $ORIENT_LAND = \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE;
    $PAPER_A4 = \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4;
    $H_LEFT = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT;
    $H_CENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $V_CENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;

    $C_HDR = 'FF1F4E79';
    $C_META = 'FFD9E1F2';
    $C_WHITE = 'FFFFFFFF';
    $C_BLACK = 'FF000000';
    $ROW_BG = [
        'FFFFFFFF', 'FFEBF3FB', 'FFFFF2CC', 'FFE2EFDA', 'FFFCE4D6',
        'FFEDEDED', 'FFD9F0E8', 'FFF4CCFF', 'FFFDECEA', 'FFE8F5E9',
    ];

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Analisis CP ATP');

    $border_style = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => $BDR_THIN,
                'color' => ['argb' => $C_BLACK],
            ],
        ],
    ];

    $styleRange = function(
        string $range,
        bool $bold = false,
        string $bg = '',
        string $fg = 'FF000000',
        string $hAlign = '',
        string $vAlign = '',
        bool $wrap = true,
        int $fontSize = 10,
        bool $border = true,
        bool $italic = false
    ) use (&$sheet, $FILL_SOLID, $H_CENTER, $V_CENTER, $border_style): void {
        if (empty($sheet)) {
            return;
        }

        $style = $sheet->getStyle($range);
        $style->getFont()
            ->setName('Arial')
            ->setSize($fontSize)
            ->setBold($bold)
            ->setItalic($italic)
            ->getColor()->setARGB($fg);

        $style->getAlignment()
            ->setHorizontal($hAlign ?: $H_CENTER)
            ->setVertical($vAlign ?: $V_CENTER)
            ->setWrapText($wrap);

        if ($bg !== '') {
            $style->getFill()
                ->setFillType($FILL_SOLID)
                ->getStartColor()->setARGB($bg);
        }

        if ($border) {
            $style->applyFromArray($border_style);
        }
    };

    $writeCell = function(
        int $row,
        int $col,
        $value = '',
        bool $bold = false,
        string $bg = '',
        string $fg = 'FF000000',
        string $hAlign = '',
        bool $wrap = true,
        int $fontSize = 10,
        bool $italic = false,
        bool $border = true
    ) use (&$sheet, &$styleRange): void {
        $cell = local_akademikmonitor_export_tp_cell($col, $row);
        $sheet->setCellValue(
            $cell,
            local_akademikmonitor_safe_excel_text($value)
        );
        $styleRange($cell, $bold, $bg, $fg, $hAlign, '', $wrap, $fontSize, $border, $italic);
    };

    $mergeWrite = function(
        int $r1,
        int $c1,
        int $r2,
        int $c2,
        $value = '',
        bool $bold = false,
        string $bg = '',
        string $fg = 'FF000000',
        string $hAlign = '',
        bool $wrap = true,
        int $fontSize = 10,
        bool $italic = false,
        bool $border = true
    ) use (&$sheet, &$styleRange): void {
        $start = local_akademikmonitor_export_tp_cell($c1, $r1);
        $end = local_akademikmonitor_export_tp_cell($c2, $r2);
        $range = $start . ':' . $end;

        if ($r1 !== $r2 || $c1 !== $c2) {
            $sheet->mergeCells($range);
        }

        $sheet->setCellValue(
            $start,
            local_akademikmonitor_safe_excel_text($value)
        );
        $styleRange($range, $bold, $bg, $fg, $hAlign, '', $wrap, $fontSize, $border, $italic);
    };

    $remerge = function(
        int $col,
        int $r1,
        int $r2,
        $value,
        bool $bold = false,
        string $bg = '',
        string $hAlign = '',
        int $fontSize = 10
    ) use (&$sheet, &$styleRange, $H_CENTER): void {
        if ($r2 < $r1) {
            return;
        }

        $start = local_akademikmonitor_export_tp_cell($col, $r1);
        $end = local_akademikmonitor_export_tp_cell($col, $r2);
        $range = $start . ':' . $end;

        if ($r2 > $r1) {
            $sheet->mergeCells($range);
        }

        $sheet->setCellValue(
            $start,
            local_akademikmonitor_safe_excel_text($value)
        );
        $styleRange($range, $bold, $bg, 'FF000000', $hAlign ?: $H_CENTER, '', true, $fontSize, true, false);
    };

    // Lebar kolom: A=No, B=Konten, C=Kompetensi, D=DPL, E=ATP, F=TP, G=JP.
    $col_widths = [1 => 7, 2 => 26, 3 => 20, 4 => 22, 5 => 7, 6 => 62, 7 => 6];
    foreach ($col_widths as $col => $width) {
        $sheet->getColumnDimensionByColumn($col)->setWidth($width);
    }

    // Header utama.
    $mergeWrite(
        1, 1, 1, 7,
        'ANALISIS CAPAIAN PEMBELAJARAN DAN ALUR TUJUAN PEMBELAJARAN',
        true, $C_HDR, $C_WHITE, $H_CENTER, true, 13
    );
    $sheet->getRowDimension(1)->setRowHeight(30);

    $meta = [
        2 => ['Nama Sekolah', $nama_sekolah],
        3 => ['Mata Pelajaran', $nama_mapel],
        4 => ['Fase', $fase . ' (SMK — Kurikulum Merdeka)'],
        5 => ['Elemen', $elemen_header],
    ];

    foreach ($meta as $row => [$label, $value]) {
        $mergeWrite($row, 1, $row, 2, $label, true, $C_META, $C_BLACK, $H_LEFT, false, 10);
        $mergeWrite($row, 3, $row, 7, ': ' . $value, false, $C_META, $C_BLACK, $H_LEFT, false, 10);
        $sheet->getRowDimension($row)->setRowHeight(17);
    }

    $mergeWrite(6, 1, 6, 7, 'CP: ' . $cp_header, false, $C_META, 'FF1F1F1F', $H_LEFT, true, 9, true);
    $sheet->getRowDimension(6)->setRowHeight(80);

    $header_row = 7;
    $row_start = 8;
    if ($course_name !== '') {
        $mergeWrite(7, 1, 7, 2, 'Course / Kelas', true, $C_META, $C_BLACK, $H_LEFT, false, 10);
        $mergeWrite(7, 3, 7, 7, ': ' . $course_name, false, $C_META, $C_BLACK, $H_LEFT, true, 10);
        $mergeWrite(8, 1, 8, 2, 'Guru Pengampu', true, $C_META, $C_BLACK, $H_LEFT, false, 10);
        $mergeWrite(8, 3, 8, 7, ': ' . $teacher_name, false, $C_META, $C_BLACK, $H_LEFT, true, 10);
        $header_row = 9;
        $row_start = 10;
    }

    $table_headers = [
        1 => 'No',
        2 => 'Konten',
        3 => 'Kompetensi',
        4 => 'DPL',
        5 => 'ATP',
        6 => 'Tujuan Pembelajaran',
        7 => 'JP',
    ];

    foreach ($table_headers as $col => $header) {
        $writeCell($header_row, $col, $header, true, $C_HDR, $C_WHITE, $H_CENTER, true, 10);
    }
    $sheet->getRowDimension($header_row)->setRowHeight(28);

    // Flatten data.
    $ROW_START = $row_start;
    $rows = [];
    $no_color_map = [];
    $color_idx = 0;

    foreach ($cp_groups as $cp_group) {
        $no = $cp_group['no_konten'];
        $bg = $ROW_BG[$color_idx % count($ROW_BG)];
        $no_color_map[$no] = $bg;
        $color_idx++;

        foreach ($cp_group['tps'] as $tp) {
            $rows[] = [
                'no' => $no,
                'konten' => $tp['konten'],
                'kompetensi' => $tp['kompetensi'],
                'dpl' => $tp['dpl'],
                'atp' => $tp['atp'],
                'deskripsi' => $tp['deskripsi'],
                'jp' => $tp['jp'],
                'bg' => $bg,
            ];
        }
    }

    $no_ranges = [];
    $no_first_row = [];
    foreach ($rows as $index => $row) {
        $excel_row = $ROW_START + $index;
        $no = $row['no'];
        if (!isset($no_first_row[$no])) {
            $no_first_row[$no] = $excel_row;
        }
        $no_ranges[$no] = [$no_first_row[$no], $excel_row];
    }

    $konten_ranges = [];
    $index = 0;
    $row_count = count($rows);

    while ($index < $row_count) {
        $start_index = $index;
        $current_konten = $rows[$index]['konten'];
        $current_no = $rows[$index]['no'];
        $jp_in_block = $rows[$index]['jp'];

        $next = $index + 1;
        while (
            $next < $row_count &&
            $rows[$next]['konten'] === $current_konten &&
            $rows[$next]['no'] === $current_no
        ) {
            if ($jp_in_block === null && $rows[$next]['jp'] !== null) {
                $jp_in_block = $rows[$next]['jp'];
            }
            $next++;
        }

        $end_index = $next - 1;
        $konten_ranges[] = [
            'r1' => $ROW_START + $start_index,
            'r2' => $ROW_START + $end_index,
            'konten' => $current_konten,
            'jp' => $jp_in_block,
            'bg' => $rows[$start_index]['bg'],
        ];

        $index = $next;
    }

    $konten_first_in_range = [];
    foreach ($konten_ranges as $range_data) {
        $konten_first_in_range[$range_data['r1']] = $range_data;
    }

    foreach ($rows as $index => $row) {
        $excel_row = $ROW_START + $index;
        $bg = $row['bg'];

        $writeCell($excel_row, 1, $row['no'], true, $bg, $C_BLACK, $H_CENTER, false, 11);

        if (isset($konten_first_in_range[$excel_row])) {
            $writeCell($excel_row, 2, $row['konten'], true, $bg, $C_BLACK, $H_LEFT, true, 10);
        } else {
            $writeCell($excel_row, 2, '', false, $bg, $C_BLACK, $H_LEFT, true, 10);
        }

        $writeCell($excel_row, 3, $row['kompetensi'], false, $bg, $C_BLACK, $H_LEFT, true, 10);
        $writeCell($excel_row, 4, $row['dpl'], false, $bg, $C_BLACK, $H_LEFT, true, 10);
        $writeCell($excel_row, 5, $row['atp'], true, $bg, $C_BLACK, $H_CENTER, true, 10);
        $writeCell($excel_row, 6, $row['deskripsi'], false, $bg, $C_BLACK, $H_LEFT, true, 10);

        if (isset($konten_first_in_range[$excel_row])) {
            $jp_value = $konten_first_in_range[$excel_row]['jp'];
            $writeCell($excel_row, 7, $jp_value !== null ? $jp_value : '', true, $bg, $C_BLACK, $H_CENTER, false, 10);
        } else {
            $writeCell($excel_row, 7, '', false, $bg, $C_BLACK, $H_CENTER, false, 10);
        }

        $sheet->getRowDimension($excel_row)->setRowHeight(44);
    }

    foreach ($no_ranges as $no => [$r1, $r2]) {
        $remerge(1, $r1, $r2, $no, true, $no_color_map[$no] ?? 'FFFFFFFF', $H_CENTER, 11);
    }

    foreach ($konten_ranges as $range_data) {
        $r1 = $range_data['r1'];
        $r2 = $range_data['r2'];
        $bg = $range_data['bg'];

        $remerge(2, $r1, $r2, $range_data['konten'], true, $bg, $H_LEFT, 10);
        $jp_value = $range_data['jp'] !== null ? $range_data['jp'] : '';
        $remerge(7, $r1, $r2, $jp_value, true, $bg, $H_CENTER, 10);
    }

    $total_row = $ROW_START + count($rows);
    if ($total_row < $ROW_START) {
        $total_row = $ROW_START;
    }

    $mergeWrite($total_row, 1, $total_row, 6, 'JUMLAH JAM PELAJARAN', true, $C_HDR, $C_WHITE, $H_CENTER, false, 11);
    $writeCell($total_row, 7, $total_jp_km > 0 ? $total_jp_km : '', true, $C_HDR, $C_WHITE, $H_CENTER, false, 11);
    $sheet->getRowDimension($total_row)->setRowHeight(22);

    $sheet->freezePane('A' . $ROW_START);
    $sheet->getPageSetup()
        ->setOrientation($ORIENT_LAND)
        ->setPaperSize($PAPER_A4)
        ->setFitToPage(true)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    $sheet->getHeaderFooter()->setOddHeader('&C&B' . $nama_mapel . ' — Analisis CP & ATP');
    $sheet->getHeaderFooter()->setOddFooter('&L' . $nama_sekolah . ' | ' . $tahun_ajaran . '&RHalaman &P dari &N');
    $sheet->getPageMargins()->setTop(0.75)->setBottom(0.75)->setLeft(0.7)->setRight(0.7);

    $safe = local_akademikmonitor_export_tp_safe_filename($nama_mapel);
    $coursesafe = $course_name !== '' ? '_' . local_akademikmonitor_export_tp_safe_filename($course_name) : '';
    $filename = 'Analisis_CP_ATP_' . $safe . '_Kelas' . local_akademikmonitor_export_tp_safe_filename($tingkat) . $coursesafe . '_' . date('Ymd') . '.xlsx';

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ============================================================================
// 5B. Fallback CSV.
// ============================================================================
$safe = local_akademikmonitor_export_tp_safe_filename($nama_mapel);
$coursesafe = $course_name !== '' ? '_' . local_akademikmonitor_export_tp_safe_filename($course_name) : '';
$filename = 'Analisis_CP_ATP_' . $safe . '_Kelas' . local_akademikmonitor_export_tp_safe_filename($tingkat) . $coursesafe . '_' . date('Ymd') . '.csv';

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

fputcsv($out, ['Nama Sekolah', $nama_sekolah]);
fputcsv($out, ['Mata Pelajaran', $nama_mapel]);
fputcsv($out, ['Fase', $fase]);
fputcsv($out, ['Elemen', $elemen_header]);
fputcsv($out, ['CP', $cp_header]);
if ($course_name !== '') {
    fputcsv($out, ['Course / Kelas', $course_name]);
    fputcsv($out, ['Guru Pengampu', $teacher_name]);
}
fputcsv($out, ['Jurusan', $nama_jurusan]);
fputcsv($out, ['Kurikulum', $nama_kurikulum . ' | TA: ' . $tahun_ajaran]);
fputcsv($out, ['KKTP', $kktp . ' | JP: ' . $jp_km]);
fputcsv($out, []);
fputcsv($out, ['No Konten', 'Konten', 'Kompetensi', 'DPL', 'ATP', 'Tujuan Pembelajaran', 'JP']);

foreach ($cp_groups as $cp_group) {
    $first_cp = true;
    $konten_prev = null;

    foreach ($cp_group['tps'] as $tp) {
        $konten_changed = $tp['konten'] !== $konten_prev;

        fputcsv($out, [
            $first_cp ? $cp_group['no_konten'] : '',
            $konten_changed ? $tp['konten'] : '',
            $tp['kompetensi'],
            $tp['dpl'],
            $tp['atp'],
            $tp['deskripsi'],
            $tp['jp'] !== null ? $tp['jp'] : '',
        ]);

        $first_cp = false;
        $konten_prev = $tp['konten'];
    }
}

fputcsv($out, ['Total JP', '', '', '', '', '', $total_jp_km > 0 ? $total_jp_km : '']);
fclose($out);
exit;
