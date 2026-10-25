# WP-CLI image plus the PHP FTP extension (with FTPS support), matching the
# web container, so `wp eikvakt backup` can upload to the FTP test server.
FROM wordpress:cli-php8.2

USER root
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS openssl-dev \
	&& docker-php-ext-configure ftp --with-openssl-dir=/usr \
	&& docker-php-ext-install ftp \
	&& apk del .build-deps \
	&& apk add --no-cache openssl
USER www-data
