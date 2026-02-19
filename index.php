<?php
/**
 * Hans Foundation Identity Verification Tool
 * Extracts details from Indian Aadhaar Card images using Tesseract OCR
 */

// Configuration
$uploadDir = __DIR__ . '/uploads/';
$dataDir   = __DIR__ . '/data/';
$csvFile   = $dataDir . 'aadhaar_records.csv';

// Create directories if they don't exist
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
if (!is_dir($dataDir))   mkdir($dataDir, 0755, true);

// Create CSV with headers if it doesn't exist
if (!file_exists($csvFile)) {
    $fp = fopen($csvFile, 'w');
    fputcsv($fp, ['Sr No', 'Name', 'DOB', 'Gender', 'Aadhaar Number', 'Address', 'Image File', 'Processed Date'], ',', '"', '');
    fclose($fp);
}

$message      = '';
$messageType  = '';
$extractedData = null;
$uploadedImage = '';

// ─── PROCESS UPLOAD ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'upload' && isset($_FILES['aadhaar_image'])) {
        $file = $_FILES['aadhaar_image'];

        // Validate file
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/bmp', 'image/tiff'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message     = 'File upload failed. Please try again.';
            $messageType = 'error';
        } elseif (!in_array($file['type'], $allowedTypes)) {
            $message     = 'Invalid file type. Please upload JPG, PNG, BMP, or TIFF images.';
            $messageType = 'error';
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $message     = 'File too large. Maximum size is 10 MB.';
            $messageType = 'error';
        } else {
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'aadhaar_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $_SESSION['uploaded_image'] = $filename;
                $uploadedImage = $filename;
                $message     = 'Image uploaded successfully! Click "Process Aadhaar Card" to extract details.';
                $messageType = 'success';
            } else {
                $message     = 'Failed to save image. Check directory permissions.';
                $messageType = 'error';
            }
        }
    }

    if ($_POST['action'] === 'process' && isset($_POST['image_file'])) {
        $imageFile = basename($_POST['image_file']);          // sanitise
        $imagePath = $uploadDir . $imageFile;

        if (!file_exists($imagePath)) {
            $message     = 'Image not found. Please upload again.';
            $messageType = 'error';
        } else {
            // ── Run Tesseract OCR ──────────────────────────────────────────
            $ocrText = runOCR($imagePath);

            if ($ocrText === false) {
                $message     = 'OCR processing failed. Make sure Tesseract OCR is installed on the server.';
                $messageType = 'error';
            } else {
                // Parse Aadhaar details from OCR text
                $extractedData = parseAadhaarDetails($ocrText);
                $extractedData['image_file'] = $imageFile;
                $extractedData['raw_text']   = $ocrText;

                // Save to CSV
                saveToCSV($csvFile, $extractedData);

                $uploadedImage = $imageFile;
                $message       = 'Aadhaar card processed successfully!';
                $messageType   = 'success';
            }
        }
    }
}

// ─── FUNCTIONS ─────────────────────────────────────────────────────────────

/**
 * Run Tesseract OCR on an image and return extracted text
 */
function runOCR($imagePath) {
    // Try Tesseract with Hindi + English languages
    $outputBase = tempnam(sys_get_temp_dir(), 'ocr_');
    $outputFile = $outputBase . '.txt';

    // Try multiple Tesseract paths (Windows / Linux)
    $tesseractPaths = [
        'tesseract',                                                     // On PATH
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',               // Windows default
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',         // Windows x86
        '/usr/bin/tesseract',                                            // Linux
        '/usr/local/bin/tesseract',                                      // macOS / custom
    ];

    $executed = false;
    foreach ($tesseractPaths as $tesseract) {
        // Try with English + Hindi first, fall back to English only
        $langOptions = ['eng+hin', 'eng'];
        foreach ($langOptions as $lang) {
            $cmd = sprintf(
                '"%s" "%s" "%s" -l %s --psm 3 2>&1',
                $tesseract,
                $imagePath,
                $outputBase,
                $lang
            );
            exec($cmd, $output, $returnCode);
            if ($returnCode === 0 && file_exists($outputFile)) {
                $executed = true;
                break 2;
            }
        }
    }

    if (!$executed || !file_exists($outputFile)) {
        // Clean up
        @unlink($outputBase);
        return false;
    }

    $text = file_get_contents($outputFile);
    @unlink($outputFile);
    @unlink($outputBase);

    return $text;
}

