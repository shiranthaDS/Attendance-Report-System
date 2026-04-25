# Attendance Report System – Full Workflow & Technical Documentation

## 📋 Overview

The **Attendance Report System** is a single-page PHP web application that parses a biometric/RFID attendance log file, aggregates the raw punch records into meaningful **First In / Last Out** entries per employee per day, and presents them in a filterable, exportable report.

---

## 🏗️ Project Structure

```
Attendance-Report-System/
├── index.php            # Main app – upload handler + report UI
├── export_pdf.php       # PDF export via Dompdf
├── composer.json        # PHP dependency manager config
├── vendor/              # Dompdf library (installed by Composer)
├── uploads/             # Runtime: stores the uploaded LOG.txt
│   └── .gitkeep         # Keeps folder in Git (actual files excluded)
└── assets/
    ├── css/
    │   ├── style.css    # Main dark-themed glassmorphism styles
    │   └── print.css    # Dompdf-compatible PDF print styles
    └── js/
        └── app.js       # Client-side interactions
```

---

## 🔄 Full Application Workflow

```
User visits index.php
        │
        ▼
┌──────────────────────────────┐
│  Is a log file already       │
│  uploaded (uploads/LOG.txt)? │
└──────────────┬───────────────┘
               │ NO                          YES
               ▼                              ▼
     Show upload dropzone         Skip to report (section
     (section expanded)           collapsed, data shown)
               │
               │ User drags or selects a .txt/.log file
               ▼
     POST → index.php (multipart/form-data)
               │
               ▼
┌──────────────────────────────┐
│  PHP Upload Handler          │
│  • Validates file extension  │
│  • Moves to uploads/LOG.txt  │
│  • Sets $_SESSION['log_path']│
└──────────────┬───────────────┘
               │
               ▼
     Page re-renders:
     • Success alert shown (1.8 s)
     • Upload section auto-collapses (JS)
     • Stats, filters & table appear below
               │
               ▼
┌──────────────────────────────┐
│  PHP Log Parser              │
│  • Reads file line-by-line   │
│  • Regex extracts fields     │
│  • Builds raw records array  │
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│  PHP Aggregator              │
│  • Groups by UserID + Date   │
│  • Sorts times asc           │
│  • Picks first & last punch  │
│  • Calculates work hours     │
│  • Flags late / early leave  │
└──────────────┬───────────────┘
               │
               ▼
     User applies filters
     (employee / date range)
               │
               ▼
     Filtered table rendered
     + Summary stat cards
               │
         ┌─────┴──────┐
         │            │
         ▼            ▼
   Clear File     Export PDF
   (deletes       (export_pdf.php
   uploads/        → Dompdf renders
   LOG.txt,         print.css layout)
   clears session)
```

---

## ⚙️ Technical Stack

| Layer | Technology | Purpose |
|---|---|---|
| **Server** | PHP 8.x | Upload handling, log parsing, HTML rendering |
| **Sessions** | `$_SESSION` | Remembers the uploaded file path across page loads |
| **File Storage** | `uploads/LOG.txt` | Persists the uploaded log on the server |
| **PDF Generation** | Dompdf (via Composer) | Renders the attendance table as a PDF |
| **Styling** | Vanilla CSS (Glassmorphism) | Dark-themed UI with blur, gradients, and animations |
| **Typography** | Google Fonts – Inter | Clean, modern sans-serif font |
| **JavaScript** | Vanilla JS (ES6+) | Drag-and-drop, auto-collapse, row highlights, PDF trigger |

---

## 🔬 Key PHP Techniques

### 1. File Upload Handling
```php
// Validates extension, then moves to a safe location
if ($file['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['txt', 'log', ''])) {
        move_uploaded_file($file['tmp_name'], $uploadDir . 'LOG.txt');
    }
}
```

### 2. Log File Parsing (Regex)
```php
// Matches lines like: "04 070365 20/04/2026 06:21 00 S V L Hashan"
$pattern = '/^(\S+)\s+(\S+)\s+(\d{2}\/\d{2}\/\d{4})\s+(\d{2}:\d{2})\s+\d{2}\s*(.*)$/';
preg_match($pattern, trim($line), $m);
```

