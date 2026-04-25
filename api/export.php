<?php
header('Content-Type: application/json');

$jobsDir = __DIR__ . '/../jobs/';
$outputDir = __DIR__ . '/../output/';
if (!is_dir($jobsDir)) mkdir($jobsDir, 0755, true);
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

$projectData = json_decode($_POST['project'] ?? '{}', true);
if (empty($projectData['clips'])) {
    echo json_encode(['error' => 'No clips in project']);
    exit;
}

$jobId = uniqid('export_', true);
$jobDir = $jobsDir . $jobId . '/';
mkdir($jobDir, 0755, true);

// Save uploaded clip files
foreach ($_FILES as $key => $file) {
    if (strpos($key, 'clip_') === 0 && $file['error'] === 0) {
        $idx = str_replace('clip_', '', $key);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'mp4';
        move_uploaded_file($file['tmp_name'], $jobDir . 'clip_' . $idx . '.' . $ext);
        $projectData['clips'][$idx]['local'] = $jobDir . 'clip_' . $idx . '.' . $ext;
    }
}

// For Grok-generated images (URL-based), download them
foreach ($projectData['clips'] as $i => &$clip) {
    if (empty($clip['local']) && !empty($clip['url'])) {
        $localFile = $jobDir . 'clip_' . $i . '.png';
        $srcUrl = $clip['url'];
        if (strpos($srcUrl, '/') === 0) {
            $srcUrl = '/var/www/vhosts/shortfactory.shop/httpdocs' . $srcUrl;
            if (file_exists($srcUrl)) {
                copy($srcUrl, $localFile);
            }
        }
        $clip['local'] = $localFile;
    }
}
unset($clip);

// Save project manifest
file_put_contents($jobDir . 'manifest.json', json_encode($projectData, JSON_PRETTY_PRINT));

// Save job status
$status = [
    'status' => 'queued',
    'progress' => 0,
    'status_msg' => 'Queued for processing',
    'created' => time()
];
file_put_contents($jobDir . 'status.json', json_encode($status));

// Kick off FFmpeg merge in background
$resolution = $projectData['resolution'] ?? '1080x1920';
$quality = $projectData['quality'] ?? 'medium';
$crf = ['high' => 18, 'medium' => 23, 'low' => 28][$quality] ?? 23;
list($w, $h) = explode('x', $resolution);
$outputFile = $outputDir . $jobId . '.mp4';

// Build FFmpeg concat script
$concatList = '';
$filterParts = [];
$inputs = '';
$n = 0;

foreach ($projectData['clips'] as $i => $clip) {
    $src = $clip['local'] ?? '';
    if (!file_exists($src)) continue;

    if ($clip['type'] === 'image') {
        $inputs .= "-loop 1 -t {$clip['duration']} -i \"{$src}\" ";
    } else {
        $dur = $clip['duration'];
        $inputs .= "-t {$dur} -i \"{$src}\" ";
    }
    $filterParts[] = "[{$n}:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps=30[v{$n}]";
    $n++;
}

if ($n === 0) {
    $status['status'] = 'error';
    $status['message'] = 'No valid clips found';
    file_put_contents($jobDir . 'status.json', json_encode($status));
    echo json_encode(['error' => 'No valid clips']);
    exit;
}

$concatInputs = '';
for ($i = 0; $i < $n; $i++) $concatInputs .= "[v{$i}]";
$filter = implode(';', $filterParts) . ";{$concatInputs}concat=n={$n}:v=1:a=0[out]";

$cmd = "ffmpeg -y {$inputs} -filter_complex \"{$filter}\" -map \"[out]\" -c:v libx264 -crf {$crf} -preset medium -pix_fmt yuv420p \"{$outputFile}\" 2>&1";

// Save command for debugging
file_put_contents($jobDir . 'ffmpeg_cmd.txt', $cmd);

// Update status and run
$status['status'] = 'processing';
$status['status_msg'] = 'Building video...';
$status['progress'] = 20;
file_put_contents($jobDir . 'status.json', json_encode($status));

// Run in background
$bgCmd = "nohup bash -c '" . str_replace("'", "'\\''", $cmd) . " && echo DONE > \"{$jobDir}done.flag\" || echo FAIL > \"{$jobDir}done.flag\"' > \"{$jobDir}ffmpeg.log\" 2>&1 &";
exec($bgCmd);

// Monitor script — updates status.json as ffmpeg runs
$monitorScript = <<<'BASH'
#!/bin/bash
JOB_DIR="$1"
OUTPUT="$2"
while [ ! -f "${JOB_DIR}done.flag" ]; do
    sleep 2
    if [ -f "$OUTPUT" ]; then
        SIZE=$(stat -f%z "$OUTPUT" 2>/dev/null || stat -c%s "$OUTPUT" 2>/dev/null)
        echo "{\"status\":\"processing\",\"progress\":50,\"status_msg\":\"Encoding... ${SIZE} bytes\"}" > "${JOB_DIR}status.json"
    fi
done
FLAG=$(cat "${JOB_DIR}done.flag")
if [ "$FLAG" = "DONE" ]; then
    echo "{\"status\":\"done\",\"progress\":100,\"url\":\"/shorts/output/JOB_ID.mp4\"}" > "${JOB_DIR}status.json"
else
    echo "{\"status\":\"error\",\"message\":\"FFmpeg failed. Check logs.\"}" > "${JOB_DIR}status.json"
fi
BASH;

$monitorScript = str_replace('JOB_ID', $jobId, $monitorScript);
file_put_contents($jobDir . 'monitor.sh', $monitorScript);
exec("nohup bash \"{$jobDir}monitor.sh\" \"{$jobDir}\" \"{$outputFile}\" > /dev/null 2>&1 &");

echo json_encode([
    'job_id' => $jobId,
    'status' => 'processing',
    'clips' => $n
]);
