# Backups, restore and the restore drill

> Scope: CORE-012 (R1). Encrypted daily backups of the PostgreSQL database and the private file storage, kept 14 days, plus a tested restore and a restore drill (DECIDED-004, NFR-REL-001, NFR-OPS-002, ARCHITECTURE.md §11.3). The decisions are in [ADR-024](adr/ADR-024-backup-restore-and-export.md). Team data export for users is described in API_SPEC.md §3 and is a separate feature.

The tooling is one script, [`deploy/backup/sn3-backup`](../deploy/backup/sn3-backup). Under Docker Compose it runs in the optional `backup` service, which uses the same `postgres:16.15-trixie` image as the database. That image already contains `pg_dump`, `pg_restore`, `psql`, `gpg` and `tar`, so nothing extra is installed. The script runs the same way on any host that has those tools, for example against an external PostgreSQL.

The CI job **Compose install (clean Linux host)** runs this runbook on every pull request (`.github/scripts/compose-install-smoke.sh`): backup, verification, refusal of a wrong passphrase, restore into a fresh database, row-count comparison, migration status, file restore, retention and the daily service's health.

## 1. What a backup contains

Each run writes one **backup set** named `sn3-<UTC time>`, for example `sn3-20261016T023000Z`:

| File | Content |
|---|---|
| `<set>.db.dump.gpg` | `pg_dump` custom format (compressed) of the whole SN3 database, encrypted |
| `<set>.storage.tar.gz.gpg` | The `storage/app` volume (private files; attachments once they exist), encrypted. Team export archives (`storage/app/exports`) are left out: they are deleted 24 hours after they are made and are not data of record |
| `<set>.sha256` | SHA-256 of the two encrypted files. It is written last, so a set without it is incomplete and is never restored |

- **Encryption:** OpenPGP symmetric encryption with AES-256, using `gpg` and a passphrase that you supply in a file (key derivation: iterated and salted SHA-512). Nothing is ever written unencrypted to the backup directory, and the passphrase is never logged.
- **Consistency:** `pg_dump` takes one consistent snapshot of the database. The files are archived right after it, so they are at least as new as the rows that point at them (ARCHITECTURE.md §11.3).
- **Verification:** after writing a set, the script decrypts it and reads it back (`pg_restore --list`, `tar -t`). A set that does not verify counts as a failed backup.
- **Retention:** after each successful backup, sets older than `SN3_BACKUP_RETENTION_DAYS` (14) are deleted. The newest set is always kept, so a series of failed backups can never leave you with nothing. Leftovers of interrupted runs are removed after a day.
- **Not included:** `.env` (with `APP_KEY` and `DB_PASSWORD`) and the backup passphrase. Keep both in your password manager or secret store. Without the same `APP_KEY`, sessions and other encrypted values from the backup cannot be read after a restore.
- **Recovery point:** with daily backups you can lose up to a day of changes. This baseline offers no point-in-time recovery (WAL archiving). SN3 does not advertise an RPO or RTO; measure your own restore time with the drill in §5.

## 2. Set up daily backups (Docker Compose)

Run these in the directory with `docker-compose.yml`, after the install in [SELF_HOSTING.md](SELF_HOSTING.md).

1. Create the passphrase file and the backup directory:

   ```bash
   umask 077
   openssl rand -base64 32 > backup-passphrase   # the first line is the passphrase, 16+ characters
   mkdir -p backups
   ```

   **Store a copy of the passphrase outside this host now** (password manager, secret store). Every backup is useless without it, and it cannot be recovered.

2. Optional settings in `.env`:

   | Variable | Default | Meaning |
   |---|---|---|
   | `SN3_BACKUP_HOST_DIR` | `./backups` | Host directory for the backup sets |
   | `SN3_BACKUP_PASSPHRASE_HOST_FILE` | `./backup-passphrase` | Host file holding the passphrase |
   | `SN3_BACKUP_RETENTION_DAYS` | `14` | Days a set is kept |
   | `SN3_BACKUP_AT` | `02:30` | Daily backup time, `HH:MM` in UTC |

   The database settings (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) are the ones the app uses.

3. Take a first backup by hand and check it:

   ```bash
   docker compose --profile backup run --rm backup run
   docker compose --profile backup run --rm backup list
   docker compose --profile backup run --rm backup verify latest
   ```

4. Start the daily service:

   ```bash
   docker compose --profile backup up -d --wait backup
   ```

   It backs up every day at `SN3_BACKUP_AT`. When it starts and finds no set from the last 24 hours (a fresh install, or the service was down), it takes one at once. Its log is `docker compose logs backup`.

   The container is **healthy** while the scheduler runs and the last backup succeeded, and **unhealthy** after a failed backup until the next one succeeds. Alert on it (ARCHITECTURE.md §11.2), for example with `docker compose ps backup` in your monitoring, or by watching for `the backup FAILED` in the log.

