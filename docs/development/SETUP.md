# Local Development Setup (Windows + XAMPP)

This setup is already in place on the reference machine. Repeat these steps on a new machine.

## 1. Requirements

| Component | Version | Notes |
|---|---|---|
| XAMPP | PHP 8.1.6+, MariaDB 10.4, Apache 2.4 | PHP 8.1 is EOL: never expose the dev install publicly (ADR-001) |
| Composer | 2.x | |
| Node.js + npm | 18+ | dev only, to refresh vendored frontend assets |
| Python | 3.11 | from M4 (execution runner) |
| Docker Desktop | optional | M8 notebooks only |

Required PHP extensions are mysqli, mbstring, fileinfo, openssl, json, session and intl. `php scripts/check-env.php` verifies them.

**Recommended:** disable Xdebug for day-to-day work by setting `xdebug.mode=off` in `C:\xampp\php\php.ini`.

## 2. Dependencies and configuration

```
composer install
copy .env.example .env
```

Fill in `.env`:
- the three DB passwords
- `APP_HASH_KEY` and `JWT_KEYS`: random 32-byte base64 values, e.g. `php -r "echo base64_encode(random_bytes(32));"`
- `STORAGE_PATH`, for example `C:/educloud-data`

Create the storage folder outside htdocs (`mkdir C:\educloud-data`).

## 3. Database

MariaDB's `root` account is used **once**, to create the schema and its users. The application never uses root. Run the following as root (e.g. phpMyAdmin → SQL), replacing the passwords with the values from `.env`:

```sql
CREATE DATABASE educloud      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE educloud_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'educloud_migrator'@'localhost' IDENTIFIED BY '<DB_MIGRATOR_PASS>';
CREATE USER 'educloud_app'@'localhost'      IDENTIFIED BY '<DB_PASS>';
CREATE USER 'educloud_test'@'localhost'     IDENTIFIED BY '<DB_TEST_PASS>';
-- Repeat the three CREATE USER lines for host '127.0.0.1'.

GRANT ALL PRIVILEGES ON educloud.*      TO 'educloud_migrator'@'localhost';
GRANT ALL PRIVILEGES ON educloud_test.* TO 'educloud_migrator'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON educloud.* TO 'educloud_app'@'localhost';
GRANT ALL PRIVILEGES ON educloud_test.* TO 'educloud_test'@'localhost';
-- Repeat the four GRANT lines for host '127.0.0.1'.
FLUSH PRIVILEGES;
```

Then apply the migrations:

```
php scripts/migrate.php up          # educloud
php scripts/migrate.php up --test   # educloud_test
php scripts/migrate.php status
```

## 4. Web server

### Default — XAMPP Apache, sub-directory URL (no Apache config changes, ADR-013)

With Apache running, open **http://localhost/EduCloud%20Lab/**. `APP_URL` in `.env` must be `http://localhost/EduCloud%20Lab`.

The root `.htaccess` rewrites every request into `public/`, so files such as `.env`, `vendor/` or `app/` return 403/404. This requires `mod_rewrite` (enabled in XAMPP) and `AllowOverride All` for htdocs (the XAMPP default).

### Option A — PHP built-in server

Set `APP_URL=http://127.0.0.1:8099` in `.env`, then run:

```
php -d xdebug.mode=off -S 127.0.0.1:8099 -t public scripts/dev-router.php
```

Open http://127.0.0.1:8099/.

### Option B — Apache virtual host `educloud.test` (optional)

Both files below are **shared by every XAMPP project**, so edit them carefully and back them up first.

1. **`C:\xampp\apache\conf\extra\httpd-vhosts.conf`.** Once any vhost exists, Apache sends unmatched hosts (including `localhost`) to the *first* vhost. So add a `localhost` vhost **first**, so your other projects keep working, then add EduCloud:

   ```apache
   <VirtualHost *:80>
       ServerName localhost
       DocumentRoot "C:/xampp/htdocs"
   </VirtualHost>

   <VirtualHost *:80>
       ServerName educloud.test
       DocumentRoot "C:/xampp/htdocs/EduCloud Lab/public"
       <Directory "C:/xampp/htdocs/EduCloud Lab/public">
           AllowOverride All
           Require local
       </Directory>
       ErrorLog "logs/educloud-error.log"
       CustomLog "logs/educloud-access.log" common
   </VirtualHost>
   ```

   `Require local` keeps the site reachable only from this machine.

2. **`C:\Windows\System32\drivers\etc\hosts`.** Edit it as Administrator and add:

   ```
   127.0.0.1  educloud.test
   ```

3. Set `APP_URL=http://educloud.test`, restart Apache from the XAMPP control panel and open http://educloud.test/.

## 5. Frontend assets (only when upgrading libraries)

```
npm install
npm run vendor     # copies dist files into public/assets/vendor (committed)
```

After upgrading, update the versions in `THIRD_PARTY_NOTICES.md`.

## 6. Quality checks

```
composer check     # PHPCS (PSR-12) + PHPStan + PHPUnit
php scripts/check-env.php
```

Integration tests use the `educloud_test` database only.
