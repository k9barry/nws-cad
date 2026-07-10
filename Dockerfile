ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# Timezone — provided as a build arg from docker-compose (sourced from .env).
# Default UTC keeps standalone `docker build` working without compose.
ARG TZ=UTC

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libxml2-dev \
    zip \
    unzip \
    inotify-tools \
    ca-certificates \
    gosu \
    && update-ca-certificates \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql xml simplexml \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy composer files first for better caching
COPY composer.json composer.lock* /var/www/

# Configure Composer and install dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer config -g repos.packagist composer https://packagist.org && \
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts --no-dev 2>&1 || \
    echo "Composer install skipped or failed - will install in running container"

# Copy rest of application files
COPY . /var/www
RUN mkdir -p /var/www/var/watch /var/www/var/log \
    && chmod -R 755 /var/www/var/watch /var/www/var/log

# Set PHP configuration
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini \
    && echo "date.timezone = ${TZ}" > /usr/local/etc/php/conf.d/timezone.ini

# Drop root at runtime: the entrypoint (running as root) fixes ownership of the
# writable paths — including host bind mounts used by docker-compose — then
# execs the watcher as www-data via gosu. The watcher must be able to write
# logs/heartbeat and rename ingested files into watch/processed|failed.
RUN chown -R www-data:www-data /var/www
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "/var/www/src/watcher.php"]
