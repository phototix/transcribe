<?php
$inputFolder = '/var/www/cloud.webbypage.com/data/brandonchong/files/AI-Workplace/Audio/';
$files = glob($inputFolder . '*.mp3');
$filenames = array_map('basename', $files);
header('Content-Type: application/json');
echo json_encode($filenames);
