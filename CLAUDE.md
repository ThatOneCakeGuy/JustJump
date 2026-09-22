# justjump.lol

Static site served by nginx on AlmaLinux 9.

## Layout
- This directory is the **git working copy** — edit files here.
- `/var/www/justjump.lol/public_html` is the **live webroot**. It is generated.
  Never edit it directly; `deploy-site` runs `rsync --delete` and will wipe changes.

## Publishing
- `sudo deploy-local` — publish this working copy to the webroot immediately.
- `sudo deploy-site`  — pull from GitHub `main` and publish (use after pushing).

Normal flow: edit here -> `sudo deploy-local` to preview -> commit -> push.

## Server facts
- nginx vhost: `/etc/nginx/conf.d/10-justjump.lol.conf`
- PHP-FPM socket: `/run/php-fpm/www.sock` (PHP 8.4, with pdo_sqlite)
- Logs: `/var/log/nginx/justjump.lol.{access,error}.log`
- Health check: `/healthz`
- The domain is proxied through Cloudflare. Purge the CF cache after deploying,
  or changes may not show up for visitors even when the origin is correct.
- SELinux is enforcing. New files need `restorecon`; `deploy-local` handles it.

## Backend (api/)
- `api/*.php` implement soft-login (name + 4-8 digit PIN, hashed with
  `password_hash`) and per-user jump counts for the leaderboard.
- The SQLite DB lives at `/var/www/justjump.lol/data/jump.sqlite` —
  **outside** the git tree and outside `public_html`, because both deploy
  scripts `rsync --delete` into the webroot and would wipe it otherwise.
- One-time server setup (not part of any deploy script):
  `sudo install -d -o nginx -g nginx -m 750 /var/www/justjump.lol/data`
  PHP creates the DB file and schema itself on first request once that
  directory exists and is writable by the `nginx` user.

## Conventions
- Keep it dependency-free static HTML/CSS/JS unless asked otherwise. The
  `api/` PHP backend is the one exception, added for soft-login + leaderboard.
- Test with `curl -sI https://justjump.lol/` after publishing.