### 3. Record Aggregation
- Groups all punches by `UserID_Date` key
- Sorts punches chronologically → `first = IN`, `last = OUT`
- Computes total minutes = `(lastOut - firstIn)` in minutes
- Late threshold: **08:30** · Early-leave threshold: **17:00**

### 4. Session-Based File Memory
```php
session_start();
$_SESSION['log_path'] = $uploadDir . 'LOG.txt'; // stored on upload
// On clear:
unlink($_SESSION['log_path']);
unset($_SESSION['log_path']);
```

### 5. PDF Export (Dompdf)
`export_pdf.php` reads the same session log path, re-runs parse + aggregate + filter with the same GET params, builds a standalone HTML document using `print.css`, and streams it as `application/pdf`.

---

## 🎨 CSS / UI Techniques

| Technique | Where Used |
|---|---|
| **Glassmorphism** | Cards – `backdrop-filter: blur(16px)` + semi-transparent background |
| **CSS Custom Properties** | All colors, radii, shadows defined in `:root` |
| **Grid & Flexbox** | Layout for stats cards, filter form, table header |
| **`max-height` transition** | Upload section collapse/expand animation |
| **`@keyframes`** | Spinner on loading buttons, `fadeInUp` on table rows |
| **CSS `sticky` thead** | Table header stays visible while scrolling |
| **Progress bars** | Hours bar filled proportionally to a 9-hour working day |

---

## 🟨 JavaScript Techniques

| Feature | Mechanism |
|---|---|
| **Drag & drop upload** | `dragenter`, `dragover`, `drop` events on the dropzone label |
| **File-selected feedback** | `change` event on `<input type="file">` updates the dropzone text |
| **Auto-collapse after upload** | PHP injects `window._justUploaded = true`, JS reads it and calls `classList.add('upload-collapsed')` after 1.8 s |
| **Toggle upload panel** | `toggleUploadSection()` – `classList.toggle('upload-collapsed')` |
| **Row highlighting** | Click on any row toggles `.row-highlighted` for all rows of same UserID |
| **Fade-in rows** | CSS animation + staggered `animation-delay` per `nth-child` |
| **PDF export** | Passes current URL filter params to `export_pdf.php`, opens in new tab |
| **Spinning loader** | `.spin` class + `@keyframes spin` injected via `createElement('style')` |

---

## 📥 Upload Section – State Machine

```
State A: No file uploaded
  → Upload card: EXPANDED
  → Stats / Table: HIDDEN

State B: File just uploaded (POST success)
  → Upload card: EXPANDED + success alert visible
  → After 1.8 s → auto-collapses to State C
  → Stats / Table: VISIBLE

State C: File loaded (subsequent page loads / after collapse)
  → Upload card: COLLAPSED (header only shown)
  → "Change File ▾" button toggles to State D
  → Stats / Table: VISIBLE

State D: User manually expands upload card
  → Upload card: EXPANDED (dropzone visible)
  → "Hide ▴" button collapses back to State C
```

---

## 📤 Log File Format

The system expects a space-delimited text file. Each data row:

```
<RecordID>  <UserID>  <DD/MM/YYYY>  <HH:MM>  <00>  <Full Name>
04          070365    20/04/2026    06:21    00    S V L Hashan
```

- **RecordID** – ignored (record sequence number)
- **UserID** – employee identifier (used for grouping)
- **Date** – `DD/MM/YYYY` format
- **Time** – `HH:MM` (24-hour)
- **`00`** – fixed field, ignored
- **Name** – optional; first non-empty name found is used

---

## 🔒 Security Considerations

| Risk | Mitigation |
|---|---|
| Arbitrary file upload | Extension whitelist (`.txt`, `.log` only) |
| Path traversal | File always saved as fixed name `uploads/LOG.txt` |
| XSS | All output through `htmlspecialchars()` |
| Large file DoS | PHP's `upload_max_filesize` / `post_max_size` limits apply |

---

## 🚀 Running Locally

```bash
# Install Dompdf
composer install

# Start PHP dev server
php -S localhost:9000

# Visit
open http://localhost:9000
```
