# Hans Foundation Identity Verification Tool

## Overview
A PHP-based web application that extracts details (Name, Address, Aadhaar Number, DOB, Gender) from Indian Aadhaar Card images using **Tesseract OCR** and stores the extracted data in a CSV file.

## Requirements

### Server
- PHP 7.4+ (with `exec()` enabled)
- Apache/Nginx web server
- **Tesseract OCR** installed on the server

### Installing Tesseract OCR

#### Windows
1. Download from: https://github.com/UB-Mannheim/tesseract/wiki
2. Install and add to PATH
3. Optionally install Hindi language pack during setup

#### Linux (Ubuntu/Debian)
```bash
sudo apt update
sudo apt install tesseract-ocr tesseract-ocr-hin
```

#### macOS
```bash
brew install tesseract tesseract-lang
```

### Verify Tesseract Installation
```bash
tesseract --version
tesseract --list-langs   # Should show 'eng' and ideally 'hin'
```

## Folder Structure
```
Test-OCR/
├── index.php              # Main application
├── style.css              # Stylesheet
├── README.md              # This file
├── uploads/               # Uploaded Aadhaar images
│   └── (images stored here)
├── data/
│   ├── .htaccess          # Protects CSV from direct access
│   └── aadhaar_records.csv # Extracted records
```

## Usage
1. Open the application in a browser (`http://localhost/Test-OCR/`)
2. Click **Upload Image** and select an Aadhaar card image
3. Click **Process Aadhaar Card** to extract details via OCR
4. View the extraction report with Name, Address, Aadhaar Number, DOB, and Gender
5. All records are saved to `data/aadhaar_records.csv`

## Notes
- For best OCR results, upload clear, well-lit, high-resolution images
- The tool supports both front and back of the Aadhaar card
- Address extraction works best from the back of the Aadhaar card
- The Aadhaar number pattern (XXXX XXXX XXXX) is detected automatically
