FROM php:8.1-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN apt-get update && apt-get install -y libmagickwand-dev --no-install-recommends
RUN printf "\n" | pecl install imagick
RUN docker-php-ext-enable imagick
RUN sed -ri -e "s!/var/www/html!$APACHE_DOCUMENT_ROOT!g" /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html

COPY ./app /var/www/html/app
COPY ./bootstrap /var/www/html/bootstrap
COPY ./database /var/www/html/database
COPY ./printq /var/www/html/printq
COPY ./public /var/www/html/public
COPY ./resources /var/www/html/resources
COPY ./routes /var/www/html/routes
COPY ./storage /var/www/html/storage
COPY ./vendor /var/www/html/vendor
EXPOSE 80
