FROM php:8.3-cli

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
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set PHP timezone (TZ build arg is sourced from .env via docker-compose)
RUN echo "date.timezone = ${TZ}" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /var/www

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy application files
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www

EXPOSE 8080

# Drop root: the API listens on 8080 (> 1024), so it needs no privileged port.
USER www-data

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/public", "/var/www/public/router.php"]