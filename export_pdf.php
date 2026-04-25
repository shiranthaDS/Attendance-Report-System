<?php
// ============================================================
//  export_pdf.php – Generate PDF using Dompdf
//  Renders filtered attendance data as a professional PDF.
// ============================================================

// Suppress PHP 8.4/8.5 deprecation warnings from Dompdf's own vendor code.
// These are cosmetic warnings inside Dompdf (nullable types, (double) cast,
// $http_response_header) that do NOT affect functionality, but if printed
// they corrupt the binary PDF output stream.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// Start output buffering so any stray output can be cleared before
// we stream the PDF binary to the browser.
ob_start();

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Re-use the same parse/aggregate logic ─────────────────
function parseLogFile(string $filePath): array {
    $records = [];
    if (!file_exists($filePath)) return $records;
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $i => $line) {
        if ($i === 0) continue;
        $line    = rtrim($line, "\r\n");
        $pattern = '/^(\S+)\s+(\S+)\s+(\d{2}\/\d{2}\/\d{4})\s+(\d{2}:\d{2})\s+\d{2}\s*(.*)$/';
        if (!preg_match($pattern, trim($line), $m)) continue;
        $userID    = trim($m[2]);
        $entryDate = trim($m[3]);
        $entryTime = trim($m[4]);
        $name      = trim(preg_replace('/\s+/', ' ', $m[5]));
        if (empty($userID)) continue;
        $records[] = ['userID' => $userID, 'date' => $entryDate, 'time' => $entryTime, 'name' => $name];
    }
    return $records;
}

function aggregateRecords(array $records): array {
    $grouped = [];
    foreach ($records as $r) {
        $key = $r['userID'] . '_' . $r['date'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['userID' => $r['userID'], 'date' => $r['date'], 'name' => '', 'times' => []];
        }
        $grouped[$key]['times'][] = $r['time'];
        if (empty($grouped[$key]['name']) && !empty($r['name'])) {
            $grouped[$key]['name'] = $r['name'];
        }
    }
    $result = [];
    foreach ($grouped as $entry) {
        sort($entry['times']);
        $firstIn  = $entry['times'][0];
        $lastOut  = end($entry['times']);
        [$inH, $inM]   = array_map('intval', explode(':', $firstIn));
        [$outH, $outM] = array_map('intval', explode(':', $lastOut));
        $totalMins = ($outH * 60 + $outM) - ($inH * 60 + $inM);
        $workHours = $totalMins > 0 ? floor($totalMins / 60) . 'h ' . ($totalMins % 60) . 'm' : '–';
        $lateThresholdMins  = 8 * 60 + 30;
        $earlyThresholdMins = 17 * 60;
        $firstInMins  = $inH * 60 + $inM;
        $lastOutMins  = $outH * 60 + $outM;
        $isLate       = $firstInMins > $lateThresholdMins;
        $isEarlyLeave = $lastOutMins < $earlyThresholdMins;
        [$dd, $mm, $yyyy] = explode('/', $entry['date']);
        $result[] = [
            'userID'       => $entry['userID'],
            'name'         => $entry['name'] ?: 'Unknown',
            'date'         => $entry['date'],
            'dateISO'      => "$yyyy-$mm-$dd",
            'firstIn'      => $firstIn,
            'lastOut'      => $lastOut,
            'totalMinutes' => $totalMins,
            'workHours'    => $workHours,
            'isLate'       => $isLate,
            'isEarlyLeave' => $isEarlyLeave,
        ];
    }
    usort($result, fn($a, $b) => $a['dateISO'] <=> $b['dateISO'] ?: $a['name'] <=> $b['name']);
    return $result;
}

// ── Load & filter data ────────────────────────────────────
$logFile  = __DIR__ . '/LOG.txt';
$raw      = parseLogFile($logFile);
$allRows  = aggregateRecords($raw);

