# Database Backup and Restore Guide

## Overview
The NWS CAD system now includes automated database backup and restore capabilities to prevent accidental data loss.

## Quick Start

### Create a Backup
```bash
./scripts/backup-database.sh
```

This creates a timestamped, compressed backup in the `var/backups/` directory:
- `mysql_nws_cad_YYYYMMDD_HHMMSS.sql.gz`
- `postgres_nws_cad_YYYYMMDD_HHMMSS.sql.gz` (if PostgreSQL is running)

### Restore from Backup
```bash
./scripts/restore-database.sh
```

This will:
1. Show a list of available backups
2. Let you select which backup to restore
3. Create a safety backup of current data
4. Restore the selected backup

## Automated Backups

### Daily Backups with Cron
Add to your crontab (`crontab -e`):

```bash
# Daily backup at 2 AM
0 2 * * * cd /home/jcleaver/nws-cad && ./scripts/backup-database.sh >> /home/jcleaver/nws-cad/var/log/backup.log 2>&1

# Weekly backup on Sunday at 3 AM
0 3 * * 0 cd /home/jcleaver/nws-cad && ./scripts/backup-database.sh >> /home/jcleaver/nws-cad/var/log/backup.log 2>&1
```

### Backup Before Reset
The `reset-repo.sh` script now **automatically creates a backup** before deleting database data (if you confirm the deletion).

## Configuration

### Environment Variables
Add to your `.env` file:

```bash
# Backup configuration
BACKUP_DIR=./backups
BACKUP_RETENTION_DAYS=30
```

- `BACKUP_DIR`: Where to store backups (default: `./backups`)
- `BACKUP_RETENTION_DAYS`: How long to keep old backups (default: 30 days)

## Features

### backup-database.sh
- ✅ Creates compressed SQL dumps
- ✅ Timestamps all backups
- ✅ Automatically cleans up old backups (30+ days)
- ✅ Supports both MySQL and PostgreSQL
- ✅ Shows backup size and location
- ✅ Lists recent backups

### restore-database.sh
- ✅ Interactive backup selection
- ✅ Safety backup before restore
- ✅ Strong warning prompts (type "RESTORE" to confirm)
- ✅ Automatic database type detection
- ✅ Validates container is running

### reset-repo.sh (Enhanced)
- ✅ **Strong warning** before database deletion
- ✅ Requires typing "DELETE" to confirm
- ✅ **Automatic backup** before deletion
- ✅ Skips database deletion in non-interactive mode
- ✅ Clear visual warnings with colored output

## Safety Features

### Multiple Confirmation Levels
1. **Warning Banner** - Red warning box with clear message
2. **Explicit Confirmation** - Must type "DELETE" (all caps)
3. **Automatic Backup** - Created before deletion
4. **Safety Backup** - Created before restore

### Non-Interactive Protection
When running in CI/CD or automated scripts, `reset-repo.sh` will **skip database deletion** by default to prevent accidental data loss.

## File Locations

```
nws-cad/
├── scripts/
│   ├── backup-database.sh      # Create backups
│   ├── restore-database.sh     # Restore from backup
│   └── reset-repo.sh           # Enhanced with protection
├── var/backups/                    # Backup storage
│   ├── .gitkeep
│   ├── mysql_nws_cad_*.sql.gz
│   └── postgres_nws_cad_*.sql.gz
└── var/log/
    └── backup.log              # Automated backup logs
```

## Backup Examples

### Manual Backup
```bash
$ ./scripts/backup-database.sh

════════════════════════════════════════════════
  NWS CAD Database Backup
════════════════════════════════════════════════

Backing up MySQL database...
✓ MySQL backup created: var/backups/mysql_nws_cad_20260201_153843.sql.gz (42K)

Cleaning up backups older than 30 days...
✓ No old backups to delete

Recent backups:
─────────────────────────────────────────────────
var/backups/mysql_nws_cad_20260201_153843.sql.gz (42K)
var/backups/mysql_nws_cad_20260131_210000.sql.gz (1.2M)

════════════════════════════════════════════════
✅ Backup completed successfully
```

