# Публикация на хостинге

Проект состоит из трёх приложений:

| Приложение | Что это | Где живёт |
| --- | --- | --- |
| `backend/` | REST API на Laravel 13 | `api.ваш-домен.ru` |
| `frontend/` → `site` | Публичный сайт, Angular с SSR | `ваш-домен.ru` |
| `frontend/` → `admin` | Админ-панель, Angular SPA | `admin.ваш-домен.ru` |

Фронтенды собираются из одного воркспейса: `npm run build` делает оба.

Домены уже прописаны: сайт `food-guru.ru`, API `api.food-guru.ru`, админка
`admin.food-guru.ru` — в `backend/.env.production.example`, `docker/nginx/*.prod.conf`
и обоих `environment.prod.ts`. Если домен поменяется — замените `food-guru.ru` во всех
этих файлах **до** сборки фронтенда.

Для сборки фронтенда нужен Node.js 20.19+ или 22.12+ (Angular 21).

---

## 1. VPS с Docker — рекомендуемый путь

Нужен сервер с root-доступом. Память: 2 ГБ для работы, но **на время сборки Angular
нужно 4 ГБ** — иначе сборка падает по нехватке памяти. Если на сервере 2 ГБ, добавьте
своп или соберите фронтенд на своей машине (вариант 2 ниже).

### Автоматически: deploy.sh

[deploy.sh](deploy.sh) делает всё, что описано ниже, одной командой на Alpine, Ubuntu
или Debian. Он ставит Docker (на Alpine — из репозитория community, где `get.docker.com`
не работает) и при нехватке памяти добавляет своп. Пароли базы, `SSR_API_KEY` и
`APP_KEY` генерирует сам и прописывает в оба `.env`. Затем собирает контейнеры,
выполняет миграции и поднимает HTTPS через Caddy (`docker-compose.https.yml`).
Сертификаты Let's Encrypt Caddy получает и продлевает сам.

```sh
sh deploy.sh --seed --admin вы@почта.ru "Имя Фамилия"
```

Повторный запуск уже заданные пароли не меняет — он же служит для обновления.
Пошагово для reg.ru, с DNS, почтой и загрузкой архива: [DEPLOY-REGRU.md](DEPLOY-REGRU.md).
Ручные шаги ниже нужны, только если скрипт не подходит.

### Подготовка

```bash
curl -fsSL https://get.docker.com | sh
docker compose version
```

Направьте на IP сервера A-записи: `food-guru.ru`, `www.food-guru.ru`, `api.food-guru.ru`,
`admin.food-guru.ru`.

### Развёртывание

```bash
cd /srv/food-guru

# 1. Пароли базы и секрет SSR для compose
cp .env.example .env
nano .env                      # DB_PASSWORD, DB_ROOT_PASSWORD и SSR_API_KEY
                               # SSR_API_KEY: вставьте вывод `openssl rand -hex 32`

# 2. Настройки приложения
cp backend/.env.production.example backend/.env
nano backend/.env              # тот же DB_PASSWORD, доступы к SMTP; домены уже стоят

# 3. Сборка и запуск (первый раз занимает 5–10 минут)
docker compose -f docker-compose.prod.yml up -d --build

# 4. Ключ, таблицы, доступ к загруженным файлам
docker compose -f docker-compose.prod.yml exec php php artisan key:generate
docker compose -f docker-compose.prod.yml exec php php artisan migrate --force
docker compose -f docker-compose.prod.yml exec php php artisan storage:link

# 5. Демо-данные — рецепты, книги, товары, конкурсы для наполнения витрины.
#    Демо-аккаунты без паролей и на несуществующих адресах @food-guru.local:
#    войти под ними с боевого сайта нельзя.
docker compose -f docker-compose.prod.yml exec php php artisan db:seed --force

# 6. Ваш аккаунт администратора — на настоящую почту.
#    Затем: admin.food-guru.ru → «По ссылке» → письмо → задайте пароль.
docker compose -f docker-compose.prod.yml exec php php artisan food-guru:admin вы@почта.ru "Имя Фамилия"
```

