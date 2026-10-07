# Deployment

EduCloud Lab runs as a PHP web application plus three background processes (dispatcher, scheduler, mailer) and a Python/DuckDB runner. The code is the same in every environment; only `.env` and the web-server configuration change.

> **Before any public deployment:** the development stack (XAMPP, PHP 8.1, MariaDB 10.4) has reached end of life (risk R16). Production must use a supported PHP (8.2 or later) and MySQL 8.0 or 8.4, MariaDB 10.11 or 11.4. The code is PHP 8.1-compatible and portable (ADR-001, ADR-002), but run the test suite on the target versions first.

## 1. Single Linux server (recommended first production)

| Component | Recommendation |
|---|---|
| OS | Ubuntu 24.04 LTS or Debian 12 |
| Web | Apache 2.4 + PHP-FPM 8.3 (extensions: mysqli, mbstring, intl, fileinfo, openssl, zip, json) |
| Database | MySQL 8.0/8.4 or MariaDB 10.11 on the same host or a managed instance |
| Runner | Python 3.11 venv with the hash-pinned `worker/requirements.txt` |
| TLS | Let's Encrypt (certbot). Set `APP_URL=https://…` |

### Layout and permissions

```
/srv/educloud/app         code (git checkout), owned by deploy user, read-only for www-data
/srv/educloud/data        STORAGE_PATH, owned by educloud:educloud, mode 0750
/srv/educloud/backups     backups, mode 0700, never web-served
```

- `DocumentRoot /srv/educloud/app/public`. Only `public/` is served; the root `.htaccess` stays as defence in depth.
- PHP-FPM pool user `educloud`, with access to `data/`.
- `.env` is mode 0640, owned by root and readable by the `educloud` group.

### Apache virtual host (excerpt)

```apache
<VirtualHost *:443>
    ServerName lab.example.edu
    DocumentRoot /srv/educloud/app/public
    <Directory /srv/educloud/app/public>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm-educloud.sock|fcgi://localhost"
    </FilesMatch>
    Protocols h2 http/1.1
    SSLEngine on
    # certificates managed by certbot
</VirtualHost>
```

The application sends HSTS, CSP, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy` itself. Session cookies get `Secure` automatically over HTTPS.

### Database users

Use the same three accounts as in development (`docs/development/SETUP.md`), each with a strong password:
- `educloud_app`: DML only on `educloud.*`. This is the runtime account.
- `educloud_migrator`: DDL. Used only for migrations and backups.
- Never run the application as root.

### Background services (systemd)

```ini
# /etc/systemd/system/educloud-dispatcher.service
[Unit]
Description=EduCloud Lab job dispatcher
After=network.target mysql.service

[Service]
User=educloud
WorkingDirectory=/srv/educloud/app
ExecStart=/usr/bin/php scripts/dispatcher.php
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/srv/educloud/data
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

- **Mailer:** `educloud-mailer.service`, the same unit with `ExecStart=/usr/bin/php scripts/mailer.php`.
- **Scheduler:** `educloud-scheduler.timer` runs `php scripts/scheduler.php` every 5 minutes. It handles stale jobs, lab expiry warnings and expiry, storage release, usage gauges and purges.
- **Backups:** `educloud-backup.timer`, daily (see `BACKUP_RESTORE.md`).
- **Isolation:** on Linux the runner's memory cap uses `RLIMIT_AS` (`process_memory_mb`). For stronger isolation, run the dispatcher in its own container or VM (scalability roadmap).

### Release procedure

1. `git pull`, then `composer install --no-dev --optimize-autoloader`. Rebuild the Python venv only if `worker/requirements.txt` changed.
2. `php scripts/backup.php`.
3. `php scripts/migrate.php up`, then `php scripts/labs-import.php`.
4. `systemctl restart educloud-dispatcher educloud-mailer` and reload PHP-FPM.
5. Run `php scripts/check-env.php` and smoke-test `/api/v1/health`, login and a SQL query.

Rollback: every migration has a down script (`php scripts/migrate.php down`). For a data rollback, restore the backup taken in step 2.

### Operators

```
php scripts/admin.php grant ops@example.edu              # platform admin (admin monitor /app/admin)
php scripts/org.php create "Universidad X" admin@x.edu   # organization + first org_admin
php scripts/org.php add-member <org_id> prof@x.edu instructor
```

## 2. Local XAMPP (development and classroom demos)

See `docs/development/SETUP.md`. **Do not expose a XAMPP install to the Internet.** The bundled MariaDB root has no password by default, and the PHP and MariaDB versions are end of life.

## 3. Growing beyond one server

Follow the scalability roadmap (master plan §25):
- **Separate worker host:** the dispatcher plus runner, sharing `STORAGE_PATH` over NFS or an object store driver.
- **Several dispatchers:** with per-workspace locking.
- **Managed database:** MySQL 8 with automated backups.
- **Scaled web tier:** several PHP nodes behind a load balancer. Sessions are already stored in the database.

## Production checklist

- [ ] HTTPS only; `APP_URL` uses https; `APP_DEBUG=false`; `APP_ENV=production`.
- [ ] Fresh random `JWT_KEYS` and `APP_HASH_KEY`. Never reuse the development keys.
- [ ] Real SMTP (`MAIL_DRIVER=smtp`) with SPF/DKIM for the sender domain.
- [ ] Runtime database user is not root and holds DML only.
- [ ] `STORAGE_PATH` is outside the web root and not world-readable.
- [ ] Dispatcher, mailer and scheduler run under systemd; backups are scheduled and a restore drill has been done.
- [ ] `composer audit` and `pip-audit` are clean.
- [ ] Security gate run (`.claude/skills/educloud-security-gate`).
- [ ] Firewall: only 80/443 open; database reachable only from the application host.
