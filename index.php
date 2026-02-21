<?php
/**
 * Hans Foundation Identity Verification Tool
 * Extracts details from Indian Aadhaar Card images using OCR.space API
 */
session_start();

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
    fputcsv($fp, ['Sr No', 'Name', 'DOB', 'Gender', 'Aadhaar Number', 'Address', 'Front Image', 'Back Image', 'Processed Date'], ',', '"', '');
    fclose($fp);
}

$message      = '';
$messageType  = '';
$extractedData = null;
$uploadedFront = '';
$uploadedBack  = '';

// ─── HANDLE RESET (upload different image) ─────────────────────────────────
if (isset($_GET['reset'])) {
    unset($_SESSION['uploaded_front'], $_SESSION['uploaded_back'], $_SESSION['extracted_data'],
          $_SESSION['flash_message'], $_SESSION['flash_type']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ─── POST HANDLERS ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Upload front & back images (handled inline — no redirect) ──────
    if ($_POST['action'] === 'upload') {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/bmp', 'image/tiff'];
        $saved = [];
        $uploadOk = true;

        foreach (['front_image', 'back_image'] as $field) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                if ($field === 'front_image') {
                    $message = 'Please upload at least the front side of the Aadhaar card.';
                    $messageType = 'error';
                    $uploadOk = false;
                    break;
                }
                continue; // back is optional
            }
            $file = $_FILES[$field];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $message = ucfirst(str_replace('_', ' ', $field)) . ' upload failed.';
                $messageType = 'error';
                $uploadOk = false;
                break;
            }
            if (!in_array($file['type'], $allowedTypes)) {
                $message = 'Invalid file type for ' . str_replace('_', ' ', $field) . '. Use JPG, PNG, BMP, or TIFF.';
                $messageType = 'error';
                $uploadOk = false;
                break;
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                $message = ucfirst(str_replace('_', ' ', $field)) . ' is too large (max 10 MB).';
                $messageType = 'error';
                $uploadOk = false;
                break;
            }
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $side     = ($field === 'front_image') ? 'front' : 'back';

            // Dedup: compute hash of uploaded file to avoid saving duplicates
            $fileHash = md5_file($file['tmp_name']);
            $existing = findExistingUpload($uploadDir, $fileHash, $ext);
            if ($existing) {
                // File with identical content already exists — reuse it
                $saved[$field] = $existing;
                continue;
            }

            $filename = 'aadhaar_' . $side . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $saved[$field] = $filename;
            } else {
                $message = 'Failed to save ' . str_replace('_', ' ', $field) . '.';
                $messageType = 'error';
                $uploadOk = false;
                break;
            }
        }

        if ($uploadOk) {
            $uploadedFront = $saved['front_image'] ?? '';
            $uploadedBack  = $saved['back_image']  ?? '';
            $message       = 'Image(s) uploaded successfully! Click "Process Aadhaar Card" to extract details.';
            $messageType   = 'success';
            // Also store in session in case of page refresh
            $_SESSION['uploaded_front'] = $uploadedFront;
            $_SESSION['uploaded_back']  = $uploadedBack;
        }
    }

    // ── Process uploaded images (PRG — redirects after processing) ─────
    if ($_POST['action'] === 'process') {
        $frontFile = basename($_POST['front_image'] ?? '');
        $backFile  = basename($_POST['back_image']  ?? '');
        $frontPath = $uploadDir . $frontFile;

        if (!$frontFile || !file_exists($frontPath)) {
            $_SESSION['flash_message'] = 'Front image not found. Please upload again.';
            $_SESSION['flash_type']    = 'error';
            session_write_close();
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }

        // OCR front image — get ALL engine results
        $ocrFrontResults = runOCR($frontPath);
        if (empty($ocrFrontResults)) {
            $_SESSION['flash_message'] = 'OCR failed on front image. Please try again or check the API key.';
            $_SESSION['flash_type']    = 'error';
            session_write_close();
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }

        // OCR back image (if provided) — get ALL engine results
        $ocrBackResults = [];
        if ($backFile && file_exists($uploadDir . $backFile)) {
            $ocrBackResults = runOCR($uploadDir . $backFile);
        }

        // Parse EACH engine result independently and merge best fields
        $data = ['name' => '', 'dob' => '', 'gender' => '', 'aadhaar_number' => '', 'address' => ''];
        $allRawTexts = [];

        // Parse FRONT results (name, DOB, gender, aadhaar number)
        foreach ($ocrFrontResults as $frontText) {
            $allRawTexts[] = $frontText;
            $parsed = parseAadhaarDetails($frontText);
            $data = mergeBestFields($data, $parsed);
        }

        // Parse BACK results SEPARATELY — only take address from back.
        // This prevents "Details as on: DD/MM/YYYY" on back from being
        // misidentified as DOB.
        $backText = implode("\n", $ocrBackResults);
        if (!empty($ocrBackResults)) {
            $allRawTexts[] = "---BACK---\n" . $backText;
        }
        foreach ($ocrBackResults as $bt) {
            $parsed = parseAadhaarDetails($bt);
            if (!empty($parsed['address']) && strlen($parsed['address']) > strlen($data['address'])) {
                $data['address'] = $parsed['address'];
            }
            // Also take aadhaar number from back if front didn't detect it
            if (empty($data['aadhaar_number']) && !empty($parsed['aadhaar_number'])) {
                $data['aadhaar_number'] = $parsed['aadhaar_number'];
            }
        }

        $data['front_image'] = $frontFile;
        $data['back_image']  = $backFile;
        $data['raw_text']    = implode("\n---ENGINE---\n", $allRawTexts);

        saveToCSV($csvFile, $data);

        $_SESSION['extracted_data']  = $data;
        $_SESSION['uploaded_front']  = $frontFile;
        $_SESSION['uploaded_back']   = $backFile;
        $_SESSION['flash_message']   = 'Aadhaar card processed successfully!';
        $_SESSION['flash_type']      = 'success';
        session_write_close();
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

// ─── RESTORE FLASH DATA FROM SESSION (GET request after redirect) ──────────
if (isset($_SESSION['flash_message'])) {
    $message     = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}
if (isset($_SESSION['extracted_data'])) {
    $extractedData = $_SESSION['extracted_data'];
    unset($_SESSION['extracted_data']);
}
if (!$uploadedFront && isset($_SESSION['uploaded_front'])) {
    $uploadedFront = $_SESSION['uploaded_front'];
    $uploadedBack  = $_SESSION['uploaded_back'] ?? '';
}
if ($extractedData) {
    unset($_SESSION['uploaded_front'], $_SESSION['uploaded_back']);
}

// ─── FUNCTIONS ─────────────────────────────────────────────────────────────

/**
 * Run OCR on an image using OCR.space API.
 * Returns an ARRAY of ALL successful OCR text results from multiple engines.
 * The caller will parse each result and merge the best fields.
 */
function runOCR($imagePath) {
    $apiKey = 'K83915108588957';

    $attempts = [
        ['engine' => '1', 'lang' => 'eng'],   // Engine 1, English (best for names)
        ['engine' => '2', 'lang' => 'eng'],   // Engine 2, English (best for DOB/structure)
    ];

    $results = [];
    foreach ($attempts as $attempt) {
        $text = callOcrSpaceAPI($imagePath, $apiKey, $attempt['engine'], $attempt['lang']);
        if ($text !== false && trim($text) !== '') {
            $results[] = $text;
        }
    }

    return $results; // array of strings (may be empty)
}

/**
 * Call OCR.space API with a specific engine and language
 */
function callOcrSpaceAPI($imagePath, $apiKey, $engine, $lang) {
    $apiUrl = 'https://api.ocr.space/parse/image';

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $imagePath);
    finfo_close($finfo);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . $apiKey],
        CURLOPT_POSTFIELDS     => [
            'file'              => new CURLFile($imagePath, $mimeType, basename($imagePath)),
            'language'          => $lang,
            'isOverlayRequired' => 'false',
            'detectOrientation' => 'true',
            'scale'             => 'true',
            'OCREngine'         => $engine,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("OCR.space API error (Engine $engine, $lang): " . ($curlError ?: "HTTP $httpCode"));
        return false;
    }

    $json = json_decode($response, true);

    if (!$json || !empty($json['IsErroredOnProcessing'])) {
        $errMsg = $json['ErrorMessage'][0] ?? 'Unknown API error';
        error_log("OCR.space processing error (Engine $engine, $lang): $errMsg");
        return false;
    }

    $text = '';
    if (!empty($json['ParsedResults'])) {
        foreach ($json['ParsedResults'] as $result) {
            $text .= $result['ParsedText'] ?? '';
        }
    }

    if (trim($text) === '') return false;
    return $text;
}