$filterEmployee = isset($_GET['employee']) ? trim($_GET['employee']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

// Collect names for filter display
$employees = [];
foreach ($allRows as $row) {
    if (!isset($employees[$row['userID']])) {
        $employees[$row['userID']] = $row['name'];
    }
}

$filtered = array_filter($allRows, function($row) use ($filterEmployee, $filterDateFrom, $filterDateTo) {
    if (!empty($filterEmployee) && $row['userID'] !== $filterEmployee) return false;
    if (!empty($filterDateFrom) && $row['dateISO'] < $filterDateFrom)  return false;
    if (!empty($filterDateTo)   && $row['dateISO'] > $filterDateTo)    return false;
    return true;
});
$filtered = array_values($filtered);

// ── Summary stats ─────────────────────────────────────────
$totalRecords  = count($filtered);
$lateCount     = count(array_filter($filtered, fn($r) => $r['isLate']));
$earlyCount    = count(array_filter($filtered, fn($r) => $r['isEarlyLeave']));
$totalWorkMins = array_sum(array_column($filtered, 'totalMinutes'));
$avgWorkH      = $totalRecords > 0
    ? floor(($totalWorkMins / $totalRecords) / 60) . 'h ' . (floor($totalWorkMins / $totalRecords) % 60) . 'm'
    : '–';

// ── Build HTML for PDF ────────────────────────────────────
// ── Build rows HTML ───────────────────────────────────────
// Use a separate inner buffer so it doesn't interfere with the
// outer ob_start() that catches stray deprecation output.
$tableRows = '';
foreach ($filtered as $i => $row) {
    if ($row['isLate'] && $row['isEarlyLeave']) {
        $statusBadge = '<span class="badge-crit">Late &amp; Early Leave</span>';
    } elseif ($row['isLate']) {
        $statusBadge = '<span class="badge-late">Late</span>';
    } elseif ($row['isEarlyLeave']) {
        $statusBadge = '<span class="badge-early">Early Leave</span>';
    } else {
        $statusBadge = '<span class="badge-ok">On Time</span>';
    }

    $firstInClass = $row['isLate']       ? 'time-late'  : 'time-ok';
    $lastOutClass = $row['isEarlyLeave'] ? 'time-early' : 'time-ok';

    [$dd, $mm, $yyyy] = explode('/', $row['date']);
    $dateFormatted = date('d M Y', mktime(0, 0, 0, (int)$mm, (int)$dd, (int)$yyyy));

    $tableRows .= '<tr>'
        . '<td class="col-num">'  . ($i + 1) . '</td>'
        . '<td class="col-uid uid-cell">'  . htmlspecialchars($row['userID']) . '</td>'
        . '<td class="col-name name-cell">' . htmlspecialchars($row['name'])   . '</td>'
        . '<td class="col-date date-cell">' . $dateFormatted . '</td>'
        . '<td class="col-time time-cell '  . $firstInClass . '">' . htmlspecialchars($row['firstIn'])  . '</td>'
        . '<td class="col-time time-cell '  . $lastOutClass . '">' . htmlspecialchars($row['lastOut'])  . '</td>'
        . '<td class="col-hours hours-cell">' . htmlspecialchars($row['workHours']) . '</td>'
        . '<td class="col-status">' . $statusBadge . '</td>'
        . '</tr>' . "\n";
}

// ── Build HTML for PDF ────────────────────────────────────
$generatedAt = date('d M Y, H:i:s');
$cssPath     = __DIR__ . '/assets/css/print.css';
$cssContent  = file_exists($cssPath) ? file_get_contents($cssPath) : '';

$empLabel  = !empty($filterEmployee)
    ? htmlspecialchars($filterEmployee . ' – ' . ($employees[$filterEmployee] ?? 'Unknown'))
    : 'All Employees';
$dateLabel = (!empty($filterDateFrom) || !empty($filterDateTo))
    ? htmlspecialchars(($filterDateFrom ?: 'Start') . '  to  ' . ($filterDateTo ?: 'End'))
    : 'All Dates';

$onTimeCnt = max(0, $totalRecords - $lateCount - $earlyCount);

$html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Attendance Report</title>
  <style>' . $cssContent . '</style>
</head>
<body>

<!-- ════════════════════════════════════════════════════
     FIXED FOOTER — position:fixed repeats on every page
     Table layout so Dompdf renders it correctly
     ════════════════════════════════════════════════════ -->
<div class="pdf-footer">
  <table width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="text-align:left; font-size:7pt; color:#444;">
        <strong style="color:#5c54e8;">Attendance Report System</strong>
        &nbsp;|&nbsp; First In &amp; Last Out
        &nbsp;|&nbsp; Employee: ' . $empLabel . '
        &nbsp;|&nbsp; Period: ' . $dateLabel . '
      </td>
      <td style="text-align:right; font-size:7pt; color:#666;">
        Generated: ' . $generatedAt . ' &nbsp;|&nbsp; Records: ' . $totalRecords . '
      </td>
    </tr>
  </table>
</div>

<!-- ════════════════════════════════════════════════════
     PAGE HEADER — 2-column table (title left, meta right)
     ════════════════════════════════════════════════════ -->
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-bottom:3px solid #5c54e8; margin-bottom:8px; padding-bottom:7px;">
  <tr>
    <td style="vertical-align:top;">
      <div style="font-size:14pt; font-weight:700; color:#5c54e8; line-height:1.2;">
        Attendance Report &mdash; First In &amp; Last Out
      </div>
      <div style="font-size:7.5pt; color:#555; margin-top:3px;">
        Official Record &nbsp;|&nbsp;
        Employee: ' . $empLabel . ' &nbsp;|&nbsp;
        Period: ' . $dateLabel . '
      </div>
    </td>
    <td style="vertical-align:top; text-align:right; width:180px;">
      <div style="font-size:7.5pt; color:#333; line-height:1.6;">
        <strong>Generated:</strong> ' . $generatedAt . '<br>
        <strong>Total Records:</strong> ' . $totalRecords . '
      </div>
    </td>
  </tr>
</table>

<!-- ════════════════════════════════════════════════════
     SUMMARY STATS — 5-column table
     ════════════════════════════════════════════════════ -->
<table width="100%" cellpadding="6" cellspacing="0" border="0"
       style="margin-bottom:10px; border-collapse:separate; border-spacing:4px;">
  <tr>
    <td style="width:19%; border:1px solid #d0ccff; border-radius:5px;
               background:#f8f7ff; text-align:center; vertical-align:middle;">
      <div style="font-size:16pt; font-weight:800; color:#5c54e8; line-height:1.1;">' . $totalRecords . '</div>
      <div style="font-size:6.5pt; color:#777; text-transform:uppercase; letter-spacing:.3px;">Total Records</div>
    </td>
    <td style="width:19%; border:1px solid #d0ccff; border-radius:5px;
               background:#f8f7ff; text-align:center; vertical-align:middle;">
      <div style="font-size:16pt; font-weight:800; color:#1a7a4a; line-height:1.1;">' . $onTimeCnt . '</div>
      <div style="font-size:6.5pt; color:#777; text-transform:uppercase; letter-spacing:.3px;">On Time</div>
    </td>
    <td style="width:19%; border:1px solid #f0c87a; border-radius:5px;
               background:#fffbf0; text-align:center; vertical-align:middle;">
      <div style="font-size:16pt; font-weight:800; color:#c0720a; line-height:1.1;">' . $lateCount . '</div>
      <div style="font-size:6.5pt; color:#777; text-transform:uppercase; letter-spacing:.3px;">Late Arrivals</div>
    </td>
    <td style="width:19%; border:1px solid #f0a0a0; border-radius:5px;
               background:#fff8f8; text-align:center; vertical-align:middle;">
      <div style="font-size:16pt; font-weight:800; color:#b52020; line-height:1.1;">' . $earlyCount . '</div>
      <div style="font-size:6.5pt; color:#777; text-transform:uppercase; letter-spacing:.3px;">Early Leaves</div>
    </td>
    <td style="width:19%; border:1px solid #d0ccff; border-radius:5px;
               background:#f8f7ff; text-align:center; vertical-align:middle;">
      <div style="font-size:14pt; font-weight:800; color:#5c54e8; line-height:1.1;">' . $avgWorkH . '</div>
      <div style="font-size:6.5pt; color:#777; text-transform:uppercase; letter-spacing:.3px;">Avg Work Hours</div>
    </td>
  </tr>
</table>

<!-- ════════════════════════════════════════════════════
     ATTENDANCE DATA TABLE
     thead with display:table-header-group repeats on
     every page. page-break-inside:avoid on tbody rows.
     ════════════════════════════════════════════════════ -->
<table class="pdf-table" width="100%" cellpadding="0" cellspacing="0">
  <thead>
    <tr>
      <th style="width:4%;  text-align:center;">#</th>
      <th style="width:9%;">User ID</th>
      <th style="width:21%;">Employee Name</th>
      <th style="width:11%;">Date</th>
      <th style="width:9%;  text-align:center;">First In</th>
      <th style="width:9%;  text-align:center;">Last Out</th>
      <th style="width:10%; text-align:center;">Work Hours</th>
      <th style="width:14%;">Status</th>
    </tr>
  </thead>
  <tbody>
' . $tableRows . '
  </tbody>
</table>

</body>
</html>';


// ── Render with Dompdf ────────────────────────────────────
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);
$options->set('isFontSubsettingEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Add "Page X of Y" to every page via Dompdf canvas API
$canvas    = $dompdf->getCanvas();
$pageW     = $canvas->get_width();
$pageH     = $canvas->get_height();
$canvas->page_text(
    $pageW - 90,
    $pageH - 16,
    'Page {PAGE_NUM} of {PAGE_COUNT}',
    null,
    7,
    [0.5, 0.5, 0.5]
);

// Discard any buffered output (warnings/notices) before sending the PDF binary.
ob_end_clean();

$filename = 'Attendance_Report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
?>
