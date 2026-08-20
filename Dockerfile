FROM php:8.2-apache

# Instalar controladores de MySQL para PHP
RUN docker-php-ext-install pdo pdo_mysql

# Habilitar mod_rewrite de Apache para URLs limpias
RUN a2enmod rewrite

# Copiar configuración de Apache si fuera necesario
WORKDIR /var/www/html
