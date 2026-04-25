# Attendance Report System

A complete PHP-based Attendance Report web system that reads biometric attendance log files, processes them, and displays a modern filterable report with PDF export.

---

## Features

- **LOG.txt Parsing** — Reads the fixed-width biometric log file format
- **First In / Last Out** — Calculates daily first entry and last exit per employee
- **Late Arrival Detection** — Flags employees arriving after 08:30
- **Early Leave Detection** — Flags employees leaving before 17:00
- **Total Working Hours** — Calculates and visualises hours worked per day
- **Employee Filter** — Dropdown to filter by specific employee
- **Date Range Filter** — From/To date range filtering
- **PDF Export** — Exports filtered results to a professional A4 landscape PDF (via Dompdf)
- **Modern Dark UI** — Glassmorphism design with animated stats and responsive layout

---

## Prerequisites

| Requirement | Version |
|-------------|---------|
| PHP         | ≥ 7.4   |
| Composer    | ≥ 2.x   |

---

## Installation

### 1. Clone / copy the project

```bash
git clone <repo-url> attendance-report
cd attendance-report
```

### 2. Install PHP dependencies (Dompdf)

```bash
composer install
```

> If Composer is not installed, download it from https://getcomposer.org/download/

### 3. Place your LOG.txt in the project root

The system expects a `LOG.txt` file in the root directory (same folder as `index.php`).

### 4. Start a local PHP server

**Option A – PHP built-in server:**
```bash
php -S localhost:8080
```
Then open http://localhost:8080 in your browser.

**Option B – XAMPP / WAMP / MAMP:**
- Copy the entire project folder into your `htdocs` (XAMPP) or `www` (WAMP) directory.
- Start Apache.
- Visit `http://localhost/attendance-report/`

---

## File Structure

```
attendance-report/
├── index.php           ← Main report page
├── export_pdf.php      ← PDF export endpoint
├── LOG.txt             ← Your attendance log file
├── composer.json       ← PHP dependency config
├── vendor/             ← Installed by Composer (Dompdf)
└── assets/
    ├── css/
    │   ├── style.css   ← Main dark-themed stylesheet
    │   └── print.css   ← PDF stylesheet (used by Dompdf)
    └── js/
        └── app.js      ← Client-side interactions
```

---

## LOG.txt Format

The system parses fixed-width / multi-space-delimited lines in this format:

```
ID  UserID    EntryDate   EntryTime  00  Name
04  070365    20/04/2026  06:21      00  S V L Hashan
01  036696    20/04/2026  07:04      00  A B P N ATHTHANAYA
```

- **ID** — Reader/device ID (ignored)
- **UserID** — Employee ID (used as primary key)
- **EntryDate** — DD/MM/YYYY format
- **EntryTime** — HH:MM format
- **"00"** — Seconds field (ignored)
- **Name** — Employee name (may be absent on some records)

---

## Usage

1. Open the system in your browser
2. Use the **filter panel** to narrow results by employee and/or date range
3. Click **Search** to apply filters
4. Click **Export PDF** to download the filtered results as a PDF

---

## Business Rules

| Rule | Threshold |
|------|-----------|
| Late Arrival | First In > 08:30 |
| Early Leave  | Last Out < 17:00 |
| Working Day  | 9 hours (used for bar chart) |

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "LOG.txt not found" warning | Place `LOG.txt` in the project root |
| PDF export fails | Run `composer install` to install Dompdf |
| Blank page | Check PHP error logs; ensure PHP ≥ 7.4 |
| Garbled names | LOG.txt should be UTF-8 or ANSI encoded |