`SSR_API_KEY` обязателен. Сервер рендера ходит в API за всех посетителей с одного
адреса; с ключом API считает лимит запросов по каждому посетителю, без него — один
на всех, и уже при небольшой нагрузке страницы начнут отдавать ошибку 503.
Compose без ключа не запустится.

Оплаты пока нет: `PAYMENT_PROVIDER` не задан, и страница кошелька показывает
«Оплата на сайте скоро появится». Бесплатные книги, рецепты, конкурсы, журнал и
академия работают. Платёжный шлюз подключается реализацией
`App\Services\Commerce\PaymentGateway`.

Проверка: `curl http://ваш-домен.ru` отдаёт готовый HTML с текстом рецептов (это и есть
SSR — если видите пустой `<app-root></app-root>`, рендер не работает),
`curl http://api.ваш-домен.ru/up` отвечает 200.

### HTTPS

Запускать без шифрования нельзя: в `backend/.env` стоит `SESSION_SECURE_COOKIE=true`,
и по HTTP вход не заработает вообще.

Проще всего — готовый `docker-compose.https.yml`: Caddy перед nginx сам получает
сертификаты. Добавьте в `.env` рядом с compose `SITE_DOMAIN=food-guru.ru` и
`ACME_EMAIL=вы@почта.ru` и запускайте с двумя файлами:
`docker compose -f docker-compose.prod.yml -f docker-compose.https.yml up -d`.
Вариант с certbot:

```bash
apt install certbot
certbot certonly --standalone -d ваш-домен.ru -d api.ваш-домен.ru -d admin.ваш-домен.ru
```

