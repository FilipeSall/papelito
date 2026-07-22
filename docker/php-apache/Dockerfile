FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        less \
        msmtp \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" exif gd mysqli opcache zip \
    && a2enmod rewrite headers expires \
    && printf "ServerName papelitobrasil.local\n" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp \
    && mkdir -p /var/www/papelito-private/labels \
    && chown -R www-data:www-data /var/www/html /var/www/papelito-private \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php-apache/entrypoint.sh /usr/local/bin/papelito-entrypoint
RUN chmod +x /usr/local/bin/papelito-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["papelito-entrypoint"]
CMD ["apache2-foreground"]
