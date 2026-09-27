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

- The PHP page shall be named `index.php` and be located in the project root. Apache shall configure `DirectoryIndex index.php` so `http://<webserver>/svtplay` loads the page without the filename in the URL.
- Only POST may start a download job. GET shall only display the page and job status.
- Authenticate every request using HTTP Basic Authentication configured through Apache environment variables `SVTPLAY_USERNAME` and `SVTPLAY_PASSWORD_HASH`. Generate the password hash with PHP `password_hash` and verify it with `password_verify`.
- Require a per-session CSRF token for POST requests and reject missing or invalid tokens.
- The URL field is required, and the form shall clearly report a missing or invalid URL.
- Reject URLs that do not use HTTPS, whose host is not exactly `svtplay.se` or `www.svtplay.se`, or whose path is not an SVT Play video page. Example of an accepted format: `https://www.svtplay.se/video/eDmdMBr/everything-everywhere-all-at-once`.
- URL sanitization shall use strict parsing and allowlist validation, not removal of suspicious substrings from an arbitrary URL.
- Sanitization shall trim surrounding whitespace and reject control characters, user information, unexpected ports, incorrect schemes, foreign hosts, and paths that do not match the SVT Play video format. Build a canonical URL from validated components. Remove fragments and query parameters unless they are explicitly required.
- Preserve the video identifier. Reject malformed or ambiguous URL variants instead of trying to repair them heuristically.
- Start processes without shell interpretation and pass the URL as one separate argument. Never interpolate the URL into a shell command string. Accept it only after validating its HTTPS scheme and SVT Play host so it cannot be interpreted as a CLI option.
- Start `svtplay-dl` detached from the HTTP request so the request can finish while the download continues.
- The PHP page shall enqueue the job and return without waiting for the download. A persistent systemd-managed PHP CLI worker shall process queued jobs as `www-data`, one job at a time.
- Pass `--subtitle` (`-S`) and `--require-subtitle` to `svtplay-dl` to download subtitles and fail if none are available.
- Pass `--output <JOB_DIRECTORY>` to `svtplay-dl` so generated artifacts are confined to the job's private directory.
- Burn subtitles into the video in a separate post-processing step using FFmpeg's `subtitles` video filter. `svtplay-dl --merge-subtitle` muxes subtitles into the media file and does not burn them into the video frames.
- Run FFmpeg only after the video and subtitle file have downloaded successfully. Write a separate final output file, and do not mark the job complete until FFmpeg exits successfully.
- Start FFmpeg without shell interpretation as well. Keep input files in the controlled job directory and write the final output to the configured downloads directory; user-provided URLs must not affect file paths.
- If subtitles are unavailable or downloading/burning fails, mark the job as failed, not complete. Document the temporary-file handling policy.
- Store at least the normalized URL and PID for every started job on disk. The data shall persist after the PHP request ends and be available on the next page view.
- Store job data under `/var/lib/svtplay`: the JSON registry at `/var/lib/svtplay/jobs.json`, per-job work directories under `/var/lib/svtplay/jobs`, and completed MKV files under `/var/lib/svtplay/downloads`.
- Store the `svtplay-dl` PID in the job record. During post-processing, also store the FFmpeg PID or another verifiable process identity for the active phase.
- Show each job's URL, PID, and current phase on the home page, for example `downloading`, `burning subtitles`, `complete`, or `failed`.
- Check the active process when rendering the page. Associate the PID with the correct job/process so PID reuse cannot make a completed job appear active.
- Report process-start failures to the user and do not register a failed start as an active job.
- Concurrent POST requests must not overwrite or corrupt the job history.

## Security requirements

- Validate URLs on the server; client-side validation is only a convenience.
- Validate the complete URL (scheme, host, and path), not just whether it contains the text `svtplay.se`.
- Start commands without shell interpretation, or use equivalent safe argument handling if the platform requires a shell. The URL must never be able to alter which commands are run.
- Protect the page from unauthorized use. If it is exposed beyond a trusted local environment, require authentication and CSRF protection before allowing downloads to start.
- Store the job registry outside the public web root, restrict its permissions, and update it in a way that prevents concurrent-write conflicts and partial files.
- Escape HTML output contextually, including URLs and process values.
- Run download commands as a restricted system user, never as root.
- Require HTTPS before exposing Basic Authentication beyond a trusted, isolated network.

