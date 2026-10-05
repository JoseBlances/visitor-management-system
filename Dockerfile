# The ISATU Visitor Management web server (PHP 8.2 + Apache) for Railway or any Docker host.
# DEPLOYMENT.md explains the setup. The Android app is built separately and is not included.
FROM php:8.2-apache-bookworm

# mysqli and opcache for PHP; the MariaDB command-line client imports the database files
# (phone_tracker/tools/setup_database.php); mod_headers sends the security headers in
# phone_tracker/.htaccess. Folder listings and the server-status page are switched off.
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli opcache \
    && a2enmod headers \
    && a2dismod -f autoindex status

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-isatu.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/isatu-entrypoint
COPY index.php /var/www/html/index.php
COPY phone_tracker /var/www/html/phone_tracker

# Apache listens on Railway's $PORT. Uploaded photos live on the persistent /data volume
# (the entrypoint prepares it); the code itself stays read-only for the web server.
RUN printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && sed -i 's/\r$//' /usr/local/bin/isatu-entrypoint \
    && chmod 755 /usr/local/bin/isatu-entrypoint \
    && mv /var/www/html/phone_tracker/uploads /usr/local/share/isatu-uploads \
    && ln -s /data/uploads /var/www/html/phone_tracker/uploads \
    && rm -f /var/www/html/index.html \
    && chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} +

# Railway sends every request through its proxy, so the visitor's address and HTTPS come
# from the proxy's headers (runtime.php). Set ISATU_TRUST_PROXY=0 on a host without one.
ENV ISATU_TRUST_PROXY=1 \
    ISATU_CONTAINER=1 \
    ISATU_DATA_DIR=/data \
    VISITOR_APP_ENV=production \
    PORT=8080

EXPOSE 8080
CMD ["isatu-entrypoint"]
