ARG PHP_VERSION=8.3.36
# Pinned to the 8.3.36 security release (21 Oct 2026: CVE-2026-109618 /
# CVE-2026-109619, unauthenticated RCE). A floating `8.3` tag can resolve to a
# stale cached image, so bump this explicitly on each PHP security release.
FROM php:${PHP_VERSION}-cli

# Timezone — provided as a build arg from docker-compose (sourced from .env).
# Default UTC keeps standalone `docker build` working without compose.
ARG TZ=UTC

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    curl \
    libpq-dev \
    gosu \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

# The API is served via `php -S` (CLI SAPI), so APCu must be enabled for CLI for
# the rate limiter to keep counters across requests in the same process.
RUN { echo "apc.enable_cli=1"; } > /usr/local/etc/php/conf.d/apcu-cli.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set PHP timezone (TZ build arg is sourced from .env via docker-compose)
RUN echo "date.timezone = ${TZ}" > /usr/local/etc/php/conf.d/timezone.ini

# Production PHP hardening (both CLI SAPI processes: watcher and `php -S` API).
#  - display_errors off: warnings must never reach API response bodies.
#  - session.upload_progress off: the app uses no sessions; disables the
#    CVE-2026-115560 attack surface outright.
RUN { \
      echo "expose_php = Off"; \
      echo "display_errors = Off"; \
      echo "display_startup_errors = Off"; \
      echo "log_errors = On"; \
      echo "session.upload_progress.enabled = 0"; \
    } > /usr/local/etc/php/conf.d/zz-security.ini

WORKDIR /var/www

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy application files
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

# Drop root at runtime: the entrypoint fixes ownership of bind-mounted paths as
# root, then execs the API server as www-data via gosu. Port 8080 (> 1024)
# needs no privileged binding.
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/public", "/var/www/public/router.php"]