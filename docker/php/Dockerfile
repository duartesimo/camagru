FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libpng-dev msmtp \
    && docker-php-ext-install pdo_pgsql gd \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' 'sendmail_path = "/usr/bin/msmtp --host=mailpit --port=1025 --tls=off --auth=off --timeout=10 --read-envelope-from -t"' \
    > /usr/local/etc/php/conf.d/mail.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/docker-php.conf

RUN mkdir -p /var/lib/camagru/photos \
    && chown www-data:www-data /var/lib/camagru/photos \
    && chmod 0750 /var/lib/camagru/photos