/**
 * Parse Aadhaar card details from OCR text.
 *
 * Uses POSITIONAL LOGIC based on the consistent Aadhaar layout:
 *   Line before DOB  → English Name
 *   DOB line         → Date of Birth
 *   Line after DOB   → Gender (Male / Female)
 */
function parseAadhaarDetails($text) {
    $data = [
        'name'           => '',
        'dob'            => '',
        'gender'         => '',
        'aadhaar_number' => '',
        'address'        => '',
    ];

    // Normalise: split into non-empty trimmed lines
    $lines = array_map('trim', explode("\n", $text));
    $lines = array_filter($lines, function ($l) { return $l !== ''; });
    $lines = array_values($lines);
    $lineCount = count($lines);

    // ── 1. Aadhaar Number  (XXXX XXXX XXXX) ────────────────────────────
    // Pick the FIRST 12-digit group that is NOT a VID
    if (preg_match_all('/\b(\d{4}\s?\d{4}\s?\d{4})\b/', $text, $allMatches)) {
        foreach ($allMatches[1] as $candidate) {
            $data['aadhaar_number'] = preg_replace('/\s+/', ' ', trim($candidate));
            break; // first match is the Aadhaar; VID comes later
        }
    }

    // ── 2. Find the DOB line index (anchor for name & gender) ──────────
    $dobLineIdx = -1;
    foreach ($lines as $i => $line) {
        // Match "DOB: DD/MM/YYYY" or "DOB DD/MM/YYYY" or just a date on a line with DOB/जन्म
        if (preg_match('/DOB|जन्म|Date\s*of\s*Birth/iu', $line)) {
            if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4})/', $line, $dm)) {
                $data['dob'] = $dm[1];
                $dobLineIdx = $i;
                break;
            }
        }
    }
    // Fallback: find any DD/MM/YYYY date
    if ($dobLineIdx === -1) {
        foreach ($lines as $i => $line) {
            if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4})/', $line, $dm)) {
                $data['dob'] = $dm[1];
                $dobLineIdx = $i;
                break;
            }
        }
    }
    // Year-of-Birth fallback
    if (empty($data['dob'])) {
        if (preg_match('/(?:year\s*of\s*birth|yob)\s*[:\-]?\s*(\d{4})/i', $text, $dm)) {
            $data['dob'] = $dm[1];
        }
    }

    // ── 3. Name  (line immediately before the DOB line) ────────────────
    if ($dobLineIdx > 0) {
        // Walk upwards from DOB to find the English name line
        for ($j = $dobLineIdx - 1; $j >= 0; $j--) {
            $candidate = trim($lines[$j]);
            // Skip empty, header, or non-name lines
            if ($candidate === '') continue;
            if (preg_match('/government|india|aadhaar|unique|identification|authority|uid|issued|help/i', $candidate)) continue;
            if (preg_match('/\d/', $candidate)) continue; // has digits → not a name
            // English name: letters & spaces, at least 2 words
            if (preg_match('/^[A-Za-z][A-Za-z\s\.]{2,50}$/', $candidate) && preg_match('/\s/', $candidate)) {
                $data['name'] = ucwords(strtolower(trim($candidate)));
                // Trim "Name:" label if present
                $data['name'] = preg_replace('/^name\s*[:\-]?\s*/i', '', $data['name']);
                $data['name'] = trim($data['name']);
                break;
            }
        }
    }

    // ── 4. Gender  (line immediately after the DOB line) ───────────────
    //    Also search nearby lines and full text for MALE/FEMALE
    if ($dobLineIdx >= 0) {
        // Check a few lines after DOB
        for ($j = $dobLineIdx; $j <= min($dobLineIdx + 3, $lineCount - 1); $j++) {
            if (preg_match('/\b(MALE|FEMALE|Male|Female|male|female|पुरुष|महिला|Transgender)\b/iu', $lines[$j], $gm)) {
                $data['gender'] = normalizeGender($gm[1]);
                break;
            }
        }
    }
    // Fallback: search entire text
    if (empty($data['gender'])) {
        if (preg_match('/\b(MALE|FEMALE|Male|Female|male|female|पुरुष|महिला|Transgender)\b/iu', $text, $gm)) {
            $data['gender'] = normalizeGender($gm[1]);
        }
    }

    // ── 5. Address ─────────────────────────────────────────────────────
    $addressStarted = false;
    $addressLines   = [];
    foreach ($lines as $line) {
        // Start collecting at Address/S-O/D-O/W-O/C-O label or "पता"
        if (preg_match('/^(Address|पता|S\/O|D\/O|W\/O|C\/O)/iu', $line)) {
            $addressStarted = true;
            $addr = preg_replace('/^(Address|पता)\s*[:\-]?\s*/iu', '', $line);
            if (trim($addr)) $addressLines[] = trim($addr);
            continue;
        }
        if ($addressStarted) {
            // Stop at Aadhaar number, VID, or disclaimer lines
            if (preg_match('/\d{4}\s?\d{4}\s?\d{4}/', $line)) break;
            if (preg_match('/^(VID|vid)/i', $line)) break;
            if (preg_match('/aadhaar\s*is\s*proof/i', $line)) break;
            if (preg_match('/Details\s*as\s*on/i', $line)) break;
            $addressLines[] = trim($line);
            if (preg_match('/\d{6}/', $line)) break;  // PIN code → end of address
        }
    }
    // Fallback: look for address-like content with Lane/Road/Colony/District/PO patterns
    if (empty($addressLines)) {
        $fullAddrLines = [];
        $collecting = false;
        foreach ($lines as $line) {
            if (preg_match('/Lane|Road|Colony|Nagar|Street|DIST|PO:|Village|Block|Tehsil|Mandal|Taluk|Address/i', $line)) {
                $collecting = true;
                $addr = preg_replace('/^Address\s*[:\-]?\s*/i', '', $line);
                $fullAddrLines[] = trim($addr);
                continue;
            }
            if ($collecting) {
                // Keep collecting until PIN code or non-address line
                if (preg_match('/\d{4}\s?\d{4}\s?\d{4}/', $line)) break;
                if (preg_match('/^(VID|vid)/i', $line)) break;
                if (preg_match('/aadhaar\s*is\s*proof/i', $line)) break;
                if (preg_match('/Details\s*as\s*on/i', $line)) break;
                $fullAddrLines[] = trim($line);
                if (preg_match('/\d{6}/', $line)) break;  // PIN code → done
            }
        }
        if (!empty($fullAddrLines)) {
            $addressLines = $fullAddrLines;
        }
    }
    if (!empty($addressLines)) {
        $data['address'] = implode(', ', $addressLines);
        // Clean up commas
        $data['address'] = preg_replace('/,\s*,/', ',', $data['address']);
        $data['address'] = trim($data['address'], ', ');
    }

    return $data;
}

