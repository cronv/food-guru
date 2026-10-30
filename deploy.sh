#!/bin/sh
# Развёртывание на чистом VPS (reg.ru и любой другой): Alpine, Ubuntu или Debian.
# Ставит Docker и compose, при нехватке памяти добавляет своп, заполняет .env
# случайными паролями, поднимает docker-compose.prod.yml и HTTPS через Caddy.
#
# Повторный запуск безопасен: уже заданные пароли и APP_KEY не трогаются,
# поэтому этот же скрипт служит для обновления.
#
#   sh deploy.sh                                     # установка или обновление
#   sh deploy.sh --seed                              # плюс демо-данные (один раз)
#   sh deploy.sh --admin вы@почта.ru "Имя Фамилия"   # плюс аккаунт администратора
#   sh deploy.sh --no-https                          # без Caddy, только порт 80

set -eu

# Сборке Angular нужно 4 ГБ: столько должно набираться вместе со свопом.
BUILD_MEMORY_MB=4096

SEED=0
HTTPS=1
ADMIN_EMAIL=
ADMIN_NAME=

while [ $# -gt 0 ]; do
  case "$1" in
    --seed) SEED=1 ;;
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
  apk add docker docker-cli-compose openssl curl

  log "Запускаем Docker и включаем автозапуск"
  rc-update add cgroups boot >/dev/null 2>&1 || true
  rc-update add docker default
  rc-service cgroups start >/dev/null 2>&1 || true
  rc-service docker start || true
else
  log "$(. /etc/os-release && echo "$PRETTY_NAME"): ставим Docker и compose"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q
  apt-get install -yq curl openssl ca-certificates
  if ! command -v docker >/dev/null 2>&1; then
    curl -fsSL https://get.docker.com | sh
  fi
  systemctl enable --now docker
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

log "Готовим .env и backend/.env"

[ -f .env ] || cp .env.example .env
fill_env .env DB_PASSWORD "$(openssl rand -hex 24)"
fill_env .env DB_ROOT_PASSWORD "$(openssl rand -hex 24)"
fill_env .env SSR_API_KEY "$(openssl rand -hex 32)"

[ -f backend/.env ] || cp backend/.env.production.example backend/.env
# Доступы к базе и секрет SSR обязаны совпадать в обоих файлах — берём из .env.
for key in DB_DATABASE DB_USERNAME DB_PASSWORD SSR_API_KEY; do
  value=$(get_env .env "$key")
  [ -z "$value" ] || set_env backend/.env "$key" "$value"
done
# Ключ генерируем здесь: artisan key:generate внутри контейнера писал бы в .env,
# которого в образе нет, и ключ терялся бы при пересоздании контейнера.
fill_env backend/.env APP_KEY "base64:$(openssl rand -base64 32)"

site_host=$(get_env backend/.env SITE_URL | host_of)
api_host=$(get_env backend/.env APP_URL | host_of)
admin_host=$(get_env backend/.env ADMIN_URL | host_of)

# Caddy выпускает сертификаты на домен сайта и его поддомены.
set_env .env SITE_DOMAIN "$site_host"
if [ -n "$ADMIN_EMAIL" ]; then
  set_env .env ACME_EMAIL "$ADMIN_EMAIL"
else
  fill_env .env ACME_EMAIL "$(get_env backend/.env MAIL_FROM_ADDRESS)"
fi

chmod 600 .env backend/.env

if [ "$(get_env backend/.env MAIL_HOST)" = "smtp.example-provider.com" ]; then
  warn "В backend/.env не настроена почта (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD)."
  warn "Сайт запустится, но письма подтверждения и входа не уйдут. Пропишите SMTP и запустите скрипт снова."
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

# ---------------------------------------------------------------- запуск

log "Собираем и запускаем контейнеры (первый раз 5–10 минут)"
compose up -d --build --remove-orphans

log "Ждём, пока поднимется PHP"
i=0
until compose exec -T php php artisan --version >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -le 60 ] || die "контейнер php не отвечает, смотрите: docker compose -f docker-compose.prod.yml logs php"
  sleep 2
done

log "Миграции и ссылка на загруженные файлы"
compose exec -T php php artisan migrate --force
compose exec -T php php artisan storage:link >/dev/null 2>&1 || true
compose exec -T php php artisan config:cache

if [ "$SEED" -eq 1 ]; then
  log "Демо-данные"
  compose exec -T php php artisan db:seed --force
fi

if [ -n "$ADMIN_EMAIL" ]; then
  log "Аккаунт администратора $ADMIN_EMAIL"
  compose exec -T php php artisan food-guru:admin "$ADMIN_EMAIL" "$ADMIN_NAME"
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
  warn "Что-то не отвечает. Логи: docker compose -f docker-compose.prod.yml logs php ssr nginx --tail=50"
  [ "$HTTPS" -eq 0 ] || warn "Сертификаты: docker compose -f docker-compose.prod.yml -f docker-compose.https.yml logs caddy --tail=50"
fi
