# Requirements: SVT Play Downloader

## Goal

A PHP web page shall let a user start a download from SVT Play using `svtplay-dl` and show which downloads are still running.

## User flow

1. The home page (`index.php`) displays a form with a field for an SVT Play URL and a button to start the download.
2. The form submits a POST request to the same PHP endpoint that displays the home page.
3. The server validates and normalizes the URL before using it.
4. The server starts `svtplay-dl` asynchronously with the URL as input.
5. The server stores the URL and process ID (PID) on disk.
6. The home page lists saved download jobs and indicates which processes are still running.

## Functional requirements

- The PHP page shall be named `index.php` and be located in the project root. Installation shall create a real `/var/www/html/svtplay` directory and symlink only the project's `index.php` to `/var/www/html/svtplay/index.php`; it shall not symlink the whole project directory. Apache shall use `DirectoryIndex index.php` so `http://<webserver>/svtplay` loads the page without the filename in the URL.
- Only POST may start a download job. GET shall only display the page and job status.
- Require a per-session CSRF token for POST requests and reject missing or invalid tokens.
- The URL field is required, and the form shall clearly report a missing or invalid URL.
- Reject URLs that do not use HTTPS, whose host is not exactly `svtplay.se` or `www.svtplay.se`, or whose path is not an SVT Play video page. Example of an accepted format: `https://www.svtplay.se/video/eDmdMBr/everything-everywhere-all-at-once`.
- URL sanitization shall use strict parsing and allowlist validation, not removal of suspicious substrings from an arbitrary URL.
- Sanitization shall trim surrounding whitespace and reject control characters, user information, unexpected ports, incorrect schemes, foreign hosts, and paths that do not match the SVT Play video format. Build a canonical URL from validated components. Remove fragments and query parameters unless they are explicitly required.
- Preserve the video identifier. Reject malformed or ambiguous URL variants instead of trying to repair them heuristically.
- Pass the URL to `svtplay-dl` as one separate process argument. Never interpolate the URL into a shell command string. Accept it only after validating its HTTPS scheme and SVT Play host so it cannot be interpreted as a CLI option. A fixed, server-controlled launcher may detach the same PHP script; user-supplied values must never enter that launcher command.
- `index.php` shall support both HTTP mode and a private CLI job mode. An HTTP POST shall start a detached CLI instance of `index.php` and return without waiting for the download. No separate PHP worker script or systemd service is required.
- First pass `--subtitle` (`-S`) and `--require-subtitle` to `svtplay-dl` to download subtitles when available; if that attempt produces no files because subtitles are unavailable, use the documented video-only fallback.
- Pass `--output <JOB_DIRECTORY>` to `svtplay-dl` so generated artifacts are confined to the job's private directory.
- Burn subtitles into the video in a separate post-processing step using FFmpeg's `subtitles` video filter. `svtplay-dl --merge-subtitle` muxes subtitles into the media file and does not burn them into the video frames.
- Run FFmpeg only after the video and subtitle file have downloaded successfully. Write a separate final output file, and do not mark the job complete until FFmpeg exits successfully.
- Start FFmpeg without shell interpretation as well. Keep input files in the controlled job directory and write the final output to the configured downloads directory; user-provided URLs must not affect file paths.
- If the subtitle-required attempt produces no recognized video or subtitle artifacts because subtitles are unavailable, retry `svtplay-dl` without subtitle options, then remux the video without subtitles and complete the job with a visible note. Other download or FFmpeg failures shall mark the job failed. Document the temporary-file handling policy.
- Store at least the normalized URL and PID for every started job on disk. The data shall persist after the PHP request ends and be available on the next page view.
- Store the PID registry at `/tmp/svtplay-dl-www/jobs.json`, its lock at `/tmp/svtplay-dl-www/jobs.lock`, and per-job work directories/logs under `/tmp/svtplay-dl-www/jobs`. Store completed MKV files persistently under `/var/lib/svtplay/downloads`.
- Store the `svtplay-dl` PID in the job record. During post-processing, also store the FFmpeg PID or another verifiable process identity for the active phase.
- Show each job's URL, PID, and current phase on the home page, for example `downloading`, `burning subtitles`, `complete`, or `failed`.
- For failed jobs and jobs whose process stopped unexpectedly, display an expandable, HTML-escaped tail of the per-job log, bounded to 12 KB.
- Provide a checkbox for each completed or failed job and a `Delete selected job records and logs` action. Do not allow active jobs to be selected for deletion.
- Deleting a terminal job shall remove its registry entry, per-job log, and temporary work directory. It shall preserve any completed output video under `/var/lib/svtplay/downloads`.
- Check active PIDs when rendering the page by comparing them with Raspberry Pi OS process information at `/proc/<pid>/cmdline`. Verify the expected command and job-specific arguments so PID reuse cannot make an unrelated process appear active.
- Report process-start failures to the user and do not register a failed start as an active job.
- Concurrent POST requests must not overwrite or corrupt the job history.

## Security requirements

