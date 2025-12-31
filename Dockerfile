FROM php:8.2-apache

# 1. Install System Dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd mysqli pdo pdo_mysql

# 2. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Enable Mod Rewrite (Sudah ada, bagus)
RUN a2enmod rewrite

# 4. TAMBAHAN PENTING: Izinkan .htaccess bekerja
# Kita mengedit file konfigurasi apache secara otomatis menggunakan sed
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html