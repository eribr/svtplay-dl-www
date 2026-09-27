<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$configuredUser = getenv('SVTPLAY_USERNAME');
$configuredPasswordHash = getenv('SVTPLAY_PASSWORD_HASH');
$providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$providedPassword = $_SERVER['PHP_AUTH_PW'] ?? '';

if (!is_string($configuredUser) || $configuredUser === '' || !is_string($configuredPasswordHash) || $configuredPasswordHash === '') {
    http_response_code(503);
    exit('The application is not configured. Set SVTPLAY_USERNAME and SVTPLAY_PASSWORD_HASH in Apache.');
}

if (!hash_equals($configuredUser, $providedUser) || !password_verify($providedPassword, $configuredPasswordHash)) {
    header('WWW-Authenticate: Basic realm="SVT Play Downloader", charset="UTF-8"');
    http_response_code(401);
    exit('Authentication required.');
}

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
            if (!isWorkerActive()) {
                throw new RuntimeException('The background worker is not running. Contact the server administrator.');
            }

            $jobId = bin2hex(random_bytes(16));
            updateRegistry(static function (array &$registry) use ($jobId, $normalizedUrl): void {
                $now = gmdate(DATE_ATOM);
                $registry['jobs'][$jobId] = [
                    'id' => $jobId,
                    'url' => $normalizedUrl,
                    'status' => 'queued',
                    'phase' => 'queued',
                    'pid' => null,
                    'process_name' => null,
                    'output' => null,
                    'error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });

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
    if (in_array($status, ['downloading', 'burning'], true)) {
        $expectedName = ($job['process_name'] ?? '') === 'ffmpeg' ? 'ffmpeg' : 'svtplay-dl';
        $pid = filter_var($job['pid'] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false || $pid === null) {
            return 'Starting';
        }
        $jobId = (string) ($job['id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
            return 'Process stopped unexpectedly';
        }
        $jobDirectory = DATA_DIR . '/jobs/' . $jobId;
        $requiredArguments = $status === 'downloading'
            ? ['--output', $jobDirectory . '/', (string) ($job['url'] ?? '')]
            : [DATA_DIR . '/downloads/' . $jobId . '.mkv'];
        if (processIsExpected((int) $pid, $expectedName, $requiredArguments)) {
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
    static fn (array $job): bool => in_array($job['status'] ?? '', ['queued', 'downloading', 'burning'], true)
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
                        : (in_array($job['status'] ?? '', ['queued', 'downloading', 'burning'], true) ? 'running' : escapeHtml((string) ($job['status'] ?? 'unknown')));
                    ?>
                    <article class="job">
                        <div class="job-url"><?= escapeHtml((string) ($job['url'] ?? '')) ?></div>
                        <span class="job-status <?= $statusClass ?>"><?= escapeHtml($statusLabel) ?></span>
                        <div class="job-meta">
                            Job <?= escapeHtml((string) ($job['id'] ?? '')) ?>
                            · PID <?= escapeHtml((string) ($job['pid'] ?? 'pending')) ?>
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