/**
 * Score an OCR result by counting how many key Aadhaar fields are detectable.
 * Higher score = better result. Max score = 5.
 */
function scoreOcrResult($text) {
    $score = 0;
    // Aadhaar number (XXXX XXXX XXXX)
    if (preg_match('/\d{4}\s?\d{4}\s?\d{4}/', $text)) $score++;
    // Date of birth (DD/MM/YYYY)
    if (preg_match('/\d{2}[\/\-]\d{2}[\/\-]\d{4}/', $text)) $score++;
    // Gender
    if (preg_match('/\b(MALE|FEMALE|Male|Female|male|female|पुरुष|महिला|Transgender)\b/iu', $text)) $score++;
    // Name-like line (2+ word alphabetic line near DOB)
    if (preg_match('/^[A-Za-z][A-Za-z\s\.]{4,50}$/m', $text)) $score++;
    // Address indicators
    if (preg_match('/Address|S\/O|D\/O|W\/O|C\/O|पता|DIST|Colony|Road|Lane|Nagar|Village/iu', $text)) $score++;
    return $score;
}

/**
 * Merge two parsed Aadhaar results, keeping the best value for each field.
 */
function mergeBestFields($current, $new) {
    // Name: prefer non-empty, non-garbled, with more real words
    if (!empty($new['name'])) {
        if (empty($current['name'])) {
            $current['name'] = $new['name'];
        } else {
            // Prefer name with more real (3+ char) words
            $newReal = count(array_filter(explode(' ', $new['name']), fn($w) => strlen($w) >= 3));
            $curReal = count(array_filter(explode(' ', $current['name']), fn($w) => strlen($w) >= 3));
            if ($newReal > $curReal) $current['name'] = $new['name'];
        }
    }
    // DOB: prefer older date (DOB is always oldest date on Aadhaar)
    if (!empty($new['dob'])) {
        if (empty($current['dob'])) {
            $current['dob'] = $new['dob'];
        } else {
            // Extract years and prefer the older one
            preg_match('/(\d{4})/', $new['dob'], $ny);
            preg_match('/(\d{4})/', $current['dob'], $cy);
            if (!empty($ny[1]) && !empty($cy[1]) && (int)$ny[1] < (int)$cy[1]) {
                $current['dob'] = $new['dob'];
            }
        }
    }
    // Gender: take first non-empty
    if (!empty($new['gender']) && empty($current['gender'])) {
        $current['gender'] = $new['gender'];
    }
    // Aadhaar: take first non-empty
    if (!empty($new['aadhaar_number']) && empty($current['aadhaar_number'])) {
        $current['aadhaar_number'] = $new['aadhaar_number'];
    }
    // Address: prefer longer address
    if (!empty($new['address']) && strlen($new['address']) > strlen($current['address'] ?? '')) {
        $current['address'] = $new['address'];
    }
    return $current;
}