Every command of the script:

| Command | Does |
|---|---|
| `run` | Take one backup set now, verify it, apply retention |
| `daemon` | The service's default: daily at `SN3_BACKUP_AT` |
| `list` | Complete sets, newest first, with size and content |
| `verify <set\|latest>` | Checksums, decryption and readability |
| `restore <set\|latest> --database <name>` | Restore the dump into a **new** database (refuses an existing one) |
| `restore-storage <set\|latest> --into <dir> [--force]` | Extract the files into a directory (empty unless `--force`) |
| `compare <db-a> <db-b>` | Exact row counts of every table in both databases; exit code 1 on any difference. Skips `cache`, `cache_locks`, `sessions` and `jobs` (`SN3_BACKUP_COMPARE_EXCLUDE`) |
| `prune` | Apply retention only |

Without Compose, set `PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD`, `PGDATABASE`, `SN3_BACKUP_DIR`, `SN3_BACKUP_PASSPHRASE_FILE` and `SN3_BACKUP_STORAGE_DIR` (your `storage/app`, or empty to skip files), and run `deploy/backup/sn3-backup run` from cron, for example `30 2 * * * /opt/sn3/deploy/backup/sn3-backup run`. Use the PostgreSQL client tools of the same major version as the server.

## 3. Who is responsible for what

| | Self-hosted | SN3 Cloud |
|---|---|---|
| Runs the backups | The operator, with this tooling or their own | Cloud operations, with this tooling |
| Passphrase / key | The operator's secret store | The Cloud secret manager; the passphrase file is mounted from it, never stored in the repository or the image |
| Offsite copy | The operator (§4) | Required: a private object-storage bucket (§4) |
| Retention | 14 days by default, configurable | **14 days, fixed** (DECIDED-004) |
| Restore drill | Recommended after install, after each upgrade and regularly | Part of every release (DEVELOPMENT_PLAN.md §9 step 5: backup/restore rehearsal) and before production (NFR-SEC-006) |

Object storage, KMS and monitoring for Cloud cannot be provisioned from this repository. §4 lists the exact configuration they need.

## 4. Copy backups off the host

Backups on the same disk as the database do not survive the loss of that disk. Copy the backup directory somewhere else after each run. The files are already encrypted, so the destination never sees plain data.

**Any host:** sync the directory with your tool of choice after `SN3_BACKUP_AT`, for example from the host's crontab:

```bash
# rsync over SSH to another machine, mirroring deletions so the copy keeps the same 14 days
45 2 * * * rsync -a --delete /srv/sn3/backups/ backup@vault.example.com:/srv/sn3-backups/
# or rclone to any S3-compatible bucket
45 2 * * * rclone sync /srv/sn3/backups/ s3remote:sn3-backups/prod/
```

**SN3 Cloud (configuration for Cloud operations):**

- **Bucket:** a dedicated private bucket (for example `sn3-cloud-backups-<region>`) in a separate account or project from the application, with public access blocked, TLS-only access and versioning on.
- **Encryption at rest on top of the client-side encryption:** server-side encryption with a customer-managed KMS key (SSE-KMS). Only the backup writer role may use it to encrypt, and only the restore role to decrypt.
- **Write-only uploader:** the role that uploads after each run may `PutObject` under its prefix and nothing else (no `Delete`, no `Get`). Restore uses a separate, audited role.
- **Retention:** a lifecycle rule that expires objects (and noncurrent versions) **14 days** after creation, so backups age out independently of trash purges (API_SPEC.md §13). If object lock is used, its retention must not exceed 14 days.
- **Replication:** optional cross-region replication with the same 14-day lifecycle.
- **Monitoring:** alert when the `backup` container is unhealthy, when no new `.sha256` object has arrived for 26 hours, and when a restore drill fails.
- **Passphrase:** generated once (`openssl rand -base64 32`), kept in the secret manager, mounted read-only as `/run/secrets/sn3_backup_passphrase`, and rotated by starting a new chain of backups (old sets stay readable with the old passphrase until they expire).

## 5. The restore drill (no downtime)

The drill proves that the latest backup can actually be restored, without touching the live database. Run it after setting up backups, after every upgrade, and on a regular schedule. Write down the date, the set and how long each step took: that is your measured restore time.

