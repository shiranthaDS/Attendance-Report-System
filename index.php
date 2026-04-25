<?php
session_start();

// ── Handle file upload ─────────────────────────────────────
$uploadMsg     = '';
$uploadSuccess = false;
$uploadError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['log_file'])) {
    $file = $_FILES['log_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'log', ''])) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $dest = $uploadDir . 'LOG.txt';
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $_SESSION['log_path'] = $dest;
                $uploadSuccess = true;
                $uploadMsg = 'Log file uploaded successfully!';
            } else {
                $uploadError = 'Failed to save the uploaded file.';
            }
        } else {
            $uploadError = 'Invalid file type. Please upload a .txt or .log file.';
        }
    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadError = 'Upload error (code ' . $file['error'] . '). Max size is ' . ini_get('upload_max_filesize') . '.';
    }
}

// Handle clear/reset
if (isset($_GET['clear_log'])) {
    // Delete the uploaded file from disk
    $toDelete = __DIR__ . '/uploads/LOG.txt';
    if (file_exists($toDelete)) {
        @unlink($toDelete);
    }
    // Also delete from any custom session path
    if (!empty($_SESSION['log_path']) && file_exists($_SESSION['log_path'])) {
        @unlink($_SESSION['log_path']);
    }
    unset($_SESSION['log_path']);
    header('Location: ?');
    exit;
}

// ── Determine which log file to use ───────────────────────
$logFile = '';
if (!empty($_SESSION['log_path']) && file_exists($_SESSION['log_path'])) {
    $logFile = $_SESSION['log_path'];
} elseif (file_exists(__DIR__ . '/uploads/LOG.txt')) {
    $logFile = __DIR__ . '/uploads/LOG.txt';
}

// ── 1. Parse LOG file ──────────────────────────────────────
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
        $records[] = ['userID'=>$userID,'date'=>$entryDate,'time'=>$entryTime,'name'=>$name];
    }
    return $records;
}

// ── 2. Aggregate ───────────────────────────────────────────
function aggregateRecords(array $records): array {
    $grouped = [];
    foreach ($records as $r) {
        $key = $r['userID'] . '_' . $r['date'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['userID'=>$r['userID'],'date'=>$r['date'],'name'=>'','times'=>[]];
        }
        $grouped[$key]['times'][] = $r['time'];
        if (empty($grouped[$key]['name']) && !empty($r['name'])) {
            $grouped[$key]['name'] = $r['name'];
        }
    }
    $result = [];
    foreach ($grouped as $entry) {
        sort($entry['times']);
        $firstIn = $entry['times'][0];
        $lastOut = end($entry['times']);
        [$inH,$inM]   = array_map('intval', explode(':', $firstIn));
        [$outH,$outM] = array_map('intval', explode(':', $lastOut));
        $totalMins = ($outH*60+$outM) - ($inH*60+$inM);
        $workHours = $totalMins > 0 ? floor($totalMins/60).'h '.($totalMins%60).'m' : '–';
        $lateThresholdMins = 8*60+30;
        $firstInMins = $inH*60+$inM;
        $isLate = $firstInMins > $lateThresholdMins;
        $lateBy = '';
        if ($isLate) { $diff=$firstInMins-$lateThresholdMins; $lateBy=floor($diff/60).'h '.($diff%60).'m'; }
        $earlyThresholdMins = 17*60;
        $lastOutMins = $outH*60+$outM;
        $isEarlyLeave = $lastOutMins < $earlyThresholdMins;
        $earlyBy = '';
        if ($isEarlyLeave) { $diff=$earlyThresholdMins-$lastOutMins; $earlyBy=floor($diff/60).'h '.($diff%60).'m'; }
        [$dd,$mm,$yyyy] = explode('/', $entry['date']);
        $dateISO = "$yyyy-$mm-$dd";
        $result[] = [
            'userID'=>$entry['userID'],'name'=>$entry['name']?:'Unknown',
            'date'=>$entry['date'],'dateISO'=>$dateISO,
            'firstIn'=>$firstIn,'lastOut'=>$lastOut,
            'totalMinutes'=>$totalMins,'workHours'=>$workHours,
            'isLate'=>$isLate,'lateBy'=>$lateBy,
            'isEarlyLeave'=>$isEarlyLeave,'earlyBy'=>$earlyBy,
        ];
    }
    usort($result, fn($a,$b) => $a['dateISO'] <=> $b['dateISO'] ?: $a['name'] <=> $b['name']);
    return $result;
}

// ── 3. Load & process ─────────────────────────────────────
$raw     = $logFile ? parseLogFile($logFile) : [];
$allRows = aggregateRecords($raw);

// ── 4. Employees dropdown ─────────────────────────────────
$employees = [];
foreach ($allRows as $row) {
    if (!isset($employees[$row['userID']])) $employees[$row['userID']] = $row['name'];
}
ksort($employees);

// ── 5. Filters ─────────────────────────────────────────────
$filterEmployee = isset($_GET['employee'])  ? trim($_GET['employee'])  : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

