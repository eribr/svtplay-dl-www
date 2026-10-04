# Installation on Raspberry Pi

These instructions apply only to a Raspberry Pi running Raspberry Pi OS (64-bit recommended), with Apache running PHP as `www-data`. They are not intended for a generic Debian/Ubuntu server or other operating systems.

## 1. Install system packages

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-cli python3 python3-venv python3-pip ffmpeg git acl
sudo systemctl enable --now apache2
```

`libapache2-mod-php` integrates PHP with Apache. Verify PHP and the Apache PHP module:

```bash
php -v
sudo apachectl -M | grep php
```

The application requires PHP 7.4 or later. PHP 8.x is recommended because PHP 7.4 is no longer security-supported. Verify the version used by both CLI and Apache; they must both be at least 7.4.

## 2. Clone the project

Clone the repository as your regular user, not as root. The GitHub account must have an SSH key configured with access to the repository.

```bash
git clone git@github.com:eribr/svtplay-dl-www.git "$HOME/svtplay-dl-www"
```

## 3. Install Python and svtplay-dl

Create a Python virtual environment outside the web project. It will be readable and executable by Apache and will not modify the operating system's Python installation.

```bash
sudo install -d -o root -g root -m 0755 /opt/svtplay-dl-venv
sudo python3 -m venv /opt/svtplay-dl-venv
sudo /opt/svtplay-dl-venv/bin/python -m pip install --upgrade pip
sudo /opt/svtplay-dl-venv/bin/python -m pip install svtplay-dl
```

`pip` installs `svtplay-dl` and its Python package dependencies in the virtual environment. Configure PHP to use the absolute executable path `/opt/svtplay-dl-venv/bin/svtplay-dl`; Apache may not have the same `PATH` as an interactive shell.

Verify that the executable runs as the Apache user:

```bash
sudo -u www-data /opt/svtplay-dl-venv/bin/svtplay-dl --version
```

## 4. Verify FFmpeg subtitle-filter support

FFmpeg needs the `subtitles` video filter, normally provided through libass. Check that the Raspberry Pi OS package includes it:

```bash
ffmpeg -hide_banner -filters 2>&1 | grep subtitles
```

The output should include the `subtitles` filter. The PHP job must run FFmpeg as `www-data` and pass arguments separately rather than constructing a shell command string.

## 5. Create the persistent download directory

The PHP script keeps the PID registry, job records, logs, and temporary media under `/tmp/svtplay-dl-www`. It creates this temporary directory on first use. Completed videos are kept outside the web root so they can persist independently of temporary job data.

```bash
sudo install -d -o www-data -g www-data -m 0750 /var/lib/svtplay/downloads
```

The job registry is `/tmp/svtplay-dl-www/jobs.json`; its lock file is `/tmp/svtplay-dl-www/jobs.lock`. Temporary files and per-job logs are under `/tmp/svtplay-dl-www/jobs`. The page's delete action removes a completed or failed job's registry entry, log, and temporary work directory, but preserves the completed video. Otherwise, registry and job data remain until Raspberry Pi OS clears `/tmp`, commonly on reboot. Completed videos are written to `/var/lib/svtplay/downloads` and remain there until manually deleted.

## 6. Publish only `index.php`

Create a real directory for the application under Apache's document root, then link only `index.php` into it. Apache needs traversal permission for the home directory and project directory, plus read permission for the PHP file.

```bash
sudo install -d -o root -g root -m 0755 /var/www/html/svtplay
sudo setfacl -m u:www-data:--x "$HOME"
sudo setfacl -m u:www-data:--x "$HOME/svtplay-dl-www"
sudo setfacl -m u:www-data:r-- "$HOME/svtplay-dl-www/index.php"
sudo ln -s "$HOME/svtplay-dl-www/index.php" /var/www/html/svtplay/index.php
```

Apache must have `FollowSymLinks` enabled for `/var/www/html`. Check the active Apache configuration if the symlink returns `403 Forbidden`. Confirm that the `svtplay` path is a directory and that its only application entry is the `index.php` symlink:

```bash
ls -l /var/www/html/svtplay
readlink -f /var/www/html/svtplay/index.php
```

## 7. Configure `index.php` as the directory index

The PHP page must be named `index.php` and be in the repository root. Apache's `DirectoryIndex` must include `index.php` so that the page loads at `http://<webserver>/svtplay` without the filename in the URL. Check the setting:

