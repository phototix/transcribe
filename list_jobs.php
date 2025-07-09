<?php
header('Content-Type: application/json');

// Directory where chunks are stored
$chunkDir = '/var/www/videostreamer/transcribe/';
$activeJobs = [];

// Scan for chunk files (pattern: originalname_chunk_001.wav)
foreach (glob($chunkDir . 'chunk_*.wav') as $chunkFile) {
    $filename = basename($chunkFile);
    
    // Extract original filename and chunk info
    if (preg_match('/^(.*?)chunk_(\d+)\.wav$/', $filename, $matches)) {
        $originalFile = 'converted.wav'; // Assuming original is mp3
        $chunkNumber = (int)$matches[2];
        
        // Check if txt file exists (completed transcription)
        $txtFile = $chunkDir . $originalFile . '.txt';
        if (!file_exists($txtFile)) {
            // Count total chunks by finding the highest chunk number
            $totalChunks = 0;
            foreach (glob($chunkDir . $originalFile . '_chunk_*.wav') as $f) {
                if (preg_match('/chunk_(\d+)\.wav$/', $f, $m)) {
                    $totalChunks = max($totalChunks, (int)$m[1]);
                }
            }
            
            // Estimate progress (current chunk / total chunks)
            $progress = $totalChunks > 0 ? round(($chunkNumber / $totalChunks) * 100) : 0;
            
            if (!isset($activeJobs[$originalFile])) {
                $activeJobs[$originalFile] = [
                    'jobId' => md5($originalFile), // Simple job ID based on filename
                    'audioFile' => $originalFile,
                    'status' => 'processing',
                    'progress' => $progress,
                    'currentChunk' => $chunkNumber,
                    'totalChunks' => $totalChunks
                ];
            } else {
                // Update with the highest chunk number found (most recent progress)
                if ($chunkNumber > $activeJobs[$originalFile]['currentChunk']) {
                    $activeJobs[$originalFile]['currentChunk'] = $chunkNumber;
                    $activeJobs[$originalFile]['progress'] = $progress;
                }
            }
        }
    }
}

// Convert associative array to indexed array for JSON
echo json_encode(array_values($activeJobs));
?>