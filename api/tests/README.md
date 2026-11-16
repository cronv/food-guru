# Тесты API

Тесты ходят в MySQL, в базу `food_guru_test`. Схему создают Doctrine-миграции из
`api/migrations` (той же командой, что и на сервере). Каждый тест работает в
транзакции, которую откатывает DAMA, поэтому данные создаются в самом тесте и после
него исчезают.

Подготовка базы — один раз и после каждой новой миграции:

```bash
docker compose exec api php bin/console doctrine:database:create --if-not-exists --env=test
docker compose exec -u www-data api php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Запуск:

```bash
docker compose exec -u www-data api composer test
docker compose exec -u www-data api composer stan    # PHPStan, уровень 8
docker compose exec api composer cs                  # php-cs-fixer, только проверка
```

Тесты загрузок пишут в `STORAGE_PATH` из `.env.test` (`/tmp/food-guru-test-storage`),
а не в настоящее хранилище. Тест обложки журнала вызывает `pdftoppm` из poppler-utils,
он есть в образе `api`.
