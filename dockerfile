FROM php:8.3-apache

# Install mbstring dependencies
RUN apt-get update && apt-get install -y \
    libonig-dev \
    && docker-php-ext-install mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy PHP application
COPY rx.php /var/www/html/rx.php
COPY manage.php /var/www/html/manage.php

# Storage directory
ENV STORE=/tmp/drops

# Render port
EXPOSE 10000

# Configure Apache for Render
CMD sed -ri "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf \
 && sed -ri "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf \
 && apache2-foreground
