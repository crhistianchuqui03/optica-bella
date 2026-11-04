FROM php:8.2-apache

# Instalar controladores de MySQL para PHP
RUN docker-php-ext-install pdo pdo_mysql

# Habilitar módulos necesarios: rewrite (Front Controller) y headers (seguridad)
RUN a2enmod rewrite headers

WORKDIR /var/www/html

# Endurecer PHP: no exponer versión ni errores al navegador
RUN echo "expose_php=Off" > /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "display_errors=Off" >> /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "session.use_strict_mode=1" >> /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "session.use_only_cookies=1" >> /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "session.cookie_httponly=1" >> /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "session.cookie_samesite=Strict" >> /usr/local/etc/php/conf.d/zz-security.ini \
    && echo "session.gc_maxlifetime=1800" >> /usr/local/etc/php/conf.d/zz-security.ini

# Directorio de logs estructurados para Wazuh SIEM
RUN mkdir -p /var/log/app \
    && chown www-data:www-data /var/log/app \
    && chmod 750 /var/log/app

# DocumentRoot aislado: únicamente /var/www/html/public queda expuesto
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf