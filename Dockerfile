ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libxml2-dev \
    zip \
    unzip \
    inotify-tools \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql xml simplexml \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . /var/www

# Install PHP dependencies
RUN if [ -f composer.json ]; then composer install --no-interaction --prefer-dist --optimize-autoloader; fi

# Create required directories
RUN mkdir -p /var/www/watch /var/www/logs /var/www/tmp \
    && chmod -R 755 /var/www/watch /var/www/logs /var/www/tmp

# Set PHP configuration
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini \
    && echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/timezone.ini

CMD ["php", "/var/www/src/watcher.php"]
