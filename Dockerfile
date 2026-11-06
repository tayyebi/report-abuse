# ---------------------------------------------------------------------------
# Abuse Report Desk — application image
# PHP 8.2 + Apache, PostgreSQL PDO driver, whois for enrichment.
# ---------------------------------------------------------------------------
FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        whois \
        ca-certificates \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Point Apache at public/ and allow .htaccess overrides.
RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && printf '\n<Directory %s>\n    AllowOverride All\n    Require all granted\n</Directory>\n' "${APACHE_DOCUMENT_ROOT}" \
        >> /etc/apache2/apache2.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/99-abuse-desk.ini

COPY . /var/www/html

RUN mkdir -p /var/www/html/storage/uploads /var/www/html/storage/outbox /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage \
    && chmod +x /var/www/html/bin/console

COPY docker/entrypoint.sh /usr/local/bin/abuse-desk-entrypoint
RUN chmod +x /usr/local/bin/abuse-desk-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["abuse-desk-entrypoint"]
CMD ["apache2-foreground"]