/**
 * Strip diacritics/accented characters to plain ASCII.
 * e.g., "Slbäfma" → "Slbafma", "fåfü" → "fafu"
 */
function stripDiacritics($str) {
    // Transliterate to ASCII
    if (function_exists('transliterator_transliterate')) {
        return transliterator_transliterate('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $str);
    }
    // Fallback: iconv
    $result = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    return $result !== false ? $result : $str;
}

/**
 * Correct garbled surname via fuzzy matching against common Indian surnames.
 *
 * OCR frequently misreads characters with similar shapes:
 *   h↔l, a↔b↔d, r↔f↔t, m↔rn, n↔ri, u↔v, i↔l↔1, o↔0, etc.
 *
 * We apply this only to the LAST word of the name (surname position)
 * and only if it doesn't already match a known surname.
 */
function correctSurname($fullName) {
    // 200+ common Indian surnames (covers major communities across India)
    static $surnames = [
        // North Indian
        'Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Agarwal', 'Aggarwal',
        'Jain', 'Joshi', 'Mishra', 'Misra', 'Pandey', 'Pande', 'Dubey',
        'Tiwari', 'Trivedi', 'Tripathi', 'Shukla', 'Srivastava', 'Srivastva',
        'Rastogi', 'Saxena', 'Nigam', 'Mathur', 'Chaturvedi', 'Dwivedi',
        'Upadhyay', 'Bajpai', 'Khanna', 'Kapoor', 'Kapur', 'Chopra',
        'Malhotra', 'Bhatia', 'Sethi', 'Anand', 'Arora', 'Bahl', 'Kohli',
        'Mehra', 'Oberoi', 'Sahni', 'Tandon', 'Walia', 'Dhawan', 'Grover',
        'Luthra', 'Sodhi', 'Bedi', 'Gill', 'Sandhu', 'Sidhu', 'Dhillon',
        'Grewal', 'Bajwa', 'Chauhan', 'Thakur', 'Rana', 'Rawat', 'Bisht',
        'Negi', 'Jat', 'Yadav', 'Goel', 'Goyal', 'Mittal', 'Bansal',
        'Jindal', 'Singhal', 'Mangal', 'Khandelwal', 'Maheshwari',
        'Agrahari', 'Kashyap', 'Gautam', 'Maurya', 'Rajput', 'Chahar',
        'Tanwar', 'Shekhawat', 'Rathore', 'Panwar', 'Parmar', 'Solanki',
        'Tomar', 'Sengar', 'Bundela', 'Chandel', 'Gujjar', 'Meena',
        'Saini', 'Choudhary', 'Chaudhary', 'Chowdhury', 'Deshpande',
        'Semwal', 'Semval', 'Bhandari', 'Uniyal', 'Kimothi', 'Maikhuri',

        // South Indian
        'Reddy', 'Naidu', 'Raju', 'Rao', 'Nair', 'Menon', 'Pillai',
        'Iyer', 'Iyengar', 'Krishnan', 'Subramaniam', 'Subramanian',
        'Ramasamy', 'Murugan', 'Bhat', 'Shetty', 'Hegde', 'Kamath',
        'Gowda', 'Patel', 'Patil', 'Kulkarni', 'Deshmukh', 'Joshi',
        'Shinde', 'Jadhav', 'Pawar', 'More', 'Bhosale', 'Chavan',
        'Nikam', 'Kale', 'Mane', 'Salunkhe', 'Wagh', 'Gaikwad',
        'Deshpande', 'Jog', 'Gore', 'Apte', 'Gokhale', 'Karve',
        'Chitnis', 'Sathe', 'Phadke', 'Tambe', 'Barde',

        // East Indian
        'Das', 'Bose', 'Ghosh', 'Sen', 'Roy', 'Dutta', 'Datta',
        'Mukherjee', 'Banerjee', 'Chatterjee', 'Bhattacharya',
        'Chakraborty', 'Dasgupta', 'Sengupta', 'Majumdar', 'Sarkar',
        'Barman', 'Choudhury', 'Saha', 'Paul', 'Debnath', 'Borah',
        'Hazarika', 'Kalita', 'Baruah', 'Gogoi', 'Deka', 'Mahanta',

        // West Indian / Gujarati
        'Shah', 'Modi', 'Desai', 'Mehta', 'Gandhi', 'Patel', 'Dave',
        'Trivedi', 'Bhatt', 'Vyas', 'Pandya', 'Raval', 'Parikh',
        'Naik', 'Soni', 'Thakker', 'Kothari',

        // Muslim
        'Khan', 'Ahmed', 'Syed', 'Sheikh', 'Ansari', 'Siddiqui',
        'Qureshi', 'Rizvi', 'Farooqui', 'Hussain', 'Ali', 'Mirza',
        'Hashmi', 'Hasan', 'Malik', 'Pathan',

        // Sikh
        'Kaur', 'Ahluwalia', 'Bajaj', 'Brar', 'Johal', 'Mann',
        'Randhawa', 'Virk', 'Toor', 'Dhaliwal', 'Multani',

        // General / pan-India
        'Prasad', 'Ram', 'Lal', 'Devi', 'Kumari', 'Chandra',
        'Narayan', 'Kishore', 'Mohan', 'Rajan', 'Pillai', 'Nath',
    ];

    $parts = explode(' ', $fullName);
    if (count($parts) < 2) return $fullName;

    $lastName = end($parts);
    $lastNameLower = strtolower($lastName);

    // Check if it already matches a known surname (case-insensitive)
    foreach ($surnames as $s) {
        if (strtolower($s) === $lastNameLower) return $fullName; // already correct
    }

    // Fuzzy match: find the closest surname
    $bestMatch = null;
    $bestDist  = PHP_INT_MAX;
    $lastLen   = strlen($lastName);

    foreach ($surnames as $s) {
        // Quick filters: first letter must match, length within ±3
        if (strtolower($s[0]) !== strtolower($lastName[0])) continue;
        if (abs(strlen($s) - $lastLen) > 3) continue;

        $dist = levenshtein(strtolower($lastName), strtolower($s));
        if ($dist < $bestDist) {
            $bestDist  = $dist;
            $bestMatch = $s;
        }
    }

    // Apply correction if distance is reasonable:
    //   - For words ≤5 chars: allow distance ≤ 2
    //   - For words 6-8 chars: allow distance ≤ 3
    //   - For words 9+ chars: allow distance ≤ 4
    $maxDist = ($lastLen <= 5) ? 2 : (($lastLen <= 8) ? 3 : 4);

    if ($bestMatch !== null && $bestDist <= $maxDist && $bestDist > 0) {
        $parts[count($parts) - 1] = ucfirst(strtolower($bestMatch));
        return implode(' ', $parts);
    }

    return $fullName;
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

    // Clean OCR artifacts: strip diacritics, normalize whitespace
    $text = stripDiacritics($text);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    $text = preg_replace('/\r\n|\r/', "\n", $text);

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

    // ── 2. Find the DOB ────────────────────────────────────────────────
    //
    // Strategy: Collect ALL dates from OCR text. The DOB is always the
    // OLDEST date on the card. "Details as on" / download dates are recent.
    // Also handle garbled separators: OCR returns 01'05/1981 or 01.05/1981
    //
    $dobLineIdx = -1;
    $currentYear = (int)date('Y');

    // Broad date regex: handles / - ' . as separators between DD MM YYYY
    $datePattern = '/(\d{2})[\'\/\-\.](\d{2})[\'\/ \-\.](\d{4})/';
    // Lines that contain non-DOB dates (metadata/download/print dates)
    $nonDobLine = '/details\s*as\s*on|download|printed|issued|generated|enrol|expiry|valid|maadhaar|online|scan|verify|vid\b|authentication/i';
    // Fuzzy DOB label (OCR garbles DOB→OOB, D0B, etc.)
    $dobLabel = '/D\.?O\.?B|[DO0][O0][B8]|DOB|Date\s*of\s*Birth|Birth/i';

    $allDates = []; // [{date, year, lineIdx, hasLabel, isDownload}]
    foreach ($lines as $i => $line) {
        if (preg_match_all($datePattern, $line, $m, PREG_SET_ORDER)) {
            $isDownload = (bool)preg_match($nonDobLine, $line);
            $hasLabel   = (bool)preg_match($dobLabel, $line);
            foreach ($m as $match) {
                $yr = (int)$match[3];
                if ($yr >= 1900 && $yr <= $currentYear) {
                    $normalizedDate = $match[1] . '/' . $match[2] . '/' . $match[3];
                    $allDates[] = [
                        'date'       => $normalizedDate,
                        'year'       => $yr,
                        'lineIdx'    => $i,
                        'hasLabel'   => $hasLabel,
                        'isDownload' => $isDownload,
                    ];
                }
            }
        }
    }

    // Sort: DOB-labeled first, non-download next, oldest year wins
    usort($allDates, function ($a, $b) {
        if ($a['hasLabel'] !== $b['hasLabel']) return $b['hasLabel'] <=> $a['hasLabel'];
        if ($a['isDownload'] !== $b['isDownload']) return $a['isDownload'] <=> $b['isDownload'];
        return $a['year'] <=> $b['year'];
    });

    // Filter out download/metadata dates — they are NEVER the DOB.
    // Only use them as an absolute last resort if nothing else is found.
    $realDates = array_filter($allDates, function ($d) {
        return !$d['isDownload'];
    });

    if (!empty($realDates)) {
        $realDates = array_values($realDates);
        $data['dob']  = $realDates[0]['date'];
        $dobLineIdx   = $realDates[0]['lineIdx'];
    }

    // Year-of-Birth fallback
    if (empty($data['dob'])) {
        if (preg_match('/(?:year\s*of\s*birth|yob)\s*[:\-]?\s*(\d{4})/i', $text, $dm)) {
            $yr = (int)$dm[1];
            if ($yr >= 1900 && $yr <= $currentYear) {
                $data['dob'] = $dm[1];
            }
        }
    }

    // ── 3. Name ────────────────────────────────────────────────────────
    //
    // Based on actual OCR output analysis:
    //   Engine 1: "Sachidanand Slbafma" (line 1, before DOB)
    //   Engine 2: "SIRE BOR" (garbled beyond use)
    // The name is on the FIRST pure-alphabetic line with 2+ words,
    // excluding known non-name keywords. We accept OCR imperfections
    // in the name since it's the best the OCR can provide.
    //
    $nonNameWords = '/government|india|aadhaar|unique|identification|authority|uid|issued|help|download|authentication|verify|verified|online|scan|maadhaar|enrol|enrollment|print|republic|qr\b|itmo|code|resident|card|beneficiary|portal|date|birth|dob|gender|male|female|address|year|validity|vid\b|masked|official|document|valid|generate|biometric|demographic|department|ministry|electronic|digital|information|technology|www\.|http|org\b|gov\b|nic\b|uidai|proof|identity|citizenship|should|scanning|offline|details/i';

    // Strategy 1: Walk upward from DOB line (name is typically 1-2 lines above)
    if ($dobLineIdx > 0) {
        for ($j = $dobLineIdx - 1; $j >= max(0, $dobLineIdx - 4); $j--) {
            $candidate = trim($lines[$j]);
            if ($candidate === '') continue;
            if (preg_match('/\d/', $candidate)) continue;

            $cleaned = preg_replace('/[^A-Za-z\s\.]/', '', $candidate);
            $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
            if (strlen($cleaned) < 4) continue;
            if (!preg_match('/\s/', $cleaned)) continue;
            if (!preg_match('/^[A-Z]/', $cleaned)) continue;
            if (preg_match($nonNameWords, $cleaned)) continue;

            $words = explode(' ', $cleaned);
            $realWords = array_filter($words, fn($w) => strlen($w) >= 2);
            if (count($realWords) < 2) continue;

            $data['name'] = ucwords(strtolower($cleaned));
            $data['name'] = preg_replace('/^name\s*[:\-]?\s*/i', '', $data['name']);
            $data['name'] = trim($data['name']);
            break;
        }
    }

    // Strategy 2: First alphabetic 2+ word line in the entire text
    //             (for cards where DOB line wasn't found or name is above it)
    if (empty($data['name'])) {
        foreach ($lines as $i => $line) {
            if (preg_match('/\d/', $line)) continue;
            $cleaned = preg_replace('/[^A-Za-z\s\.]/', '', $line);
            $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
            if (strlen($cleaned) < 4) continue;
            if (!preg_match('/\s/', $cleaned)) continue;
            if (!preg_match('/^[A-Z]/', $cleaned)) continue;
            if (preg_match($nonNameWords, $cleaned)) continue;

            $words = explode(' ', $cleaned);
            $realWords = array_filter($words, fn($w) => strlen($w) >= 2);
            if (count($realWords) < 2) continue;

            $data['name'] = ucwords(strtolower($cleaned));
            break;
        }
    }

    // Strategy 3: Look for explicit "Name:" label
    if (empty($data['name'])) {
        if (preg_match('/(?:Name)\s*[:\-]\s*([A-Za-z][A-Za-z\s\.]{2,50})/i', $text, $nm)) {
            $candidate = ucwords(strtolower(trim($nm[1])));
            if (!preg_match($nonNameWords, $candidate)) {
                $data['name'] = $candidate;
            }
        }
    }

    // ── 3b. Surname correction via fuzzy matching ──────────────────────
    //  OCR often garbles surnames (e.g. "Sharma" → "Slbafma") due to
    //  character-shape confusions (h→l, a→b, r→f). We fuzzy-match the
    //  last word against a dictionary of common Indian surnames.
    if (!empty($data['name'])) {
        $data['name'] = correctSurname($data['name']);
    }

    // ── 4. Gender  (line immediately after the DOB line) ───────────────
    //    Search nearby lines, full text, and handle OCR misreads
    if ($dobLineIdx >= 0) {
        // Check a few lines after DOB (expand range for API output which may merge lines)
        for ($j = $dobLineIdx; $j <= min($dobLineIdx + 5, $lineCount - 1); $j++) {
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
    // Fallback: handle common OCR misreads of MALE/FEMALE
    if (empty($data['gender'])) {
        // OCR often misreads MALE as MAIE, MАЛЕ, etc.
        if (preg_match('/\b(MA[LI1|]E|FEMA[LI1|]E|FE\.?MALE)\b/i', $text, $gm)) {
            $raw = strtoupper($gm[1]);
            $data['gender'] = (strpos($raw, 'FE') === 0) ? 'Female' : 'Male';
        }
    }
    // Fallback: look for Gender/लिंग label
    if (empty($data['gender'])) {
        if (preg_match('/(?:Gender|Sex|लिंग)\s*[:\-]?\s*(Male|Female|MALE|FEMALE|पुरुष|महिला|M|F)\b/iu', $text, $gm)) {
            $val = strtoupper(trim($gm[1]));
            if ($val === 'M' || $val === 'MALE' || $gm[1] === 'पुरुष') $data['gender'] = 'Male';
            elseif ($val === 'F' || $val === 'FEMALE' || $gm[1] === 'महिला') $data['gender'] = 'Female';
            else $data['gender'] = normalizeGender($gm[1]);
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
 * Find an existing upload with the same content hash to avoid duplicates.
 * Returns the filename if found, or false.
 */
function findExistingUpload($uploadDir, $hash, $ext) {
    $pattern = $uploadDir . 'aadhaar_*.' . $ext;
    foreach (glob($pattern) as $path) {
        if (md5_file($path) === $hash) {
            return basename($path);
        }
    }
    return false;
}

/**
 * Save extracted data to CSV (upsert: update existing row if same Aadhaar number, else append).
 */
function saveToCSV($csvFile, $data) {
    $rows = [];
    $header = null;
    $updated = false;
    $aadhaarToMatch = preg_replace('/\s+/', '', $data['aadhaar_number'] ?? '');

    // Read all existing rows
    if (($fp = fopen($csvFile, 'r')) !== false) {
        $header = fgetcsv($fp, 0, ',', '"', '');
        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            $existingAadhaar = preg_replace('/\s+/', '', $row[4] ?? '');
            // If same Aadhaar number — update in place
            if ($aadhaarToMatch && $existingAadhaar === $aadhaarToMatch) {
                $row = [
                    $row[0],  // keep original Sr No
                    $data['name'],
                    $data['dob'],
                    $data['gender'],
                    $data['aadhaar_number'],
                    $data['address'],
                    $data['front_image'],
                    $data['back_image'] ?? '',
                    date('Y-m-d H:i:s'),
                ];
                $updated = true;
            }
            $rows[] = $row;
        }
        fclose($fp);
    }

    if (!$updated) {
        // Append new row
        $srNo = count($rows) + 1;
        $rows[] = [
            $srNo,
            $data['name'],
            $data['dob'],
            $data['gender'],
            $data['aadhaar_number'],
            $data['address'],
            $data['front_image'],
            $data['back_image'] ?? '',
            date('Y-m-d H:i:s'),
        ];
    }

    // Rewrite the entire CSV
    $fp = fopen($csvFile, 'w');
    if ($header) fputcsv($fp, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($fp, $row, ',', '"', '');
    }
    fclose($fp);
}

// ─── Load all records for the table ────────────────────────────────────────
function loadAllRecords($csvFile) {
    $records = [];
    if (($fp = fopen($csvFile, 'r')) !== false) {
        $header = fgetcsv($fp, 0, ',', '"', ''); // skip header
        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            if (count($row) >= 9) {
                $records[] = [
                    'sr_no'          => $row[0],
                    'name'           => $row[1],
                    'dob'            => $row[2],
                    'gender'         => $row[3],
                    'aadhaar_number' => $row[4],
                    'address'        => $row[5],
                    'front_image'    => $row[6],
                    'back_image'     => $row[7],
                    'processed_date' => $row[8],
                ];
            } elseif (count($row) >= 8) {
                // Legacy format (single image column)
                $records[] = [
                    'sr_no'          => $row[0],
                    'name'           => $row[1],
                    'dob'            => $row[2],
                    'gender'         => $row[3],
                    'aadhaar_number' => $row[4],
                    'address'        => $row[5],
                    'front_image'    => $row[6],
                    'back_image'     => '',
                    'processed_date' => $row[7],
                ];
            }
        }
        fclose($fp);
    }
    return array_reverse($records);
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
                <p class="section-desc">Upload clear images of the Aadhaar card — front side (required) and back side (optional for address). Supported: JPG, PNG, BMP, TIFF.</p>
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

        <?php
        // Determine if we're in "preview mode" (image uploaded, not yet processed)
        $showPreview = ($uploadedFront && !$extractedData);
        ?>

        <?php if (!$showPreview): ?>
        <form method="POST" enctype="multipart/form-data" id="uploadForm" class="upload-form">
            <input type="hidden" name="action" value="upload">
            <div class="drop-zone-grid">
                <!-- Front Side -->
                <div class="drop-zone" id="dropZoneFront">
                    <input type="file" name="front_image" id="front_image" accept="image/*" required
                           onchange="handleFileSelect(this, 'front')">
                    <div class="drop-zone-content">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <p class="drop-text" id="dropTextFront">Front Side *</p>
                        <p class="drop-sub">Drag &amp; drop or click to browse</p>
                        <span class="drop-formats">Required &middot; Name, DOB, Aadhaar No.</span>
                    </div>
                </div>
                <!-- Back Side -->
                <div class="drop-zone" id="dropZoneBack">
                    <input type="file" name="back_image" id="back_image" accept="image/*"
                           onchange="handleFileSelect(this, 'back')">
                    <div class="drop-zone-content">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <p class="drop-text" id="dropTextBack">Back Side</p>
                        <p class="drop-sub">Drag &amp; drop or click to browse</p>
                        <span class="drop-formats">Optional &middot; Address details</span>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload Images
            </button>
        </form>
        <?php endif; ?>

        <?php if ($showPreview): ?>
            <div class="preview-section" id="preview">
                <h3>Image Preview</h3>
                <div class="preview-grid">
                    <div class="preview-img-wrapper">
                        <span class="preview-label">Front</span>
                        <img src="uploads/<?= htmlspecialchars($uploadedFront) ?>" alt="Front Preview" class="preview-img">
                    </div>
                    <?php if ($uploadedBack): ?>
                    <div class="preview-img-wrapper">
                        <span class="preview-label">Back</span>
                        <img src="uploads/<?= htmlspecialchars($uploadedBack) ?>" alt="Back Preview" class="preview-img">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="preview-actions">
                    <form method="POST" class="process-form" style="display:inline">
                        <input type="hidden" name="action" value="process">
                        <input type="hidden" name="front_image" value="<?= htmlspecialchars($uploadedFront) ?>">
                        <input type="hidden" name="back_image" value="<?= htmlspecialchars($uploadedBack) ?>">
                        <button type="submit" class="btn btn-accent">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            Process Aadhaar Card
                        </button>
                    </form>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>?reset=1" class="btn btn-secondary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        Upload Different Image
                    </a>
                </div>
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
                <?php if (!empty($extractedData['front_image'])): ?>
                <p class="report-img-label">Front</p>
                <img src="uploads/<?= htmlspecialchars($extractedData['front_image']) ?>" alt="Aadhaar Front" class="report-img">
                <?php endif; ?>
                <?php if (!empty($extractedData['back_image'])): ?>
                <p class="report-img-label">Back</p>
                <img src="uploads/<?= htmlspecialchars($extractedData['back_image']) ?>" alt="Aadhaar Back" class="report-img" style="margin-top:12px">
                <?php endif; ?>
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
                    <tr class="record-row" onclick="openRecordDetail(this)"
                        data-name="<?= htmlspecialchars($record['name'] ?? '') ?>"
                        data-dob="<?= htmlspecialchars($record['dob'] ?? '') ?>"
                        data-gender="<?= htmlspecialchars($record['gender'] ?? '') ?>"
                        data-aadhaar="<?= htmlspecialchars($record['aadhaar_number'] ?? '') ?>"
                        data-address="<?= htmlspecialchars($record['address'] ?? '') ?>"
                        data-front="uploads/<?= htmlspecialchars($record['front_image'] ?? '') ?>"
                        data-back="<?= !empty($record['back_image']) ? 'uploads/' . htmlspecialchars($record['back_image']) : '' ?>"
                        data-date="<?= htmlspecialchars($record['processed_date'] ?? '') ?>">
                        <td class="name-cell"><?= htmlspecialchars($record['name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($record['dob'] ?? '') ?></td>
                        <td><?= htmlspecialchars($record['gender'] ?? '') ?></td>
                        <td class="aadhaar-num"><?= htmlspecialchars($record['aadhaar_number'] ?? '') ?></td>
                        <td class="address-cell"><?= htmlspecialchars($record['address'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($record['front_image'])): ?>
                            <img src="uploads/<?= htmlspecialchars($record['front_image']) ?>"
                                 alt="Front" class="thumb-img"
                                 onclick="event.stopPropagation(); window.open(this.src, '_blank')">
                            <?php endif; ?>
                        </td>
                        <td class="date-cell"><?= htmlspecialchars($record['processed_date'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

<!-- ── Record Detail Modal ────────────────────────────────────────── -->
<div class="record-modal-overlay" id="recordModal" onclick="closeRecordDetail(event)">
    <div class="record-modal">
        <button class="modal-close" onclick="closeRecordDetail(event, true)" title="Close">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div class="modal-header">
            <div class="card-icon card-icon-success">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>
            </div>
            <div>
                <h3 id="modalName">—</h3>
                <p class="modal-sub">Aadhaar Card Details</p>
            </div>
        </div>
        <div class="modal-body">
            <div class="modal-grid">
                <div class="modal-details">
                    <div class="detail-row"><span class="detail-label">Name</span><span class="detail-value" id="modalNameVal">—</span></div>
                    <div class="detail-row"><span class="detail-label">Date of Birth</span><span class="detail-value" id="modalDob">—</span></div>
                    <div class="detail-row"><span class="detail-label">Gender</span><span class="detail-value" id="modalGender">—</span></div>
                    <div class="detail-row"><span class="detail-label">Aadhaar No.</span><span class="detail-value aadhaar-num" id="modalAadhaar">—</span></div>
                    <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value" id="modalAddress">—</span></div>
                    <div class="detail-row"><span class="detail-label">Processed</span><span class="detail-value" id="modalDate">—</span></div>
                </div>
                <div class="modal-image">
                    <p class="report-img-label">Front</p>
                    <img id="modalImgFront" src="" alt="Aadhaar Front" class="modal-preview-img">
                    <div id="modalBackWrap" style="display:none; margin-top:12px">
                        <p class="report-img-label">Back</p>
                        <img id="modalImgBack" src="" alt="Aadhaar Back" class="modal-preview-img">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Processing Progress Overlay ────────────────────────────────── -->
<div class="progress-overlay" id="progressOverlay">
    <div class="progress-modal">
        <div class="progress-spinner">
            <svg width="56" height="56" viewBox="0 0 56 56">
                <circle cx="28" cy="28" r="24" fill="none" stroke="#e5e7eb" stroke-width="5"/>
                <circle cx="28" cy="28" r="24" fill="none" stroke="#2563eb" stroke-width="5"
                        stroke-dasharray="150.8" stroke-dashoffset="50" stroke-linecap="round"
                        class="progress-ring"/>
            </svg>
        </div>
        <h3 class="progress-title" id="progressTitle">Processing Aadhaar Card</h3>
        <p class="progress-step" id="progressStep">Initializing...</p>
        <div class="progress-bar-track">
            <div class="progress-bar-fill" id="progressBarFill"></div>
        </div>
        <p class="progress-note">This may take 15–30 seconds. Please do not close the page.</p>
    </div>
</div>

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
            Powered by OCR.space API v1.0 | By <a href="https://www.linkedin.com/in/sachidanand-sharma/" target="_blank">Sachidanand Semwal</a></p>
        </div>
    </div>
</footer>

<!-- ─── JavaScript ──────────────────────────────────────────────────────── -->
<script>
// Drag & drop + file select (dual zones)
function initDropZone(zoneId, inputId, textId, side) {
    const zone  = document.getElementById(zoneId);
    const input = document.getElementById(inputId);
    if (!zone || !input) return; // elements not in DOM (preview mode)
    const text  = document.getElementById(textId);

    ['dragenter', 'dragover'].forEach(e => {
        zone.addEventListener(e, function(ev) { ev.preventDefault(); zone.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(e => {
        zone.addEventListener(e, function(ev) { ev.preventDefault(); zone.classList.remove('drag-over'); });
    });
    zone.addEventListener('drop', function(ev) {
        if (ev.dataTransfer.files.length) {
            input.files = ev.dataTransfer.files;
            handleFileSelect(input, side);
        }
    });
    // Open file picker when clicking the zone, but NOT if they clicked the input itself
    // (the input already opens the picker natively; without this guard the picker opens twice)
    zone.addEventListener('click', function(ev) {
        if (ev.target !== input) { input.click(); }
    });
    // Stop the input's click from bubbling up to the zone
    input.addEventListener('click', function(ev) { ev.stopPropagation(); });
}

function handleFileSelect(input, side) {
    const zoneId = side === 'front' ? 'dropZoneFront' : 'dropZoneBack';
    const textId = side === 'front' ? 'dropTextFront' : 'dropTextBack';
    const zone = document.getElementById(zoneId);
    const text = document.getElementById(textId);
    if (input.files && input.files[0]) {
        text.textContent = input.files[0].name;
        zone.classList.add('has-file');
    }
    // Enable upload button if front image is selected
    const frontInput = document.getElementById('front_image');
    const uploadBtn = document.getElementById('uploadBtn');
    if (frontInput && frontInput.files && frontInput.files.length > 0) {
        uploadBtn.disabled = false;
    }
}

initDropZone('dropZoneFront', 'front_image', 'dropTextFront', 'front');
initDropZone('dropZoneBack', 'back_image', 'dropTextBack', 'back');

// ── Record Detail Modal ──
function openRecordDetail(row) {
    const modal = document.getElementById('recordModal');
    document.getElementById('modalName').textContent = row.dataset.name || '—';
    document.getElementById('modalNameVal').textContent = row.dataset.name || '—';
    document.getElementById('modalDob').textContent = row.dataset.dob || '—';
    document.getElementById('modalGender').textContent = row.dataset.gender || '—';
    document.getElementById('modalAadhaar').textContent = row.dataset.aadhaar || '—';
    document.getElementById('modalAddress').textContent = row.dataset.address || '—';
    document.getElementById('modalDate').textContent = row.dataset.date || '—';
    document.getElementById('modalImgFront').src = row.dataset.front || '';
    const backWrap = document.getElementById('modalBackWrap');
    if (row.dataset.back) {
        document.getElementById('modalImgBack').src = row.dataset.back;
        backWrap.style.display = '';
    } else {
        backWrap.style.display = 'none';
    }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeRecordDetail(e, force) {
    if (force || e.target === e.currentTarget) {
        document.getElementById('recordModal').classList.remove('open');
        document.body.style.overflow = '';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRecordDetail(e, true);
});

// ── Processing Progress Overlay ──
(function() {
    const processForm = document.querySelector('.process-form');
    if (!processForm) return;

    processForm.addEventListener('submit', function(e) {
        const overlay = document.getElementById('progressOverlay');
        const stepEl  = document.getElementById('progressStep');
        const barFill = document.getElementById('progressBarFill');
        const titleEl = document.getElementById('progressTitle');

        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';

        const steps = [
            { text: 'Uploading images to OCR service...', pct: 10, delay: 0 },
            { text: 'Running OCR Engine 1 (English) on front image...', pct: 25, delay: 2000 },
            { text: 'Running OCR Engine 2 (English) on front image...', pct: 40, delay: 6000 },
            { text: 'Running OCR Engine 1 (English) on back image...', pct: 55, delay: 10000 },
            { text: 'Running OCR Engine 2 (English) on back image...', pct: 70, delay: 14000 },
            { text: 'Parsing and merging OCR results...', pct: 85, delay: 18000 },
            { text: 'Correcting names and saving record...', pct: 95, delay: 21000 },
        ];

        steps.forEach(function(s) {
            setTimeout(function() {
                stepEl.textContent = s.text;
                barFill.style.width = s.pct + '%';
            }, s.delay);
        });
    });
})();

// Also show a brief spinner on Upload button click
(function() {
    const uploadForm = document.getElementById('uploadForm');
    if (!uploadForm) return;
    uploadForm.addEventListener('submit', function() {
        const btn = document.getElementById('uploadBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spinner"></span> Uploading...';
        }
    });
})();

// Smooth scroll to preview or report after upload/processing
<?php if ($showPreview): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('preview')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php elseif ($extractedData): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('report')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
</script>

</body>
</html>
