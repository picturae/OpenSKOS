FROM debian:buster

ENV TERM=linux
ENV DEBIAN_FRONTEND noninteractive
ENV COMPOSER_ALLOW_SUPERUSER 1

# Replace shell with bash so we can source files
RUN rm /bin/sh && ln -s /bin/bash /bin/sh

RUN apt-get update \
    && apt install -y gnupg \
    && apt install -y --no-install-recommends ca-certificates apt-transport-https wget \
    && wget -q https://packages.sury.org/php/apt.gpg -O- | apt-key add - \
    && echo "deb https://packages.sury.org/php/ buster main" | tee /etc/apt/sources.list.d/php.list

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    && wget https://www.dotdeb.org/dotdeb.gpg --no-check-certificate \
    && apt-key add dotdeb.gpg \
    && apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv EA312927 \
    && apt-get update && apt-get install -y --no-install-recommends curl ca-certificates nano zip unzip \
    php7.4-cli php7.4-curl php7.4-json php7.4-mysql php7.4-xdebug php7.4-xsl php7.4-intl \
    php7.4-memcache php7.4-mbstring php7.4-zip php-imagick \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get -y --no-install-recommends install php7.4-fpm \
    && apt-get -y --no-install-recommends install procps git \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Configure FPM to run properly on docker
RUN sed -i "/listen = .*/c\listen = 9000" /etc/php/7.4/fpm/pool.d/www.conf \
    && sed -i "/;access.log = .*/c\access.log = /proc/self/fd/2" /etc/php/7.4/fpm/pool.d/www.conf \
    && sed -i "/;clear_env = .*/c\clear_env = no" /etc/php/7.4/fpm/pool.d/www.conf \
    && sed -i "/;catch_workers_output = .*/c\catch_workers_output = yes" /etc/php/7.4/fpm/pool.d/www.conf \
    && sed -i "/pid = .*/c\;pid = /run/php/php7.4-fpm.pid" /etc/php/7.4/fpm/php-fpm.conf \
    && sed -i "/;daemonize = .*/c\daemonize = no" /etc/php/7.4/fpm/php-fpm.conf \
    && sed -i "/error_log = .*/c\error_log = /proc/self/fd/2" /etc/php/7.4/fpm/php-fpm.conf \
    && usermod -u 1000 www-data

# The following runs FPM and removes all its extraneous log output on top of what your app outputs to stdout
CMD /usr/sbin/php-fpm7.4 -F 2>&1 | sed -u 's,.*: \"\(.*\)$,\1,'| sed -u 's,"$,,' 1>&1

# Open up fcgi port
EXPOSE 9000

WORKDIR "/var/www/openskos"
