# FROM php:8.4-fpm

# RUN apt-get update && apt-get install -y \
#     git curl zip unzip \
#     libpng-dev libonig-dev libxml2-dev \
#     libzip-dev libjpeg-dev libfreetype6-dev \
#     libicu-dev \
#     && docker-php-ext-configure gd --with-freetype --with-jpeg \
#     && docker-php-ext-install \
#         pdo_mysql \
#         mbstring \
#         exif \
#         pcntl \
#         bcmath \
#         gd \
#         zip \
#         intl

# COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WORKDIR /var/www

# # Copy source dulu
# COPY . .

# RUN chown -R www-data:www-data /var/www

# # Optional: skip script dulu
# RUN composer install --no-dev --optimize-autoloader --no-scripts

# USER www-data



FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    nginx supervisor \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev \
    libzip-dev libjpeg-dev libfreetype6-dev \
    libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql mbstring exif pcntl bcmath gd zip intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . /var/www

# nginx config
COPY docker/nginx/default.conf /etc/nginx/sites-available/default

# supervisor config
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

RUN chown -R www-data:www-data /var/www

RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader

RUN mkdir -p /var/www/storage \
    /var/www/bootstrap/cache \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord"]