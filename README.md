# all-notifications

PHP-шлюзы для приёма вебхуков и рассылки уведомлений в **Telegram**, **VK** и **Matrix** по настраиваемым правилам (каналы, получатели, расписание). Проект не привязан к конкретному фреймворку: скрипты выкладываются на сервер с PHP, cURL и доступом к внешним API.

## Состав репозитория

| Файл | Назначение |
|------|------------|
| `send.php` | Основной универсальный шлюз: POST с JSON/form/plain, раздельные тексты для Telegram, VK и Matrix, опциональный `parse_mode` только для Telegram. Настройки и правила читает из `config.php`. |
| `config.php` | Возвращает массив с ключами `log_file`, `telegram_config`, `vk_config`, `matrix_config`, `rules`. Должен лежать рядом с `send.php` и быть читаемым процессом веб-сервера. |
| `config.example.php` | Шаблон без секретов: скопируйте в `config.php` и заполните. |
| `grafana-notifications.php` | Отдельный endpoint под типичный JSON вебхука Grafana (`formatMessage`). Встроенные `$rules` и токены в файле (наследие). |
| `kuma-notifications.php` | Отдельный endpoint под JSON Uptime Kuma (`formatKumaMessage`). Встроенные `$rules` и токены в файле (наследие). |

Интеграция с **Directus** (хук, `NOTIFICATION_ENDPOINT`) живёт в отдельном репозитории `directus-custom-notifications` и шлёт POST на **`send.php`** без `sender` (универсальное тело). Для **Grafana** в URL контакт-поинта укажите `?sender=grafana` (и при необходимости `?rules=...`), для **Uptime Kuma** — `?sender=kuma`, чтобы текст собирался как в legacy-скриптах.

## Развёртывание

1. Скопируйте каталог или отдельные `.php` на сервер (например под управлением nginx + php-fpm).
2. `cp config.example.php config.php` и отредактируйте `config.php`: токены, прокси, путь к логу, массив `rules`.
3. Убедитесь, что пользователь php-fpm может писать в файл лога из `log_file`.
4. В вебхуках указывайте URL вида `https://ваш-домен/.../send.php` (при необходимости с query: `?rules=main`, `?sender=grafana` или `kuma`, `?chat_id=...`, `?user_id=...`, `?room_id=!xxx:server` — см. комментарии в начале `send.php` и `config.example.php`).

Рекомендуется не отдавать `config.php` как статику из document root без запрета в конфиге веб-сервера, либо вынести конфиг за пределы публичного каталога и подключать по абсолютному пути (потребуется небольшая правка `send.php`).

## Matrix: технический пользователь и комната

Чтобы отправка в Matrix из шлюза работала, недостаточно положить в `config.php` только `homeserver_url`, `access_token` и `room_id`. У **того же** Matrix-пользователя, для которого вы получили токен (технический / бот-аккаунт), комната должна быть **реально принята** в клиенте.

Обычный порядок такой:

1. Зайти в **Element** (или другой клиент) **именно под этим техническим пользователем**, не под своим личным аккаунтом.
2. Увидеть **приглашение** в нужную комнату или личку — **принять** его.
3. Если сервер или комната требуют **вопрос при входе** (knock / join rules, «ответ на вопрос» и т.п.) — **отправить запрошенный ответ** или выполнить условие, пока пользователь не станет полноправным участником комнаты.
4. Убедиться, что в списке участников комнаты есть этот MXID, и только после этого прописывать **`room_id`** в `config.php` и проверять шлюз.

Пока технический пользователь **не вступил** в комнату (приглашение не принято, ответ не дан), API вернёт **`403 M_FORBIDDEN`** вроде «User … not in room …» — это ожидаемо, а не сбой `send.php`.

## Примеры curl для проверки `send.php`

Подставьте свой базовый URL вместо `https://example.com/all-notifications/send.php`. Запросы только **POST**.

Общее текстовое сообщение (одинаково уйдёт в каналы, для которых в сработавшем правиле есть получатели):

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Тест шлюза send.php"}'
```

Только выбранные правила из `config.php`:

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php?rules=main' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Только правило main"}'
```

Разный текст для Telegram и VK (опционально отдельное поле `matrix`):

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php?rules=main' \
  -H 'Content-Type: application/json' \
  -d '{"telegram":"*Жирный* тест","vk":"Обычный текст для VK","parse_mode":"MarkdownV2"}'
```

Правило с фильтром `senders` в `config.php` (сработает только вместе с `?sender=grafana` или `?sender=kuma` и `?rules=from_monitoring`):

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php?rules=from_monitoring&sender=grafana' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Алерт как от Grafana"}'
```

Сырой plain text:

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php' \
  -H 'Content-Type: text/plain; charset=utf-8' \
  --data-binary 'Простое тело без JSON'
```

Форма `application/x-www-form-urlencoded`:

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'message=Тест из формы'
```

Подстановка получателей из query, если в правиле для канала список пустой:

```bash
curl -sS -X POST 'https://example.com/all-notifications/send.php?chat_id=123456789&user_id=987654321' \
  -H 'Content-Type: application/json' \
  -d '{"message":"На GET chat_id / user_id"}'
```

Флаг `-v` у `curl` покажет HTTP-код; тело ответа шлюза обычно короткое (`Notifications sent...` / `No notifications sent`).

## Требования

- PHP 8.0+ (в коде используются `str_contains` в `grafana-notifications.php`, типизированные сигнатуры в `send.php`).
- Расширения: curl, mbstring.

## Логи

- `send.php` — путь из `config['log_file']` (в примере: `/var/log/notifications.log`).
- `grafana-notifications.php` / `kuma-notifications.php` — пути заданы внутри соответствующих файлов.
