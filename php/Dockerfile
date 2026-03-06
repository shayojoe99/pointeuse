FROM php:8.2-fpm-alpine

RUN docker-php-ext-install pdo pdo_mysql mysqli

# Tune PHP-FPM for low-traffic internal use
RUN echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.max_children = 10" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.start_servers = 2" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.min_spare_servers = 1" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.max_spare_servers = 5" >> /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000
