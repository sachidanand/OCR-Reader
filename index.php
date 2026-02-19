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
    <title>Hans Foundation Identity Verification Tool</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ─── HEADER ──────────────────────────────────────────────────────────── -->
<header class="main-header">
    <div class="header-content">
        <div class="logo-area">
            <div class="logo-icon">
                <svg viewBox="0 0 48 48" width="48" height="48">
                    <circle cx="24" cy="24" r="22" fill="#1a5276" stroke="#fff" stroke-width="2"/>
                    <text x="24" y="30" text-anchor="middle" fill="#fff" font-size="18" font-weight="bold">HF</text>
                </svg>
            </div>
            <div class="header-text">
                <h1>Hans Foundation Identity Verification Tool</h1>
                <p class="subtitle">Aadhaar Card Data Extraction &amp; Management System</p>
            </div>
        </div>
    </div>
</header>

<!-- ─── MAIN CONTENT ────────────────────────────────────────────────────── -->
<main class="container">

    <!-- ── Upload Section ────────────────────────────────────────────── -->
    <section class="card upload-section">
        <h2><span class="section-icon">&#128196;</span> Upload Aadhaar Card</h2>
        <p class="section-desc">Upload a clear image of the Aadhaar card (front or back). Supported formats: JPG, PNG, BMP, TIFF.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Upload -->
        <form method="POST" enctype="multipart/form-data" id="uploadForm" class="upload-form">
            <input type="hidden" name="action" value="upload">
            <div class="file-input-wrapper">
                <label for="aadhaar_image" class="file-label">
                    <span class="file-icon">&#128247;</span>
                    <span class="file-text" id="fileText">Choose Aadhaar Card Image...</span>
                </label>
                <input type="file" name="aadhaar_image" id="aadhaar_image" accept="image/*" required
                       onchange="document.getElementById('fileText').textContent = this.files[0]?.name || 'Choose Aadhaar Card Image...'">
            </div>
            <button type="submit" class="btn btn-upload">
                <span>&#128228;</span> Upload Image
            </button>
        </form>

        <!-- Step 2: Process -->
        <?php if ($uploadedImage && $messageType === 'success' && !$extractedData): ?>
            <div class="preview-section">
                <h3>Preview</h3>
                <img src="uploads/<?= htmlspecialchars($uploadedImage) ?>" alt="Aadhaar Preview" class="preview-img">
                <form method="POST" class="process-form">
                    <input type="hidden" name="action" value="process">
                    <input type="hidden" name="image_file" value="<?= htmlspecialchars($uploadedImage) ?>">
                    <button type="submit" class="btn btn-process">
                        <span>&#9881;</span> Process Aadhaar Card
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Extracted Data Report ─────────────────────────────────────── -->
    <?php if ($extractedData): ?>
    <section class="card report-section">
        <h2><span class="section-icon">&#128202;</span> Extraction Report</h2>
        <div class="report-grid">
            <div class="report-details">
                <table class="detail-table">
                    <tr>
                        <th>Name</th>
                        <td><?= htmlspecialchars($extractedData['name'] ?: 'Not detected') ?></td>
                    </tr>
                    <tr>
                        <th>Date of Birth</th>
                        <td><?= htmlspecialchars($extractedData['dob'] ?: 'Not detected') ?></td>
                    </tr>
                    <tr>
                        <th>Gender</th>
                        <td><?= htmlspecialchars($extractedData['gender'] ?: 'Not detected') ?></td>
                    </tr>
                    <tr>
                        <th>Aadhaar Number</th>
                        <td class="aadhaar-num"><?= htmlspecialchars($extractedData['aadhaar_number'] ?: 'Not detected') ?></td>
                    </tr>
                    <tr>
                        <th>Address</th>
                        <td><?= htmlspecialchars($extractedData['address'] ?: 'Not detected') ?></td>
                    </tr>
                </table>

                <?php if (!empty($extractedData['raw_text'])): ?>
                <details class="raw-text-toggle">
                    <summary>View Raw OCR Text</summary>
                    <pre class="raw-text"><?= htmlspecialchars($extractedData['raw_text']) ?></pre>
                </details>
                <?php endif; ?>
            </div>
            <div class="report-image">
                <h3>Aadhaar Card Photo</h3>
                <img src="uploads/<?= htmlspecialchars($extractedData['image_file']) ?>" alt="Aadhaar Card" class="report-img">
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── All Records Table ─────────────────────────────────────────── -->
    <?php if (!empty($allRecords)): ?>
    <section class="card records-section">
        <h2><span class="section-icon">&#128203;</span> All Processed Records</h2>
        <p class="section-desc">Records are saved in <code>data/aadhaar_records.csv</code></p>
        <div class="table-responsive">
            <table class="records-table">
                <thead>
                    <tr>
                        <th>Sr No</th>
                        <th>Name</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th>Aadhaar Number</th>
                        <th>Address</th>
                        <th>Image</th>
                        <th>Processed Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allRecords as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['sr_no']) ?></td>
                        <td><?= htmlspecialchars($record['name']) ?></td>
                        <td><?= htmlspecialchars($record['dob']) ?></td>
                        <td><?= htmlspecialchars($record['gender']) ?></td>
                        <td class="aadhaar-num"><?= htmlspecialchars($record['aadhaar_number']) ?></td>
                        <td class="address-cell"><?= htmlspecialchars($record['address']) ?></td>
                        <td>
                            <img src="uploads/<?= htmlspecialchars($record['image_file']) ?>"
                                 alt="Aadhaar" class="thumb-img"
                                 onclick="window.open(this.src, '_blank')">
                        </td>
                        <td><?= htmlspecialchars($record['processed_date']) ?></td>
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
    <div class="footer-content">
        <div class="footer-top">
            <div class="footer-brand">
                <h3>Hans Foundation</h3>
                <p>Identity Verification Tool</p>
            </div>
            <div class="footer-links">
                <p><strong>Disclaimer:</strong> This tool is intended for authorised verification purposes only. 
                All Aadhaar data is processed locally and stored securely on the server.</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> Hans Foundation. All rights reserved. | 
               Powered by Tesseract OCR | Version 1.0</p>
        </div>
    </div>
</footer>

</body>
</html>
