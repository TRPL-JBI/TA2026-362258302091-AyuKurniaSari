<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot .
    '/local/akademikmonitor/lib/dompdf/vendor/autoload.php'
);

if (!class_exists('\\Dompdf\\Dompdf')) {
    throw new \exception(
        'DOMPDF gagal dimuat'
    );
}

require_once($CFG->libdir .
    '/excellib.class.php');

use \Dompdf\Dompdf;

class report_export_service {

    public static function create_student_pdf(
        \stdClass $student,
        array $report,
        string $filepath
    ): void {

        $html = '
        <h2>Laporan Presensi Siswa</h2>

        <p>
        Nama : ' . fullname($student) . '
        </p>

        <table border="1" width="100%" cellpadding="5">
            <tr>
                <th>Hadir</th>
                <th>Terlambat</th>
                <th>Izin</th>
                <th>Alfa</th>
            </tr>
            <tr>
                <td>' . $report['present'] . '</td>
                <td>' . $report['late'] . '</td>
                <td>' . $report['excused'] . '</td>
                <td>' . $report['absent'] . '</td>
            </tr>
        </table>

        <br>

        <table border="1" width="100%" cellpadding="5">
            <tr>
                <th>Tanggal</th>
                <th>Mapel</th>
                <th>Pertemuan</th>
                <th>Status</th>
            </tr>';

        foreach ($report['details'] as $detail) {

            $html .= '
            <tr>
                <td>' . s($detail['date']) . '</td>
                <td>' . s($detail['course']) . '</td>
                <td>' . s($detail['session']) . '</td>
                <td>' . s($detail['status']) . '</td>
            </tr>';
        }

        $html .= '</table>';

        $dompdf = new \Dompdf\Dompdf();

        $dompdf->loadHtml($html);

        $dompdf->setPaper('A4');

        $dompdf->render();

        file_put_contents(
            $filepath,
            $dompdf->output()
        );
    }

    public static function create_student_excel(
        \stdClass $student,
        array $report,
        string $filepath
    ): void {

        $workbook =
            new \MoodleExcelWorkbook($filepath);

        $sheet =
            $workbook->add_worksheet('Presensi');

        $row = 0;

        $sheet->write($row,0,'Tanggal');
        $sheet->write($row,1,'Mapel');
        $sheet->write($row,2,'Pertemuan');
        $sheet->write($row,3,'Status');

        $row++;

        foreach ($report['details'] as $detail) {

            $sheet->write($row,0,$detail['date']);
            $sheet->write($row,1,$detail['course']);
            $sheet->write($row,2,$detail['session']);
            $sheet->write($row,3,$detail['status']);

            $row++;
        }

        $workbook->close();
    }
}