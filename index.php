<?php
/**
 * PDF Compressor Tool – Production Ready for Render Docker
 * 
 * Backend improvements:
 * - Auto-detect Ghostscript binary path (gs) using multiple methods.
 * - Fallback to common paths (/usr/bin/gs, /usr/local/bin/gs).
 * - If Ghostscript not found, return clear JSON error.
 * - Temp directory auto-created with proper permissions.
 * - Secure file handling with unique names and auto-cleanup.
 * - Full error handling for exec() and shell commands.
 * 
 * UI, CSS, and JavaScript remain unchanged.
 */

// -------------------- CONFIGURATION --------------------
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100 MB
define('ALLOWED_MIME', 'application/pdf');
define('TEMP_DIR', __DIR__ . '/temp/');

// -------------------- GHOSTSCRIPT PATH DETECTION --------------------
/**
 * Find the Ghostscript executable path.
 * Tries: which gs, then common paths, then fallback to 'gs' (if in PATH).
 * Returns the full path or false if not found.
 */
function findGhostscript() {
    // List of possible locations
    $possible_paths = [
        '/usr/bin/gs',
        '/usr/local/bin/gs',
        '/bin/gs',
        '/opt/ghostscript/bin/gs'
    ];

    // First, try 'which gs' via exec (if allowed)
    if (function_exists('exec')) {
        $output = [];
        $return_var = 0;
        @exec('which gs 2>/dev/null', $output, $return_var);
        if ($return_var === 0 && !empty($output[0]) && file_exists($output[0]) && is_executable($output[0])) {
            return $output[0];
        }
    }

    // Then, check common paths directly
    foreach ($possible_paths as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    // Fallback: try 'gs' (assuming it's in PATH)
    if (function_exists('exec')) {
        @exec('gs --version 2>/dev/null', $out, $ret);
        if ($ret === 0) {
            return 'gs'; // let the system use PATH
        }
    }

    // Not found
    return false;
}

// Determine Ghostscript command
$gs_path = findGhostscript();
if ($gs_path === false) {
    define('GS_COMMAND', false);
} else {
    define('GS_COMMAND', $gs_path);
}

// Create temp directory if not exists with proper permissions
if (!is_dir(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
    // Ensure write permission for web server
    chmod(TEMP_DIR, 0755);
}

// Cleanup old temp files (older than 1 hour)
if ($handle = opendir(TEMP_DIR)) {
    while (false !== ($file = readdir($handle))) {
        if ($file != '.' && $file != '..') {
            $filepath = TEMP_DIR . $file;
            if (is_file($filepath) && (time() - filemtime($filepath) > 3600)) {
                unlink($filepath);
            }
        }
    }
    closedir($handle);
}

// -------------------- DOWNLOAD HANDLER --------------------
if (isset($_GET['download']) && isset($_GET['file'])) {
    $file = basename($_GET['file']); // sanitize
    $filepath = TEMP_DIR . $file;
    if (file_exists($filepath) && is_file($filepath)) {
        // Serve file for download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="compressed_' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        // Delete after download
        unlink($filepath);
        exit;
    } else {
        http_response_code(404);
        echo "File not found.";
        exit;
    }
}

// -------------------- COMPRESSION HANDLER --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compress'])) {
    // Ensure Ghostscript is available before processing
    if (GS_COMMAND === false) {
        echo json_encode(['error' => 'Ghostscript is not installed or not found. Please contact administrator.']);
        exit;
    }

    // Check for upload errors
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'File upload failed.']);
        exit;
    }

    $file = $_FILES['pdf_file'];
    $tmp_name = $file['tmp_name'];
    $name = $file['name'];
    $size = $file['size'];

    // Validate file type (MIME and extension)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($mime !== ALLOWED_MIME || $ext !== 'pdf') {
        echo json_encode(['error' => 'Only PDF files are allowed.']);
        exit;
    }

    // Validate file size
    if ($size > MAX_FILE_SIZE) {
        echo json_encode(['error' => 'File size exceeds 100MB limit.']);
        exit;
    }

    // Compression level
    $level = isset($_POST['level']) ? $_POST['level'] : 'medium';
    $gs_settings = [
        'low'    => '-dPDFSETTINGS=/screen',
        'medium' => '-dPDFSETTINGS=/ebook',
        'high'   => '-dPDFSETTINGS=/printer'
    ];
    if (!isset($gs_settings[$level])) {
        echo json_encode(['error' => 'Invalid compression level.']);
        exit;
    }

    // Generate unique filenames
    $unique_id = uniqid() . '_' . bin2hex(random_bytes(8));
    $input_path = TEMP_DIR . 'input_' . $unique_id . '.pdf';
    $output_path = TEMP_DIR . 'compressed_' . $unique_id . '.pdf';

    // Move uploaded file to temp
    if (!move_uploaded_file($tmp_name, $input_path)) {
        echo json_encode(['error' => 'Failed to move uploaded file.']);
        exit;
    }

    // Build Ghostscript command
    $command = escapeshellcmd(GS_COMMAND) . ' -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 ' .
               $gs_settings[$level] . ' -dNOPAUSE -dQUIET -dBATCH ' .
               '-sOutputFile=' . escapeshellarg($output_path) . ' ' .
               escapeshellarg($input_path);

    // Execute compression
    $start_time = microtime(true);
    exec($command . ' 2>&1', $exec_output, $exec_return);
    $time_taken = microtime(true) - $start_time;

    // Check if output file exists and has size > 0
    if (!file_exists($output_path) || filesize($output_path) == 0) {
        unlink($input_path);
        if (file_exists($output_path)) unlink($output_path);
        echo json_encode(['error' => 'Compression failed. Please check Ghostscript installation or command.']);
        exit;
    }

    $original_size = filesize($input_path);
    $compressed_size = filesize($output_path);
    $percentage = ($original_size > 0) ? round((1 - $compressed_size / $original_size) * 100, 2) : 0;

    // Prepare response
    $response = [
        'success' => true,
        'original_size' => $original_size,
        'compressed_size' => $compressed_size,
        'percentage' => $percentage,
        'time' => round($time_taken, 2),
        'download_url' => '?download=1&file=' . urlencode('compressed_' . $unique_id . '.pdf')
    ];

    // Clean input file (keep compressed for download)
    unlink($input_path);

    echo json_encode($response);
    exit;
}

