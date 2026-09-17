# DigitalOcean deployment with homelab media storage

The web app remains public on the Droplet. Permanent images are written to the
private Samba share over Tailscale and are streamed to authenticated users by
Laravel. The Samba host is never exposed to a browser or to the public internet.

This guide assumes the share is `//files.tail9745da.ts.net/homelab` and the app
should own the `Push` directory within it.

## Public endpoint and HTTPS

The app can be served through the Droplet's public network while storage stays
on Tailscale. Web Push, service workers, and the installed iOS web app require a
trusted HTTPS origin. Pointing a normal hostname at the Droplet's public IP and
issuing a TLS certificate is the simplest setup. If the IP address itself is
used in the browser, it still needs a certificate that is valid for that IP;
plain `http://PUBLIC_IP` will not support the push flow.

## 1. Join the Droplet to the tailnet

Install Tailscale on the Droplet, authenticate it, and confirm that the Samba
host resolves and is reachable:

```bash
tailscale status
getent hosts files.tail9745da.ts.net
```

The tailnet ACL must allow the Droplet to reach the file server on TCP 445.

## 2. Mount the Samba share

Install the CIFS client and create a protected credentials file:

```bash
sudo apt update
sudo apt install -y cifs-utils
sudo install -m 600 /dev/null /etc/samba/push-credentials
sudoedit /etc/samba/push-credentials
```

Use this format in `/etc/samba/push-credentials`:

```ini
username=YOUR_SAMBA_USERNAME
password=YOUR_SAMBA_PASSWORD
domain=WORKGROUP
```

Create the mount point and add this single line to `/etc/fstab`:

```fstab
//files.tail9745da.ts.net/homelab /mnt/homelab cifs credentials=/etc/samba/push-credentials,vers=3.1.1,uid=www-data,gid=www-data,dir_mode=0770,file_mode=0660,nofail,_netdev,x-systemd.automount,x-systemd.after=tailscaled.service 0 0
```

Then mount it, create the app folder, and verify the PHP user can write:

```bash
sudo mkdir -p /mnt/homelab
sudo mount -a
sudo install -d -o www-data -g www-data -m 0770 /mnt/homelab/Push
sudo -u www-data touch /mnt/homelab/Push/.write-test
sudo rm /mnt/homelab/Push/.write-test
```

If the share does not support Unix ownership, create `Push` through Samba and
make sure the configured Samba account has read, write, create, and delete
permissions. The `uid`, `gid`, and mode options control how those permissions
appear on the Droplet.

## 3. Configure Laravel

Use the mounted `Push` directory—not an `smb://` URI—in the production `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_PUBLIC_HOST
HOMELAB_CLOUD_URL=/mnt/homelab/Push
```

`HOMELAB_CLOUD_URL` is intentionally the path of the OS-mounted share. Do not
put `smb://...` in this setting: PHP sees a normal local filesystem, while the
kernel CIFS client handles Samba and Tailscale.

After deploying code and configuring the rest of the environment:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan homelab:check
npm ci
npm run build
```

Because a moment can contain six 500 MB images, align the web-server and PHP
limits with the application rules. Use `client_max_body_size 3100M` in Nginx,
`upload_max_filesize=500M`, and `post_max_size=3100M` in PHP-FPM. Large mobile
uploads may also need a longer `client_body_timeout` and `max_input_time`.
Restart Nginx and PHP-FPM after changing them.

Photo inputs explicitly request JPEG so iOS uses its native Photo Library export
pipeline for HEIC and ProRAW assets. The server only performs lightweight
normalization for ordinary browser-readable formats that bypass that picker.
Install ImageMagick and its PHP extension on the Droplet:

```bash
sudo apt install -y imagemagick php8.4-imagick
sudo systemctl restart php8.4-fpm
php -r 'foreach (["PNG", "GIF", "WEBP", "JPEG"] as $f) echo $f.": ".(Imagick::queryFormats($f) ? "yes" : "no").PHP_EOL;'
```

Use the PHP package and service version installed on the server if it is not
PHP 8.4. All four formats should report `yes`.

Existing database records retain the disk on which they were created, so local
development photos remain readable and are not silently moved or deleted. For
a clean production deployment, new uploads immediately use the mounted share.
If existing photos must move too, copy the private storage tree and migrate the
stored disk values as a separate, backed-up data migration.

Run `php artisan homelab:check` after reboots or storage changes. It writes,
reads, and removes a small probe file through the same Laravel disk used by
uploads.

## 4. Keep scheduled prompts running

Create `/etc/systemd/system/push-scheduler.service`:

```ini
[Unit]
Description=Push Laravel scheduler
After=network-online.target tailscaled.service remote-fs.target
Wants=network-online.target
Requires=tailscaled.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/push
ExecStart=/usr/bin/php artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Adjust `WorkingDirectory` if the release lives elsewhere, then enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now push-scheduler
sudo systemctl status push-scheduler
```

A queue worker should be configured separately if `QUEUE_CONNECTION` remains
`database` and queued jobs are introduced. Current web-push sends are performed
by the application flow, while daily prompt creation depends on the scheduler.

## Request path

1. A browser uploads an image to the public Droplet over HTTPS.
2. Laravel writes it under `/mnt/homelab/Push` through the `homelab_cloud` disk.
3. The database stores the disk name and relative path.
4. An authorized image route reads from the mounted share and streams the bytes
   through the public Droplet.

Do not point Nginx directly at `/mnt/homelab/Push`; doing so would bypass the
relationship authorization enforced by the Laravel photo controllers.
