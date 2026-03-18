FROM php:8.2-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip libssl-dev pkg-config $PHPIZE_DEPS \
	&& pecl install mongodb \
	&& docker-php-ext-enable mongodb \
	&& apt-get clean \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* /app/
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
COPY . /app

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