```bash
grep -n DirectoryIndex /etc/apache2/mods-enabled/dir.conf
```

If `index.php` is missing, add it as the first value on the `DirectoryIndex` line in `/etc/apache2/mods-enabled/dir.conf`. Then validate and reload Apache:

```bash
sudo apachectl configtest
sudo systemctl reload apache2
```

Once `index.php` has been implemented, open `http://<webserver>/svtplay`. The URL `http://<webserver>/svtplay/` should also work.

## 8. Configure application authentication

The page requires HTTP Basic Authentication for every request. Generate a password hash interactively so the plaintext password is not part of the shell command:

```bash
php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
```

Create `/etc/apache2/conf-available/svtplay-auth.conf` and replace the example username and hash with your chosen values:

```apache
SetEnv SVTPLAY_USERNAME "replace-with-a-username"
SetEnv SVTPLAY_PASSWORD_HASH "replace-with-the-generated-password-hash"
```

Enable the configuration, validate Apache, and reload it:

```bash
sudo a2enconf svtplay-auth
sudo apachectl configtest
sudo systemctl reload apache2
```

## 9. Restrict network access

Authentication is enabled, but keep the service on a trusted private network and restrict access to trusted devices with your router or firewall. Do not expose Basic Authentication over plain HTTP to an untrusted network; configure HTTPS before allowing access outside a trusted, isolated network.

## 10. Verify the application

No systemd service or separately installed worker is required. When a valid URL is submitted, `index.php` starts another CLI instance of itself in the background. That process runs `svtplay-dl`, burns the subtitle into the video with FFmpeg, and updates the PID registry.

```bash
sudo systemctl status apache2
sudo -u www-data /opt/svtplay-dl-venv/bin/svtplay-dl --version
ffmpeg -hide_banner -filters 2>&1 | grep subtitles
```

After submitting a job, inspect registered PIDs with `ps` if needed:

```bash
ps -eo pid,ppid,user,args | grep -E '[i]ndex.php --run-job|[s]vtplay-dl|[f]fmpeg'
```

The page checks the recorded PID against `/proc/<pid>/cmdline` to ensure the process is the expected job, rather than trusting that a PID merely exists. If Apache returns `403 Forbidden`, check `FollowSymLinks`, ACL permissions on every directory in the path, and the Apache error log. If background jobs do not start, check that PHP CLI and `proc_open` are available to `www-data`, and that `/var/lib/svtplay/downloads` is writable by `www-data`.

## 11. Uninstall

Remove the `index.php` symlink and the now-empty web directory:

```bash
sudo rm /var/www/html/svtplay/index.php
sudo rmdir /var/www/html/svtplay
```

Disable the authentication configuration as well, remove its Apache configuration file, then validate and reload Apache:

```bash
if [ -e /etc/apache2/conf-enabled/svtplay-auth.conf ]; then sudo a2disconf svtplay-auth; fi
sudo rm -f /etc/apache2/conf-available/svtplay-auth.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

The application code remains in `$HOME/svtplay-dl-www`. Remove it only after preserving any local changes you need:

```bash
rm -rf "$HOME/svtplay-dl-www"
```

The PID registry and temporary job files are under `/tmp/svtplay-dl-www` and may be removed after confirming that no jobs are running. Completed downloads are kept separately. To permanently delete those downloads as well, review the directory contents first; the next command is irreversible:

```bash
sudo find /var/lib/svtplay/downloads -maxdepth 1 -type f -print
sudo rm -rf /tmp/svtplay-dl-www /var/lib/svtplay/downloads
```

The Python virtual environment can be removed if it is not used by another application:

```bash
sudo rm -rf /opt/svtplay-dl-venv
```
