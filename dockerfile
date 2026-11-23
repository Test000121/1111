FROM php:8.3-apache

RUN docker-php-ext-install mbstring

COPY rx.php /var/www/html/rx.php

ENV STORE=/tmp/drops
EXPOSE 10000

CMD sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf \
 && sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf \
 && apache2-foreground
