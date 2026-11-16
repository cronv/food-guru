#!/bin/sh
# Развёртывание на чистом VPS (reg.ru и любой другой): Alpine, Ubuntu или Debian.
# Ставит Docker и compose, при нехватке памяти добавляет своп, заполняет .env и
# api/.env случайными паролями, поднимает docker-compose.prod.yml и HTTPS через Caddy.
#
# Повторный запуск безопасен: уже заданные пароли и APP_SECRET не трогаются,
# поэтому этот же скрипт служит для обновления.
#
#   sh deploy.sh                                     # установка или обновление
#   sh deploy.sh --admin вы@почта.ru "Имя Фамилия"   # плюс аккаунт администратора
#   sh deploy.sh --no-https                          # без Caddy, только порт 80
#
# Адреса сайта, админки и API, почта и платёжная система задаются в api/.env до
# первого запуска (шаблон api/.env.production.example).

set -eu

# Сборке Angular нужно 4 ГБ: столько должно набираться вместе со свопом.
BUILD_MEMORY_MB=4096

HTTPS=1
ADMIN_EMAIL=
ADMIN_NAME=

while [ $# -gt 0 ]; do
  case "$1" in
    --seed) echo "--seed: загрузите демо командой docker compose exec api php bin/console food-guru:seed-demo --force" >&2; exit 1 ;;
    --no-https) HTTPS=0 ;;
    --admin)
      [ $# -ge 3 ] || { echo "Использование: --admin email \"Имя Фамилия\"" >&2; exit 1; }
      ADMIN_EMAIL=$2
      ADMIN_NAME=$3
      shift 2
      ;;
    *) echo "Неизвестный параметр: $1" >&2; exit 1 ;;
  esac
  shift
done

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!! %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31mОшибка: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "запустите от root: sudo sh $0"

cd "$(dirname "$0")"
[ -f docker-compose.prod.yml ] || die "не найден docker-compose.prod.yml: запускайте из корня проекта"

if [ -f /etc/alpine-release ]; then
  OS=alpine
elif [ -f /etc/os-release ] && grep -Eq '^ID(_LIKE)?=.*(debian|ubuntu)' /etc/os-release; then
  OS=debian
else
  die "поддерживаются Alpine, Ubuntu и Debian; на другой системе ставьте по DEPLOY.md вручную"
fi

# Значение переменной из env-файла без кавычек.
get_env() {
  sed -n "s/^$2=//p" "$1" | tail -n 1 | sed 's/^"\(.*\)"$/\1/'
}

# Задать переменную в env-файле: заменить строку или дописать в конец.
set_env() {
  if grep -q "^$2=" "$1"; then
    sed -i "s|^$2=.*|$2=$3|" "$1"
  else
    printf '%s=%s\n' "$2" "$3" >> "$1"
  fi
}

# Задать переменную, только если она пустая.
fill_env() {
  [ -n "$(get_env "$1" "$2")" ] || set_env "$1" "$2" "$3"
}

host_of() { sed -E 's#^https?://##; s#/.*##'; }

compose() {
  if [ "$HTTPS" -eq 1 ]; then
    docker compose -f docker-compose.prod.yml -f docker-compose.https.yml "$@"
  else
    docker compose -f docker-compose.prod.yml "$@"
  fi
}

# Есть ли таблица в базе приложения (имя — аргумент).
db_table_exists() {
  compose exec -T mysql sh -c "mysql -N -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"\$MYSQL_DATABASE\" -e \"SHOW TABLES LIKE '$1'\"" 2>/dev/null | grep -qx "$1"
}

# ---------------------------------------------------------------- пакеты

if [ "$OS" = alpine ]; then
  log "Alpine $(cat /etc/alpine-release): подключаем репозиторий community"
  # Docker лежит в community, а на минимальной установке он часто закомментирован.
  if ! grep -Eq '^[^#].*/community/?$' /etc/apk/repositories; then
    release=$(cut -d. -f1,2 /etc/alpine-release)
    case "$release" in
      *_alpha*|*edge*) branch=edge ;;
      *) branch="v$release" ;;
    esac
    mirror=$(grep -Eo '^https?://[^ ]+/alpine' /etc/apk/repositories | head -n 1)
    echo "${mirror:-https://dl-cdn.alpinelinux.org/alpine}/$branch/community" >> /etc/apk/repositories
  fi

  log "Ставим Docker, compose и утилиты"
  apk update
  apk upgrade
  apk add docker docker-cli-compose openssl curl dcron

  log "Запускаем Docker и включаем автозапуск"
  rc-update add cgroups boot >/dev/null 2>&1 || true
  rc-update add docker default
  rc-update add dcron default >/dev/null 2>&1 || true
  rc-service cgroups start >/dev/null 2>&1 || true
  rc-service docker start || true
  rc-service dcron start >/dev/null 2>&1 || true