/**
 * Normalize gender value to English title case
 */
function normalizeGender($raw) {
    $raw = trim($raw);
    if (in_array($raw, ['पुरुष'])) return 'Male';
    if (in_array($raw, ['महिला'])) return 'Female';
    return ucfirst(strtolower($raw));
}

/**
 * Save extracted data to CSV
 */
function saveToCSV($csvFile, $data) {
    // Determine serial number
    $rows = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $srNo = max(1, count($rows));   // header is row 1

    $fp = fopen($csvFile, 'a');
    fputcsv($fp, [
        $srNo,
        $data['name'],
        $data['dob'],
        $data['gender'],
        $data['aadhaar_number'],
        $data['address'],
        $data['image_file'],
        date('Y-m-d H:i:s'),
    ], ',', '"', '');
    fclose($fp);
}

// ─── Load all records for the table ────────────────────────────────────────
function loadAllRecords($csvFile) {
    $records = [];
    if (($fp = fopen($csvFile, 'r')) !== false) {
        $header = fgetcsv($fp, 0, ',', '"', ''); // skip header
        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            if (count($row) >= 8) {
                $records[] = [
                    'sr_no'          => $row[0],
                    'name'           => $row[1],
                    'dob'            => $row[2],
                    'gender'         => $row[3],
                    'aadhaar_number' => $row[4],
                    'address'        => $row[5],
                    'image_file'     => $row[6],
                    'processed_date' => $row[7],
                ];
            }
        }
        fclose($fp);
    }
    return $records;
}

