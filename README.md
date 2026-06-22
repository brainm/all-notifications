# all-notifications

PHP-шлюз для приёма вебхуков и рассылки уведомлений в **Telegram**, **VK** и **Matrix** по настраиваемым правилам (каналы, получатели, расписание). Сообщения, пришедшие вне окна `schedule`, не теряются: попадают в очередь MySQL и доставляются, когда расписание снова разрешает отправку.

Проект не привязан к фреймворку: PHP 8+, cURL, PDO (MySQL/MariaDB), доступ к внешним API.

## Состав репозитория

| Файл | Назначение |
|------|------------|
| `send.php` | HTTP-шлюз: приём POST, разбор тела, применение правил, немедленная отправка или постановка в очередь. |
| `send_functions.php` | Вспомогательные функции отправки и форматирования (подключается из `send.php` и `cron.php`). |
| `queue.php` | Работа с очередью в БД: постановка, доставка, очистка просроченных записей. |
| `cron.php` | CLI-воркер очереди: удаляет записи старше 7 дней, отправляет накопившееся. |
| `cron.sh` | Обёртка для cron: `php cron.php`. |
| `schema.sql` | DDL таблицы `notification_queue` для MySQL/MariaDB. |
| `config.php` | Рабочая конфигурация (не коммитится). |
| `config.example.php` | Шаблон конфигурации без секретов. |

## Развёртывание

1. Скопируйте каталог на сервер (nginx + php-fpm или аналог).
2. `cp config.example.php config.php` — заполните токены, `db_config`, правила `rules`.
3. Создайте БД и таблицу очереди:

```bash
mysql -u root -p -e "CREATE DATABASE notifications CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p notifications < schema.sql
```

4. Убедитесь, что пользователь php-fpm может писать в `log_file` и подключаться к MySQL.
5. Добавьте cron (каждую минуту):

```cron
* * * * * /path/to/all-notifications/cron.sh >> /var/log/notifications-cron.log 2>&1
```

6. В вебхуках укажите URL вида `https://ваш-домен/.../send.php` (см. разделы ниже про query-параметры).

Рекомендуется не отдавать `config.php` как статику из document root (запрет в конфиге веб-сервера).

## Конфигурация (`config.php`)

Обязательные ключи верхнего уровня:

| Ключ | Описание |
|------|----------|
| `log_file` | Путь к логу, например `/var/log/notifications.log`. |
| `db_config` | Подключение к MySQL/MariaDB для очереди (`host`, `port`, `database`, `username`, `password`, `charset`). |
| `telegram_config` | `bot_token`, `proxies[]`, `timeout`. |
| `vk_config` | `access_token`, `api_version`, `proxies[]`, `timeout`. |
| `matrix_config` | `homeserver_url`, `access_token`, `proxies[]`, `timeout`. |
| `rules` | Массив правил доставки (см. ниже). |

### Правило (`rules`)

```php
'kitchen1' => [
    'enabled'    => true,
    'channels'   => ['vk'],
    'recipients' => [
        'vk' => ['123456789'],
    ],
    'schedule'   => [
        'days'  => [1, 2, 3, 4, 5],           // 1=пн … 7=вс (timezone сервера PHP)
        'hours' => [9, 10, 11, 12, 13, 14, 15, 16, 17, 18],
    ],
    // Опционально: только для указанных ?sender=
    'senders'    => ['grafana', 'kuma'],
    // Опционально: всегда отправлять, игнорируя schedule
    // 'always_send' => true,
],
```

- Пустой `schedule` или `schedule: []` — доставка в любое время.
- `senders` отсутствует или `[]` — правило для любого запроса (в т.ч. без `?sender`).
- `enabled: false` — правило не обрабатывается; записи в очереди для него ждут включения или истекают через 7 дней.

### Прокси

В `telegram_config` / `vk_config` / `matrix_config` ключ `proxies` — массив URI; используется первый элемент (`http://`, `socks5h://` и т.д.). Пустой массив — без прокси.

## Очередь отложенных сообщений

Если правило **подходит** по `enabled`, `senders` и `rules=`, но **не попадает в `schedule`**, каждая пара «канал + получатель» сохраняется в таблицу `notification_queue` (текст, `telegram_parse_mode` для Telegram, имя правила).

**`cron.php`** (через `cron.sh`, раз в минуту):

1. Удаляет записи старше **7 дней**.
2. Обходит очередь в порядке `created_at`.
3. Для каждой записи проверяет, что правило существует, включено и **сейчас** попадает в `schedule`.
4. Отправляет в канал; при успехе удаляет запись из очереди.