```bash
# 1. Take a fresh backup (or use the latest nightly one) and verify it.
docker compose --profile backup run --rm backup run
docker compose --profile backup run --rm backup verify latest

# 2. Restore it into a new database next to the live one.
docker compose --profile backup run --rm backup restore latest --database sn3_restore_drill

# 3. Compare row counts with the live database. Right after step 1 they must be identical;
#    later, only tables changed by activity since the backup may differ.
docker compose --profile backup run --rm backup compare sn3 sn3_restore_drill

# 4. The restored schema is complete: every migration shows "Ran", none "Pending".
docker compose run --rm --no-deps -e DB_DATABASE=sn3_restore_drill app php artisan migrate:status

# 5. The files restore too (into a scratch directory inside the backup container).
docker compose --profile backup run --rm --entrypoint bash backup -c \
  'mkdir /tmp/r && bash /opt/sn3-backup/sn3-backup restore-storage latest --into /tmp/r && find /tmp/r -type f | wc -l'
docker compose exec app sh -c 'find storage/app -type f -not -path "storage/app/exports/*" | wc -l'   # same number, right after step 1

# 6. Remove the drill database.
docker compose exec postgres dropdb -U sn3 sn3_restore_drill
```

Every step must succeed. `restore` runs in a single transaction with `--exit-on-error`, so a dump that does not restore completely (a broken foreign key, a missing extension) fails instead of leaving a partial database, and the half-created database is dropped. Referential integrity is checked by PostgreSQL itself, because every constraint is re-created and validated during the restore (DATABASE.md §16 DB-T12).

## 6. Restoring for real

Use this after data loss or corruption. It replaces the live data with a backup set, so everything written after that set is lost. The old database is renamed, not dropped, so you can go back.

```bash
# 1. Stop everything that writes. PostgreSQL keeps running.
docker compose --profile backup stop app worker scheduler backup

# 2. Pick the set (normally the newest one taken before the incident) and verify it.
docker compose --profile backup run --rm backup list
docker compose --profile backup run --rm backup verify sn3-20261016T023000Z

# 3. Restore the database into a new database and check it.
docker compose --profile backup run --rm backup restore sn3-20261016T023000Z --database sn3_restored
docker compose run --rm --no-deps -e DB_DATABASE=sn3_restored app php artisan migrate:status

# 4. Swap it in. Keep the old database until you are sure.
docker compose exec postgres psql -U sn3 -d postgres \
  -c 'ALTER DATABASE sn3 RENAME TO sn3_before_restore' \
  -c 'ALTER DATABASE sn3_restored RENAME TO sn3'

# 5. Restore the files into the storage volume. Files newer than the set stay, but nothing in
#    the restored database points at them.
docker compose --profile backup run --rm -v sn3_app-storage:/restore backup \
  restore-storage sn3-20261016T023000Z --into /restore --force

# 6. Bring SN3 back. If the running version is newer than the backup, migrate first.
docker compose run --rm app php artisan migrate --force
docker compose --profile backup up -d --wait

# 7. Sign in and check a few teams and projects. Then drop the old database.
docker compose exec postgres dropdb -U sn3 sn3_before_restore
```

To **roll back** step 4 before dropping anything: stop the app again and rename the databases the other way round.

Notes:

- Restore with the **same `APP_KEY`** as the backup. The application role (`DB_USERNAME`) must be able to create databases, which the bundled PostgreSQL's role can. With an external PostgreSQL, run the restore as the application's role, so the restored tables belong to it.
- A restore brings back the database as it was, including teams and projects that were in the trash then. Their 30-day clock continues from their original `deleted_at`, so the next `trash:purge` removes those past the window. Team exports in the restored database have no archive (archives are not backed up); they expire within 24 hours and are pruned.
- Restoring an **older** backup over a newer installation is a data rollback. To roll back an upgrade, restore the backup taken before the upgrade and run the previous release's image (GH-008 documents upgrades).

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `no passphrase file at /run/secrets/sn3_backup_passphrase` | `SN3_BACKUP_PASSPHRASE_HOST_FILE` does not point at a file. If Docker created a directory with that name, remove it and create the file (§2). |
| `the passphrase … must have at least 16 characters` | Use a longer passphrase, e.g. `openssl rand -base64 32`. |
| `gpg: decryption failed: Bad session key` | Wrong passphrase for this set. Use the one that was active when the set was written. |
| `checksum mismatch` | The set was changed or damaged after it was written (for example by an incomplete copy). Use another set. |
| `database … already exists` | `restore` only writes into a new database. Pick another name, or drop the old one first. |
| `another backup is running` | A run is still in progress (the daemon or a manual one). Wait for it. |
| `backup` container unhealthy | The last backup failed. `docker compose logs backup` shows why; after fixing it, `docker compose --profile backup run --rm backup run` and restart the service. |
