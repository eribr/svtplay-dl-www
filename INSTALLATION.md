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

## 5. Create job-data directories

Keep the job registry, temporary media, and completed downloads outside both the web project and the web document root so they cannot be fetched directly over HTTP.

```bash
sudo install -d -o www-data -g www-data -m 0750 /var/lib/svtplay
sudo install -d -o www-data -g www-data -m 0750 /var/lib/svtplay/jobs
sudo install -d -o www-data -g www-data -m 0750 /var/lib/svtplay/downloads
```

Configure the PHP application to use these directories. Do not put the job registry or downloaded files under `/var/www/html` or in the cloned repository.

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

The symlink exposes repository files under the web root. Deny HTTP access to `.git`, deployment files, internal PHP files, Markdown files, and `LICENSE`. Create `/etc/apache2/conf-available/svtplay.conf` with this content:

```apache
<LocationMatch "^/svtplay/(?:\.git|deploy)(?:/|$)|^/svtplay/(?:lib|worker)\.php$|^/svtplay/.*\.md$|^/svtplay/LICENSE$">
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

## 8. Configure application authentication

The application requires HTTP Basic Authentication. Generate a password hash; enter the password when prompted:

```bash
php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
```

Create `/etc/apache2/conf-available/svtplay-auth.conf` and replace both example values with your chosen username and the generated hash:

```apache
SetEnv SVTPLAY_USERNAME "replace-with-a-username"
SetEnv SVTPLAY_PASSWORD_HASH "replace-with-the-generated-password-hash"
```

Enable the configuration and reload Apache:

```bash
sudo a2enconf svtplay-auth
sudo apachectl configtest
sudo systemctl reload apache2
```

Basic Authentication does not encrypt credentials over plain HTTP. Configure HTTPS before exposing this application beyond a trusted, isolated network.

## 9. Install and start the background worker

The worker runs continuously as `www-data`, consumes queued jobs, and updates the JSON registry as it downloads and processes each video. Install the repository's systemd unit and enable it:

```bash
sudo cp /var/www/html/svtplay/deploy/svtplay-worker.service /etc/systemd/system/svtplay-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now svtplay-worker.service
sudo systemctl status svtplay-worker.service
```

The service expects the symlink `/var/www/html/svtplay`, the directories under `/var/lib/svtplay`, and the executable paths documented above. It writes per-job logs and failed-job temporary files under `/var/lib/svtplay/jobs`; after successful processing it removes intermediate media/subtitle files and retains the final MKV under `/var/lib/svtplay/downloads`.

## 10. Verify the service

```bash
sudo systemctl status apache2
sudo systemctl status svtplay-worker.service
sudo -u www-data /opt/svtplay-dl-venv/bin/svtplay-dl --version
ffmpeg -hide_banner -filters 2>&1 | grep subtitles
```

If Apache returns `403 Forbidden`, check `FollowSymLinks`, ACL permissions on every directory in the path, and the Apache error log. If the worker cannot start child processes, check that `proc_open` is enabled for PHP CLI and that the job and download directories are owned by `www-data`. If submissions say that the worker is not running, inspect `journalctl -u svtplay-worker.service`.