// -------------------- MAIN HTML PAGE (unchanged) --------------------
// The entire UI, CSS, and JavaScript remains exactly as before.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="Free online PDF compressor tool. Reduce PDF file size with Ghostscript. Drag & drop, real-time progress." />
    <meta property="og:title" content="PDF Compressor Tool" />
    <meta property="og:description" content="Compress PDF files online for free. Fast, secure, and professional." />
    <meta property="og:type" content="website" />

    <!-- Favicon (from navbar) -->
    <link rel="icon" type="image/png" href="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" />
    <link rel="shortcut icon" href="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" />

    <!-- Google Font: Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <title>PDF Compressor - ProToolss</title>

    <style>
        /* ===== RESET & BASE (from navbar) ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f0f4f8;
            color: #1a1a1a;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ===== MAIN CONTENT WRAPPER ===== */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding-top: 80px; /* offset for fixed navbar */
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 28px;
        }

        /* ===== NAVIGATION (from user) ===== */
        nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999;
            padding: 14px 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 1px 20px rgba(0, 0, 0, 0.03);
            transition: all 0.4s cubic-bezier(0.2, 0.9, 0.3, 1.2);
        }

        nav.scrolled {
            padding: 10px 0;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 2px 30px rgba(0, 0, 0, 0.06);
        }

        .nav-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            flex-wrap: nowrap;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
            justify-content: space-between;
        }

        .hamburger {
            display: block !important;
            font-size: 1.6rem;
            color: #333;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 6px 8px;
            background: transparent;
            border: none;
            border-radius: 8px;
            line-height: 1;
            flex-shrink: 0;
            order: 2;
            margin-left: auto;
        }

        .hamburger:hover {
            color: #cc0000;
            background: rgba(204, 0, 0, 0.06);
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
            flex-shrink: 1;
            min-width: 0;
            margin-right: auto;
        }

        .logo .pro {
            color: #cc0000;
        }

        .logo .toolss {
            color: #1a2a6c;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }

        .nav-center {
            display: none !important;
        }

        .nav-links {
            display: none !important;
        }

        .nav-links-mobile {
            display: none;
            flex-direction: column;
            width: 100%;
            gap: 0.6rem;
            padding: 16px 20px 12px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            margin-top: 10px;
            background: rgba(255, 255, 255, 0.98);
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            border-radius: 0 0 16px 16px;
        }

        .nav-links-mobile.show {
            display: flex;
        }

        .nav-links-mobile a {
            padding: 8px 0;
            font-size: 0.95rem;
            width: 100%;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
            white-space: normal;
            color: #444;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: 0.3s;
        }

        .nav-links-mobile a:last-child {
            border-bottom: none;
        }

        .nav-links-mobile a i {
            width: 24px;
            text-align: center;
            flex-shrink: 0;
            color: #cc0000;
        }

        .nav-links-mobile a.active {
            color: #cc0000;
        }

        .nav-links-mobile a:hover {
            color: #cc0000;
            padding-left: 8px;
        }

        /* ===== FOOTER (from user) - STICKY BOTTOM ===== */
        footer {
            margin-top: auto; /* pushes footer to bottom */
            padding: 20px 0;
            background: linear-gradient(145deg, #1a1a2e, #16213e);
            color: #e0e0e0;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            width: 100%;
        }

        .footer-content {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .footer-powered {
            font-size: 1rem;
            color: #aaaaaa;
            font-weight: 300;
            letter-spacing: 0.5px;
        }

        .footer-powered a {
            color: #ff6b6b;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }

        .footer-powered a:hover {
            color: #ff4444;
            text-decoration: underline;
        }

        .footer-powered i {
            color: #ff6b6b;
            margin: 0 6px;
        }

        /* ===== PDF COMPRESSOR TOOL CARD ===== */
        .tool-wrapper {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px 20px;
            width: 100%;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            padding: 40px 30px;
            width: 100%;
            max-width: 820px;
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.12);
        }

        .tool-header {
            margin-bottom: 30px;
            text-align: center;
        }

        .tool-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }

        .tool-header p {
            font-size: 0.9rem;
            color: #4a5568;
            margin-top: 4px;
        }

        /* ===== Drop Zone ===== */
        .drop-zone {
            border: 2px dashed #a0aec0;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.1);
            padding: 50px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
        }

        .drop-zone i {
            font-size: 4rem;
            color: #a0aec0;
            margin-bottom: 15px;
            transition: color 0.3s;
        }

        .drop-zone:hover i {
            color: #667eea;
        }

        .drop-zone p {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 5px;
            color: #1a202c;
        }

        .drop-zone .sub {
            font-size: 0.9rem;
            color: #4a5568;
        }

        #file-input {
            display: none;
        }

        /* ===== File Info ===== */
        .file-info {
            display: none;
            margin-top: 20px;
            background: rgba(255, 255, 255, 0.5);
            padding: 15px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .file-info.show {
            display: flex;
        }

        .file-info .details {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-info .details i {
            font-size: 2rem;
            color: #667eea;
        }

        .file-info .details .name {
            font-weight: 500;
            word-break: break-all;
            color: #1a202c;
        }

        .file-info .details .size {
            font-size: 0.85rem;
            color: #4a5568;
        }

        .file-info .remove-btn {
            background: transparent;
            border: none;
            color: #a0aec0;
            font-size: 1.2rem;
            cursor: pointer;
            transition: color 0.3s;
        }

        .file-info .remove-btn:hover {
            color: #e53e3e;
        }

        /* ===== Controls ===== */
        .controls {
            margin-top: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .controls label {
            font-weight: 500;
            font-size: 0.95rem;
            color: #4a5568;
        }

        .controls select {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            padding: 10px 14px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            color: #1a202c;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
            flex: 1;
            min-width: 150px;
        }

        .controls select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .controls .actions {
            display: flex;
            gap: 10px;
            margin-left: auto;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 50px; /* pill shape – unchanged */
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.6);
            color: #1a202c;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 50px;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.8);
        }

        .btn-danger {
            background: #e53e3e;
            color: #fff;
            border-radius: 50px;
        }

        .btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #38a169;
            color: #fff;
            border-radius: 50px;
        }

        .btn-success:hover {
            background: #2f855a;
            transform: translateY(-2px);
        }

        /* ===== Progress ===== */
        .progress-container {
            display: none;
            margin-top: 25px;
        }

        .progress-container.show {
            display: block;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 50px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar .fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 50px;
            transition: width 0.2s ease;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 0.85rem;
            color: #4a5568;
        }

        /* ===== Results ===== */
        .results {
            display: none;
            margin-top: 25px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            gap: 15px;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        }

        .results.show {
            display: grid;
        }

        .result-item {
            text-align: center;
        }

        .result-item .label {
            font-size: 0.8rem;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .result-item .value {
            font-size: 1.3rem;
            font-weight: 600;
            margin-top: 4px;
            color: #1a202c;
        }

        .result-item .value.positive {
            color: #38a169;
        }

        .result-item .value.negative {
            color: #e53e3e;
        }

        .result-actions {
            grid-column: 1 / -1;
            text-align: center;
            margin-top: 10px;
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ===== Alerts ===== */
        .alert {
            display: none;
            padding: 14px 18px;
            border-radius: 12px;
            margin-top: 20px;
            font-weight: 500;
            align-items: center;
            gap: 10px;
        }

        .alert.show {
            display: flex;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .alert-error {
            background: #fed7d7;
            color: #9b2c2c;
        }

        .alert i {
            font-size: 1.2rem;
        }

        /* ===== Responsive (from navbar + tool) ===== */
        @media (max-width: 768px) {
            .main-wrapper {
                padding-top: 68px;
            }
            nav {
                padding: 10px 0;
            }
            .nav-container {
                padding: 0 14px;
            }
            .nav-left {
                gap: 8px;
            }
            .hamburger {
                font-size: 1.5rem;
                padding: 4px 6px;
            }
            .logo {
                font-size: 1.2rem;
                gap: 6px;
            }
            .logo-icon {
                width: 28px;
                height: 28px;
            }
            .nav-links-mobile {
                padding: 12px 16px 10px;
                gap: 0.4rem;
            }
            .nav-links-mobile a {
                font-size: 0.85rem;
                padding: 6px 0;
            }
            .nav-links-mobile a i {
                width: 20px;
                font-size: 0.8rem;
            }
            footer {
                padding: 15px 0;
            }
            .footer-powered {
                font-size: 0.9rem;
            }

            .glass-card {
                padding: 25px 18px;
            }
            .tool-header h1 {
                font-size: 1.4rem;
            }
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            .controls .actions {
                margin-left: 0;
                justify-content: center;
            }
            .file-info {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            .file-info .details {
                justify-content: center;
            }
            .results {
                grid-template-columns: 1fr 1fr;
            }
            .drop-zone {
                padding: 30px 15px;
            }
            .drop-zone i {
                font-size: 3rem;
            }
            .btn {
                padding: 10px 18px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .main-wrapper {
                padding-top: 62px;
            }
            nav {
                padding: 8px 0;
            }
            .nav-container {
                padding: 0 12px;
            }
            .nav-left {
                gap: 6px;
            }
            .hamburger {
                font-size: 1.3rem;
                padding: 3px 5px;
            }
            .logo {
                font-size: 1rem;
                gap: 4px;
            }
            .logo-icon {
                width: 24px;
                height: 24px;
            }
            .nav-links-mobile {
                padding: 12px 16px 10px;
                gap: 0.4rem;
            }
            .nav-links-mobile a {
                font-size: 0.85rem;
                padding: 6px 0;
            }
            .nav-links-mobile a i {
                width: 20px;
                font-size: 0.8rem;
            }
            .footer-powered {
                font-size: 0.8rem;
            }

            .results {
                grid-template-columns: 1fr;
            }
            .btn {
                padding: 8px 14px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 360px) {
            .logo {
                font-size: 0.85rem;
                gap: 3px;
            }
            .logo-icon {
                width: 20px;
                height: 20px;
            }
            .hamburger {
                font-size: 1.1rem;
                padding: 2px 4px;
            }
            .nav-container {
                padding: 0 8px;
            }
        }

        @media (min-width: 769px) {
            .nav-left {
                flex: 1;
                justify-content: space-between;
            }
            .hamburger {
                display: block !important;
                font-size: 1.8rem;
                padding: 8px 12px;
            }
            .nav-links-mobile {
                max-width: 300px;
                right: 0;
                left: auto;
                border-radius: 0 0 16px 16px;
            }
        }
    </style>
</head>
<body>

    <!-- ===== NAVBAR ===== -->
    <nav id="mainNav">
        <div class="nav-container">
            <div class="nav-left">
                <a href="index.php" class="logo">
                    <img src="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" alt="Pro Toolss Logo" class="logo-icon" />
                    <span class="pro">Pro</span><span class="toolss">Toolss</span>
                </a>
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="nav-center" style="display:none !important;"></div>
            <div class="nav-links-mobile" id="navLinksMobile">
                <a href="index.php" class="active"><i class="fas fa-home"></i> Home</a>
            </div>
        </div>
    </nav>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-wrapper">
        <div class="tool-wrapper">
            <div class="glass-card">
                <div class="tool-header">
                    <h1><i class="fas fa-file-pdf" style="background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-right: 8px;"></i> PDF Compressor</h1>
                    <p>Reduce PDF file size with Ghostscript</p>
                </div>

                <!-- Drop Zone -->
                <div class="drop-zone" id="dropZone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Drag & drop your PDF here</p>
                    <p class="sub">or click to browse &nbsp;•&nbsp; Max 100 MB</p>
                    <input type="file" id="file-input" accept=".pdf,application/pdf">
                </div>

                <!-- File Info -->
                <div class="file-info" id="fileInfo">
                    <div class="details">
                        <i class="fas fa-file-pdf"></i>
                        <div>
                            <div class="name" id="fileName">file.pdf</div>
                            <div class="size" id="fileSize">0 MB</div>
                        </div>
                    </div>
                    <button class="remove-btn" id="removeFile" title="Remove file"><i class="fas fa-times"></i></button>
                </div>

                <!-- Controls -->
                <div class="controls">
                    <label for="compressionLevel"><i class="fas fa-compress-alt"></i> Level</label>
                    <select id="compressionLevel">
                        <option value="low">Low Compression</option>
                        <option value="medium" selected>Medium Compression</option>
                        <option value="high">High Compression</option>
                    </select>
                    <div class="actions">
                        <button class="btn btn-primary" id="compressBtn"><i class="fas fa-play"></i> Compress</button>
                        <button class="btn btn-secondary" id="cancelBtn" style="display:none;"><i class="fas fa-stop"></i> Cancel</button>
                    </div>
                </div>

                <!-- Progress -->
                <div class="progress-container" id="progressContainer">
                    <div class="progress-bar">
                        <div class="fill" id="progressFill" style="width:0%;"></div>
                    </div>
                    <div class="progress-label">
                        <span id="progressText">Compressing...</span>
                        <span id="progressPercent">0%</span>
                    </div>
                </div>

                <!-- Results -->
                <div class="results" id="results">
                    <div class="result-item">
                        <div class="label">Original Size</div>
                        <div class="value" id="origSize">-</div>
                    </div>
                    <div class="result-item">
                        <div class="label">Compressed Size</div>
                        <div class="value" id="compSize">-</div>
                    </div>
                    <div class="result-item">
                        <div class="label">Reduction</div>
                        <div class="value positive" id="reduction">-</div>
                    </div>
                    <div class="result-item">
                        <div class="label">Time Taken</div>
                        <div class="value" id="timeTaken">-</div>
                    </div>
                    <div class="result-actions">
                        <button class="btn btn-success" id="downloadBtn" style="display:none;"><i class="fas fa-download"></i> Download</button>
                        <button class="btn btn-secondary" id="newCompressBtn"><i class="fas fa-plus"></i> New</button>
                    </div>
                </div>

                <!-- Alerts -->
                <div class="alert" id="alert">
                    <i class="fas fa-info-circle"></i>
                    <span id="alertMessage">Success</span>
                </div>
            </div>
        </div>

        <!-- ===== FOOTER (sticky bottom) ===== -->
        <footer>
            <div class="footer-content">
                <div class="footer-powered">
                    <i class=""></i> Powered by
                    <a href="https://www.protoolss.online" target="_blank" rel="noopener noreferrer">www.protoolss.online</a>
                    <i class="" style="color: #ff6b6b;"></i>
                </div>
            </div>
        </footer>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        (function() {
            'use strict';

            // ===== NAVBAR SCROLL EFFECT =====
            const nav = document.getElementById('mainNav');
            window.addEventListener('scroll', function() {
                if (window.scrollY > 50) nav.classList.add('scrolled');
                else nav.classList.remove('scrolled');
            });

            // ===== HAMBURGER TOGGLE =====
            const hamburger = document.getElementById('hamburgerBtn');
            const navLinksMobile = document.getElementById('navLinksMobile');

            hamburger.addEventListener('click', function(e) {
                e.stopPropagation();
                navLinksMobile.classList.toggle('show');
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-bars');
                icon.classList.toggle('fa-times');
            });

            document.querySelectorAll('#navLinksMobile a').forEach(link => {
                link.addEventListener('click', function() {
                    navLinksMobile.classList.remove('show');
                    const icon = hamburger.querySelector('i');
                    icon.classList.add('fa-bars');
                    icon.classList.remove('fa-times');
                });
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('nav') && navLinksMobile.classList.contains('show')) {
                    navLinksMobile.classList.remove('show');
                    const icon = hamburger.querySelector('i');
                    icon.classList.add('fa-bars');
                    icon.classList.remove('fa-times');
                }
            });

            // ===== PDF COMPRESSOR LOGIC =====
            // DOM refs
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('file-input');
            const fileInfo = document.getElementById('fileInfo');
            const fileName = document.getElementById('fileName');
            const fileSize = document.getElementById('fileSize');
            const removeFileBtn = document.getElementById('removeFile');
            const compressBtn = document.getElementById('compressBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            const progressPercent = document.getElementById('progressPercent');
            const resultsDiv = document.getElementById('results');
            const origSize = document.getElementById('origSize');
            const compSize = document.getElementById('compSize');
            const reduction = document.getElementById('reduction');
            const timeTaken = document.getElementById('timeTaken');
            const downloadBtn = document.getElementById('downloadBtn');
            const newCompressBtn = document.getElementById('newCompressBtn');
            const alertDiv = document.getElementById('alert');
            const alertMessage = document.getElementById('alertMessage');
            const compressionLevel = document.getElementById('compressionLevel');

            let selectedFile = null;
            let xhr = null;
            let progressInterval = null;
            let isCompressing = false;

            function formatSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            function showAlert(message, type = 'success') {
                alertDiv.className = 'alert alert-' + type + ' show';
                alertMessage.textContent = message;
                setTimeout(() => {
                    alertDiv.classList.remove('show');
                }, 5000);
            }

            function resetUI() {
                progressContainer.classList.remove('show');
                progressFill.style.width = '0%';
                progressPercent.textContent = '0%';
                resultsDiv.classList.remove('show');
                downloadBtn.style.display = 'none';
                compressBtn.disabled = false;
                compressBtn.innerHTML = '<i class="fas fa-play"></i> Compress';
                cancelBtn.style.display = 'none';
                isCompressing = false;
                if (xhr) {
                    xhr.abort();
                    xhr = null;
                }
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
            }

            function handleFile(file) {
                if (!file) return;
                if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                    showAlert('Only PDF files are allowed.', 'error');
                    return;
                }
                if (file.size > 100 * 1024 * 1024) {
                    showAlert('File size exceeds 100MB limit.', 'error');
                    return;
                }
                selectedFile = file;
                fileName.textContent = file.name;
                fileSize.textContent = formatSize(file.size);
                fileInfo.classList.add('show');
                resultsDiv.classList.remove('show');
                downloadBtn.style.display = 'none';
                alertDiv.classList.remove('show');
                resetUI();
            }

            // Drop zone events
            dropZone.addEventListener('click', () => fileInput.click());
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('dragover');
            });
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleFile(files[0]);
                }
            });
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    handleFile(e.target.files[0]);
                }
            });

            removeFileBtn.addEventListener('click', () => {
                selectedFile = null;
                fileInput.value = '';
                fileInfo.classList.remove('show');
                resetUI();
                resultsDiv.classList.remove('show');
                alertDiv.classList.remove('show');
            });

            // Compression
            function startCompression() {
                if (!selectedFile) {
                    showAlert('Please select a PDF file first.', 'error');
                    return;
                }
                if (isCompressing) return;

                resetUI();
                alertDiv.classList.remove('show');

                progressContainer.classList.add('show');
                progressFill.style.width = '0%';
                progressPercent.textContent = '0%';
                progressText.textContent = 'Compressing...';
                compressBtn.disabled = true;
                compressBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Compressing';
                cancelBtn.style.display = 'inline-flex';
                isCompressing = true;

                const formData = new FormData();
                formData.append('pdf_file', selectedFile);
                formData.append('compress', '1');
                formData.append('level', compressionLevel.value);

                let progress = 0;
                progressInterval = setInterval(() => {
                    if (progress < 90) {
                        progress += Math.random() * 10;
                        if (progress > 90) progress = 90;
                        progressFill.style.width = progress + '%';
                        progressPercent.textContent = Math.round(progress) + '%';
                    }
                }, 200);

                xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function() {
                    clearInterval(progressInterval);
                    progressInterval = null;
                    isCompressing = false;
                    compressBtn.disabled = false;
                    compressBtn.innerHTML = '<i class="fas fa-play"></i> Compress';
                    cancelBtn.style.display = 'none';

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                showAlert(response.error, 'error');
                                progressFill.style.width = '100%';
                                progressPercent.textContent = '100%';
                                progressText.textContent = 'Error';
                                return;
                            }
                            if (response.success) {
                                progressFill.style.width = '100%';
                                progressPercent.textContent = '100%';
                                progressText.textContent = 'Done!';

                                origSize.textContent = formatSize(response.original_size);
                                compSize.textContent = formatSize(response.compressed_size);
                                const pct = response.percentage;
                                reduction.textContent = (pct > 0 ? '-' : '+') + Math.abs(pct) + '%';
                                reduction.className = 'value ' + (pct > 0 ? 'positive' : 'negative');
                                timeTaken.textContent = response.time + 's';
                                resultsDiv.classList.add('show');
                                downloadBtn.style.display = 'inline-flex';
                                downloadBtn.dataset.url = response.download_url;

                                showAlert('Compression completed successfully!', 'success');
                            }
                        } catch (e) {
                            showAlert('Invalid server response.', 'error');
                        }
                    } else {
                        showAlert('Server error (HTTP ' + xhr.status + ').', 'error');
                    }
                };

                xhr.onerror = function() {
                    clearInterval(progressInterval);
                    progressInterval = null;
                    isCompressing = false;
                    compressBtn.disabled = false;
                    compressBtn.innerHTML = '<i class="fas fa-play"></i> Compress';
                    cancelBtn.style.display = 'none';
                    showAlert('Network error. Please try again.', 'error');
                    progressText.textContent = 'Error';
                };

                xhr.onabort = function() {
                    clearInterval(progressInterval);
                    progressInterval = null;
                    isCompressing = false;
                    compressBtn.disabled = false;
                    compressBtn.innerHTML = '<i class="fas fa-play"></i> Compress';
                    cancelBtn.style.display = 'none';
                    progressText.textContent = 'Cancelled';
                    showAlert('Compression cancelled.', 'error');
                };

                xhr.send(formData);
            }

            compressBtn.addEventListener('click', startCompression);

            cancelBtn.addEventListener('click', () => {
                if (xhr) {
                    xhr.abort();
                    xhr = null;
                }
                clearInterval(progressInterval);
                progressInterval = null;
                isCompressing = false;
                compressBtn.disabled = false;
                compressBtn.innerHTML = '<i class="fas fa-play"></i> Compress';
                cancelBtn.style.display = 'none';
                progressFill.style.width = '0%';
                progressPercent.textContent = '0%';
                progressText.textContent = 'Cancelled';
                alertDiv.classList.remove('show');
            });

            downloadBtn.addEventListener('click', function() {
                const url = this.dataset.url;
                if (url) {
                    window.location.href = url;
                }
            });

            newCompressBtn.addEventListener('click', () => {
                resetUI();
                resultsDiv.classList.remove('show');
                alertDiv.classList.remove('show');
                if (selectedFile) {
                    fileInfo.classList.add('show');
                } else {
                    fileInfo.classList.remove('show');
                }
                downloadBtn.style.display = 'none';
                progressContainer.classList.remove('show');
            });

            // Keyboard shortcut
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && document.activeElement !== fileInput) {
                    if (!isCompressing) startCompression();
                }
            });

        })();
    </script>

</body>
</html>
