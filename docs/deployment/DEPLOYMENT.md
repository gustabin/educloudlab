# Deployment

EduCloud Lab runs as a PHP web application, a Python/DuckDB runner, and these background processes:
- the dispatcher;
- the scheduler;
- the mailer;
- optionally, the notebook worker.

The code is the same in every environment; only `.env` and the web-server configuration change. Production installs use **release archives** (`educloud-lab-<version>.tar.gz`), not a git checkout. Ready-to-use configuration lives in `deploy/`.

> **Before any public deployment:** the development stack (XAMPP, PHP 8.1, MariaDB 10.4) has reached end of life (risk R16). Production must use a supported PHP (8.2 or later) and MySQL 8.0/8.4 or MariaDB 10.11/11.4.
>
> The CI (`ci/`, `docs/development/CI.md`) runs the whole test suite on Linux against the versions below. Results on 2026-10-08: PHP 8.1 and 8.3 × MySQL 8.0 and MariaDB 10.11 (and 10.4).

## 1. Single Linux server (recommended first production)

| Component | Recommendation (verified by the CI) |
|---|---|
| OS | **Debian 12** (bookworm). On Ubuntu 24.04, install Python 3.11 from the deadsnakes PPA: the runner's wheels are pinned for CPython 3.11. |
| Web | Apache 2.4 + PHP-FPM 8.3 (extensions: mysqli, mbstring, intl, fileinfo, openssl, zip, curl) |
| Database | MySQL 8.0/8.4 or MariaDB 10.11 on the same host or a managed instance |
| Runner | Python 3.11 venv with the hash-pinned `worker/requirements.txt` (manylinux x86_64 hash included) |
| TLS | Let's Encrypt (certbot). Set `APP_URL=https://…` |

### Layout

```
/srv/educloud/releases/<version>/   unpacked release archives (read-only for the app)
/srv/educloud/current -> releases/<version>    the active release (symlink, switched atomically)
/srv/educloud/shared/.env           configuration and secrets (mode 0640 root:educloud), linked into each release
/srv/educloud/data/                 STORAGE_PATH (educloud:educloud, 0750)
/srv/educloud/backups/              backups (0700), never web-served
```

### First installation

1. **Packages** (Debian 12), plus the database server or a managed instance:
   ```
   apt install apache2 php8.3-fpm php8.3-mysql php8.3-intl php8.3-mbstring php8.3-zip php8.3-curl python3.11-venv
   ```
   PHP 8.3 comes from packages.sury.org; Debian 12's own PHP is 8.2, which is also supported.
2. **Users:**
   ```
   useradd --system --home /srv/educloud --shell /usr/sbin/nologin educloud
   ```
   Add `educloud-nb` only if you will enable notebooks (see below).
3. **Verify and unpack the release:**
   ```
   sha256sum -c SHA256SUMS
   tar -xzf educloud-lab-1.3.0.tar.gz -C /srv/educloud/releases
   ```
4. **Runner venv** (inside the release):
   ```
   python3.11 -m venv worker/.venv
   worker/.venv/bin/pip install --require-hashes --only-binary=:all: -r worker/requirements.txt
   ```
5. **Database:** create the database and its three accounts as in `docs/development/SETUP.md` §3, with strong passwords. The runtime account `educloud_app` has DML only; `educloud_migrator` has DDL. Never use root.
6. **Configuration:** copy `.env.example` to `/srv/educloud/shared/.env` and link it as `.env` in the release. Then set:
   - `APP_ENV=production`, `APP_URL=https://…` and `STORAGE_PATH=/srv/educloud/data`;
   - fresh `JWT_KEYS` and `APP_HASH_KEY`;
   - SMTP settings;
   - `MYSQLDUMP_PATH=/usr/bin/mysqldump` and `MYSQL_PATH=/usr/bin/mysql`;
   - optionally `METRICS_TOKEN`.