else
  log "$(. /etc/os-release && echo "$PRETTY_NAME"): ставим Docker и compose"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q
  apt-get install -yq curl openssl ca-certificates cron
  if ! command -v docker >/dev/null 2>&1; then
    curl -fsSL https://get.docker.com | sh
  fi
  systemctl enable --now docker
  systemctl enable --now cron >/dev/null 2>&1 || true
fi

i=0
until docker info >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -le 30 ] || die "Docker не запустился за 30 секунд"
  sleep 1
done
docker compose version

# ---------------------------------------------------------------- своп

mem_mb=$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)
swap_mb=$(awk '/^SwapTotal:/ {print int($2 / 1024)}' /proc/meminfo)
need_mb=$((BUILD_MEMORY_MB - mem_mb - swap_mb))

if [ "$need_mb" -gt 0 ] && [ ! -f /swapfile ]; then
  # Не меньше 1 ГБ, чтобы был запас поверх расчёта.
  [ "$need_mb" -ge 1024 ] || need_mb=1024
  log "Памяти ${mem_mb} МБ + своп ${swap_mb} МБ — мало для сборки, добавляем своп ${need_mb} МБ"
  dd if=/dev/zero of=/swapfile bs=1M count="$need_mb" 2>/dev/null
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  [ "$OS" != alpine ] || rc-update add swap boot >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------- настройки

log "Готовим .env и api/.env"

[ -f .env ] || cp .env.example .env
fill_env .env DB_PASSWORD "$(openssl rand -hex 24)"
fill_env .env DB_ROOT_PASSWORD "$(openssl rand -hex 24)"
fill_env .env SSR_API_KEY "$(openssl rand -hex 32)"

[ -f api/.env ] || cp api/.env.production.example api/.env

# База и секрет SSR обязаны совпадать с .env рядом с compose.
db_name=$(get_env .env DB_DATABASE)
db_user=$(get_env .env DB_USERNAME)
[ -z "$db_name" ] && db_name=food_guru
[ -z "$db_user" ] && db_user=food_guru
set_env api/.env DATABASE_URL "mysql://${db_user}:$(get_env .env DB_PASSWORD)@mysql:3306/${db_name}?serverVersion=8.4.0&charset=utf8mb4"
set_env api/.env SSR_API_KEY "$(get_env .env SSR_API_KEY)"
fill_env api/.env APP_SECRET "$(openssl rand -hex 32)"
chmod 600 .env api/.env

site_url=$(get_env api/.env SITE_URL)
admin_url=$(get_env api/.env ADMIN_URL)
api_url=$(get_env api/.env DEFAULT_URI)
[ -n "$site_url" ] && [ -n "$admin_url" ] && [ -n "$api_url" ] || die "в api/.env заполните SITE_URL, ADMIN_URL и DEFAULT_URI"

site_host=$(echo "$site_url" | host_of)
admin_host=$(echo "$admin_url" | host_of)
api_host=$(echo "$api_url" | host_of)
root_domain=$(echo "$site_host" | sed 's/^www\.//')

# Адреса, которые производны от доменов: пишем всегда, чтобы не разъехались.
set_env api/.env PUBLIC_STORAGE_URL "${site_url}/storage"
set_env api/.env STATEFUL_DOMAINS "${root_domain},www.${root_domain},${admin_host}"
# Точка в начале: cookie XSRF-TOKEN видят и сайт, и админка.
set_env api/.env SESSION_DOMAIN ".${root_domain}"

# Caddy выпускает сертификаты на домен сайта и его поддомены.
set_env .env SITE_DOMAIN "$site_host"
if [ -n "$ADMIN_EMAIL" ]; then
  set_env .env ACME_EMAIL "$ADMIN_EMAIL"
else
  fill_env .env ACME_EMAIL "$(get_env api/.env MAIL_FROM_ADDRESS | sed -E 's/.*<([^>]+)>.*/\1/')"
fi

mailer=$(get_env api/.env MAILER_DSN)
if [ -z "$mailer" ] || [ "$mailer" = "null://null" ]; then
  warn "В api/.env не настроена почта (MAILER_DSN). Сайт запустится, но письма подтверждения и входа не уйдут."
fi

# ---------------------------------------------------------------- DNS

