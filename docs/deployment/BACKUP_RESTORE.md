# Backup and restore

A backup holds **two things that must stay consistent**: the MariaDB/MySQL database (`DB_NAME`) and the storage directory (`STORAGE_PATH`: raw files, per-workspace `lakehouse.duckdb`, previews, logs).

## Backup

```
php scripts/backup.php [--out=D:/educloud-backups]
```

The default output is `<parent of STORAGE_PATH>/educloud-backups/<UTC timestamp>/`. It must be outside `STORAGE_PATH`. Each backup contains:

| File | Content |
|---|---|
| `db.sql` | `mysqldump --single-transaction --routines --triggers --hex-blob --no-tablespaces` of `DB_NAME`, run as `DB_MIGRATOR_USER` |
| `storage.zip` | `STORAGE_PATH` without transient folders (`jobs/`, `tmp/`, `backups/`) |
| `manifest.json` | Row counts of the key tables, number of storage files, and size and SHA-256 of both files |

Credentials are passed to `mysqldump` through a temporary option file (mode 0600, deleted afterwards), never on the command line. The client tool paths default to XAMPP; override them with `MYSQLDUMP_PATH` and `MYSQL_PATH` in `.env`.

**Consistency:**
- The database dump is transactional.
- DuckDB lakehouse files are copied as they are. For a strictly consistent lakehouse copy, take the backup while the dispatcher is stopped (a job in progress could be writing a lakehouse file).

**Schedule:** daily, with Windows Task Scheduler or cron. Keep, for example, 7 daily and 4 weekly backups.

**Sensitive content:** backups contain personal data (emails, names), password hashes, audit logs and student work.
- Store them encrypted (BitLocker, an encrypted volume, or encrypted object storage).
- Restrict access to the operators.
- Never store them under `htdocs` or another web-served directory.
- Delete them according to your retention policy.

## Restore drill (recommended monthly)

```
php scripts/restore.php <backup-dir> --dry-run
```

- **What it does:**
  - Verifies the checksums.
  - Imports `db.sql` into **`DB_TEST_NAME`**, not the real database.
  - Compares the table counts with the manifest.
  - Extracts `storage.zip` into a temporary directory, checks the file count and removes it.
- **What it does not touch:** the real database and the real storage are never modified.
- **Cleanup:** the test database is emptied by the next test run.
- **Archive validation:** paths with `..`, absolute paths or drive letters are rejected before extracting.

Dry run performed on 2026-10-07 against the development data:
- checksums OK;
- 15 tables restored into `educloud_test`, with counts matching;
- 89 storage files extracted;
- `DRY RUN OK`.

## Real restore (disaster recovery)

1. Stop Apache, the dispatcher, the scheduler and the mailer.
2. Check that the code version matches the backup. If the backup is older, `schema_migrations` in `db.sql` tells which migrations it has; after restoring, run `php scripts/migrate.php up`.
3. Restore:

   ```
   php scripts/restore.php <backup-dir> --confirm=<DB_NAME>
   ```

   The `--confirm` value must equal `DB_NAME`, otherwise nothing happens. The current storage directory is kept as `<STORAGE_PATH>.before-restore-<timestamp>`.
4. Run `php scripts/check-env.php` and `php scripts/labs-import.php`, then start the services again.
5. Sessions and refresh tokens are restored as they were. To force everyone to log in again, empty the `sessions` table and revoke the `refresh_tokens`.
