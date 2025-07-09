<?php
header('Content-Type: application/json');

// Directory where transcription files are stored
$transcriptionDir = '/var/www/videostreamer/transcribe/transcribed/';

try {
    // Check if directory exists
    if (!is_dir($transcriptionDir)) {
        throw new Exception("Transcription directory not found");
    }

    // Scan directory for .txt files
    $files = scandir($transcriptionDir);
    $txtFiles = array();

    foreach ($files as $file) {
        // Only include .txt files and exclude hidden files
        if (pathinfo($file, PATHINFO_EXTENSION) === 'txt' && substr($file, 0, 1) !== '.') {
            $txtFiles[] = $file;
        }
    }

    // Sort files by modification time (newest first)
    usort($txtFiles, function($a, $b) use ($transcriptionDir) {
        return filemtime($transcriptionDir . $b) - filemtime($transcriptionDir . $a);
    });

    echo json_encode($txtFiles);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
}
?>