<?php
require_once 'config.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

if (ob_get_length()) {
    ob_end_clean();
}

try {
    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']}", $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$firstDay = "{$year}-{$month}-01";
$lastDay = date("Y-m-t", strtotime($firstDay));

$stmt = $pdo->prepare(trim("
    SELECT u.user_id, u.full_name, ar.datetime, ar.attendance_status, ar.check_type
    FROM users u
    LEFT JOIN attendance_records ar 
    ON u.user_id = ar.user_id AND ar.datetime BETWEEN :firstDay AND :lastDay
    ORDER BY u.user_id, ar.datetime
"));
$stmt->execute(['firstDay' => $firstDay, 'lastDay' => $lastDay]);
$records = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Styling enhancements
$headerFillColor = 'FFD9E1F2'; // Light blue 
$summaryRowFillColor = 'FFF2F2F2'; // Light gray
$borderColor = 'FFCCCCCC'; // Light gray border
$titleFillColor = 'FFC0C0C0'; // Silver

// Set title and subtitle
$sheet->setCellValue('A1', date('F', strtotime($firstDay)) . ' Monthly Attendance Report');
$sheet->setCellValue('A2', 'PT Prio Integritas Universal');
// Merge cells for the title and company name
$sheet->mergeCells('A1:C2');
$sheet->getStyle('A1:C2')->applyFromArray([
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER, 
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'font' => [
        'bold' => true,
        'size' => 16
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['argb' => $titleFillColor]
    ]
]);

$sheet->getRowDimension(1)->setRowHeight(30); // Adjust the height as needed

// Set the header rows (start from row 4)
$sheet->setCellValue('A4', 'No.')
      ->setCellValue('B4', 'User ID')
      ->setCellValue('C4', 'Name')
      ->setCellValue('D4', 'Present')
      ->setCellValue('E4', 'Izin')
      ->setCellValue('F4', 'Sakit')
      ->setCellValue('G4', 'Cuti')
      ->setCellValue('H4', 'Late')          // Add new header for Late count
      ->setCellValue('I4', 'Early Leave');  // Add new header for Early Leave count

// Merge cell for the month display and set date headers dynamically
$currentColumn = 'J';
for ($date = 1; $date <= date('t', strtotime($firstDay)); $date++) {
    $dateHeader = $date . '-' . $month . '-' . $year;
    $sheet->setCellValue($currentColumn . '4', $dateHeader);

    // Set the column width to make the date columns wider
    $sheet->getColumnDimension($currentColumn)->setWidth(20); // Adjust the width as needed

    $currentColumn++;
}
$lastColumn = $sheet->getHighestColumn();
$sheet->mergeCells('J3:' . $lastColumn . '3');
$sheet->setCellValue('J3', date('F Y', strtotime($firstDay))); 
$sheet->getStyle('J3:' . $lastColumn . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('J3:' . $lastColumn . '3')->getFont()->setBold(true);

$row = 5; // Start data from row 5
$no = 1;

foreach ($records as $user_id => $userRecords) {
    $sheet->setCellValue('A' . $row, $no);
    $sheet->setCellValue('B' . $row, $user_id);
    $sheet->setCellValue('C' . $row, $userRecords[0]['full_name'] ?? '');

    // Initialize summary counts
    $summary = [
        'present' => 0, 
        'izin' => 0, 
        'sakit' => 0, 
        'cuti' => 0,
        'late' => 0,
        'early_leave' => 0,
        'missed_checkin' => 0,
        'missed_checkout' => 0
    ];

    $currentColumn = 'J'; // Start from column J for attendance data (after summary columns)

    for ($date = 1; $date <= date('t', strtotime($firstDay)); $date++) {
        $dateString = "{$year}-{$month}-" . str_pad($date, 2, '0', STR_PAD_LEFT);
        $checkinData = '-';
        $checkoutData = '-';
        $hasCheckIn = false;
        $hasCheckOut = false;

        foreach ($userRecords as $record) {
            if (strpos($record['datetime'], $dateString) !== false) {
                if ($record['check_type'] == 0) { // Check-in
                    $checkinData = getAttendanceSymbol($record['attendance_status']);
                    $hasCheckIn = true;

                    // Update check-out data based on check-in status
                    if ($record['attendance_status'] == '2') { // Izin
                        $checkoutData = 'I';
                    } elseif ($record['attendance_status'] == '3') { // Sakit
                        $checkoutData = 'S';
                    } elseif ($record['attendance_status'] == '4') { // Cuti
                        $checkoutData = 'C';
                    }

                    if ($record['attendance_status'] == '1') { // Present
                        $summary['present']++;
                    } elseif ($record['attendance_status'] == '2') {
                        $summary['izin']++;
                    } elseif ($record['attendance_status'] == '3') {
                        $summary['sakit']++;
                    } elseif ($record['attendance_status'] == '4') {
                        $summary['cuti']++;
                    }

                    // Check if check-in is late
                    if (isset($record['is_late']) && $record['is_late'] == 1) {
                        $summary['late']++;
                    }
                } elseif ($record['check_type'] == 1) { // Check-out
                    $checkoutData = getAttendanceSymbol($record['attendance_status']);
                    $hasCheckOut = true;

                    // Update check-in data based on check-out status
                    if ($record['attendance_status'] == '2') { // Izin
                        $checkinData = 'I';
                    } elseif ($record['attendance_status'] == '3') { // Sakit
                        $checkinData = 'S';
                    } elseif ($record['attendance_status'] == '4') { // Cuti
                        $checkinData = 'C';
                    }

                    // Check if check-out is early leave
                    if (isset($record['early_leave']) && $record['early_leave'] == 1) {
                        $summary['early_leave']++;
                    }
                }
            }
        }

        // Check for missed check-in or check-out and update summary counts
        if (!$hasCheckIn) {
            $summary['missed_checkin']++;
            $checkinData = 'X'; // Indicate missed check-in
        }
        if (!$hasCheckOut) {
            $summary['missed_checkout']++;
            $checkoutData = 'X'; // Indicate missed check-out
        }

        $sheet->setCellValue($currentColumn . $row, "Check In: $checkinData\nCheck Out: $checkoutData");
        $sheet->getStyle($currentColumn . $row)->getAlignment()->setWrapText(true);
        $sheet->getStyle($currentColumn . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $currentColumn++;
    }

    // Set summary counts
    $sheet->setCellValue('D' . $row, $summary['present']);
    $sheet->setCellValue('E' . $row, $summary['izin']);
    $sheet->setCellValue('F' . $row, $summary['sakit']);
    $sheet->setCellValue('G' . $row, $summary['cuti']);
    $sheet->setCellValue('H' . $row, $summary['late']);
    $sheet->setCellValue('I' . $row, $summary['early_leave']);

    $row++;
    $no++;
}

// Apply borders and fill colors
$sheet->getStyle('A4:' . $lastColumn . ($row - 1))
      ->applyFromArray([
          'borders' => [
              'allBorders' => [
                  'borderStyle' => Border::BORDER_THIN,
                  'color' => ['argb' => $borderColor],
              ],
          ],
          'fill' => [
              'fillType' => Fill::FILL_SOLID,
              'startColor' => ['argb' => $summaryRowFillColor]
          ],
      ]);

// Set auto width for columns
foreach (range('A', $lastColumn) as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

$writer = new Xlsx($spreadsheet);
$fileName = "Attendance_Report_{$year}_{$month}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit();

function getAttendanceSymbol($status) {
    switch ($status) {
        case '1':
            return '✓'; // ✓Present
        case '2':
            return 'I'; // Izin
        case '3':
            return 'S'; // Sakit
        case '4':
            return 'C'; // Cuti
        default:
            return '-'; // Missing or unknown status
    }
}
?>
