FROM php:8.3-fpm-alpine

# poppler-utils: pdftoppm рисует обложку выпуска журнала из первой
# страницы PDF, pdfinfo считает страницы. Без них выпуск просто
# останется без обложки — загрузка файла не ломается.
RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev poppler-utils \
    && docker-php-ext-install pdo_mysql intl mbstring zip bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# Пределы загрузки те же, что в боевом образе: видеоработа на конкурс весит до 100 МБ.
# По умолчанию PHP режет на 2 МБ и делает это молча — файл приходит обрезанным.
RUN { \
      echo 'upload_max_filesize=100M'; \
      echo 'post_max_size=110M'; \
      echo 'max_execution_time=300'; \
      echo 'max_input_time=300'; \
    } > /usr/local/etc/php/conf.d/99-uploads.ini
WORKDIR /var/www/html

CMD ["php-fpm"]