$allRecords = loadAllRecords($csvFile);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identity Verification | The Hans Foundation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="https://thehansfoundation.org/wp-content/uploads/2024/05/logo-1.svg">
</head>
<body>

<!-- ─── TOP BAR ─────────────────────────────────────────────────────────── -->
<div class="top-bar">
    <div class="top-bar-inner">
        <span>Aadhaar Identity Verification Portal</span>
        <span class="top-bar-right">
            <a href="https://thehansfoundation.org/" target="_blank" rel="noopener">thehansfoundation.org</a>
        </span>
    </div>
</div>

<!-- ─── HEADER ──────────────────────────────────────────────────────────── -->
<header class="main-header">
    <div class="header-inner">
        <a href="/" class="logo-link">
            <img src="https://thehansfoundation.org/wp-content/uploads/2024/05/logo-1.svg"
                 alt="The Hans Foundation" class="logo-img">
        </a>
        <div class="header-title-area">
            <h1 class="page-title">Identity Verification Tool</h1>
            <p class="page-subtitle">Secure Aadhaar Card Data Extraction &amp; Records Management</p>
        </div>
        <!-- Mobile menu toggle -->
        <button class="mobile-menu-btn" onclick="document.querySelector('.nav-links').classList.toggle('open')" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
        <nav class="nav-links">
            <a href="https://thehansfoundation.org/health" target="_blank">Health</a>
            <a href="https://thehansfoundation.org/disability" target="_blank">Disability</a>
            <a href="https://thehansfoundation.org/education" target="_blank">Education</a>
            <a href="https://thehansfoundation.org/contact" target="_blank">Contact</a>
        </nav>
    </div>
</header>

<!-- ─── HERO BANNER ─────────────────────────────────────────────────────── -->
<section class="hero-banner">
    <div class="hero-inner">
        <div class="hero-text">
            <h2>Empowering Communities Through Verified Identity</h2>
            <p>Upload an Aadhaar card image to instantly extract and verify beneficiary details. Fast, secure, and reliable.</p>
        </div>
        <div class="hero-stats">
            <div class="stat-item">
                <span class="stat-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                </span>
                <span class="stat-label">Beneficiaries</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </span>
                <span class="stat-label">Records Saved</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </span>
                <span class="stat-label">Secure &amp; Private</span>
            </div>
        </div>
    </div>
</section>

