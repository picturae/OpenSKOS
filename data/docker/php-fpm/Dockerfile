FROM alpine:3.7
ENV TERM=ansi
ENV COMPOSER_ALLOW_SUPERUSER 1
# Alpine 3.7 to use php7.1

# Set the workdir for script usage
WORKDIR "/var/www/openskos"

# Update all packages
RUN apk update
RUN apk upgrade
RUN apk add bash curl git

# Build user to run as
RUN addgroup -g 1000 -S    www-data
RUN adduser  -u 1000 -S -G www-data -h /home/www www-data

# Setup php
RUN apk add php7
RUN apk add php7-fpm
RUN apk add php7-json

# Setup composer
RUN apk add php7-iconv
RUN apk add php7-openssl
RUN apk add php7-phar
RUN curl https://getcomposer.org/install | php -- --install-dir=/usr/bin
RUN ln -s /usr/bin/composer.phar /usr/bin/composer

# Install required libs
RUN apk add zlib-dev

# Install remaining dependencies
RUN apk add build-base
RUN apk add php7-dev
RUN apk add php7-pear
RUN pecl channel-update pecl.php.net
COPY data/docker/extra/composer-ext-install.php /usr/bin/composer-ext-install
RUN chmod +x /usr/bin/composer-ext-install
COPY composer.json /etc/php/composer.json
COPY composer.lock /etc/php/composer.lock
RUN composer-ext-install /etc/php/composer.json /etc/php/composer.lock

# Open up fcgi port
EXPOSE 9000

RUN mkdir -p /var/log/php
CMD /usr/sbin/php-fpm7