### Restore Backup
```bash
$ ./scripts/restore-database.sh

════════════════════════════════════════════════
  NWS CAD Database Restore
════════════════════════════════════════════════

Available backups:
─────────────────────────────────────────────────
1. mysql_nws_cad_20260201_153843.sql.gz - 42K - 2026-02-01 15:38
2. mysql_nws_cad_20260131_210000.sql.gz - 1.2M - 2026-01-31 21:00

Enter the number of the backup to restore (or 'q' to quit): 2

Selected backup: mysql_nws_cad_20260131_210000.sql.gz

╔════════════════════════════════════════════════════════════╗
║  ⚠️  WARNING: DATABASE RESTORE  ⚠️                         ║
║                                                            ║
║  This will REPLACE the current database with the backup!  ║
║  All current data will be LOST!                           ║
╚════════════════════════════════════════════════════════════╝

Type 'RESTORE' to confirm: RESTORE

Creating safety backup of current database...
✓ Safety backup created: var/backups/pre_restore_20260201_153900.sql.gz

Restoring database from backup...

════════════════════════════════════════════════
✅ Database restored successfully
════════════════════════════════════════════════

Restored from: mysql_nws_cad_20260131_210000.sql.gz
Safety backup: var/backups/pre_restore_20260201_153900.sql.gz
```

### Enhanced Reset Script
```bash
$ ./scripts/reset-repo.sh

🔄 Resetting nws-cad repository to fresh state...

[... previous steps ...]

Step 7: Cleaning database data...

╔════════════════════════════════════════════════════════════╗
║  ⚠️  WARNING: DATABASE DELETION  ⚠️                        ║
║                                                            ║
║  This will PERMANENTLY DELETE ALL DATABASE DATA including: ║
║  • All calls, incidents, units, and narratives            ║
║  • All processed file history                             ║
║  • All historical records                                 ║
║                                                            ║
║  THIS CANNOT BE UNDONE WITHOUT A BACKUP!                  ║
╚════════════════════════════════════════════════════════════╝

Do you want to DELETE all database data? Type 'DELETE' to confirm (or anything else to skip): no

✓ Skipping database cleanup (data preserved)
```

## Best Practices

1. **Daily Backups**: Set up automated daily backups with cron
2. **Before Major Changes**: Always backup before schema migrations or bulk updates
3. **Before Reset**: The script now does this automatically
4. **Test Restores**: Periodically test restoring backups to ensure they work
5. **Off-site Storage**: Copy critical backups to external storage/cloud
6. **Monitor Logs**: Check `var/log/backup.log` for automated backup status

## Troubleshooting

### Backup Fails
```bash
# Check if MySQL container is running
docker ps | grep nws-cad-mysql

# Check MySQL credentials in .env
grep MYSQL_ .env

# Try manual backup
docker exec nws-cad-mysql mysqldump -u nws_user -p'YOUR_PASSWORD' nws_cad > test.sql
```

### Restore Fails
```bash
# Check backup file is valid
gunzip -t var/backups/mysql_nws_cad_*.sql.gz

# Check container is running
docker ps | grep nws-cad-mysql
```

### Disk Space
```bash
# Check backup directory size
du -sh var/backups/

# Adjust retention period in .env
echo "BACKUP_RETENTION_DAYS=7" >> .env

# Clean old backups manually
find var/backups/ -name "*.sql.gz" -mtime +7 -delete
```

## Recovery Scenarios

### Scenario 1: Accidental Reset
If you accidentally ran `reset-repo.sh` and deleted the database:

1. Check `var/backups/` for the automatic backup created during reset
2. Run `./scripts/restore-database.sh`
3. Select the most recent backup (should be pre_delete_*.sql.gz)

### Scenario 2: Corrupted Database
If the database becomes corrupted:

1. Run `./scripts/backup-database.sh` (creates a backup of corrupted state, just in case)
2. Run `./scripts/restore-database.sh`
3. Select a backup from before the corruption occurred

### Scenario 3: Testing/Development
If you need to test with production-like data:

1. Backup production: `./scripts/backup-database.sh`
2. Copy backup to development environment
3. Restore: `./scripts/restore-database.sh`

## Security Notes

⚠️ **Important**: Backup files contain **unencrypted database data**. 

- Backups are stored in `var/backups/` directory
- This directory is in `.gitignore` (not committed to git)
- Protect access to the backups directory
- Consider encrypting backups for production:

```bash
# Encrypt backup
gpg --encrypt --recipient you@example.com var/backups/mysql_nws_cad_*.sql.gz

# Decrypt when needed
gpg --decrypt var/backups/mysql_nws_cad_*.sql.gz.gpg > backup.sql.gz
```

## Support

For issues or questions:
1. Check logs: `tail -f var/log/backup.log`
2. Test database connection: `docker exec -it nws-cad-mysql mysql -u nws_user -p`
3. Review documentation: `docs/README.md`