// ── 6. Apply filters ───────────────────────────────────────
$filtered = array_values(array_filter($allRows, function($row) use ($filterEmployee,$filterDateFrom,$filterDateTo) {
    if (!empty($filterEmployee) && $row['userID'] !== $filterEmployee) return false;
    if (!empty($filterDateFrom) && $row['dateISO'] < $filterDateFrom)  return false;
    if (!empty($filterDateTo)   && $row['dateISO'] > $filterDateTo)    return false;
    return true;
}));

// ── 7. Stats ───────────────────────────────────────────────
$totalRecords  = count($filtered);
$lateCount     = count(array_filter($filtered, fn($r) => $r['isLate']));
$earlyCount    = count(array_filter($filtered, fn($r) => $r['isEarlyLeave']));
$totalWorkMins = array_sum(array_column($filtered, 'totalMinutes'));
$avgWorkH      = $totalRecords > 0 ? floor(($totalWorkMins/$totalRecords)/60).'h '.(floor($totalWorkMins/$totalRecords)%60).'m' : '–';

$logFileName = $logFile ? basename($logFile) : '';
$hasData     = !empty($logFile);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report – First In &amp; Last Out</title>
    <meta name="description" content="Modern PHP Attendance Report System – upload, view, filter and export employee attendance data.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if ($uploadSuccess): ?>
    <script>window._justUploaded = true;</script>
    <?php endif; ?>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────── -->
<header class="site-header">
    <div class="header-inner">
        <div class="header-brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="8" cy="15" r="1" fill="currentColor"/><circle cx="12" cy="15" r="1" fill="currentColor"/><circle cx="16" cy="15" r="1" fill="currentColor"/></svg>
            </div>
            <div>
                <h1 class="brand-title">Attendance Report</h1>
                <p class="brand-sub">First In &amp; Last Out Tracker</p>
            </div>
        </div>
        <div class="header-meta">
            <?php if ($hasData): ?>
            <span class="log-active-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <?= htmlspecialchars($logFileName) ?>
            </span>
            <a href="?clear_log=1" class="btn btn-clear" title="Remove uploaded file and reset">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                Clear
            </a>
            <?php endif; ?>
            <span class="header-date"><?= date('D, d M Y – H:i') ?></span>
            <?php if ($hasData): ?>
            <button id="exportPdfBtn" class="btn btn-pdf" onclick="exportPDF()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Export PDF
            </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="main-container">

<!-- ── Upload Card ────────────────────────────────────────── -->
<section class="upload-card glass-card<?= ($hasData && !$uploadSuccess) ? ' upload-collapsed' : '' ?>" id="uploadSection">
    <div class="upload-card-header">
        <h2>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Upload Log File
        </h2>
        <?php if ($hasData): ?>
        <span class="upload-status-ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            File loaded
        </span>
        <button type="button" class="btn btn-toggle-upload" id="toggleUploadBtn" onclick="toggleUploadSection()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            <span id="toggleUploadLabel">Change File</span>
        </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($uploadError)): ?>
    <div class="alert alert-error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span><?= htmlspecialchars($uploadError) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($uploadSuccess): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <span><?= htmlspecialchars($uploadMsg) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="uploadForm" class="upload-form">
        <label for="log_file" class="dropzone" id="dropzone">
            <div class="dropzone-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <p class="dropzone-title" id="dropzoneTitle">
                <?= $hasData ? 'Replace with a new log file' : 'Drag &amp; drop your log file here' ?>
            </p>
            <p class="dropzone-sub">or <span class="dropzone-link">browse to choose</span> &nbsp;·&nbsp; .txt or .log files accepted</p>
            <input type="file" name="log_file" id="log_file" accept=".txt,.log" class="file-input">
        </label>
        <div class="upload-actions">
            <button type="submit" class="btn btn-upload" id="uploadBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload &amp; Parse
            </button>
        </div>
    </form>
</section>

