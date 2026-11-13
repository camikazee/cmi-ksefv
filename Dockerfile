FROM composer:2.11.1@sha256:3d1eb9437516b0ba88c46a0bb05826451a1ab178d29adacb5ded26dad0899ae4 AS test
WORKDIR /app
COPY composer.json ./
RUN composer validate --strict --no-check-publish \
    && composer install --no-interaction --prefer-dist --no-progress --no-autoloader
COPY . .
RUN composer dump-autoload
CMD ["sh", "-c", "mkdir -p var && vendor/bin/phpunit --log-junit var/phpunit.junit.xml"]
