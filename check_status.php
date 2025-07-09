<?php
header('Content-Type: application/json');

// Directory settings
$chunkDir = '/var/www/videostreamer/transcribe/';
$jobId = $_GET['jobId'] ?? '';

if (empty($jobId)) {
    http_response_code(400);
    die(json_encode(['error' => 'Job ID is required']));
}

// Find the job by matching filename (since jobId is md5 of filename)
$status = ['status' => 'not_found'];
foreach (glob($chunkDir . 'chunk_*.wav') as $chunkFile) {
    $filename = basename($chunkFile);
    
    if (preg_match('/^chunk_(\d+)\.wav$/', $filename, $matches)) {
        $originalFile = 'converted.wav';
        
        if (md5($originalFile) === $jobId) {
            // Check if transcription is complete
            $txtFile = $chunkDir . $originalFile . '.txt';
            if (file_exists($txtFile)) {
                $status = [
                    'status' => 'completed',
                    'fileName' => basename($txtFile),
                    'filePath' => '/transcribe/transcribed/' . basename($txtFile)
                ];
                break;
            }
            
            // Calculate progress
            $currentChunk = (int)$matches[2];
            $totalChunks = 0;
            foreach (glob($chunkDir . $originalFile . 'chunk_*.wav') as $f) {
                if (preg_match('/chunk_(\d+)\.wav$/', $f, $m)) {
                    $totalChunks = max($totalChunks, (int)$m[1]);
                }
            }
            
            $progress = $totalChunks > 0 ? round(($currentChunk / $totalChunks) * 100) : 0;
            
            $status = [
                'status' => 'processing',
                'progress' => $progress,
                'currentChunk' => $currentChunk,
                'totalChunks' => $totalChunks,
                'audioFile' => $originalFile
            ];
            break;
        }
    }
}

// If no chunks found but txt file exists, job is completed
if ($status['status'] === 'not_found') {
    foreach (glob($chunkDir . '*.txt') as $txtFile) {
        $originalFile = str_replace('.txt', '', basename($txtFile));
        if (md5($originalFile) === $jobId) {
            $status = [
                'status' => 'completed',
                'fileName' => basename($txtFile),
                'filePath' => '/transcribe/transcribed/' . basename($txtFile)
            ];
            break;
        }
    }
}

echo json_encode($status);
?>