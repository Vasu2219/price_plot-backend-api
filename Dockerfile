FROM alpine:3.20

RUN apk add --no-cache \
	php82 \
	php82-phar \
	php82-openssl \
	php82-curl \
	php82-mbstring \
	php82-session \
	php82-tokenizer \
	php82-ctype \
	php82-dom \
	php82-xml \
	php82-zip \
	php82-opcache \
	php82-pecl-mongodb \
	composer \
	bash \
	ca-certificates \
	git \
	unzip \
	&& ln -sf /usr/bin/php82 /usr/bin/php \
	&& php -m | grep -i mongodb

WORKDIR /app
COPY composer.json composer.lock* /app/
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
COPY . /app

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
