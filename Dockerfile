FROM php:7.2-cli As build
MAINTAINER Sven Stam <sven@ltonetwork.com>

RUN apt-get update && apt-get install -y \
        libyaml-dev \
        libbase58-dev \
        git \
        && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /var/app/
WORKDIR /var/app/

RUN echo | pecl install yaml && pecl install base58-0.1.3 && pecl install mongodb && docker-php-ext-enable mongodb base58 yaml
RUN composer install

FROM php:7.2-cli

RUN apt-get update && apt-get install -y \
        libyaml-dev \
        libbase58-dev

COPY --from=build /var/app /var/app

RUN ln -s /var/app/bin/lctest /usr/bin/lctest
RUN echo | pecl install yaml && pecl install base58-0.1.3 && pecl install mongodb && docker-php-ext-enable mongodb base58 yaml

ENV BEHAT_CONFIG /var/app/docker.behat.yml

WORKDIR /livecontracts

ENTRYPOINT ["lctest"]