7. **Initialise:**
   ```
   php scripts/check-env.php
   php scripts/migrate.php up
   php scripts/labs-import.php
   php scripts/admin.php grant ops@example.edu
   ```
   The first admin must register through the web UI first.
8. **Web server:** copy `deploy/php-fpm/educloud.conf` to `/etc/php/8.3/fpm/pool.d/` and `deploy/apache/educloud.conf` to `/etc/apache2/sites-available/`. Then:
   ```
   a2enmod proxy_fcgi setenvif rewrite headers ssl http2
   a2ensite educloud
   certbot --apache
   ```
9. **Services:** copy `deploy/systemd/*` to `/etc/systemd/system/`. Then:
   ```
   systemctl enable --now educloud-dispatcher educloud-mailer educloud-scheduler.timer educloud-backup.timer
   ```
10. **Check:** open **/app/admin → Observabilidad**. Every component must be green.

The application sends HSTS, CSP, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy` itself. Session cookies get `Secure` automatically over HTTPS.

### Services (`deploy/systemd`)

| Unit | Runs | Notes |
|---|---|---|
| `educloud-dispatcher.service` | `scripts/dispatcher.php` | SQL Lab, ingestion, transforms, pipelines, grading. One instance. |
| `educloud-mailer.service` | `scripts/mailer.php` | Email outbox |
| `educloud-scheduler.timer` | `scripts/scheduler.php` every 5 min | Stale jobs, lab expiry, storage release, retention |
| `educloud-backup.timer` | `scripts/backup.php --out=/srv/educloud/backups` daily | See `BACKUP_RESTORE.md` |
| `educloud-notebooks.service` | `scripts/dispatcher.php --notebooks` | Only with `NOTEBOOKS_MODE=docker` |

All units run as unprivileged users with `ProtectSystem=strict`, so only `/srv/educloud/data` is writable. The PHP-FPM pool disables shell functions and restricts `open_basedir`.

**Runner isolation on Linux:**
- The runner caps its address space (`process_memory_mb`, `RLIMIT_AS`).
- It runs with `MALLOC_ARENA_MAX=2`, set by the dispatcher. Without it, glibc's per-thread arenas exhaust the cap and DuckDB crashes. The Linux CI found this.
- For stronger isolation, run the dispatcher in its own container or VM (scalability roadmap).

### Notebooks (optional)

The notebook worker starts Docker containers. Membership in the `docker` group is **equivalent to root** on that host.

**Recommended setup:**
- a dedicated worker host, or rootless Docker;
- the worker runs as its own user (`educloud-nb`), never the web user;
- the web server never talks to Docker (ADR-010).

**Steps:**
1. Install Docker and build the image: `php scripts/notebook-image.php build`.
2. Run the isolation suite on the target host. It must pass. The suite lives in the source repository, not in the release.
3. Set `NOTEBOOKS_MODE=docker`.
4. `systemctl enable --now educloud-notebooks`.

### Upgrade (zero-downtime switch, instant rollback)

1. Verify and unpack the new archive into `releases/<new>`. Create its runner venv and link `shared/.env`.
2. Back up: `php scripts/backup.php --out=/srv/educloud/backups`.
3. From the new release, run `php scripts/migrate.php up`, then `php scripts/labs-import.php`.
4. Switch the symlink and restart:
   ```
   ln -sfn releases/<new> /srv/educloud/current
   systemctl reload php8.3-fpm
   systemctl restart educloud-dispatcher educloud-mailer
   ```
   Also restart `educloud-notebooks` if you use it.
5. Smoke-test:
   - `/api/v1/health`;
   - login and a SQL query;
   - **/app/admin → Observabilidad**.

**Rollback:**
- **Code:** point `current` back to the previous release and restart the services.
- **Schema:** migrations have down scripts (`php scripts/migrate.php down --steps=N`, run from the *new* release before switching back).
- **Data:** restore the backup from step 2.

### Monitoring

- **Built in:** **/app/admin → Observabilidad** (component health, latency and errors per route, job times, log lookup by request id). See `docs/architecture/OBSERVABILITY.md`.
- **External (Prometheus):** set `METRICS_TOKEN` (32 or more random characters) and scrape `GET /metrics` with `Authorization: Bearer <token>`. Optionally also restrict it by IP in the vhost. Useful alerts:
  - `educloud_component_up == 0`;
  - `educloud_job_queue_oldest_seconds > 300`;
  - `educloud_http_server_errors_5m > 0`.

### Operators

```
php scripts/admin.php grant ops@example.edu              # platform admin (admin monitor /app/admin)
php scripts/org.php create "Universidad X" admin@x.edu   # organization + first org_admin
php scripts/org.php add-member <org_id> prof@x.edu instructor
```

### Shared hosting (cPanel) without systemd

Use cron jobs instead of the systemd units, all from the project folder:

```
* * * * *   cd /home/<user>/<project> && php scripts/dispatcher.php --once >/dev/null 2>&1
* * * * *   cd /home/<user>/<project> && php scripts/mailer.php --once >/dev/null 2>&1
*/5 * * * * cd /home/<user>/<project> && php scripts/scheduler.php >/dev/null 2>&1
```

- The **dispatcher** runs the SQL Lab, ingestion, pipelines and grading. Without it, jobs stay queued.
- The **mailer** sends the email outbox. Without it, no email ever leaves.
- If the default `php` is not 8.1 or newer, use the hosting's full path, for example `/opt/cpanel/ea-php83/root/usr/bin/php`.

### Email troubleshooting

Email is **queued**: a request only writes a row in `email_outbox`, and `scripts/mailer.php` sends it.

1. **Is the mailer running?** In **/app/admin → Observabilidad**, check the *mailer* card: last heartbeat and pending emails. Alternatively, run the outbox query below: rows that stay `pending` with `attempts = 0` mean the mailer never ran.
2. **Send a test now:**
   ```
   php scripts/mailer.php --test=you@example.org --verbose
   ```
   It prints the driver, host, port, encryption and From address, then either `OK` or the failure category plus the SMTP server's message. `--verbose` adds the SMTP dialogue. Passwords and AUTH data are masked, and nothing is queued.
3. **Outbox state:**
   ```
   SELECT id, to_email, template, status, attempts, last_error_code, send_after FROM email_outbox ORDER BY id DESC LIMIT 10;
   ```

| `last_error_code` | Typical fix |
|---|---|
| `SMTP_AUTH` | Wrong user or password; some hosts require the full mailbox address as the user. |
| `SMTP_CONNECT` | Wrong host or port, or outbound SMTP blocked; on the same cPanel server try `localhost` or `mail.<domain>`. |
| `SMTP_TLS` | Encryption does not match the port: 465 = `ssl`, 587 = `tls`, 25 or a local relay = empty (`SMTP_ENCRYPTION=`). |
| `SMTP_SENDER` | `MAIL_FROM` must usually be the authenticated mailbox, or at least on the same domain. |
| `SMTP_RECIPIENT` | The recipient was rejected by the server. |

**Where to look.** The application log is `STORAGE_PATH/logs/app-YYYY-MM-DD.log` (JSON lines: `email_sent`, `email_failed` with `code`, `email_test`). It never contains SMTP text, passwords or message contents.

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
- [ ] Installed from a verified release archive (`sha256sum -c SHA256SUMS`); `deploy/` units and configs in place.
- [ ] Dispatcher, mailer and scheduler run under systemd; backups are scheduled and a restore drill has been done.
- [ ] **/app/admin → Observabilidad** is all green; `METRICS_TOKEN` set if an external monitor scrapes `/metrics`.
- [ ] `composer audit` and `pip-audit` are clean.
- [ ] Security gate run (`.claude/skills/educloud-security-gate`).
- [ ] Firewall: only 80/443 open; database reachable only from the application host.