<!-- ─── MAIN CONTENT ────────────────────────────────────────────────────── -->
<main class="container">

    <!-- ── Upload Section ────────────────────────────────────────────── -->
    <section class="card upload-section" id="upload">
        <div class="card-header">
            <div class="card-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div>
                <h2>Upload Aadhaar Card</h2>
                <p class="section-desc">Upload a clear, well-lit image of the Aadhaar card (front or back). Supported: JPG, PNG, BMP, TIFF.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span class="alert-icon">
                    <?php if ($messageType === 'success'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?php endif; ?>
                </span>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm" class="upload-form">
            <input type="hidden" name="action" value="upload">
            <div class="drop-zone" id="dropZone">
                <input type="file" name="aadhaar_image" id="aadhaar_image" accept="image/*" required
                       onchange="handleFileSelect(this)">
                <div class="drop-zone-content">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <p class="drop-text" id="dropText">Drag &amp; drop Aadhaar card image here</p>
                    <p class="drop-sub">or click to browse files</p>
                    <span class="drop-formats">JPG, PNG, BMP, TIFF &middot; Max 10 MB</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload Image
            </button>
        </form>

        <?php if ($uploadedImage && $messageType === 'success' && !$extractedData): ?>
            <div class="preview-section">
                <h3>Image Preview</h3>
                <div class="preview-img-wrapper">
                    <img src="uploads/<?= htmlspecialchars($uploadedImage) ?>" alt="Aadhaar Preview" class="preview-img">
                </div>
                <form method="POST" class="process-form">
                    <input type="hidden" name="action" value="process">
                    <input type="hidden" name="image_file" value="<?= htmlspecialchars($uploadedImage) ?>">
                    <button type="submit" class="btn btn-accent">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Process Aadhaar Card
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Extracted Data Report ─────────────────────────────────────── -->
    <?php if ($extractedData): ?>
    <section class="card report-section" id="report">
        <div class="card-header">
            <div class="card-icon card-icon-success">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <h2>Extraction Report</h2>
                <p class="section-desc">Verified identity details extracted from the uploaded Aadhaar card.</p>
            </div>
        </div>
        <div class="report-grid">
            <div class="report-details">
                <div class="detail-row">
                    <span class="detail-label">Full Name</span>
                    <span class="detail-value"><?= htmlspecialchars($extractedData['name'] ?: 'Not detected') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date of Birth</span>
                    <span class="detail-value"><?= htmlspecialchars($extractedData['dob'] ?: 'Not detected') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Gender</span>
                    <span class="detail-value"><?= htmlspecialchars($extractedData['gender'] ?: 'Not detected') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Aadhaar Number</span>
                    <span class="detail-value aadhaar-num"><?= htmlspecialchars($extractedData['aadhaar_number'] ?: 'Not detected') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Address</span>
                    <span class="detail-value"><?= htmlspecialchars($extractedData['address'] ?: 'Not detected') ?></span>
                </div>

                <?php if (!empty($extractedData['raw_text'])): ?>
                <details class="raw-text-toggle">
                    <summary>View Raw OCR Text</summary>
                    <pre class="raw-text"><?= htmlspecialchars($extractedData['raw_text']) ?></pre>
                </details>
                <?php endif; ?>
            </div>
            <div class="report-image">
                <h3>Uploaded Document</h3>
                <img src="uploads/<?= htmlspecialchars($extractedData['image_file']) ?>" alt="Aadhaar Card" class="report-img">
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── All Records Table ─────────────────────────────────────────── -->
    <?php if (!empty($allRecords)): ?>
    <section class="card records-section" id="records">
        <div class="card-header">
            <div class="card-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div>
                <h2>Processed Records</h2>
                <p class="section-desc"><?= count($allRecords) ?> record(s) saved to <code>data/aadhaar_records.csv</code></p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="records-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th>Aadhaar Number</th>
                        <th>Address</th>
                        <th>Image</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allRecords as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['sr_no']) ?></td>
                        <td class="name-cell"><?= htmlspecialchars($record['name']) ?></td>
                        <td><?= htmlspecialchars($record['dob']) ?></td>
                        <td><?= htmlspecialchars($record['gender']) ?></td>
                        <td class="aadhaar-num"><?= htmlspecialchars($record['aadhaar_number']) ?></td>
                        <td class="address-cell"><?= htmlspecialchars($record['address']) ?></td>
                        <td>
                            <img src="uploads/<?= htmlspecialchars($record['image_file']) ?>"
                                 alt="Aadhaar" class="thumb-img"
                                 onclick="window.open(this.src, '_blank')">
                        </td>
                        <td class="date-cell"><?= htmlspecialchars($record['processed_date']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

</main>

<!-- ─── FOOTER ──────────────────────────────────────────────────────────── -->
<footer class="main-footer">
    <div class="footer-inner">
        <div class="footer-grid">
            <div class="footer-brand">
                <img src="https://thehansfoundation.org/wp-content/uploads/2022/03/logo-footer.svg"
                     alt="The Hans Foundation" class="footer-logo">
                <p class="footer-about">Established in 2009, The Hans Foundation works towards Health and Wellbeing of marginalized and under-served groups such as children, women and persons with disabilities.</p>
            </div>
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="https://thehansfoundation.org/health" target="_blank">Health</a></li>
                    <li><a href="https://thehansfoundation.org/disability" target="_blank">Disability</a></li>
                    <li><a href="https://thehansfoundation.org/education" target="_blank">Education</a></li>
                    <li><a href="https://thehansfoundation.org/livelihood" target="_blank">Livelihood</a></li>
                    <li><a href="https://thehansfoundation.org/climate-action" target="_blank">Climate Action</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Contact</h4>
                <ul>
                    <li>Wing B, 7th Floor, Milestone Experion Centre</li>
                    <li>Sector 15 Part 2, Gurugram, Haryana - 122001</li>
                    <li>Phone: (0124) 6904545</li>
                    <li><a href="mailto:info@thfmail.com">info@thfmail.com</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Disclaimer</h4>
                <p class="footer-disclaimer">This tool is intended for authorised verification purposes only. All Aadhaar data is processed locally and stored securely on the server.</p>
                <div class="footer-social">
                    <a href="https://www.facebook.com/TheHansFoundation" target="_blank" aria-label="Facebook">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    </a>
                    <a href="https://www.instagram.com/thehansfoundation/" target="_blank" aria-label="Instagram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                    </a>
                    <a href="https://in.linkedin.com/company/thehansfoundation" target="_blank" aria-label="LinkedIn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                    </a>
                    <a href="https://www.youtube.com/user/TheHansFoundation" target="_blank" aria-label="YouTube">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19.1c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="#fff"/></svg>
                    </a>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>COPYRIGHT &copy; <?= date('Y') ?> The Hans Foundation. All Rights Reserved. | 
            <a href="https://thehansfoundation.org/terms-conditions" target="_blank">Terms &amp; Conditions</a> | 
            <a href="https://thehansfoundation.org/privacy-policy" target="_blank">Privacy Policy</a> |
            Powered by Tesseract OCR v1.0</p>
        </div>
    </div>
</footer>

<!-- ─── JavaScript ──────────────────────────────────────────────────────── -->
<script>
// Drag & drop + file select
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('aadhaar_image');
const dropText = document.getElementById('dropText');
const uploadBtn = document.getElementById('uploadBtn');

function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        dropText.textContent = input.files[0].name;
        dropZone.classList.add('has-file');
        uploadBtn.disabled = false;
    }
}

if (dropZone) {
    ['dragenter', 'dragover'].forEach(e => {
        dropZone.addEventListener(e, function(ev) { ev.preventDefault(); dropZone.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(e => {
        dropZone.addEventListener(e, function(ev) { ev.preventDefault(); dropZone.classList.remove('drag-over'); });
    });
    dropZone.addEventListener('drop', function(ev) {
        if (ev.dataTransfer.files.length) {
            fileInput.files = ev.dataTransfer.files;
            handleFileSelect(fileInput);
        }
    });
    dropZone.addEventListener('click', function() { fileInput.click(); });
}

// Smooth scroll to report after processing
<?php if ($extractedData): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('report')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
</script>

</body>
</html>
