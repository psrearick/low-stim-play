FROM php:8.4-fpm-alpine

# Install system dependencies
# linux-headers is needed to compile the sockets extension below --
# sockets.c includes linux/sock_diag.h, which isn't present in Alpine's
# base image otherwise (a missing header, not a missing compiler --
# gcc/make are already available for the other extensions here).
RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    linux-headers \
    mariadb-client \
    nodejs \
    npm \
    oniguruma-dev \
    unzip \
    zip

# gd needs an explicit --with-jpeg configure step -- without it, gd builds
# with only the PNG support libpng-dev (above) provides, and any code
# calling imagejpeg() (e.g. Illuminate\Http\Testing\FileFactory, behind
# UploadedFile::fake()->image('*.jpg')) throws a LogicException at runtime,
# not a build-time failure -- confirmed live via a real failing test before
# assuming this was the cause.
RUN docker-php-ext-configure gd --with-jpeg

# Install PHP extensions
# sockets is required by pestphp/pest-plugin-browser (its Playwright driver
# talks over a raw websocket connection) -- missing here even though it's
# present on the host, since this image was built before that plugin was
# ever added as a dependency.
RUN docker-php-ext-install \
    bcmath \
    gd \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_mysql \
    sockets \
    zip

# Install Redis extension via PECL. docker-php-ext-install (above) installs
# its own build tools and removes them again within that same RUN layer, so
# a separate `pecl install` here has nothing left to compile with unless it
# brings its own -- hence the explicit $PHPIZE_DEPS add/remove around it.
RUN apk add --no-cache --virtual .build-deps ${PHPIZE_DEPS} \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# PHP-FPM config: use nobody user for dev
RUN sed -i 's/user = www-data/user = nobody/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/group = www-data/group = nobody/' /usr/local/etc/php-fpm.d/www.conf

# storage/framework/views is mounted from a dedicated named volume (see
# docker-compose.yml) rather than the host bind mount, so compiled Blade
# view caches don't collide with the host's own absolute paths. Docker
# seeds a fresh named volume from whatever already exists at its mount
# point in the image, so this directory needs to exist here, owned by the
# same "nobody" user php-fpm runs as -- otherwise the first request to
# write a compiled view into the freshly created (root-owned) volume fails.
RUN mkdir -p storage/framework/views \
    && chown -R nobody:nobody storage/framework/views

# PHP config: opcache settings for development
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini

# PHP config: raise upload limits above the default 2M/8M so avatar
# uploads (validated up to 10MB, see resources/views/pages/⚡profile.blade.php)
# aren't silently truncated by php-fpm before Laravel ever sees them.
RUN echo "upload_max_filesize=10M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=12M" >> /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 9000

CMD ["php-fpm"]
