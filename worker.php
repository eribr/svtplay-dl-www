<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib.php';

const JOBS_DIR = DATA_DIR . '/jobs';
const DOWNLOADS_DIR = DATA_DIR . '/downloads';
const POLL_INTERVAL_MICROSECONDS = 500000;

function setJobFailure(string $jobId, string $message): void
{
    updateJob($jobId, static function (array &$job) use ($message): void {
        $job['status'] = 'failed';
        $job['phase'] = 'failed';
        $job['error'] = $message;
        $job['pid'] = null;
        $job['process_name'] = null;
    });
}

function claimNextJob(): ?array
{
    $claimed = null;
    updateRegistry(static function (array &$registry) use (&$claimed): void {
        $pending = array_filter(
            $registry['jobs'],
            static fn (array $job): bool => ($job['status'] ?? '') === 'queued'
        );
        if ($pending === []) {
            return;
        }
        uasort($pending, static fn (array $left, array $right): int => strcmp($left['created_at'] ?? '', $right['created_at'] ?? ''));
        $jobId = (string) array_key_first($pending);
        $registry['jobs'][$jobId]['status'] = 'downloading';
        $registry['jobs'][$jobId]['phase'] = 'downloading';
        $registry['jobs'][$jobId]['worker_pid'] = getmypid();
        $registry['jobs'][$jobId]['pid'] = null;
        $registry['jobs'][$jobId]['process_name'] = null;
        $registry['jobs'][$jobId]['updated_at'] = gmdate(DATE_ATOM);
        $claimed = $registry['jobs'][$jobId];
    });
    return $claimed;
}

function runJobProcess(string $jobId, string $phase, string $processName, array $command, string $workingDirectory, string $logFile): int
{
    $process = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ],
        $pipes,
        $workingDirectory,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ' . $processName . '.');
    }

    $initialStatus = proc_get_status($process);
    $pid = (int) ($initialStatus['pid'] ?? 0);
    if ($pid < 2) {
        proc_close($process);
        throw new RuntimeException('Could not determine the ' . $processName . ' process ID.');
    }

    updateJob($jobId, static function (array &$job) use ($phase, $processName, $pid): void {
        $job['status'] = $phase;
        $job['phase'] = $phase;
        $job['process_name'] = $processName;
        $job['pid'] = $pid;
        $job['error'] = null;
    });

    $lastStatus = $initialStatus;
    while ($lastStatus['running']) {
        usleep(POLL_INTERVAL_MICROSECONDS);
        $lastStatus = proc_get_status($process);
    }
    $exitCode = (int) ($lastStatus['exitcode'] ?? -1);
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

    $extensionlessVideo = $jobDirectory . '/source';
    if ($videos === [] && is_file($extensionlessVideo)) {
        $videos[] = $extensionlessVideo;
    }
    if (count($videos) !== 1 || count($subtitles) !== 1) {
        throw new RuntimeException('Expected one downloaded video and one supported subtitle file. Check the worker log.');
    }

    return [$videos[0], $subtitles[0]];
}

function processJob(array $job): void
{
    $jobId = (string) $job['id'];
    $jobDirectory = JOBS_DIR . '/' . $jobId;
    $logFile = $jobDirectory . '/job.log';
    if (!mkdir($jobDirectory, 0750) && !is_dir($jobDirectory)) {
        throw new RuntimeException('Could not create the job directory.');
    }

    $downloadCommand = [
        SVTPLAY_BIN,
        '--subtitle',
        '--require-subtitle',
        '--output',
        $jobDirectory . '/',
        (string) $job['url'],
    ];
    $downloadExitCode = runJobProcess($jobId, 'downloading', 'svtplay-dl', $downloadCommand, $jobDirectory, $logFile);
    if ($downloadExitCode !== 0) {
        throw new RuntimeException('svtplay-dl failed with exit code ' . $downloadExitCode . '. Check the worker log.');
    }

    [$videoPath, $subtitlePath] = locateArtifacts($jobDirectory);
    $videoExtension = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
    $sourcePath = $jobDirectory . '/source.' . ($videoExtension !== '' ? $videoExtension : 'media');
    $subtitleExtension = strtolower(pathinfo($subtitlePath, PATHINFO_EXTENSION));
    $normalizedSubtitlePath = $jobDirectory . '/subtitles.' . $subtitleExtension;
    if (!rename($videoPath, $sourcePath) || !rename($subtitlePath, $normalizedSubtitlePath)) {
        throw new RuntimeException('Could not move downloaded media into the controlled job paths.');
    }

    $finalPath = DOWNLOADS_DIR . '/' . $jobId . '.mkv';
    $ffmpegCommand = [
        FFMPEG_BIN,
        '-nostdin',
        '-y',
        '-i',
        $sourcePath,
        '-vf',
        'subtitles=' . $normalizedSubtitlePath,
        '-c:v',
        'libx264',
        '-preset',
        'ultrafast',
        '-crf',
        '24',
        '-c:a',
        'copy',
        $finalPath,
    ];
    $ffmpegExitCode = runJobProcess($jobId, 'burning', 'ffmpeg', $ffmpegCommand, $jobDirectory, $logFile);
    if ($ffmpegExitCode !== 0 || !is_file($finalPath) || filesize($finalPath) === 0) {
        throw new RuntimeException('FFmpeg subtitle burn-in failed. Check the worker log.');
    }

    unlink($sourcePath);
    unlink($normalizedSubtitlePath);
    updateJob($jobId, static function (array &$storedJob) use ($finalPath): void {
        $storedJob['status'] = 'complete';
        $storedJob['phase'] = 'complete';
        $storedJob['pid'] = null;
        $storedJob['process_name'] = null;
        $storedJob['output'] = $finalPath;
        $storedJob['error'] = null;
    });
}

function markInterruptedJobs(): void
{
    updateRegistry(static function (array &$registry): void {
        foreach ($registry['jobs'] as &$job) {
            if (in_array($job['status'] ?? '', ['downloading', 'burning'], true)) {
                $job['status'] = 'failed';
                $job['phase'] = 'failed';
                $job['error'] = 'The background worker restarted before this job finished.';
                $job['pid'] = null;
                $job['process_name'] = null;
                $job['updated_at'] = gmdate(DATE_ATOM);
            }
        }
        unset($job);
    });
}

ensureDataDirectory();
if (!is_dir(JOBS_DIR) || !is_writable(JOBS_DIR) || !is_dir(DOWNLOADS_DIR) || !is_writable(DOWNLOADS_DIR)) {
    fwrite(STDERR, "Job and download directories must exist and be writable.\n");
    exit(1);
}
if (!is_executable(SVTPLAY_BIN) || !is_executable(FFMPEG_BIN)) {
    fwrite(STDERR, "svtplay-dl and ffmpeg must be installed at their configured paths.\n");
    exit(1);
}

markInterruptedJobs();
while (true) {
    $job = null;
    try {
        $job = claimNextJob();
        if ($job === null) {
            usleep(POLL_INTERVAL_MICROSECONDS);
            continue;
        }
        processJob($job);
    } catch (Throwable $exception) {
        if (isset($job['id']) && is_string($job['id'])) {
            try {
                setJobFailure($job['id'], $exception->getMessage());
            } catch (Throwable $registryException) {
                error_log('Could not mark SVT Play job failed: ' . $registryException->getMessage());
            }
        }
        error_log('SVT Play worker error: ' . $exception->getMessage());
        usleep(POLL_INTERVAL_MICROSECONDS);
    }
}