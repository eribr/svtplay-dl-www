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

The application requires PHP 8.0 or later.

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

The job registry is `/tmp/svtplay-dl-www/jobs.json`; its lock file is `/tmp/svtplay-dl-www/jobs.lock`. Temporary files and per-job logs are under `/tmp/svtplay-dl-www/jobs`. Temporary registry and job data are removed when Raspberry Pi OS clears `/tmp`, commonly on reboot. Completed videos are written to `/var/lib/svtplay/downloads`.

## 6. Publish the project through a symlink

Apache needs permission to traverse the home directory and read the project files. These ACL commands grant `www-data` traversal through the home directory and read access to the project without making the home directory listable to everyone.

```bash
sudo setfacl -m u:www-data:--x "$HOME"
sudo setfacl -R -m u:www-data:rX "$HOME/svtplay-dl-www"
sudo find "$HOME/svtplay-dl-www" -type d -exec setfacl -m d:u:www-data:rX {} +
sudo ln -s "$HOME/svtplay-dl-www" /var/www/html/svtplay
```

Apache must have `FollowSymLinks` enabled for `/var/www/html`. Check the active Apache configuration if the symlink returns `403 Forbidden`. Confirm that the symlink points to the repository:

```bash
readlink -f /var/www/html/svtplay
```

The symlink exposes repository files under the web root. Deny HTTP access to `.git`, Markdown files, and `LICENSE`. Create `/etc/apache2/conf-available/svtplay.conf` with this content:

```apache
<LocationMatch "^/svtplay/(?:\.git(?:/|$)|.*\.md$|LICENSE$)">
    Require all denied
</LocationMatch>
```

Enable the configuration, validate it, and reload Apache:

```bash
sudo a2enconf svtplay
sudo apachectl configtest
sudo systemctl reload apache2
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

## 8. Restrict network access

The application has no password prompt or application-level authentication. Anyone who can reach the page can start downloads and consume the Raspberry Pi's bandwidth, CPU, and storage. Keep it on a trusted private network and restrict access to trusted devices with your router or firewall. Do not expose it directly to the public internet.

## 9. Verify the application

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

## 10. Uninstall

Disable the Apache access rule, remove the web-root symlink, and reload Apache:

```bash
sudo a2disconf svtplay
sudo apachectl configtest
sudo systemctl reload apache2
sudo rm /var/www/html/svtplay
```

If an older installation created `/etc/apache2/conf-available/svtplay-auth.conf`, remove that obsolete configuration too:

```bash
sudo a2disconf svtplay-auth
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
