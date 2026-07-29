FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libpng-dev \
    libzip-dev \
    mariadb-client \
    nodejs \
    npm \
    oniguruma-dev \
    unzip \
    zip

# Install PHP extensions
RUN docker-php-ext-install \
    bcmath \
    gd \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_mysql \
    zip

# Install Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# PHP-FPM config: use nobody user for dev
RUN sed -i 's/user = www-data/user = nobody/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/group = www-data/group = nobody/' /usr/local/etc/php-fpm.d/www.conf

# PHP config: opcache settings for development
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 9000

CMD ["php-fpm"]