<?php if ($hasData): ?>

    <!-- ── Filter Card ──────────────────────────────────────── -->
    <section class="filter-card glass-card" id="filterSection">
        <div class="filter-card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Filter Attendance
            </h2>
        </div>
        <form method="GET" action="" id="filterForm" class="filter-form">
            <div class="form-group">
                <label for="employee">Employee</label>
                <div class="select-wrapper">
                    <select name="employee" id="employee">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $uid => $name): ?>
                            <option value="<?= htmlspecialchars($uid) ?>" <?= ($filterEmployee===$uid)?'selected':'' ?>>
                                <?= htmlspecialchars($uid) ?> – <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="select-arrow">&#9660;</span>
                </div>
            </div>
            <div class="form-group">
                <label for="date_from">From Date</label>
                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
            </div>
            <div class="form-group">
                <label for="date_to">To Date</label>
                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-search" id="searchBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Search
                </button>
                <a href="?" class="btn btn-reset">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                    Reset
                </a>
            </div>
        </form>
    </section>

    <!-- ── Summary Stats ──────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card glass-card">
            <div class="stat-icon stat-icon-blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-body">
                <p class="stat-label">Total Records</p>
                <p class="stat-value"><?= $totalRecords ?></p>
            </div>
        </div>
        <div class="stat-card glass-card">
            <div class="stat-icon stat-icon-orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="stat-body">
                <p class="stat-label">Late Arrivals</p>
                <p class="stat-value"><?= $lateCount ?></p>
            </div>
        </div>
        <div class="stat-card glass-card">
            <div class="stat-icon stat-icon-red">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            </div>
            <div class="stat-body">
                <p class="stat-label">Early Leaves</p>
                <p class="stat-value"><?= $earlyCount ?></p>
            </div>
        </div>
        <div class="stat-card glass-card">
            <div class="stat-icon stat-icon-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="stat-body">
                <p class="stat-label">Avg Work Hours</p>
                <p class="stat-value"><?= $avgWorkH ?></p>
            </div>
        </div>
    </div>

    <!-- ── Active Filters ─────────────────────────────────── -->
    <?php if (!empty($filterEmployee) || !empty($filterDateFrom) || !empty($filterDateTo)): ?>
    <div class="active-filters glass-card">
        <span class="active-filters-label">Active Filters:</span>
        <?php if (!empty($filterEmployee)): ?>
        <span class="filter-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($employees[$filterEmployee] ?? $filterEmployee) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($filterDateFrom)): ?>
        <span class="filter-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            From: <?= htmlspecialchars($filterDateFrom) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($filterDateTo)): ?>
        <span class="filter-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            To: <?= htmlspecialchars($filterDateTo) ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Report Table ───────────────────────────────────── -->
    <section class="table-section glass-card" id="reportTable">
        <div class="table-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
                Attendance Records
            </h2>
            <span class="record-count"><?= $totalRecords ?> record<?= $totalRecords !== 1 ? 's' : '' ?></span>
        </div>

        <div class="table-scroll-wrapper">
            <table class="report-table" id="attendanceTable">
                <thead>
                    <tr>
                        <th class="th-num">#</th>
                        <th>User ID</th>
                        <th>Employee Name</th>
                        <th>Date</th>
                        <th>First In</th>
                        <th>Last Out</th>
                        <th>Working Hours</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($filtered)): ?>
                    <tr>
                        <td colspan="8" class="no-data">
                            <div class="no-data-inner">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <p>No records found for the selected filters.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filtered as $i => $row): ?>
                    <?php
                        $statusChips = [];
                        if ($row['isLate'])
                            $statusChips[] = '<span class="badge badge-late" title="Late by '.$row['lateBy'].'">Late</span>';
                        if ($row['isEarlyLeave'])
                            $statusChips[] = '<span class="badge badge-early" title="Early leave by '.$row['earlyBy'].'">Early Leave</span>';
                        if (empty($statusChips))
                            $statusChips[] = '<span class="badge badge-ok">On Time</span>';
                        $rowClass = '';
                        if ($row['isLate'] && $row['isEarlyLeave']) $rowClass = 'row-critical';
                        elseif ($row['isLate']) $rowClass = 'row-late';
                        elseif ($row['isEarlyLeave']) $rowClass = 'row-early';
                    ?>
                    <tr class="<?= $rowClass ?>" data-userid="<?= htmlspecialchars($row['userID']) ?>">
                        <td class="td-num"><?= $i + 1 ?></td>
                        <td><span class="uid-badge"><?= htmlspecialchars($row['userID']) ?></span></td>
                        <td class="td-name"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="td-date">
                            <?php [$dd,$mm,$yyyy]=explode('/',$row['date']); echo date('d M Y',mktime(0,0,0,(int)$mm,(int)$dd,(int)$yyyy)); ?>
                        </td>
                        <td class="td-time td-first-in <?= $row['isLate'] ? 'time-late' : 'time-ok' ?>">
                            <span class="time-value"><?= htmlspecialchars($row['firstIn']) ?></span>
                            <?php if ($row['isLate']): ?><span class="time-flag">+<?= $row['lateBy'] ?></span><?php endif; ?>
                        </td>
                        <td class="td-time td-last-out <?= $row['isEarlyLeave'] ? 'time-early' : 'time-ok' ?>">
                            <span class="time-value"><?= htmlspecialchars($row['lastOut']) ?></span>
                            <?php if ($row['isEarlyLeave']): ?><span class="time-flag">-<?= $row['earlyBy'] ?></span><?php endif; ?>
                        </td>
                        <td class="td-hours">
                            <div class="hours-bar-wrap">
                                <span class="hours-text"><?= htmlspecialchars($row['workHours']) ?></span>
                                <?php $pct=min(100,round(($row['totalMinutes']/(9*60))*100)); $barClass=$pct>=100?'bar-full':($pct>=80?'bar-good':'bar-low'); ?>
                                <div class="hours-bar"><div class="hours-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%"></div></div>
                            </div>
                        </td>
                        <td><?= implode(' ', $statusChips) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php endif; ?>

</main>

<!-- ── Footer ────────────────────────────────────────────── -->
<footer class="site-footer">
    <p>Attendance Report System &copy; <?= date('Y') ?> &nbsp;|&nbsp; Generated on <?= date('d M Y, H:i:s') ?></p>
</footer>

<script src="assets/js/app.js"></script>
</body>
</html>
