# Troubleshooting Guide

This guide helps resolve common issues with the NWS CAD system.

## Table of Contents

1. [Database Connection Issues](#database-connection-issues)
2. [File Watcher Not Processing Files](#file-watcher-not-processing-files)
3. [API Endpoint Errors](#api-endpoint-errors)
4. [Dashboard Not Loading](#dashboard-not-loading)
5. [Docker Container Issues](#docker-container-issues)
6. [Performance Problems](#performance-problems)
7. [Testing Failures](#testing-failures)
8. [Common Error Messages](#common-error-messages)

---

## Database Connection Issues

### Problem: "Database connection failed"

**Symptoms:**
- Application cannot connect to database
- Error messages in logs about connection timeout

**Solutions:**

1. **Check database service is running:**
   ```bash
   docker-compose ps
   # Should show mysql/postgres as "Up" and "healthy"
   ```

2. **Verify database credentials:**
   ```bash
   # Check .env file
   cat .env
   
   # Ensure DB_TYPE matches your database
   # Ensure credentials are correct
   ```

3. **Wait for database to be ready:**
   ```bash
   # Database may take 30-60 seconds to initialize
   docker-compose logs mysql  # or postgres
   # Look for "ready for connections" message
   ```

4. **Test connection manually:**
   ```bash
   # For MySQL
   docker-compose exec mysql mysql -u nws_user -p
   
   # For PostgreSQL  
   docker-compose exec postgres psql -U nws_user -d nws_cad
   ```

5. **Reset database:**
   ```bash
   docker-compose down -v  # Remove volumes
   docker-compose up -d    # Recreate everything
   ```

### Problem: "Unknown database" or "database does not exist"

**Solution:**
```bash
# Recreate database manually
docker-compose exec mysql mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS nws_cad;"

# Or for PostgreSQL
docker-compose exec postgres psql -U postgres -c "CREATE DATABASE nws_cad;"

# Then run init script
docker-compose exec mysql mysql -u nws_user -p nws_cad < database/mysql/init.sql
```

---

## File Watcher Not Processing Files

### Problem: XML files not being processed

**Symptoms:**
- Files remain in `watch/` folder
- No entries in `processed_files` table
- No log messages about processing

**Solutions:**

1. **Check file watcher is running:**
   ```bash
   docker-compose logs app
   # Look for "File watcher started" message
   ```

2. **Verify watch folder permissions:**
   ```bash
   ls -la watch/
   # Ensure Docker has read/write access
   ```

3. **Check file format:**
   ```bash
   # Ensure files have .xml extension
   # Ensure files are valid XML
   xmllint --noout watch/yourfile.xml
   ```

4. **Manually trigger processing:**
   ```bash
   # Copy test file
   cp samples/260_2022120307164448.xml watch/
   
   # Watch logs in real-time
   docker-compose logs -f app
   ```

5. **Check for errors in logs:**
   ```bash
   docker-compose logs app | grep -i error
   ```

### Problem: Files move to failed/ folder

**Solution:**
```bash
# Check error log in processed_files table
docker-compose exec mysql mysql -u nws_user -p -e "
  SELECT filename, status, error_message 
  FROM nws_cad.processed_files 
  WHERE status = 'failed' 
  ORDER BY processed_at DESC 
  LIMIT 10;"

# Common issues:
# - Invalid XML structure
# - Missing required elements
# - Duplicate call_id (fixed in v1.1.1+ - now automatically updates existing calls)
```

**Note on Duplicate Calls:**
CAD systems often send multiple updates for the same call (e.g., unit assignments, status changes). 
Version 1.1.1+ handles this automatically by updating existing records instead of failing. 
The system will log "Call ID X already exists, updating record" when processing updates.

---

## API Endpoint Errors

### Problem: "404 Not Found" on API requests

**Solutions:**

1. **Check API service is running:**
   ```bash
   docker-compose ps api
   # Should show as "Up"
   
   curl http://localhost:8080/api/
   ```

2. **Verify port mapping:**
   ```bash
   # Check docker-compose.yml ports section
   # Ensure API_PORT is set correctly in .env
   ```

3. **Check .htaccess file exists:**
   ```bash
   ls -la public/.htaccess
   # Should exist with URL rewrite rules
   ```

### Problem: "500 Internal Server Error"

**Solutions:**

1. **Check API logs:**
   ```bash
   docker-compose logs api
   tail -f logs/app.log
   ```

2. **Enable debug mode:**
   ```bash
   # In .env
   APP_DEBUG=true
   APP_ENV=development
   
   docker-compose restart api
   ```

3. **Test database connection:**
   ```bash
   curl http://localhost:8080/api/
   # Should return API info if DB is connected
   ```

### Problem: Rate limiting (429 Too Many Requests)

**Solution:**
```php
// Adjust rate limits in API code if needed
// Default: 60 requests per minute per IP

// Or wait before retrying
sleep 60
```

---

## Dashboard Not Loading

### Problem: Blank page or JavaScript errors

**Solutions:**

1. **Check browser console:**
   - Open Developer Tools (F12)
   - Look for JavaScript errors
   - Check Network tab for failed requests

2. **Verify API endpoint:**
   ```bash
   curl http://localhost:8080/api/calls
   # Should return JSON data
   ```

3. **Check CDN libraries load:**
   - Ensure internet connection for CDN resources
   - Or use local copies of libraries

4. **Clear browser cache:**
   - Hard refresh: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac)

### Problem: Map not displaying

**Solutions:**

1. **Check data has coordinates:**
   ```sql
   SELECT call_number, latitude, longitude 
   FROM calls 
   WHERE latitude IS NOT NULL 
   LIMIT 10;
   ```

2. **Verify Leaflet.js loaded:**
   - Check browser console for errors
   - Ensure CDN is accessible

3. **Check location permissions:**
   - Browser may block geolocation
   - Check browser settings

---

## Docker Container Issues

### Problem: Container keeps restarting

**Solutions:**

1. **Check container logs:**
   ```bash
   docker-compose logs [service-name]
   # Look for crash reasons
   ```

2. **Check resource limits:**
   ```bash
   docker stats
   # Ensure sufficient memory/CPU
   ```

3. **Verify Dockerfile syntax:**
   ```bash
   docker-compose config
   # Should show no errors
   ```

### Problem: "Port already in use"

**Solutions:**

1. **Find process using port:**
   ```bash
   # Linux/Mac
   lsof -i :8080
   netstat -tulpn | grep 8080
   
   # Windows
   netstat -ano | findstr :8080
   ```

2. **Change port in .env:**
   ```bash
   API_PORT=8081
   docker-compose down
   docker-compose up -d
   ```

3. **Kill conflicting process:**
   ```bash
   # Use PID from above command
   kill -9 [PID]
   ```

---

## Performance Problems

### Problem: Slow database queries

**Solutions:**

1. **Check query performance:**
   ```sql
   -- MySQL
   SHOW PROCESSLIST;
   EXPLAIN SELECT * FROM calls WHERE ...;
   
   -- PostgreSQL
   SELECT * FROM pg_stat_activity;
   EXPLAIN ANALYZE SELECT * FROM calls WHERE ...;
   ```

2. **Verify indexes exist:**
   ```sql
   SHOW INDEX FROM calls;
   # Should have 51+ indexes per database
   ```

3. **Optimize tables:**
   ```sql
   -- MySQL
   OPTIMIZE TABLE calls, units, narratives;
   
   -- PostgreSQL
   VACUUM ANALYZE calls, units, narratives;
   ```

4. **Add pagination to API requests:**
   ```bash
   # Limit results
   curl "http://localhost:8080/api/calls?per_page=10"
   ```

### Problem: High memory usage

**Solutions:**

1. **Monitor container resources:**
   ```bash
   docker stats
   ```

2. **Increase container limits:**
   ```yaml
   # In docker-compose.yml
   services:
     app:
       deploy:
         resources:
           limits:
             memory: 2G
   ```

3. **Reduce batch sizes:**
   - Process fewer files simultaneously
   - Use smaller pagination page sizes

---

## Testing Failures

### Problem: Tests fail with database errors

**Solutions:**

1. **Ensure test database exists:**
   ```bash
   docker-compose exec mysql mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS nws_cad_test;"
   ```

2. **Run with correct environment:**
   ```bash
   APP_ENV=testing composer test
   ```

3. **Check phpunit.xml configuration:**
   ```bash
   cat phpunit.xml
   # Verify database credentials
   ```

### Problem: Code coverage below threshold

**Solutions:**

1. **Generate coverage report:**
   ```bash
   composer test:coverage
   open coverage/html/index.html
   ```

2. **Add tests for uncovered code:**
   - Focus on controller methods
   - Add edge case tests
   - Test error conditions

---

## Common Error Messages

### "Duplicate entry for key 'calls.uk_call_id'" (Fixed in v1.1.1+)

**Error Message:**
```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'XXXXX' for key 'calls.uk_call_id'
```

**Background:**
This error occurred in versions prior to v1.1.1 when CAD systems sent multiple updates for the same call. The system would try to INSERT a new record instead of UPDATE the existing one.

**Fixed:**
Version 1.1.1+ automatically detects duplicate call_ids and updates the existing record instead of attempting to insert. No configuration changes needed.

**If you're on an older version:**
1. Upgrade to v1.1.1 or later
2. Or manually clean up duplicate processed files:
   ```bash
   docker-compose exec mysql mysql -u nws_user -p -e "
     DELETE pf FROM nws_cad.processed_files pf
     INNER JOIN (
       SELECT filename, MIN(id) as keep_id
       FROM nws_cad.processed_files
       GROUP BY filename HAVING COUNT(*) > 1
     ) dup ON pf.filename = dup.filename AND pf.id != dup.keep_id;"
   ```

### "Call to undefined function mb_strlen()"

**Solution:**
```bash
# Install mbstring extension
docker-compose exec app docker-php-ext-install mbstring
docker-compose restart app
```

### "Class 'PDO' not found"

**Solution:**
```bash
# Ensure PDO extension is enabled in Dockerfile
# Already included in PHP 8.3 build
docker-compose build --no-cache app
```

### "Maximum execution time exceeded"

**Solution:**
```bash
# Increase timeout in php.ini
docker-compose exec app sh -c "echo 'max_execution_time=300' >> /usr/local/etc/php/php.ini"
docker-compose restart app
```

### "Allowed memory size exhausted"

**Solution:**
```bash
# Increase memory limit
docker-compose exec app sh -c "echo 'memory_limit=512M' >> /usr/local/etc/php/php.ini"
docker-compose restart app
```

---

## Getting Help

If these solutions don't resolve your issue:

1. **Check logs:**
   ```bash
   # Application logs
   tail -100 logs/app.log
   
   # Docker logs
   docker-compose logs --tail=100
   ```

2. **Enable debug mode:**
   ```bash
   # In .env
   APP_DEBUG=true
   LOG_LEVEL=debug
   ```

3. **Create minimal reproduction:**
   - Use sample XML files
   - Test with fresh database
   - Document exact steps

4. **Gather system information:**
   ```bash
   docker --version
   docker-compose --version
   php --version  # If running locally
   ```

5. **Open an issue:**
   - Include error messages
   - Attach relevant logs
   - Describe expected vs actual behavior
   - List troubleshooting steps already tried
