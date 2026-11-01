# Frontend PHP: Apache + PHP con PDO MySQL
FROM php:8.3-apache

# mod_rewrite + AllowOverride All: permite las URLs sin .php (public/.htaccess)
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite \
 && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/taller.conf \
 && a2enconf taller

# public/ es la raíz web. src/ y config/ quedan fuera de la raíz, sin acceso desde el navegador
COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY config/ /var/www/config/

EXPOSE 80