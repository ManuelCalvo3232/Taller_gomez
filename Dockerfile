# Frontend PHP: Apache + PHP con PDO MySQL
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

# public/ es la raíz web. src/ y config/ quedan fuera de la raíz, sin acceso desde el navegador
COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY config/ /var/www/config/

EXPOSE 80