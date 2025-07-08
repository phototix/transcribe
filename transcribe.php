<?php
// transcribe.php
header('Content-Type: application/json');

$configPath = __DIR__ . '/config/credentials.php';
if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'error' => 'Credentials file missing.']);
    exit;
}
$config = require $configPath;
$apiKey = $config['openai_api_key'];
$inputFolder = '/var/www/cloud.webbypage.com/data/brandonchong/files/AI-Workplace/Audio/';
$maxSize = 25000000;
$chunkPrefix = 'chunk_';
$tempWav = 'converted.wav';

$data = json_decode(file_get_contents('php://input'), true);
$inputFile = basename($data['file'] ?? '');
$language = $data['language'] ?? 'en';

if (!$inputFile || !file_exists($inputFolder . $inputFile)) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing audio file.']);
    exit;
}

$inputPath = $inputFolder . $inputFile;
$outputText = '';

// Convert to WAV 16kHz mono
exec("ffmpeg -y -i " . escapeshellarg($inputPath) . " -ar 16000 -ac 1 -c:a pcm_s16le " . escapeshellarg($tempWav), $out, $code);
if ($code !== 0 || !file_exists($tempWav)) {
    echo json_encode(['success' => false, 'error' => 'FFmpeg conversion failed.']);
    exit;
}

$fileSize = filesize($tempWav);
if ($fileSize <= $maxSize) {
    $outputText .= transcribe_chunk($tempWav, $apiKey, $language);
} else {
    $durationSec = (int) shell_exec("ffprobe -i " . escapeshellarg($tempWav) . " -show_entries format=duration -v quiet -of csv=\"p=0\"");
    $chunkDuration = 60;
    $chunkIndex = 0;

    for ($start = 0; $start < $durationSec; $start += $chunkDuration) {
        $chunkFile = $chunkPrefix . $chunkIndex . '.wav';
        $cmd = "ffmpeg -y -i " . escapeshellarg($tempWav) . " -ss $start -t $chunkDuration -c copy " . escapeshellarg($chunkFile);
        exec($cmd, $splitOut, $splitCode);

        if (!file_exists($chunkFile)) break;

        if (filesize($chunkFile) > $maxSize) {
            $chunkDuration = max(10, $chunkDuration - 10);
            continue;
        }

        $outputText .= transcribe_chunk($chunkFile, $apiKey, $language);
        unlink($chunkFile);
        $chunkIndex++;
    }
}

unlink($tempWav);

echo json_encode(['success' => true, 'text' => trim($outputText)]);

function transcribe_chunk($filePath, $apiKey, $language) {
    $ch = curl_init();
    $postFields = [
        'file' => new CURLFile($filePath, 'audio/wav', basename($filePath)),
        'model' => 'whisper-1',
        'response_format' => 'text',
        'language' => $language
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/audio/transcriptions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 && $response) ? ($response . "\n") : "[Chunk failed]\n";
}
