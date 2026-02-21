# Hans Foundation Identity Verification Tool

## Overview
A PHP-based web application that extracts details (Name, Address, Aadhaar Number, DOB, Gender) from Indian Aadhaar Card images using the **OCR.space API** and stores the extracted data in a CSV file.

## Requirements

### Server
- PHP 7.4+ (with `curl` extension enabled)
- Apache/Nginx web server

### OCR.space API
This app uses the [OCR.space](https://ocr.space/) cloud API — no local OCR software required. The API key is configured in `index.php`.

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
