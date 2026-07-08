# all-notifications

PHP-шлюз для приёма вебхуков и рассылки уведомлений в **Telegram**, **VK**, **Matrix**, **Email** и **Web** (inbox + browser push) по настраиваемым правилам.

Проект не привязан к фреймворку: PHP 8+, cURL, PDO (MySQL/MariaDB), Node.js для сборки фронтенда.

## Структура репозитория

```
all-notifications/
├── package.json, vite.config.js, tailwind.config.js
├── composer.json, schema.sql
├── public/              — manifest.json, service-worker.js
├── src/                 — исходники PHP и React
│   ├── send.php         — HTTP-шлюз (не трогать URL вебхуков)
│   ├── cron.php, queue.php, web.php, auth.php, api.php, …
│   ├── entries/         — React: login, dashboard, admin
│   ├── components/, lib/
│   ├── assets.php       — подключение Vite-сборки
│   └── config.example.php
└── dist/                — production (gitignored), document root на сервере
```

| Путь | Назначение |
|------|------------|
| `src/send.php` | HTTP-шлюз: приём POST, разбор тела, применение правил, немедленная отправка или постановка в очередь. |
| `src/send_functions.php` | Вспомогательные функции отправки и форматирования. |
| `src/queue.php` | Очередь в БД: постановка, доставка, очистка. |
| `src/cron.php` / `src/cron.sh` | CLI-воркер очереди. |
| `src/web.php` | Канал web: пользователи, inbox, Web Push. |
| `src/login.php`, `dashboard.php`, `admin.php` | PHP-оболочки с React (Vite). |
| `src/api.php` | JSON API для inbox, push, admin. |
| `public/service-worker.js`, `manifest.json` | PWA и push в браузере. |
| `src/config.php` | Рабочая конфигурация (не коммитится). |

## Сборка и развёртывание

### 1. Зависимости

```bash
npm install
composer install
php scripts/generate-vapid-keys.php   # → WEB_PUSH_* в корневой .env
```

### 2. Конфигурация

```bash
cp src/config.example.php src/config.php
```

Заполните токены, `db_config`, `web_push_config` (URL и subject), правила `rules`.  
Секреты в корневом `.env`: `ADMIN_*`, `WEB_PUSH_PUBLIC_KEY`, `WEB_PUSH_PRIVATE_KEY` → `dist/.env` при сборке.

### 3. База данных

```bash
mysql -u root -p -e "CREATE DATABASE notifications CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p notifications < schema.sql
```

### 4. Production-сборка

```bash
npm run build
```

Сборка:

- компилирует React (login, dashboard, admin) и Tailwind CSS;
- копирует `src/**/*.php` (включая `config.php`) и `cron.sh` в `dist/`;
- копирует `public/` и `vendor/` в `dist/`;
- генерирует manifest для `assets.php`.

**Document root** веб-сервера указывайте на каталог **`dist/`** (например `https://site.com/notifications/` → `.../all-notifications/dist/`).

После каждого изменения PHP или фронтенда пересобирайте: `npm run build`.

### 5. Cron

```cron
* * * * * /path/to/all-notifications/dist/cron.sh >> /var/log/notifications-cron.log 2>&1
```

### 6. Вебхуки

URL шлюза: `https://ваш-домен/.../send.php` (относительно `dist/`).

Рекомендуется не отдавать `config.php` как статику (запрет в конфиге веб-сервера).

## Конфигурация (`config.php`)

Обязательные ключи верхнего уровня:

| Ключ | Описание |
|------|----------|
| `log_file` | Путь к логу, например `/var/log/notifications.log`. |
| `db_config` | MySQL/MariaDB. |
| `web_push_config` | `app_base_url`, `app_base_path`, `vapid.subject`. |
| `dist/.env` | `ADMIN_*`, `WEB_PUSH_PUBLIC_KEY`, `WEB_PUSH_PRIVATE_KEY` (создаётся при сборке). |
| `telegram_config` | `bot_token`, `proxies[]`, `timeout`. |
| `vk_config` | `access_token`, `api_version`, `proxies[]`, `timeout`. |
| `matrix_config` | `homeserver_url`, `access_token`, `proxies[]`, `timeout`. |
| `email_config` | `host` (обяз.), `port`, `encryption`, `username`, `password`, `from_email`, `from_name` (опц.), `timeout`. |
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

Канал **`email`** — получатели в `recipients.email` — **адреса** `user@example.com`. Тема: `?subject=`, JSON `subject` / `title`, иначе имя правила. Нужен `composer install` (PHPMailer).

Канал **`web`** — получатели в `recipients.web` указываются **login** (локальная часть email) или **числовой id** пользователя из `admin.php`:

```php
'web_alerts' => [
    'enabled'    => true,
    'channels'   => ['web'],
    'recipients' => [
        'web' => ['ivan', 3],
    ],
    'schedule'   => [ /* как у остальных каналов */ ],
],
```

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

## Web-интерфейс и push

| URL (в `dist/`) | Назначение |
|-----------------|------------|
| `login.php` | Вход пользователя (React + Tailwind). |
| `dashboard.php` | Inbox: список уведомлений, «прочитано», push. |
| `admin.php` | Управление пользователями (отдельная admin-сессия). |
| `api.php` | JSON API для фронтенда и push subscribe. |

Порядок внедрения:

1. `composer install`, VAPID-ключи в `.env` (`WEB_PUSH_*`).
2. `npm run build`, document root → `dist/`.
3. Применить `schema.sql`.
4. Создать пользователей в `admin.php`.
5. Добавить канал `web` в правила `config.php`.

**Надёжность:** сообщение всегда попадает в `web_notifications`. Push — дополнительный сигнал; при долгом offline пользователь увидит inbox при открытии `dashboard.php`.

Миграция существующей БД:

```sql
ALTER TABLE notification_queue
    MODIFY channel ENUM('telegram', 'vk', 'matrix', 'web', 'email') NOT NULL;
ALTER TABLE notification_queue
    ADD COLUMN payload_json MEDIUMTEXT DEFAULT NULL AFTER telegram_parse_mode;
```

## `send.php`: форматы запроса

Только **POST**.

### Универсальный режим (без `?sender`)

- JSON: `{ "message": "..." }` или `text` / `body`
- JSON с разными текстами: `{ "telegram": "...", "vk": "...", "matrix": "...", "email": "..." }`
- `subject` / `title` в JSON или `?subject=` — тема письма для канала email
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
./dist/cron.sh
```

## Требования

- PHP 8.0+ (`str_contains`, типизированные сигнатуры)
- Расширения: **curl**, **mbstring**, **pdo_mysql**, **zlib**, **json**
- **Node.js 18+** и `npm run build` для фронтенда
- **Composer** + `minishlink/web-push` для browser push
- MySQL или MariaDB для очереди
- Cron (или systemd timer) для `dist/cron.sh`

## Логи

- `send.php` и `cron.php` пишут в `config['log_file']` (по умолчанию `/var/log/notifications.log`).
- **Ротация по месяцам:** при первом запуске в новом календарном месяце (в `send.php` или `cron.php`) текущий лог сжимается в `gzip` и сохраняется как `/var/log/notifications/YYYY-MM.log.gz` (для пути `/var/log/notifications.log` каталог архивов — `/var/log/notifications/`). После этого `notifications.log` начинается заново. Маркер месяца: `/var/log/notifications/.current-month`.
- Вывод `cron.sh` в cron-задаче можно перенаправить в отдельный файл (см. раздел «Развёртывание»).