В очередь попадают только сообщения, отложенные из‑за **расписания**. Ошибки API (Telegram/VK недоступен) при немедленной отправке в очередь не кладутся; при доставке из cron неудачная попытка остаётся в очереди до следующего запуска или до истечения недели.

Ответы `send.php`:

| Ситуация | HTTP-тело |
|----------|-----------|
| Отправлено сразу | `Notifications sent according to rules.` |
| Только в очереди | `Notifications queued for later delivery.` |
| Ничего не сделано | `No notifications sent.` |

## `send.php`: форматы запроса

Только **POST**.

### Универсальный режим (без `?sender`)

- JSON: `{ "message": "..." }` или `text` / `body`
- JSON с разными текстами: `{ "telegram": "...", "vk": "...", "matrix": "..." }`
- `parse_mode` / `telegram_parse_mode` — только для Telegram
- `text/plain` — всё тело как сообщение
- `application/x-www-form-urlencoded` — поле `message` или `text`

### Режимы `?sender=`

| `sender` | Поведение |
|----------|-----------|
| `grafana` | Форматирование как в legacy Grafana webhook |
| `kuma` | Форматирование как в Uptime Kuma |
| `market` или `market/notification` | Вебхук [Яндекс Маркета](https://yandex.ru/dev/market/partner-api/doc/ru/push-notifications/reference/sendNotification): тело `php://input` в каналы **без разбора**; ответ всегда JSON `200` с `{ "version": "1.0.0", "name": "all-notifications", "time": "<текущее UTC>" }`. В URL для `market/notification` используйте `sender=market%2Fnotification`. |

### GET-параметры

| Параметр | Назначение |
|----------|------------|
| `rules` | Список имён правил через запятую (`?rules=kitchen1,kitchen2`). Без параметра — все правила. |
| `sender` | Фильтр источника (см. таблицу выше и `senders` в правиле). |
| `chat_id` | Подстановка получателя Telegram, если в правиле список пуст. |
| `user_id` | Подстановка получателя VK. |
| `room_id` | Подстановка комнаты Matrix (`!room:server`). |

Интеграция с **Directus** (отдельный репозиторий) шлёт POST на `send.php` без `sender`. Для Grafana/Kuma укажите `?sender=grafana` или `?sender=kuma`.

## Matrix: технический пользователь и комната

Токен в `matrix_config` должен принадлежать пользователю, который **принял приглашение** в целевую комнату в Element (или другом клиенте). Пока бот не в комнате, API вернёт `403 M_FORBIDDEN` — это не ошибка шлюза.

Сообщения через Client-Server API уходят **без E2EE**. Для бот-каналов обычно используют отдельную комнату **без шифрования**; в зашифрованных комнатах клиенты показывают «Not encrypted».

## Примеры curl

Базовый URL: `https://example.com/all-notifications/send.php`.

```bash
# Простое сообщение
curl -sS -X POST 'https://example.com/all-notifications/send.php' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Тест шлюза"}'

# Одно правило
curl -sS -X POST 'https://example.com/all-notifications/send.php?rules=main' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Только main"}'

# Разный текст по каналам
curl -sS -X POST 'https://example.com/all-notifications/send.php?rules=main' \
  -H 'Content-Type: application/json' \
  -d '{"telegram":"*жирный*","vk":"обычный","parse_mode":"MarkdownV2"}'

# Grafana
curl -sS -X POST 'https://example.com/all-notifications/send.php?sender=grafana&rules=from_monitoring' \
  -H 'Content-Type: application/json' \
  -d '{"status":"firing","title":"Disk full"}'

# Яндекс Маркет (PING)
curl -sS -X POST 'https://example.com/all-notifications/send.php?sender=market&rules=main' \
  -H 'Content-Type: application/json' \
  -d '{"notificationType":"PING"}'

# Получатели из query
curl -sS -X POST 'https://example.com/all-notifications/send.php?chat_id=123&user_id=456' \
  -H 'Content-Type: application/json' \
  -d '{"message":"На GET-параметры"}'
```

Проверка воркера очереди вручную:

```bash
./cron.sh
```

## Требования

- PHP 8.0+ (`str_contains`, типизированные сигнатуры)
- Расширения: **curl**, **mbstring**, **pdo_mysql**
- MySQL или MariaDB для очереди
- Cron (или systemd timer) для `cron.sh`

## Логи

- `send.php` и `cron.php` пишут в `config['log_file']` (по умолчанию `/var/log/notifications.log`).
- Вывод `cron.sh` в cron-задаче можно перенаправить в отдельный файл (см. пример в разделе «Развёртывание»).
