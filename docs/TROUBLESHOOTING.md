# Troubleshooting Guide

## Quick Diagnostics

```bash
# Check all services
docker-compose ps

# View recent logs
docker-compose logs --tail=50

# Test API
curl http://localhost:8080/api/

# Test database
docker-compose exec mysql mysql -u nws_user -p -e "SELECT COUNT(*) FROM calls;"
```

## Common Issues

### Database Connection Failed

**Symptoms:** API returns errors, dashboard blank

**Solutions:**

```bash
# 1. Check database is running
docker-compose ps mysql  # Should show "Up (healthy)"

# 2. Wait for initialization (30-60 seconds on first start)
docker-compose logs mysql | grep "ready for connections"

# 3. Verify credentials in .env match docker-compose.yml

# 4. Test connection manually
docker-compose exec mysql mysql -u nws_user -p nws_cad

# 5. Reset database (WARNING: deletes data)
docker-compose down -v && docker-compose up -d
```

### XML Files Not Processing

**Symptoms:** Files stay in `watch/`, no database entries

**Solutions:**

```bash
# 1. Check watcher is running
docker-compose logs app | grep "File watcher"

# 2. Verify file permissions
ls -la watch/

# 3. Check file is valid XML
xmllint --noout watch/yourfile.xml

# 4. Check for errors
docker-compose logs app | grep -i error

# 5. Test with sample file
cp samples/260_2022120307164448.xml watch/
docker-compose logs -f app
```

### Files Moving to failed/ Folder

**Check error in database:**

```sql
SELECT filename, status, error_message 
FROM processed_files 
WHERE status = 'failed' 
ORDER BY processed_at DESC 
LIMIT 5;
```

**Common causes:**
- Invalid XML structure
- Missing required elements
- Database constraint violation

### API Returns 404

**Solutions:**

```bash
# 1. Check API service
docker-compose ps api

# 2. Verify port
curl http://localhost:8080/api/

# 3. Check .htaccess exists
ls -la public/.htaccess
```

### API Returns 500

**Solutions:**

```bash
# 1. Check logs
docker-compose logs api
tail -f logs/app.log

# 2. Enable debug mode in .env
APP_DEBUG=true
APP_ENV=development

# 3. Restart
docker-compose restart api
```

### Dashboard Blank or Errors

**Solutions:**

1. Open browser DevTools (F12)
2. Check Console tab for JavaScript errors
3. Check Network tab for failed API requests
4. Verify API is responding: `curl http://localhost:8080/api/calls`
5. Clear browser cache: Ctrl+Shift+R

### Map Not Displaying

**Solutions:**

1. Check internet connection (map tiles load from CDN)
2. Verify calls have coordinates:
   ```sql
   SELECT COUNT(*) FROM locations WHERE latitude_y IS NOT NULL;
   ```
3. Check browser console for Leaflet errors

### Mobile View Not Loading

**Solutions:**

1. Check User-Agent detection is working
2. Clear browser cache
3. Try different mobile browser
4. Verify `jenssegers/agent` package is installed

### Slow Performance

**Solutions:**

```bash
# 1. Check resource usage
docker stats

# 2. Optimize tables (MySQL)
docker-compose exec mysql mysql -u root -p -e "OPTIMIZE TABLE nws_cad.calls, nws_cad.units;"

# 3. Add pagination to API requests
curl "http://localhost:8080/api/calls?per_page=10"

# 4. Check slow queries
docker-compose exec mysql mysql -u root -p -e "SHOW PROCESSLIST;"
```

### Port Already in Use

**Solutions:**

```bash
# 1. Find process using port
lsof -i :8080
netstat -tulpn | grep 8080

# 2. Change port in .env
API_PORT=8081

# 3. Restart
docker-compose down && docker-compose up -d
```

### Tests Failing

**Solutions:**

```bash
# 1. Check test database exists
mysql -u test_user -ptest_pass -e "USE nws_cad_test; SHOW TABLES;"

# 2. Run specific test with verbose
./vendor/bin/phpunit --verbose tests/Unit/ConfigTest.php

# 3. Check PHP extensions
php -m | grep -E "pdo|mysql|xml"
```

## Error Messages

### "Duplicate entry for key 'uk_call_id'"

**Fixed in v1.1.1+** - System now updates existing records instead of failing.

**Older versions:** Upgrade to v1.1.1 or later.

### "Maximum execution time exceeded"

```bash
docker-compose exec app sh -c "echo 'max_execution_time=300' >> /usr/local/etc/php/php.ini"
docker-compose restart app
```

### "Allowed memory size exhausted"

```bash
docker-compose exec app sh -c "echo 'memory_limit=512M' >> /usr/local/etc/php/php.ini"
docker-compose restart app
```

### "Class 'PDO' not found"

```bash
docker-compose build --no-cache app
docker-compose up -d
```

## Logs

### View Logs

```bash
# Application logs
tail -100 logs/app.log

# Docker logs
docker-compose logs --tail=100 app
docker-compose logs --tail=100 api
docker-compose logs --tail=100 mysql
```

### Enable Debug Logging

In `.env`:
```bash
APP_DEBUG=true
LOG_LEVEL=debug
```

## Getting Help

1. Check this guide
2. Review [CHANGELOG.md](../CHANGELOG.md) for known issues
3. Check Docker and application logs
4. Create minimal reproduction case
5. Open GitHub issue with:
   - Error messages
   - Log output
   - Steps to reproduce
   - Environment info (`docker --version`, `php --version`)

---

**Version:** 1.1.0 | **Last Updated:** 2026-02-15
