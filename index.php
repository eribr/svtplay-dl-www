<?php
declare(strict_types=1);

const DATA_DIR = '/tmp/svtplay-dl-www';
const REGISTRY_FILE = DATA_DIR . '/jobs.json';
const LOCK_FILE = DATA_DIR . '/jobs.lock';
const JOBS_DIR = DATA_DIR . '/jobs';
const DOWNLOADS_DIR = '/var/lib/svtplay/downloads';
const PHP_CLI_BIN = '/usr/bin/php';
const SVTPLAY_BIN = '/opt/svtplay-dl-venv/bin/svtplay-dl';
const FFMPEG_BIN = '/usr/bin/ffmpeg';
const POLL_INTERVAL_MICROSECONDS = 500000;

function ensureStorage(): void
{
    foreach ([DATA_DIR, JOBS_DIR, DOWNLOADS_DIR] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the job-data directory.');
        }
        chmod($directory, 0750);
    }
}

function readRegistry(): array
{
    ensureStorage();
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the job registry lock.');
    }
    chmod(LOCK_FILE, 0640);
    try {
        if (!flock($lock, LOCK_SH)) {
            throw new RuntimeException('Could not lock the job registry.');
        }
        if (!is_file(REGISTRY_FILE)) {
            return ['jobs' => []];
        }
        $contents = file_get_contents(REGISTRY_FILE);
        $registry = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($registry) || !isset($registry['jobs']) || !is_array($registry['jobs'])) {
            throw new RuntimeException('The job registry is invalid JSON.');
        }
        return $registry;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function updateRegistry(callable $update): array
{
    ensureStorage();
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the job registry lock.');
    }
    chmod(LOCK_FILE, 0640);
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the job registry.');
        }
        $registry = ['jobs' => []];
        if (is_file(REGISTRY_FILE)) {
            $contents = file_get_contents(REGISTRY_FILE);
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
                throw new RuntimeException('The job registry is invalid JSON.');
            }
            $registry = $decoded;
        }
        $update($registry);
        $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryFile = tempnam(DATA_DIR, '.jobs-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Could not create a temporary registry file.');
        }
        try {
            if (file_put_contents($temporaryFile, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the job registry.');
            }
            chmod($temporaryFile, 0640);
            if (!rename($temporaryFile, REGISTRY_FILE)) {
                throw new RuntimeException('Could not atomically replace the job registry.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        return $registry;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function updateJob(string $jobId, callable $update): void
{
    updateRegistry(static function (array &$registry) use ($jobId, $update): void {
        if (!isset($registry['jobs'][$jobId])) {
            throw new RuntimeException('The requested job does not exist.');
        }
        $update($registry['jobs'][$jobId]);
        $registry['jobs'][$jobId]['updated_at'] = gmdate(DATE_ATOM);
    });
}

function normalizeSvtplayUrl(string $input): string
{
    $url = trim($input);
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1 || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) === 1) {
        throw new InvalidArgumentException('Enter a valid SVT Play video URL.');
    }
    if (str_contains($url, '\\')) {
        throw new InvalidArgumentException('Backslashes are not allowed in the URL.');
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !in_array(strtolower((string) ($parts['host'] ?? '')), ['svtplay.se', 'www.svtplay.se'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])) {
        throw new InvalidArgumentException('Only HTTPS URLs from svtplay.se are accepted.');
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('~^/video/([A-Za-z0-9]+)(?:/([A-Za-z0-9-]+))?/?$~D', $path, $matches) !== 1) {
        throw new InvalidArgumentException('The URL must point to an SVT Play video page.');
    }
    $canonicalPath = '/video/' . $matches[1];
    if (isset($matches[2]) && $matches[2] !== '') {
        $canonicalPath .= '/' . $matches[2];
    }
    return 'https://www.svtplay.se' . $canonicalPath;
}

function processIsExpected(int $pid, string $expectedName, array $requiredArguments = []): bool
{
    $commandLinePath = '/proc/' . $pid . '/cmdline';
    if ($pid < 2 || !is_readable($commandLinePath)) {
        return false;
    }
    $commandLine = file_get_contents($commandLinePath);
    if (!is_string($commandLine) || $commandLine === '') {
        return false;
    }
    $arguments = explode("\0", rtrim($commandLine, "\0"));
    $executableMatches = isset($arguments[0]) && basename($arguments[0]) === $expectedName;
    if ($expectedName === 'php' && isset($arguments[0]) && preg_match('/^php(?:[0-9.]*)$/', basename($arguments[0])) === 1) {
        $executableMatches = true;
    }
    if ($expectedName === 'svtplay-dl' && in_array(SVTPLAY_BIN, $arguments, true)) {
        $executableMatches = true;
    }
    foreach ($requiredArguments as $requiredArgument) {
        if (!in_array($requiredArgument, $arguments, true)) {
            return false;
        }
    }
    return $executableMatches;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function spawnJob(string $jobId, string $logFile): int
{
    $launcher = 'nohup "$1" "$2" --run-job "$3" </dev/null >>"$4" 2>&1 & echo $!';
    $process = proc_open(
        ['/bin/sh', '-c', $launcher, 'svtplay-launcher', PHP_CLI_BIN, __FILE__, $jobId, $logFile],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the background job.');
    }
    $pidText = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $launcherExitCode = proc_close($process);
    $pid = filter_var(trim((string) $pidText), FILTER_VALIDATE_INT);
    if ($launcherExitCode !== 0 || $pid === false || $pid < 2) {
        throw new RuntimeException('Could not start the background job.');
    }
    return $pid;
}

function runExternalProcess(string $jobId, string $phase, string $name, array $command, string $jobDirectory, string $logFile): int
{
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        $jobDirectory,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ' . $name . '.');
    }
    $status = proc_get_status($process);
    $pid = (int) ($status['pid'] ?? 0);
    if ($pid < 2) {
        proc_close($process);
        throw new RuntimeException('Could not determine the ' . $name . ' process ID.');
    }
    updateJob($jobId, static function (array &$job) use ($phase, $name, $pid): void {
        $job['status'] = $phase;
        $job['phase'] = $phase;
        $job['pid'] = $pid;
        $job['process_name'] = $name;
        $job['error'] = null;
    });
    while ($status['running']) {
        usleep(POLL_INTERVAL_MICROSECONDS);
        $status = proc_get_status($process);
    }
    $exitCode = (int) ($status['exitcode'] ?? -1);
    proc_close($process);
    updateJob($jobId, static function (array &$job): void {
        $job['pid'] = null;
        $job['process_name'] = null;
    });
    return $exitCode;
}

function locateArtifacts(string $jobDirectory): array
{
    $videoExtensions = ['mp4', 'mkv', 'ts', 'webm', 'm4v', 'mov', 'avi', 'flv', 'mpg', 'mpeg'];
    $subtitleExtensions = ['srt', 'vtt', 'ass', 'ssa'];
    $videos = [];
    $subtitles = [];
    foreach (new DirectoryIterator($jobDirectory) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (in_array($extension, $videoExtensions, true)) {
            $videos[] = $file->getPathname();
        } elseif (in_array($extension, $subtitleExtensions, true)) {
            $subtitles[] = $file->getPathname();
        }
    }
    if (count($videos) !== 1 || count($subtitles) !== 1) {
        throw new RuntimeException('Expected one video and one supported subtitle file. Check the job log.');
    }
    return [$videos[0], $subtitles[0]];
}

function runJob(string $jobId): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
        throw new RuntimeException('Invalid job ID.');
    }
    $job = null;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $registry = readRegistry();
        $candidate = $registry['jobs'][$jobId] ?? null;
        if (is_array($candidate) && (int) ($candidate['worker_pid'] ?? 0) === getmypid()) {
            $job = $candidate;
            break;
        }
        usleep(50000);
    }
    if (!is_array($job)) {
        throw new RuntimeException('The job was not registered for this process.');
    }
    ensureStorage();
    $jobDirectory = JOBS_DIR . '/' . $jobId;
    if (!mkdir($jobDirectory, 0750) && !is_dir($jobDirectory)) {
        throw new RuntimeException('Could not create the job directory.');
    }
    $logFile = $jobDirectory . '/job.log';
    $downloadCommand = [SVTPLAY_BIN, '--subtitle', '--require-subtitle', '--output', $jobDirectory . '/', (string) $job['url']];
    $downloadExitCode = runExternalProcess($jobId, 'downloading', 'svtplay-dl', $downloadCommand, $jobDirectory, $logFile);
    if ($downloadExitCode !== 0) {
        throw new RuntimeException('svtplay-dl failed with exit code ' . $downloadExitCode . '. Check the job log.');
    }
    [$videoPath, $subtitlePath] = locateArtifacts($jobDirectory);
    $subtitleExtension = strtolower(pathinfo($subtitlePath, PATHINFO_EXTENSION));
    if (!in_array($subtitleExtension, ['srt', 'vtt', 'ass', 'ssa'], true)) {
        throw new RuntimeException('The downloaded subtitle format is not supported.');
    }
    $safeSubtitlePath = $jobDirectory . '/subtitle.' . $subtitleExtension;
    if (!copy($subtitlePath, $safeSubtitlePath)) {
        throw new RuntimeException('Could not prepare the subtitle file for FFmpeg.');
    }
    $finalPath = DOWNLOADS_DIR . '/' . $jobId . '.mkv';
    $ffmpegCommand = [
        FFMPEG_BIN, '-nostdin', '-y', '-i', $videoPath,
        '-vf', 'subtitles=' . $safeSubtitlePath,
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '24', '-c:a', 'copy', $finalPath,
    ];
    $ffmpegExitCode = runExternalProcess($jobId, 'burning', 'ffmpeg', $ffmpegCommand, $jobDirectory, $logFile);
    if ($ffmpegExitCode !== 0 || !is_file($finalPath) || filesize($finalPath) === 0) {
        throw new RuntimeException('FFmpeg subtitle burn-in failed. Check the job log.');
    }
    foreach ([$videoPath, $subtitlePath, $safeSubtitlePath] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
    updateJob($jobId, static function (array &$storedJob) use ($finalPath): void {
        $storedJob['status'] = 'complete';
        $storedJob['phase'] = 'complete';
        $storedJob['pid'] = null;
        $storedJob['process_name'] = null;
        $storedJob['worker_pid'] = null;
        $storedJob['output'] = $finalPath;
        $storedJob['error'] = null;
    });
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--run-job') {
    try {
        runJob((string) ($argv[2] ?? ''));
        exit(0);
    } catch (Throwable $exception) {
        $jobId = (string) ($argv[2] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $jobId) === 1) {
            try {
                updateJob($jobId, static function (array &$job) use ($exception): void {
                    $job['status'] = 'failed';
                    $job['phase'] = 'failed';
                    $job['pid'] = null;
                    $job['process_name'] = null;
                    $job['worker_pid'] = null;
                    $job['error'] = $exception->getMessage();
                });
            } catch (Throwable $registryException) {
                error_log('Could not update failed job: ' . $registryException->getMessage());
            }
        }
        error_log('SVT Play job failed: ' . $exception->getMessage());
        exit(1);
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        $errorMessage = 'The form expired. Reload the page and try again.';
    } else {
        try {
            $rawUrl = $_POST['url'] ?? '';
            if (!is_string($rawUrl) || strlen($rawUrl) > 2048) {
                throw new InvalidArgumentException('Enter a valid SVT Play video URL.');
            }
            $normalizedUrl = normalizeSvtplayUrl($rawUrl);
            $jobId = bin2hex(random_bytes(16));
            $jobDirectory = JOBS_DIR . '/' . $jobId;
            if (!mkdir($jobDirectory, 0750, true)) {
                throw new RuntimeException('Could not create the job directory.');
            }
            $logFile = $jobDirectory . '/job.log';
            updateRegistry(static function (array &$registry) use ($jobId, $normalizedUrl): void {
                $now = gmdate(DATE_ATOM);
                $registry['jobs'][$jobId] = [
                    'id' => $jobId,
                    'url' => $normalizedUrl,
                    'status' => 'queued',
                    'phase' => 'queued',
                    'pid' => null,
                    'worker_pid' => null,
                    'process_name' => null,
                    'output' => null,
                    'error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });

            try {
                $workerPid = spawnJob($jobId, $logFile);
                updateJob($jobId, static function (array &$job) use ($workerPid): void {
                    $job['status'] = 'starting';
                    $job['phase'] = 'starting';
                    $job['worker_pid'] = $workerPid;
                });
            } catch (Throwable $exception) {
                updateJob($jobId, static function (array &$job) use ($exception): void {
                    $job['status'] = 'failed';
                    $job['phase'] = 'failed';
                    $job['error'] = $exception->getMessage();
                });
                throw $exception;
            }

            $_SESSION['flash_message'] = 'Download queued. Job ID: ' . $jobId;
            $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/svtplay/index.php'));
            $redirectPath = rtrim($scriptDirectory, '/');
            header('Location: ' . ($redirectPath === '' ? '/' : $redirectPath . '/'), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('SVT Play job submission failed: ' . $exception->getMessage());
            $errorMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The job could not be submitted. Check the server log.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

try {
    $jobs = array_values(readRegistry()['jobs']);
    usort($jobs, static fn (array $left, array $right): int => strcmp($right['created_at'] ?? '', $left['created_at'] ?? ''));
} catch (Throwable $exception) {
    error_log('SVT Play registry read failed: ' . $exception->getMessage());
    $jobs = [];
    $errorMessage = $errorMessage !== '' ? $errorMessage : 'Job status is temporarily unavailable.';
}

function displayedJobStatus(array $job): string
{
    $status = (string) ($job['status'] ?? 'unknown');
    if ($status === 'queued') {
        return 'Queued';
    }
    if ($status === 'starting') {
        $jobId = (string) ($job['id'] ?? '');
        $workerPid = filter_var($job['worker_pid'] ?? null, FILTER_VALIDATE_INT);
        if (preg_match('/^[a-f0-9]{32}$/', $jobId) === 1
            && $workerPid !== false
            && processIsExpected((int) $workerPid, 'php', [__FILE__, '--run-job', $jobId])) {
            return 'Starting';
        }
        return 'Process stopped unexpectedly';
    }
    if (in_array($status, ['downloading', 'burning'], true)) {
        $jobId = (string) ($job['id'] ?? '');
        $workerPid = filter_var($job['worker_pid'] ?? null, FILTER_VALIDATE_INT);
        $workerIsRunning = preg_match('/^[a-f0-9]{32}$/', $jobId) === 1
            && $workerPid !== false
            && processIsExpected((int) $workerPid, 'php', [__FILE__, '--run-job', $jobId]);
        $expectedName = ($job['process_name'] ?? '') === 'ffmpeg' ? 'ffmpeg' : 'svtplay-dl';
        $pid = filter_var($job['pid'] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid === null) {
            return $workerIsRunning ? 'Starting' : 'Process stopped unexpectedly';
        }
        if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
            return 'Process stopped unexpectedly';
        }
        $jobDirectory = DATA_DIR . '/jobs/' . $jobId;
        $requiredArguments = $status === 'downloading'
            ? [SVTPLAY_BIN, '--output', $jobDirectory . '/', (string) ($job['url'] ?? '')]
            : [DOWNLOADS_DIR . '/' . $jobId . '.mkv'];
        if ($workerIsRunning && processIsExpected((int) $pid, $expectedName, $requiredArguments)) {
            return $status === 'downloading' ? 'Downloading' : 'Burning subtitles';
        }
        return 'Process stopped unexpectedly';
    }
    return match ($status) {
        'complete' => 'Complete',
        'failed' => 'Failed',
        default => ucfirst($status),
    };
}

$csrfToken = (string) $_SESSION['csrf_token'];
$hasActiveJobs = count(array_filter(
    $jobs,
    static fn (array $job): bool => in_array($job['status'] ?? '', ['queued', 'starting', 'downloading', 'burning'], true)
)) > 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($hasActiveJobs): ?>
        <meta http-equiv="refresh" content="5">
    <?php endif; ?>
    <title>SVT Play Downloader</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #192522;
            --muted: #64716c;
            --line: #d8e0dc;
            --paper: #f4f6f2;
            --panel: #ffffff;
            --green: #17684f;
            --green-dark: #104936;
            --amber: #9a5a12;
            --red: #a33232;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: linear-gradient(135deg, #e9efea 0%, var(--paper) 42%, #f6f3ec 100%);
            color: var(--ink);
            font: 16px/1.5 "Aptos", "Segoe UI", sans-serif;
        }
        main { width: min(100% - 32px, 1020px); margin: 44px auto; }
        header { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 26px; }
        h1 { margin: 0; font-size: 30px; line-height: 1.1; font-weight: 720; }
        .eyebrow { margin: 0 0 7px; color: var(--green); font-size: 12px; font-weight: 750; text-transform: uppercase; }
        .count { color: var(--muted); white-space: nowrap; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 6px; }
        .form-panel { padding: 22px; border-top: 3px solid var(--green); }
        label { display: block; margin-bottom: 8px; font-weight: 650; }
        .form-row { display: flex; gap: 10px; }
        input[type="url"] { min-width: 0; flex: 1; height: 46px; padding: 0 13px; border: 1px solid #aebbb4; border-radius: 4px; color: var(--ink); font: inherit; }
        input[type="url"]:focus { outline: 3px solid #b7d6c7; border-color: var(--green); }
        button { min-height: 46px; padding: 0 18px; border: 0; border-radius: 4px; background: var(--green); color: white; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: var(--green-dark); }
        .notice { margin: 16px 0 0; padding: 11px 13px; border-left: 3px solid var(--green); background: #edf5ef; }
        .notice.error { border-color: var(--red); background: #fff0ef; }
        h2 { margin: 32px 0 12px; font-size: 20px; }
        .job-list { overflow: hidden; }
        .job { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 18px; padding: 17px 20px; border-bottom: 1px solid var(--line); }
        .job:last-child { border-bottom: 0; }
        .job-url { overflow-wrap: anywhere; font-weight: 650; }
        .job-meta { color: var(--muted); font-size: 13px; }
        .job-status { align-self: start; padding: 3px 9px; border: 1px solid var(--line); border-radius: 20px; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .job-status.running, .job-status.queued { color: var(--green-dark); border-color: #b9d4c6; background: #edf5ef; }
        .job-status.failed { color: var(--red); border-color: #e8c4c1; background: #fff0ef; }
        .empty { padding: 24px 20px; color: var(--muted); }
        @media (max-width: 620px) {
            main { width: min(100% - 22px, 1020px); margin: 24px auto; }
            header { align-items: start; flex-direction: column; gap: 5px; }
            h1 { font-size: 26px; }
            .form-panel { padding: 16px; }
            .form-row { flex-direction: column; }
            button { width: 100%; }
            .job { grid-template-columns: minmax(0, 1fr); }
            .job-status { grid-row: 1; justify-self: start; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <p class="eyebrow">SVT Play</p>
            <h1>Video downloads</h1>
        </div>
        <div class="count"><?= count($jobs) ?> recorded jobs</div>
    </header>

    <section class="panel form-panel" aria-labelledby="download-heading">
        <form method="post" action="">
            <label id="download-heading" for="url">SVT Play video URL</label>
            <div class="form-row">
                <input id="url" name="url" type="url" inputmode="url" maxlength="2048" placeholder="https://www.svtplay.se/video/..." required>
                <button type="submit">Download video</button>
            </div>
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
        </form>
        <?php if (is_string($flashMessage) && $flashMessage !== ''): ?>
            <p class="notice" role="status"><?= escapeHtml($flashMessage) ?></p>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <p class="notice error" role="alert"><?= escapeHtml($errorMessage) ?></p>
        <?php endif; ?>
    </section>

    <section aria-labelledby="jobs-heading">
        <h2 id="jobs-heading">Download jobs</h2>
        <div class="panel job-list">
            <?php if ($jobs === []): ?>
                <div class="empty">No downloads have been started.</div>
            <?php else: ?>
                <?php foreach ($jobs as $job): ?>
                    <?php
                    $statusLabel = displayedJobStatus($job);
                    $statusClass = $statusLabel === 'Process stopped unexpectedly'
                        ? 'failed'
                        : (in_array($job['status'] ?? '', ['queued', 'starting', 'downloading', 'burning'], true) ? 'running' : escapeHtml((string) ($job['status'] ?? 'unknown')));
                    $displayPid = $job['pid'] ?? $job['worker_pid'] ?? 'pending';
                    ?>
                    <article class="job">
                        <div class="job-url"><?= escapeHtml((string) ($job['url'] ?? '')) ?></div>
                        <span class="job-status <?= $statusClass ?>"><?= escapeHtml($statusLabel) ?></span>
                        <div class="job-meta">
                            Job <?= escapeHtml((string) ($job['id'] ?? '')) ?>
                            · PID <?= escapeHtml((string) $displayPid) ?>
                            · <?= escapeHtml((string) ($job['updated_at'] ?? '')) ?>
                        </div>
                        <?php if (($job['status'] ?? '') === 'failed' && !empty($job['error'])): ?>
                            <div class="job-meta"><?= escapeHtml((string) $job['error']) ?></div>
                        <?php elseif (($job['status'] ?? '') === 'complete' && !empty($job['output'])): ?>
                            <div class="job-meta">Output: <?= escapeHtml((string) $job['output']) ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>