## On-disk data

- The job registry shall contain one record per started job with at least the URL and PID.
- A structured format such as JSON is recommended over a custom line format.
- Lock concurrent writes and write atomically so an interrupted request does not leave the registry unreadable.
- Completed records may be retained so the page can show previous job status. Automatic history cleanup is not required for the first version.
- A PID is not a permanent process identity and can be reused by the operating system. Status checks should also verify that the process belongs to the recorded `svtplay-dl` job, not merely that a process with that number exists.

## Runtime requirements

- The documented target platform is a Raspberry Pi running Raspberry Pi OS, with Apache and PHP.
- Use PHP 8.0 or later and a systemd-managed Raspberry Pi OS service for the queue worker.
- PHP must be able to start external processes and read/write the job registry.
- `svtplay-dl` must be installed and available to the system user running PHP.
- `ffmpeg` must be installed and available to the same system user, with support for the `subtitles` filter (normally provided through libass).
- The Apache/PHP configuration must not block the selected process-management method.
- The download directory must be configured and writable by the process user.
- Document the locations of the job registry and downloaded files, along with any process-management tools required.

## Acceptance criteria

- Once deployed as `/var/www/html/svtplay`, `http://<webserver>/svtplay` automatically displays `index.php`.
- A valid SVT Play video URL can be submitted by POST and starts a separate `svtplay-dl` job.
- Valid submissions are queued without keeping the HTTP request open until download completion.
- Requests without valid configured credentials or with an invalid/missing CSRF token are rejected.
- The job uses `svtplay-dl --subtitle --require-subtitle --output <JOB_DIRECTORY> <SVTPLAY_URL>`, followed after a successful download by an FFmpeg burn-in equivalent to `ffmpeg -i <VIDEO_FILE> -vf subtitles=<SUBTITLE_FILE> -c:v libx264 -c:a copy <OUTPUT_FILE>`. This command is illustrative; the implementation must use separate arguments and safely handle paths/filter arguments.
- After POST, the form is displayed again and the page reports whether the job started or why it failed.
- The job's normalized URL and PID remain in the registry after the POST request ends.
- On page reload, the job is shown as running while its registered process is active and as completed when processing has ended.
- The job remains active during both downloading and FFmpeg processing, and is complete only after the final video with burned-in subtitles has been created.
- GET never starts a job.
- URLs with an incorrect scheme, foreign host, invalid/ambiguous path, control characters, or CLI/shell injection attempts are rejected; no URL component can become an extra argument or command.
- Two concurrent job starts leave a readable registry containing both jobs.
- Values rendered from the job registry are safely escaped as HTML.

## First-version scope

- Job queuing, pausing, and cancellation are not required.
- Automatic cleanup of old job records is not required.
- Subtitle-language selection is not required; `svtplay-dl` uses its default behavior. Fail clearly if no subtitle is available rather than producing a video without burned-in subtitles.
- Fetching title/filename metadata beyond information produced by `svtplay-dl` is not required.
- The interface only needs the form and job status. The authentication design depends on whether the page is exposed outside a trusted environment.
- Preserve per-job logs and temporary media/subtitle files after failure for diagnosis. On success, remove intermediate files and retain the final MKV and log.

## CLI references

- `svtplay-dl` documents the general form `svtplay-dl [OPTIONS] <URL>`.
- `--subtitle` (`-S`) downloads available subtitles with the media. `--require-subtitle` requires subtitles to be available. `--merge-subtitle` is documented as muxing subtitles together with `-M`; it does not burn text into video frames.
- Burning subtitles is a separate FFmpeg step using `-vf subtitles=<SUBTITLE_FILE>`. The video stream must be re-encoded; audio can normally be copied with `-c:a copy` if supported by the container and codec.
- Sources: [svtplay-dl CLI documentation](https://github.com/spaam/svtplay-dl/blob/master/svtplay-dl.pod) and [FFmpeg filters documentation](https://ffmpeg.org/ffmpeg-filters.html#subtitles-1).