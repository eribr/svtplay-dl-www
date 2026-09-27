<?php
declare(strict_types=1);

const DATA_DIR = '/var/lib/svtplay';
const REGISTRY_FILE = DATA_DIR . '/jobs.json';
const LOCK_FILE = DATA_DIR . '/jobs.lock';
const WORKER_SERVICE = 'svtplay-worker.service';
const SVTPLAY_BIN = '/opt/svtplay-dl-venv/bin/svtplay-dl';
const FFMPEG_BIN = '/usr/bin/ffmpeg';

function ensureDataDirectory(): void
{
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        throw new RuntimeException('The job-data directory is missing or not writable by the web server.');
    }
}

function readRegistry(): array
{
    ensureDataDirectory();
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the job registry lock.');
    }

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
    ensureDataDirectory();
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the job registry lock.');
    }

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

function isWorkerActive(): bool
{
    $process = proc_open(
        ['/usr/bin/systemctl', 'is-active', '--quiet', WORKER_SERVICE],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return false;
    }
    return proc_close($process) === 0;
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

function processIsExpected(int $pid, string $expectedName, array $requiredArguments = []): bool
{
    if ($pid < 2 || !is_readable('/proc/' . $pid . '/cmdline')) {
        return false;
    }
    $commandLine = file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($commandLine) || $commandLine === '') {
        return false;
    }
    $arguments = explode("\0", rtrim($commandLine, "\0"));
    $executableMatches = isset($arguments[0]) && basename($arguments[0]) === $expectedName;
    if ($expectedName === 'svtplay-dl' && in_array(SVTPLAY_BIN, $arguments, true)) {
        $executableMatches = true;
    }
    if (!$executableMatches) {
        return false;
    }
    foreach ($requiredArguments as $requiredArgument) {
        if (!in_array($requiredArgument, $arguments, true)) {
            return false;
        }
    }
    return true;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}