- Validate URLs on the server; client-side validation is only a convenience.
- Validate the complete URL (scheme, host, and path), not just whether it contains the text `svtplay.se`.
- Start commands without shell interpretation, or use equivalent safe argument handling if the platform requires a shell. The URL must never be able to alter which commands are run.
- The application shall not require a password. Deploy it only on a trusted private network and restrict network access with a firewall/router. Do not expose the unauthenticated endpoint directly to the public internet.
- Store the job registry outside the public web root, restrict its permissions, and update it in a way that prevents concurrent-write conflicts and partial files.
- Escape HTML output contextually, including URLs and process values.
- Run download commands as a restricted system user, never as root.

## On-disk data

- The job registry shall contain one record per started job with at least the URL and PID.
- A structured format such as JSON is recommended over a custom line format.
- Lock concurrent writes and write atomically so an interrupted request does not leave the registry unreadable.
- Completed records may be retained so the page can show previous job status until explicitly deleted or `/tmp` is cleared. Automatic history cleanup is not required for the first version.
- A PID is not a permanent process identity and can be reused by the operating system. Status checks should also verify that the process belongs to the recorded `svtplay-dl` job, not merely that a process with that number exists.

## Runtime requirements

- The documented target platform is a Raspberry Pi running Raspberry Pi OS, with Apache and PHP.
- Use PHP 7.4 or later with PHP CLI and `proc_open` enabled. A currently security-supported PHP 8.x release is recommended. No systemd service is required.
- PHP must be able to start external processes and read/write the job registry.
- `svtplay-dl` must be installed and available to the system user running PHP.
- `ffmpeg` must be installed and available to the same system user, with support for the `subtitles` filter (normally provided through libass).
- The Apache/PHP configuration must not block the selected process-management method.
- The download directory must be configured and writable by the process user.
- Document the locations of the job registry and downloaded files, along with any process-management tools required.

## Acceptance criteria

- Once deployed with a real `/var/www/html/svtplay` directory containing only the `index.php` symlink, `http://<webserver>/svtplay` automatically displays the application.
- A valid SVT Play video URL can be submitted by POST and starts a separate `svtplay-dl` job.
- Valid submissions start a detached CLI invocation of the same `index.php` file without keeping the HTTP request open until download completion.
- Requests with an invalid or missing CSRF token are rejected.
- The job first uses `svtplay-dl --subtitle --require-subtitle --output <JOB_DIRECTORY> <SVTPLAY_URL>`, followed after a successful subtitle download by FFmpeg burn-in equivalent to `ffmpeg -i <VIDEO_FILE> -vf subtitles=<SUBTITLE_FILE> -c:v libx264 -c:a copy <OUTPUT_FILE>`. If that attempt exits successfully but creates no recognized video or subtitle artifacts because no subtitles are available, retry `svtplay-dl --output <JOB_DIRECTORY> <SVTPLAY_URL>` and remux the resulting video without subtitles. All commands are illustrative; the implementation must use separate arguments and safely handle paths/filter arguments.
- After POST, the form is displayed again and the page reports whether the job started or why it failed.
- The job's normalized URL and PID remain in the registry after the POST request ends.
- The application shall consist of one PHP source file, `index.php`. On page reload, a job is shown as running only while its registered worker/child PIDs match the expected live processes; otherwise it is not shown as running.
- The job remains active during both downloading and FFmpeg processing, and is complete only after the final video with burned-in subtitles has been created.
- When no subtitles are available, the fallback job completes with a video-only output and a visible note that subtitles were unavailable.
- GET never starts a job.
- URLs with an incorrect scheme, foreign host, invalid/ambiguous path, control characters, or CLI/shell injection attempts are rejected; no URL component can become an extra argument or command.
- Two concurrent job starts leave a readable registry containing both jobs.
- Values rendered from the job registry are safely escaped as HTML.

## First-version scope

- Job queuing, pausing, and cancellation are not required.
- Automatic cleanup of old job records is not required.
- Subtitle-language selection is not required; `svtplay-dl` uses its default behavior. If no subtitle is available, retry without subtitle options and complete with a clear note that the video has no burned-in subtitles.
- Fetching title/filename metadata beyond information produced by `svtplay-dl` is not required.
- The interface only needs the form and job status. Access is restricted at the network boundary rather than by an application password.
- Preserve per-job logs and temporary media/subtitle files after failure for diagnosis. On success, remove intermediate files and retain the final MKV and log.
- The registry and temporary job data may be cleared when Raspberry Pi OS cleans `/tmp`, commonly at reboot. Completed downloads remain under `/var/lib/svtplay/downloads`.

## CLI references

- `svtplay-dl` documents the general form `svtplay-dl [OPTIONS] <URL>`.
- `--subtitle` (`-S`) downloads available subtitles with the media. `--require-subtitle` requires subtitles to be available. `--merge-subtitle` is documented as muxing subtitles together with `-M`; it does not burn text into video frames.
- Burning subtitles is a separate FFmpeg step using `-vf subtitles=<SUBTITLE_FILE>`. The video stream must be re-encoded; audio can normally be copied with `-c:a copy` if supported by the container and codec.
- Sources: [svtplay-dl CLI documentation](https://github.com/spaam/svtplay-dl/blob/master/svtplay-dl.pod) and [FFmpeg filters documentation](https://ffmpeg.org/ffmpeg-filters.html#subtitles-1).