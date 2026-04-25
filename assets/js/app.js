// ============================================================
//  Attendance Report System – app.js
//  Client-side interactions: upload dropzone, search, PDF export
// ============================================================

/* ── Drag & drop upload zone ─────────────────────────────── */
const dropzone  = document.getElementById('dropzone');
const fileInput = document.getElementById('log_file');
const dropTitle = document.getElementById('dropzoneTitle');
const uploadBtn = document.getElementById('uploadBtn');
const uploadForm = document.getElementById('uploadForm');

if (dropzone && fileInput) {
    ['dragenter','dragover'].forEach(evt => {
        dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    });
    ['dragleave','drop'].forEach(evt => {
        dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.remove('drag-over'); });
    });
    dropzone.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if (files.length) {
            fileInput.files = files;
            handleFileSelected(files[0]);
        }
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) handleFileSelected(fileInput.files[0]);
    });
    function handleFileSelected(file) {
        if (dropTitle) dropTitle.textContent = '✓ ' + file.name + ' selected';
        if (uploadBtn) { uploadBtn.style.background = 'linear-gradient(135deg,#6c63ff,#00d4aa)'; }
    }
}

/* ── Upload form loading state ───────────────────────────── */
if (uploadForm && uploadBtn) {
    uploadForm.addEventListener('submit', () => {
        uploadBtn.innerHTML = `
            <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 12a9 9 0 1 1-9-9"/>
            </svg>
            Uploading…`;
        uploadBtn.disabled = true;
    });
}

/* ── Toggle upload section open/closed ───────────────────── */
function toggleUploadSection() {
    const section = document.getElementById('uploadSection');
    const label   = document.getElementById('toggleUploadLabel');
    if (!section) return;
    const isCollapsed = section.classList.toggle('upload-collapsed');
    if (label) label.textContent = isCollapsed ? 'Change File' : 'Hide';
}

/* ── Auto-collapse after successful upload (1.8 s delay) ─── */
if (window._justUploaded) {
    setTimeout(() => {
        const section = document.getElementById('uploadSection');
        const label   = document.getElementById('toggleUploadLabel');
        if (section) section.classList.add('upload-collapsed');
        if (label)   label.textContent = 'Change File';
    }, 1800);
}

/* ── Search button loading state ─────────────────────────── */
const filterForm = document.getElementById('filterForm');
const searchBtn  = document.getElementById('searchBtn');

if (filterForm && searchBtn) {
    filterForm.addEventListener('submit', () => {
        searchBtn.innerHTML = `
            <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 12a9 9 0 1 1-9-9"/>
            </svg>
            Searching…`;
        searchBtn.disabled = true;
    });
}

/* ── PDF Export ──────────────────────────────────────────── */
function exportPDF() {
    const btn = document.getElementById('exportPdfBtn');
    if (!btn) return;

    // Build URL with current filter params
    const params = new URLSearchParams(window.location.search);
    const exportUrl = 'export_pdf.php?' + params.toString();

    btn.innerHTML = `
        <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 12a9 9 0 1 1-9-9"/>
        </svg>
        Generating…`;
    btn.disabled = true;

    // Open in new tab / trigger download
    window.open(exportUrl, '_blank');

    setTimeout(() => {
        btn.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
              <line x1="16" y1="13" x2="8" y2="13"/>
              <line x1="16" y1="17" x2="8" y2="17"/>
              <polyline points="10 9 9 9 8 9"/>
            </svg>
            Export PDF`;
        btn.disabled = false;
    }, 2000);
}

/* ── Spinning animation for loading SVG ──────────────────── */
const style = document.createElement('style');
style.textContent = `
    @keyframes spin { to { transform: rotate(360deg); } }
    .spin { animation: spin 0.7s linear infinite; }
`;
document.head.appendChild(style);

/* ── Row click – highlight by UserID ─────────────────────── */
document.querySelectorAll('#attendanceTable tbody tr').forEach(row => {
    row.addEventListener('click', () => {
        const uid = row.dataset.userid;
        document.querySelectorAll('#attendanceTable tbody tr').forEach(r => {
            if (r.dataset.userid === uid) {
                r.classList.toggle('row-highlighted');
            }
        });
    });
});

/* ── Fade-in rows on load ────────────────────────────────── */
const fadeStyle = document.createElement('style');
fadeStyle.textContent = `
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    #attendanceTable tbody tr {
        animation: fadeInUp .3s ease both;
    }
    #attendanceTable tbody tr:nth-child(1)  { animation-delay: .05s; }
    #attendanceTable tbody tr:nth-child(2)  { animation-delay: .08s; }
    #attendanceTable tbody tr:nth-child(3)  { animation-delay: .11s; }
    #attendanceTable tbody tr:nth-child(4)  { animation-delay: .14s; }
    #attendanceTable tbody tr:nth-child(5)  { animation-delay: .17s; }
    #attendanceTable tbody tr:nth-child(n+6) { animation-delay: .20s; }

    #attendanceTable tbody tr.row-highlighted {
        outline: 2px solid rgba(108,99,255,.6);
        outline-offset: -1px;
    }
    #attendanceTable tbody tr { cursor: pointer; }
`;
document.head.appendChild(fadeStyle);