if [ "$HTTPS" -eq 1 ]; then
  log "Проверяем, что домены смотрят на этот сервер"
  # Let's Encrypt выдаёт сертификат, только если домен уже указывает сюда.
  # Публичный адрес VPS висит на интерфейсе, поэтому сверяем с локальными адресами.
  my_ips=$(ip -4 -o addr show 2>/dev/null | awk '{split($4, a, "/"); print a[1]}')
  dns_ok=1
  for h in "$site_host" "www.$site_host" "$api_host" "$admin_host"; do
    ip=$(getent ahostsv4 "$h" 2>/dev/null | awk 'NR == 1 {print $1}')
    [ -n "$ip" ] || ip=$(getent hosts "$h" 2>/dev/null | awk 'NR == 1 {print $1}')
    if [ -z "$ip" ]; then
      warn "$h: A-запись не найдена"
      dns_ok=0
    elif ! echo "$my_ips" | grep -qx "$ip"; then
      warn "$h указывает на $ip, а у этого сервера: $(echo $my_ips)"
      dns_ok=0
    else
      echo "$h → $ip"
    fi
  done
  if [ "$dns_ok" -eq 0 ]; then
    warn "Пока DNS не обновится, сертификаты не выпустятся: Caddy будет повторять попытки сам."
    warn "Если сервер за NAT и адрес выше чужой — это нормально, проверьте A-записи в панели reg.ru."
  fi
fi

# ---------------------------------------------------------------- загрузки

log "Каталог загрузок storage/"
mkdir -p storage
# Загрузки прежней версии лежали в томе storage-app. Переносим один раз, пока каталог пуст.
if [ -z "$(ls -A storage)" ]; then
  for vol in $(docker volume ls -q | grep '_storage-app$' || true); do
    echo "Переносим загрузки из тома $vol"
    docker run --rm -v "$vol":/from:ro -v "$(pwd)/storage":/to alpine cp -a /from/. /to/
  done
fi
# php-fpm в контейнере работает от www-data (uid 82 в образе Alpine) и пишет сюда.
chown -R 82:82 storage

# ---------------------------------------------------------------- запуск

log "Собираем и запускаем контейнеры (первый раз 5–10 минут)"
compose up -d --build --remove-orphans

log "Ждём, пока поднимется API (Symfony)"
i=0
until compose exec -T api php -v >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -le 60 ] || die "контейнер api не отвечает, смотрите: docker compose -f docker-compose.prod.yml logs api"
  sleep 2
done

log "Схема базы и прогрев кэша"
# Существующая база уже содержит таблицы, но без записей о миграциях Doctrine.
# Doctrine. Базовая миграция описывает именно эту схему: на такой базе её только отмечаем выполненной.
if db_table_exists users && ! db_table_exists doctrine_migration_versions; then
  compose exec -T api php bin/console doctrine:migrations:version 'DoctrineMigrations\Version20261110130831' --add --no-interaction
fi
compose exec -T api php bin/console doctrine:migrations:migrate --no-interaction --env=prod
compose exec -T api php bin/console cache:clear --env=prod --no-debug >/dev/null 2>&1 || true
compose exec -T api php bin/console cache:warmup --env=prod --no-debug >/dev/null 2>&1 || true

if [ -n "$ADMIN_EMAIL" ]; then
  log "Аккаунт администратора $ADMIN_EMAIL"
  compose exec -T api php bin/console food-guru:admin "$ADMIN_EMAIL" "$ADMIN_NAME"
fi

log "Очистка старых токенов: раз в сутки по cron"
https_flag=
if [ "$HTTPS" -eq 1 ]; then https_flag=' -f docker-compose.https.yml'; fi
cron_cmd="cd $(pwd) && docker compose -f docker-compose.prod.yml${https_flag} exec -T api php bin/console food-guru:prune-email-tokens >/dev/null 2>&1"
if command -v crontab >/dev/null 2>&1; then
  ( crontab -l 2>/dev/null | grep -v 'food-guru:prune-email-tokens' ; echo "17 4 * * * $cron_cmd" ) | crontab -
else
  warn "crontab не найден: добавьте задачу вручную: 17 4 * * * $cron_cmd"
fi

# Старые слои после пересборки занимают гигабайты.
docker image prune -f >/dev/null

# ---------------------------------------------------------------- проверка

log "Проверка"

# Код ответа, запрос идёт на этот же сервер, но с нужным доменом.
probe() {
  if [ "$HTTPS" -eq 1 ]; then
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$1:443:127.0.0.1" "https://$1$2" || true
  else
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $1" "http://127.0.0.1$2" || true
  fi
}

# Первый выпуск сертификатов занимает до минуты.
i=0
while :; do
  api_code=$(probe "$api_host" /up)
  site_code=$(probe "$site_host" /)
  admin_code=$(probe "$admin_host" /)
  [ "$api_code$site_code$admin_code" != 200200200 ] || break
  i=$((i + 1))
  [ "$i" -le 12 ] || break
  sleep 5
done

compose ps
echo
echo "API     $api_host/up: $api_code"
echo "Сайт    $site_host/:  $site_code"
echo "Админка $admin_host/: $admin_code"

if [ "$api_code$site_code$admin_code" = 200200200 ]; then
  log "Готово"
else
  warn "Что-то не отвечает. Логи: docker compose -f docker-compose.prod.yml logs nginx api ssr --tail=50"
  [ "$HTTPS" -eq 0 ] || warn "Сертификаты: docker compose -f docker-compose.prod.yml -f docker-compose.https.yml logs caddy --tail=50"
fi