Дальше добавьте в `docker/nginx/*.prod.conf` блоки `listen 443 ssl` и пробросьте
`/etc/letsencrypt` в контейнер nginx. Либо замените nginx на
[Caddy](https://caddyserver.com) — он получает сертификаты сам.

### Обновление

```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec php php artisan migrate --force
docker compose -f docker-compose.prod.yml exec php php artisan config:cache
```

---

## 2. Сборка фронтенда на своей машине

Если на сервере мало памяти или нет Docker. Собираем локально, на сервер кладём
готовые файлы.

```bash
cd frontend
npm install

# Домены перед сборкой
nano projects/site/src/environments/environment.prod.ts
nano projects/admin/src/environments/environment.prod.ts

npm run build
```

Получится:

| Папка | Куда класть |
| --- | --- |
| `dist/site/browser/` | статика публичного сайта |
| `dist/site/server/` | SSR-сервер: `SSR_API_KEY=… SITE_URL=https://food-guru.ru node server.mjs` (тот же `SSR_API_KEY` — в `.env` API) |
| `dist/admin/browser/` | корень поддомена админки |

**Если SSR разворачивать негде** (обычный хостинг без Node), положите
`dist/site/browser/` как обычную статику и добавьте правило «любой путь → index.html».
Сайт будет работать, но поисковики и Telegram увидят пустую страницу: превью ссылок
и выдача пропадут. Для кулинарного проекта, который живёт на репостах, это ощутимая потеря.

---

## 3. Обычный хостинг с PHP

Для backend. Нужны PHP 8.3+ с `pdo_mysql`, `mbstring`, `intl`, `zip`, `bcmath`,
MySQL 8 и возможность задать корень сайта.

1. Загрузите `backend/` вне публичной папки, например `~/food-guru-api`.
2. Заведите поддомен `api.example.com` с корнем `~/food-guru-api/public`.
   Обязательно: если корнем сделать саму `backend`, наружу окажутся доступны `.env` и код.
3. Через SSH:

   ```bash
   cd ~/food-guru-api
   composer install --no-dev --optimize-autoloader
   cp .env.production.example .env
   nano .env
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   ```

4. Очередь. Демона держать негде, поэтому cron раз в минуту:

   ```
   * * * * * cd ~/food-guru-api && php artisan queue:work --stop-when-empty --max-time=55
   * * * * * cd ~/food-guru-api && php artisan schedule:run
   ```

   Без первой строки письма подтверждения не отправятся вообще.

5. `storage` и `bootstrap/cache` должны быть доступны для записи веб-серверу.

Фронтенды кладутся как статика из варианта 2.

---

## Чек-лист перед показом людям

- [ ] `APP_DEBUG=false` — иначе при ошибке посетитель увидит код и настройки
- [ ] `APP_KEY` сгенерирован
- [ ] `APP_ENV=production`; `PAYMENT_PROVIDER` пуст (запуск без оплаты) или настоящий
      шлюз — но не `test`: тестовая оплата в production отключена
- [ ] `SSR_API_KEY` задан, а в логе SSR нет строки «SSR_API_KEY не задан»:
      `docker compose -f docker-compose.prod.yml logs ssr | head`
- [ ] Администратор создан командой `food-guru:admin` и вошёл по ссылке из письма
- [ ] MySQL и MailHog наружу не торчат: в `docker-compose.yml` их порты привязаны
      к `127.0.0.1`, проверьте `ss -tlnp` на сервере
- [ ] `pint --test`, `phpstan analyse` и `npm run lint` проходят без ошибок (см. README → Тесты)
- [ ] HTTPS работает, по HTTP сайт не отдаётся
- [ ] Домены подставлены в `environment.prod.ts` **до** сборки фронтенда
- [ ] Превью ссылки проверено: отправьте ссылку на рецепт себе в Telegram
- [ ] Письмо подтверждения доходит и не падает в спам (проверьте на Gmail и Яндексе)
- [ ] `/up` отвечает 200, миграции выполнены
- [ ] Очередь запущена либо заведён cron
- [ ] Демо-контент постепенно заменяется живым. Демо-аккаунты безопасны (без паролей,
      почта `@food-guru.local` не существует), но рецепты от их имени лучше переназначить
      или снять, когда появятся свои
- [ ] В админку заходит только сотрудник: проверьте, что обычный пользователь получает отказ
- [ ] Настроено резервное копирование базы и всей папки `storage/app` — в ней лежат
      и картинки, и PDF журнала; из базы они не восстановятся. В compose это том
      `storage-app`, бэкап снимайте с него, а не с папки в репозитории
- [ ] Миграции конкурсов, академии и рейтинга выполнены: проверьте, что в базе есть
      таблицы `contests`, `academy_sessions`, `rating_events` и колонка `users.rating_points`
- [ ] Открыт конкурс в чужом браузере без входа: форма отправки работы и кнопки
      голосования не показываются, а страница при этом читается
- [ ] Открыт эфир академии без входа: ссылок `stream_url` и `record_url` нет
      в ответе API — проверьте через «Просмотр кода», а не глазами по странице
- [ ] Загрузка видео на конкурс проходит целиком: в образах предел уже 100 МБ,
      но внешний прокси или балансировщик может резать раньше
- [ ] Скачивание гайда из журнала отдаёт PDF, а не HTML-страницу ошибки,
      и счётчик скачиваний растёт
- [ ] Обложка выпуска нарисовалась сама: `docker compose exec php which pdftoppm`
      должен вернуть путь. Если пусто — обложки придётся загружать руками

## SEO: чтобы сайт индексировался

Что уже сделано в коде: серверный рендер (поисковик получает готовый HTML), title,
description, Open Graph, `canonical` без параметров, `robots` с `noindex` для кабинета,
корзины, кошелька и одноразовых ссылок, код **404** для несуществующих страниц,
микроразметка schema.org (Recipe с питательностью, шагами, рейтингом и видео; Product
с ценой и наличием; Book; BreadcrumbList; ItemList у категорий и подборок; WebSite
с поиском и Organization на главной), `robots.txt` и `sitemap.xml`.

Для SSR-сервера сайта задайте переменные окружения:

| Переменная | Пример | Зачем |
| --- | --- | --- |
| `SITE_URL` | `https://food-guru.ru` | домен в `robots.txt` и `sitemap.xml` |
| `API_INTERNAL_URL` | `http://nginx` | откуда SSR берёт список адресов для карты; если не задан — публичный адрес API |

После запуска:

- [ ] `https://ваш-домен/robots.txt` открывается, в последней строке — ваш домен
- [ ] `https://ваш-домен/sitemap.xml` открывается, адреса — на вашем домене
- [ ] Несуществующий адрес отвечает 404: `curl -I https://ваш-домен/recipes/net-takogo`
- [ ] Сайт добавлен в **Яндекс.Вебмастер** и **Google Search Console**, подтверждён,
      в обоих указан `sitemap.xml`
- [ ] Страница рецепта проверена валидаторами разметки: Яндекс.Вебмастер →
      «Валидатор микроразметки» и Google «Проверка расширенных результатов» —
      рецепт должен определяться без ошибок
- [ ] В Вебмастере выбран регион и основное зеркало (с `www` или без) — второе
      должно отдавать 301 на основное

## Если что-то не работает

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs php --tail=50
docker compose -f docker-compose.prod.yml logs ssr --tail=50
docker compose -f docker-compose.prod.yml logs queue --tail=50
```

**Вход не работает, в ответе 419.** Проверьте `SANCTUM_STATEFUL_DOMAINS` и
`SESSION_DOMAIN` в `backend/.env`: домен сайта должен быть в списке, а `SESSION_DOMAIN`
начинаться с точки (`.example.com`), чтобы cookie работала на поддоменах.

**Письма не приходят.** Смотрите логи `queue`. Чаще всего дело в доступах SMTP или в
том, что контейнер очереди не запущен — тогда письма копятся в таблице `jobs`.

**Видео на конкурс не загружается, ответ 413.** Работа формата «видео» весит до 100 МБ.
В образах проекта пределы уже подняты: `client_max_body_size 110m` в
`docker/nginx/api.prod.conf` и `upload_max_filesize=100M` / `post_max_size=110M`
в `docker/php/Dockerfile.prod`. Ошибка 413 означает, что запрос идёт через чужой
прокси — поднимите предел и там. Если `post_max_size` меньше размера файла,
PHP обрежет его молча, без ошибки: запрос дойдёт, а файла в нём не будет.

**Гайд из журнала не скачивается, 404.** Файл лежит на закрытом диске внутри
`storage/app` и отдаётся через PHP. Проверьте три вещи: выпуск опубликован (снятый
с публикации недоступен намеренно), том `storage-app` подключён, файл на месте:

```bash
docker compose -f docker-compose.prod.yml exec php \
  php artisan tinker --execute="dd(App\Models\JournalIssue::first()->file_path);"
docker compose -f docker-compose.prod.yml exec php ls -la storage/app
```

**Обложка выпуска не появляется.** Её рисует `pdftoppm` из poppler-utils.
В образах проекта пакет стоит; на своей сборке проверьте
`docker compose -f docker-compose.prod.yml exec php which pdftoppm`.
Без него выпуск публикуется и скачивается как обычно — просто без обложки
и без числа страниц, обложку можно загрузить картинкой.

**Баллы не начисляются.** Сначала проверьте, подтверждён ли адрес: middleware
`track.visit` пропускает неподтверждённых. Дальше смотрите таблицу `rating_events` —
если записи есть, а `users.rating_points` отстаёт, счётчик и журнал разошлись:

```bash
docker compose -f docker-compose.prod.yml exec php php artisan tinker
>>> app(App\Services\RatingService::class)->recalculate(App\Models\User::find(1));
```

**Конкурс не публикуется.** Публикация требует заполненной даты начала приёма работ.
Занятие академии — даты эфира для вебинара и стрима, ссылки на запись для видеоурока.
Сервер возвращает 422 с точным текстом, он же показывается в редакторе.

**Страница пустая, в консоли ошибки CORS.** Адрес API в `environment.prod.ts` не
совпадает с реальным, либо фронтенд собран до того, как его поменяли. Ещё одна причина —
`SITE_URL` и `ADMIN_URL` в `backend/.env`: API разрешает запросы только с этих двух
адресов (`config/cors.php`), и они должны совпадать до буквы, включая `https://` и `www`.

**Сборка падает с «JavaScript heap out of memory».** Не хватает памяти на сборку
Angular: добавьте своп или соберите фронтенд локально по варианту 2.

### Ошибки серверного рендера

Прежде чем разбираться с трейсом, проверьте три вещи — они дают ложные следы чаще,
чем настоящие ошибки.

**Старый процесс всё ещё работает.** Если `npm run serve:ssr` упал с
`EADDRINUSE: address already in use :::4000`, значит сервер с прошлого запуска жив и
отвечает старым кодом. Трейсы при этом не меняются, сколько ни пересобирай:

```powershell
Get-NetTCPConnection -LocalPort 4000 -State Listen -ErrorAction SilentlyContinue |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
```

**Команда запущена не из той папки.** `node_modules`, `angular.json` и `dist` лежат в
`frontend`, а не в корне репозитория. Из корня `npx ng build` выдаст
«could not determine executable to run», а `Remove-Item frontend\dist` молча ничего
не удалит, если вы уже внутри `frontend`.

**Сборка не обновилась.** Имя чанка в `dist/site/server/` содержит хеш содержимого.
Если после пересборки трейс указывает на тот же файл `chunk-XXXX.mjs` — запускается
старый бандл. Сверяйте имя через `dir dist\site\server`.

**NG0210 при `ng serve`.** В стеке виден путь `.angular/cache/…/vite/deps_ssr/` —
дело в предварительной сборке зависимостей dev-сервером: Vite собирает вторую копию
Angular, и токен `DOCUMENT` перестаёт совпадать.

В `angular.json` у обоих приложений уже стоит `"prebundle": false` в секции `serve`.
Если ошибка всё равно появилась, очистите кэш — он мог остаться от прошлого запуска:

```powershell
# Windows, PowerShell
Remove-Item -Recurse -Force frontend\.angular -ErrorAction SilentlyContinue
```

```bat
rem Windows, cmd.exe
rmdir /s /q frontend\.angular
```

```bash
# macOS и Linux
rm -rf frontend/.angular
```

Это касается только dev-сервера. Боевая сборка идёт через esbuild без Vite, поэтому на
развёртывание не влияет — проверить можно так:

```bash
cd frontend
npm run build:site
npm run serve:ssr        # откройте http://localhost:4000
```

Если страница открывается и в исходном коде виден текст рецептов — SSR работает, и
проблема только в режиме разработки.

**NG0210 в `_ImagePerformanceWarning.start`.** Это проверка размеров картинок, которая
работает только в dev-сборке. Она пропускает себя, если в окружении нет
`PerformanceObserver`, но Node 18+ объявляет его глобально — проверка выполняется и
падает уже после рендера. В `projects/site/server.ts` этот глобальный объект
обнуляется при старте, так что ошибки быть не должно. В боевой сборке код проверки
вырезается вместе с `ngDevMode`.

**Как узнать имя провайдера из минифицированного трейса.** В боевой сборке имена
сжаты до `cw`, `Hh` и подобных. Соберите с картами исходников и запустите Node с их
поддержкой — в стеке появятся настоящие пути и имена функций:

```powershell
npm run build:site -- --source-map
node --enable-source-maps dist\site\server\server.mjs
```

**Сайт отдаёт страницу, но в логе «SSR не отрендерил …».** Серверный рендер упал, и
сработала страховка: отдана клиентская версия. Для посетителя сайт рабочий, но
поисковики и Telegram получат пустую разметку вместо контента. Ошибку из лога нужно
разобрать — именно ради превью ссылок SSR и ставился.

**«document is not defined» при каждом рендере.** Проверьте `projects/site/src/main.server.ts`:
функция `bootstrap` обязана принять `context` и передать его третьим аргументом
в `bootstrapApplication`. С Angular 18.2.14 серверная платформа доступна только через
этот аргумент; без него Angular молча поднимает браузерную и падает на первом же
обращении к `DOCUMENT`. Раньше именно из-за этого были выключены гидратация
и `withInMemoryScrolling` — сейчас оба включены.

**Вошедший читатель видит гостевую версию страницы.** Сервер ходит в API без cookie
читателя, а при гидратации браузер переиспользует ответы сервера. Поэтому переиспользуются
только ответы, общие для всех, — их список в `projects/site/src/app/transfer-cache.ts`.
Добавляя страницу, ответ которой зависит от человека, в этот список её не вносите.

**Путь с кириллицей.** Если проект лежит в папке вроде `C:\Users\…\Рилсы\`, перенесите
его в путь без кириллицы, например `C:\dev\food-guru`. Vite и esbuild на Windows
периодически спотыкаются о не-ASCII пути, и ошибки получаются невнятные.
