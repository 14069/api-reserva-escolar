FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && a2enmod rewrite headers \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "set -e; sed -ri \"s/Listen 80/Listen ${PORT:-8080}/\" /etc/apache2/ports.conf; sed -ri \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-8080}>/\" /etc/apache2/sites-available/000-default.conf; apache2-foreground"]
