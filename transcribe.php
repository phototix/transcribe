<?php
// transcribe.php
header('Content-Type: application/json');

$logFile = __DIR__ . '/transcribe.log';

function log_message($message) {
    global $logFile;
    $timestamp = date('[Y-m-d H:i:s] ');
    file_put_contents($logFile, $timestamp . $message . "\n", FILE_APPEND);
}

$configPath = __DIR__ . '/config/credentials.php';
if (!file_exists($configPath)) {
    log_message("ERROR: Credentials file missing.");
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

log_message("Start transcription: File = $inputFile, Language = $language");

if (!$inputFile || !file_exists($inputFolder . $inputFile)) {
    log_message("ERROR: Invalid or missing file: $inputFile");
    echo json_encode(['success' => false, 'error' => 'Invalid or missing audio file.']);
    exit;
}

$inputPath = $inputFolder . $inputFile;
$outputText = '';

// Convert to WAV 16kHz mono
$ffmpegCommand = "ffmpeg -y -i " . escapeshellarg($inputPath) . " -ar 16000 -ac 1 -c:a pcm_s16le " . escapeshellarg($tempWav) . " 2>&1";
exec($ffmpegCommand, $ffmpegOut, $code);
log_message("FFmpeg command: $ffmpegCommand");
log_message("FFmpeg return code: $code");
log_message("FFmpeg output:\n" . implode("\n", $ffmpegOut));

if ($code !== 0 || !file_exists($tempWav)) {
    log_message("ERROR: FFmpeg conversion failed.");
    echo json_encode([
        'success' => false,
        'error' => 'FFmpeg conversion failed.',
        'ffmpeg_output' => implode("\n", $ffmpegOut)
    ]);
    exit;
}

$fileSize = filesize($tempWav);
log_message("Converted WAV size: $fileSize bytes");

if ($fileSize <= $maxSize) {
    $outputText .= transcribe_chunk($tempWav, $apiKey, $language);
} else {
    $durationSec = (int) shell_exec("ffprobe -i " . escapeshellarg($tempWav) . " -show_entries format=duration -v quiet -of csv=\"p=0\"");
    $chunkDuration = 60;
    $chunkIndex = 0;

    for ($start = 0; $start < $durationSec; $start += $chunkDuration) {
        $chunkFile = $chunkPrefix . $chunkIndex . '.wav';
        $cmd = "ffmpeg -y -i " . escapeshellarg($tempWav) . " -ss $start -t $chunkDuration -c copy " . escapeshellarg($chunkFile) . " 2>&1";
        exec($cmd, $splitOut, $splitCode);

        if (!file_exists($chunkFile)) {
            log_message("ERROR: Failed to create chunk $chunkFile");
            break;
        }

        if (filesize($chunkFile) > $maxSize) {
            $chunkDuration = max(10, $chunkDuration - 10);
            log_message("Chunk $chunkFile too large, reducing duration to $chunkDuration sec");
            continue;
        }

        log_message("Processing chunk $chunkIndex from $start sec");
        $outputText .= transcribe_chunk($chunkFile, $apiKey, $language);
        unlink($chunkFile);
        $chunkIndex++;
    }
}

unlink($tempWav);
log_message("Finished transcription. Output length: " . strlen($outputText));

echo json_encode(['success' => true, 'text' => trim($outputText)]);

function transcribe_chunk($filePath, $apiKey, $language) {
    log_message("Sending chunk to OpenAI: " . basename($filePath));
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

    if ($httpCode === 200 && $response) {
        log_message("Chunk transcription succeeded");
        return $response . "\n";
    } else {
        log_message("ERROR: Chunk transcription failed. HTTP Code: $httpCode");
        return "[Chunk failed]\n";
    }
}
