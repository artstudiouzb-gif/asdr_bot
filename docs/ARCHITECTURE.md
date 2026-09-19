# Telegram News Reposter — анализ и архитектура

Документ для согласования перед разработкой. Отвечает на STEP 1–4 задания:
анализ ограничений, архитектура, схема БД, план реализации.

---

## 0. Краткий вывод

| Вопрос | Ответ |
|---|---|
| Можно ли собрать это на PHP + MySQL + cron на shared hosting? | **Да**, но с одной жёсткой оговоркой — см. следующую строку |
| Можно ли читать чужие каналы (`@shmirziyoyev`) через Bot API? | **Нет. Никогда.** Бот получает посты только из каналов, где он администратор |
| Что тогда читает источники? | MTProto-клиент от имени обычного аккаунта. На PHP это **MadelineProto** |
| Главный риск проекта | MadelineProto требователен к хостингу (PHP 8.2+ 64-bit CLI, ext-gmp, 256 МБ памяти, запись в файл сессии). На части shared-хостингов он не заведётся |
| Что делаем с этим риском | **Phase 0**: перед разработкой прогоняем preflight-скрипт на вашем реальном хостинге. Архитектура спроектирована так, что модуль чтения заменяем — при провале переходим на план Б без переписывания панели |
| Задержка публикации | до 1 минуты (интервал cron) + настраиваемая задержка. Мгновенно на shared hosting — только для каналов, где наш бот админ (webhook) |
| Панель | своя, PHP + vanilla JS, без CMS и тяжёлых фреймворков |

Дальше — обоснование каждого пункта.

## 0.1 Согласованные решения

| Решение | Выбор | Следствия |
|---|---|---|
| Публикация | **От аккаунта через MTProto, посты выходят от имени канала** | Аккаунт-читатель должен быть администратором целевого канала с правом постинга. В настройках канала **«Подписывать сообщения» (sign_messages) должно быть выключено** — иначе Telegram допишет под постом имя администратора. При выключенной подписи пост неотличим от опубликованного вручную от имени канала. Медиа не скачивается и не перезаливается, лимит файла 2 ГБ |
| План Б, если MadelineProto не запустится | **Парсинг публичных страниц `t.me/s/<канал>` на чистом PHP** | Важное следствие: без MadelineProto публиковать от аккаунта тоже невозможно — в плане Б публикация идёт **через Bot API от имени бота-администратора** канала. Доступны только публичные каналы и только текст и фотографии: видео, документы и аудио со страницы предпросмотра забрать нельзя |
| Первый шаг | **Preflight на реальном хостинге** (`php/preflight.php`) | Пока нет его вывода, выбор между основным планом и планом Б не сделан |

---

## 1. Анализ Telegram API

### 1.1 Bot API — что он реально умеет

| Возможность | Ответ |
|---|---|
| Читать посты из произвольного публичного канала | **Нет** |
| Читать посты из канала, где бот — администратор | Да (`channel_post` в getUpdates/webhook) |
| `copyMessage` из чужого канала | Нет — бот должен иметь доступ к исходному чату |
| Публиковать в свой канал (бот — админ) | Да, надёжно |
| Скачать файл | до **20 МБ** (`getFile`) |
| Загрузить файл | до **50 МБ**, фото до **10 МБ** |
| Медиагруппа (альбом) | 2–10 элементов; фото и видео смешиваются, документы и аудио — отдельными группами |
| Длина подписи / текста | 1024 / 4096 символов |
| Частота | ~30 сообщений/сек суммарно, ~20 сообщений/мин в один чат; при 429 приходит `retry_after` |

Вывод: Bot API — **идеальный транспорт для публикации** (простой HTTPS, работает на любом
shared hosting) и **непригоден для чтения чужих источников**.

### 1.2 MTProto (Telegram Client API)

Читает каналы так же, как обычное приложение Telegram: публичные — без подписки,
приватные — если аккаунт подписан. Даёт исходный текст с entities, все типы медиа,
альбомы, файлы до 2 ГБ.

Реализации на PHP: **MadelineProto** (`danog/madelineproto`) — единственная живая
полнофункциональная библиотека. Требования (проверяются preflight-скриптом):

* PHP **8.2+**, обязательно **64-битный**, CLI-версия для cron;
* расширения: `mbstring`, `json`, `xml`, `dom`, `fileinfo`, `iconv`, `zlib`, `openssl`, `curl`, **`gmp`**
  (без gmp криптографическое рукопожатие идёт десятки секунд — практически обязательна);
* `memory_limit` ≥ 256 МБ;
* право записи в каталог сессии;
* функции `proc_open` / `pcntl_*` могут быть отключены хостингом — библиотеку запускаем
  в однопроцессном режиме, без её IPC-сервера;
* `vendor/` собираем локально и заливаем — composer на хостинге не нужен.

Плата за отсутствие демона: каждый запуск cron заново поднимает соединение и
авторизацию (≈2–5 с). При cron раз в минуту это приемлемо.

### 1.3 Публикация: от бота или от аккаунта

| | От бота (Bot API) | От аккаунта (MTProto) |
|---|---|---|
| Кто должен быть админом в целевом канале | бот | аккаунт |
| Медиа | нужно **скачать** с источника и **залить заново**, лимит 50 МБ | медиа переиспользуется по ссылке — **без скачивания**, лимит 2 ГБ |
| Трафик и место на диске | двойной объём каждого поста | ноль |
| Подпись под постом | «от имени канала» | «от имени канала» |
| Риск | нет | аккаунт может получить FLOOD_WAIT / бан при агрессии |

**Решение: публикуем от аккаунта (MTProto), посты выходят от имени канала.** Это убирает
скачивание/заливку медиа, лимит 50 МБ и половину точек отказа. Пост, отправленный
аккаунтом-администратором в канал, Telegram показывает как обычный пост канала;
единственное условие — в настройках канала выключена опция **«Подписывать сообщения»**,
иначе под постом появится имя администратора. Bot API остаётся вторым режимом,
переключаемым на уровне каждого назначения (`destinations.publish_as`), и становится
единственным вариантом в плане Б.

### 1.4 Лимиты, которые придётся уважать

* FLOOD_WAIT у MTProto и 429 + `retry_after` у Bot API — обрабатываются паузой и переводом
  публикации в `RETRY`, а не потерей поста;
* безопасный потолок: **15–20 публикаций в минуту на один целевой канал** (настраивается
  в `destinations.rate_limit_per_min`);
* массовое вступление в каналы и рывки после долгой паузы — основная причина ограничений
  аккаунта; при первом запуске историю не заливаем (`backfill` по умолчанию 0).

---

## 2. Анализ shared hosting

| Нельзя | Можно |
|---|---|
| Docker, VPS, systemd, supervisor | PHP-CLI по cron (обычно каждую минуту) |
| Постоянный daemon, worker, WebSocket | Короткий процесс с бюджетом времени |
| Redis, RabbitMQ | MySQL/MariaDB как очередь и как блокировка |
| Долгие HTTP-запросы в панели | HTTPS-эндпоинт для webhook Telegram |

Практические ограничения, которые закладываем в архитектуру:

1. **Время выполнения.** У CLI обычно нет лимита, но хостер может убивать процессы.
   Воркер работает по бюджету (по умолчанию 50 с) и корректно выходит, не теряя состояние.
2. **Параллельные запуски cron.** Защита — `GET_LOCK()` MySQL (с запасным вариантом
   таблицы `locks`), а не только lock-файл.
3. **Память.** Медиа через Bot API льём потоково во временный файл, а не в память.
   При публикации от аккаунта файлы вообще не трогаем.
4. **Доступ к файлам из веба.** Из web-root доступен только `public_html/`;
   `.env`, сессия Telegram, логи и `vendor/` лежат выше по дереву.
5. **Сессия MTProto — однопользовательский ресурс.** Её касается **только cron-воркер**.
   Панель никогда не работает с Telegram напрямую: она кладёт задание в таблицу
   `tg_commands`, воркер выполняет и возвращает результат. Это снимает целый класс
   ошибок «две PHP-сессии одновременно испортили файл авторизации».

---

## 3. Варианты архитектуры и выбор

| | Чтение источников | Работает на shared hosting | Ограничения |
|---|---|---|---|
| **A. Bot API, бот — админ источника** | webhook, мгновенно | Да, идеально | Только для каналов, которыми вы управляете. Для `@shmirziyoyev` неприменимо |
| **B. MadelineProto (MTProto) по cron** | да, любые каналы | **Если хостинг подходит** | Требования к PHP и расширениям; задержка до 1 мин |
| **C. Парсинг `t.me/s/<канал>`** | только публичные | Да, на любом хостинге | Нет приватных каналов; **видео, документы и аудио недоступны** — только текст и фото; ломается при смене вёрстки |
| **D. Python-коллектор (уже написан в этом репозитории)** | да, любые каналы | Если у хостинга есть Python App | Вторая среда исполнения в проекте |

**Рекомендуемая схема — A + B одновременно, C и D как планы Б:**

* источник, где наш бот админ, читается **webhook'ом** (мгновенно, без cron) —
  это бесплатный бонус, его же эндпоинт обслуживает бота публикации;
* остальные источники читает **MadelineProto** из cron-воркера;
* если Phase 0 покажет, что MadelineProto на вашем хостинге не работает — включаем
  **план Б (вариант C, парсинг `t.me/s/`)** **без изменения панели, БД и правил очистки**:
  модуль чтения изолирован интерфейсом `SourceReader`. В этом случае публикация
  автоматически переключается на Bot API (бот — администратор целевого канала),
  а набор поддерживаемых медиа сужается до текста и фотографий.

---

## 4. Рекомендуемая архитектура

### 4.1 Стек

* PHP 8.2+ (без фреймворка: свой микро-роутер, ~15 файлов ядра), PDO + MySQL/MariaDB;
* `danog/madelineproto` — чтение и публикация через MTProto;
* собственный тонкий HTTP-клиент Bot API на cURL (второй режим публикации + webhook);
* панель: серверный рендеринг PHP + один CSS-файл + vanilla JS (без React/Bootstrap);
* cron: `bin/cron.php` каждую минуту.

### 4.2 Потоки данных

```
                    ┌──────────────── cron раз в минуту ───────────────┐
                    │                                                  │
  Telegram ──MTProto──► Ingestor ──► messages (NEW) ──► Processor ──► publications
 (источники)         (чтение новых                    (правила,        (очередь)
                      постов, альбомы)                 фильтры,            │
                                                       подпись)            │
  Telegram ──webhook──► Ingestor ──────────┘                               │
 (бот-админ)                                                              ▼
                                                             Publisher ──► Telegram
                                                          (MTProto/BotAPI)  (цели)
                                                                  │
                                                                  ▼
                                                          logs + publications.status
```

Разделение на две таблицы принципиально: **один пост источника = одна строка `messages`**,
**одна доставка в конкретный канал = одна строка `publications`**. Это даёт маршрутизацию
1→N, независимые статусы и повторы по каждому назначению и точную защиту от дублей.

### 4.3 Цикл воркера (`bin/cron.php`)

```
1. GET_LOCK('reposter.worker', 0)      → занято? выходим молча
2. запись в cron_runs (started_at)
3. INGEST   — для каждого активного источника: новые сообщения после last_message_id,
              склейка альбомов по grouped_id, запись в messages (INSERT IGNORE),
              создание publications по всем активным маршрутам (scheduled_at = now + delay)
4. PROCESS  — публикации в статусе NEW: фильтры → правила очистки → подпись
              → processed_text; при отсеве статус SKIPPED с причиной
5. PUBLISH  — готовые к отправке (scheduled_at <= now), с учётом лимита на канал:
              атомарный захват строки (UPDATE ... WHERE status IN ('NEW','RETRY')),
              отправка, запись dest_message_id, статус PUBLISHED
6. RETRY    — ошибки: attempts+1, backoff (1,5,15,60 мин), после N попыток — ERROR
7. бюджет времени 50 с: истёк — аккуратно выходим, остаток доберёт следующий запуск
8. финализация cron_runs, RELEASE_LOCK
```

Каждый шаг идемпотентен: падение процесса в любой точке не создаёт дублей и не теряет пост.

### 4.4 Авторизация MTProto-аккаунта (через панель, без SSH)

```
Панель: «Подключить аккаунт» → телефон  →  tg_commands: login_start
Воркер (≤60 с): отправляет код          →  tg_accounts.status = awaiting_code
Панель: поле «код из Telegram»          →  tg_commands: login_code
Воркер: вводит код (при 2FA — пароль)   →  status = active, сессия сохранена
```

* файл сессии: `storage/telegram/<account>.madeline`, **вне web-root**, права `0600`,
  каталог закрыт `.htaccess` (`Require all denied`);
* **файл сессии = полный доступ к аккаунту.** Его кража равносильна краже аккаунта,
  поэтому: отдельный аккаунт и отдельный номер телефона только для бота, не личный;
  включённый 2FA-пароль; бэкап сессии не кладём в git и не храним в web-root;
* при подозрении на компрометацию — «Завершить все сеансы» в приложении Telegram,
  после чего сессия становится бесполезной;
* альтернатива, если MTProto для вас неприемлем: только план A (бот-админ в источниках)
  или план C (парсинг публичных страниц, без видео и документов).

### 4.5 Структура проекта

```
reposter/
├── public_html/               ← единственный каталог, видимый из интернета
│   ├── index.php              фронт-контроллер панели
│   ├── webhook.php            приём апдейтов бота (секрет в пути + проверка токена)
│   ├── cron-web.php           запуск воркера по URL, если cron умеет только curl
│   ├── assets/{app.css,app.js}
│   └── .htaccess              роутинг, запрет листинга, security-заголовки
├── app/
│   ├── Core/                  Router, Request, Response, View, Db, Config, Auth,
│   │                          Csrf, Validator, Logger, RateLimiter, LockManager
│   ├── Controllers/           Auth, Dashboard, Sources, Destinations, Routes, Rules,
│   │                          Signatures, Filters, Preview, Logs, Settings, Account
│   ├── Models/                тонкие репозитории над PDO
│   ├── Services/
│   │   ├── Telegram/          MtprotoReader, MtprotoPublisher, BotApiClient,
│   │   │                      PeerResolver, MediaTransfer, SourceReader (интерфейс)
│   │   ├── Processing/        EntityHtml, FooterDetector, RuleEngine, FilterEngine,
│   │   │                      SignatureBuilder, TextPipeline
│   │   └── Queue/             Ingestor, Processor, Publisher, RetryPolicy
│   └── Views/                 layout + страницы + партиалы
├── bin/
│   ├── cron.php               воркер (раз в минуту)
│   ├── preflight.php          проверка хостинга (Phase 0)
│   ├── migrate.php            миграции
│   └── create-admin.php       создание администратора
├── db/migrations/             0001_init.sql, 0002_…
├── storage/                   session, tmp-медиа, логи  (вне web-root, 0700)
├── vendor/                    собирается локально
├── .env                       секреты, 0600, вне web-root
└── docs/                      этот документ + DEPLOYMENT.md
```

---

## 5. Схема базы данных

MySQL 5.7+/MariaDB 10.3+, InnoDB, `utf8mb4_unicode_ci`. Все времена хранятся в **UTC**,
отображаются в таймзоне из настроек (`Asia/Tashkent`).

```sql
-- ── доступ в панель ─────────────────────────────────────────────────────────
CREATE TABLE admins (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username         VARCHAR(64)  NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,          -- password_hash(), Argon2id/bcrypt
  telegram_user_id BIGINT       NULL,              -- опциональный вход через Telegram
  role             ENUM('owner','admin') NOT NULL DEFAULT 'admin',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until     DATETIME     NULL,
  last_login_at    DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_username (username),
  UNIQUE KEY uq_admins_tg (telegram_user_id)
) ENGINE=InnoDB;

CREATE TABLE admin_sessions (            -- сессии в БД: разлогин и аудит из панели
  id           CHAR(64) PRIMARY KEY,     -- случайный токен, в cookie только он
  admin_id     INT UNSIGNED NOT NULL,
  ip           VARBINARY(16) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  CONSTRAINT fk_sessions_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
  KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (            -- rate limiting входа
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARBINARY(16) NOT NULL, username VARCHAR(64) NULL,
  success TINYINT(1) NOT NULL, created_at DATETIME NOT NULL,
  KEY idx_attempts (ip, created_at)
) ENGINE=InnoDB;

-- ── доступ в Telegram ───────────────────────────────────────────────────────
CREATE TABLE tg_accounts (               -- MTProto-аккаунты и боты
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label         VARCHAR(64) NOT NULL,
  kind          ENUM('user','bot') NOT NULL,
  phone         VARCHAR(32)  NULL,       -- для user
  token_enc     VARBINARY(512) NULL,     -- для bot: токен, шифрован ключом из .env
  username      VARCHAR(64)  NULL,
  session_path  VARCHAR(255) NULL,
  status        ENUM('new','awaiting_code','awaiting_password','active','error') NOT NULL DEFAULT 'new',
  last_error    TEXT NULL,
  last_check_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_accounts_label (label)
) ENGINE=InnoDB;

CREATE TABLE tg_commands (               -- панель → воркер (панель не трогает сессию)
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  command    ENUM('login_start','login_code','login_password','logout','test_peer','resolve_peer') NOT NULL,
  payload    JSON NULL,
  status     ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  result     TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  executed_at DATETIME NULL,
  CONSTRAINT fk_commands_account FOREIGN KEY (account_id) REFERENCES tg_accounts(id) ON DELETE CASCADE,
  KEY idx_commands_status (status, id)
) ENGINE=InnoDB;

-- ── источники, назначения, маршруты ─────────────────────────────────────────
CREATE TABLE sources (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(128) NOT NULL,
  tg_identifier   VARCHAR(255) NOT NULL,          -- @username / t.me/... / -100…
  tg_peer_id      BIGINT NULL,                    -- разрешается при «Test connection»
  reader          ENUM('mtproto','bot_admin','web') NOT NULL DEFAULT 'mtproto',
  account_id      INT UNSIGNED NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  fetch_limit     SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  last_message_id BIGINT NOT NULL DEFAULT 0,      -- курсор
  last_checked_at DATETIME NULL,
  last_error      TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sources_identifier (tg_identifier),
  KEY idx_sources_active (is_active),
  CONSTRAINT fk_sources_account FOREIGN KEY (account_id) REFERENCES tg_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE destinations (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(128) NOT NULL,
  tg_identifier     VARCHAR(255) NOT NULL,
  tg_peer_id        BIGINT NULL,
  publish_as        ENUM('user','bot') NOT NULL DEFAULT 'user',
  account_id        INT UNSIGNED NULL,            -- чей аккаунт/бот публикует
  rate_limit_per_min SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  last_error        TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dest_identifier (tg_identifier),
  CONSTRAINT fk_dest_account FOREIGN KEY (account_id) REFERENCES tg_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE routes (                    -- Source → Destination, N:M
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id      INT UNSIGNED NOT NULL,
  destination_id INT UNSIGNED NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  delay_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
  rule_set_id    INT UNSIGNED NULL,       -- NULL → набор по умолчанию
  signature_id   INT UNSIGNED NULL,       -- NULL → подпись по умолчанию, 0-строка → без подписи
  filter_set_id  INT UNSIGNED NULL,
  media_mode     ENUM('all','text_only','skip_media') NOT NULL DEFAULT 'all',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route (source_id, destination_id),
  KEY idx_routes_active (is_active),
  CONSTRAINT fk_routes_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_routes_dest   FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── обработка текста ────────────────────────────────────────────────────────
CREATE TABLE rule_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL, is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rule_sets_name (name)
) ENGINE=InnoDB;

CREATE TABLE rules (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_set_id INT UNSIGNED NULL,          -- NULL = глобальное правило, действует везде
  name        VARCHAR(128) NOT NULL,
  type        ENUM('SOCIAL_FOOTER','REMOVE_LINE','REMOVE_URL','REMOVE_BLOCK',
                   'REGEX_REPLACE','REPLACE_TEXT','APPEND_TEXT','PREPEND_TEXT',
                   'DROP_MESSAGE') NOT NULL,
  pattern     TEXT NULL,                  -- regex / подстрока / список доменов
  replacement TEXT NULL,
  options     JSON NULL,                  -- {case_insensitive, domains[], labels[], unwrap_links, whole_word}
  priority    SMALLINT NOT NULL DEFAULT 100,   -- меньше = раньше
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rules_set (rule_set_id, priority),
  CONSTRAINT fk_rules_set FOREIGN KEY (rule_set_id) REFERENCES rule_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE signatures (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(128) NOT NULL,
  content    TEXT NOT NULL,               -- HTML Telegram: <a href="…">website</a> | …
  position   ENUM('append','prepend') NOT NULL DEFAULT 'append',
  separator  VARCHAR(64) NOT NULL DEFAULT '\n\n',
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE filter_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE filters (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filter_set_id INT UNSIGNED NOT NULL,
  kind          ENUM('include_keyword','exclude_keyword','include_regex','exclude_regex',
                     'min_length','max_length','require_media','skip_media','skip_forwards') NOT NULL,
  value         VARCHAR(512) NULL,
  case_insensitive TINYINT(1) NOT NULL DEFAULT 1,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_filters_set FOREIGN KEY (filter_set_id) REFERENCES filter_sets(id) ON DELETE CASCADE,
  KEY idx_filters_set (filter_set_id)
) ENGINE=InnoDB;

-- ── очередь и результаты ────────────────────────────────────────────────────
CREATE TABLE messages (                  -- один пост источника (альбом = одна строка)
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id         INT UNSIGNED NOT NULL,
  source_message_id BIGINT NOT NULL,     -- id первого сообщения альбома
  grouped_id        BIGINT NULL,
  posted_at         DATETIME NULL,
  raw_text          MEDIUMTEXT NULL,
  raw_entities      JSON NULL,           -- entities Telegram, смещения UTF-16
  media             JSON NULL,           -- [{type,file_id|file_ref,mime,size,order}]
  media_kind        ENUM('none','photo','video','document','animation','audio','album','other')
                    NOT NULL DEFAULT 'none',
  is_forward        TINYINT(1) NOT NULL DEFAULT 0,
  content_hash      CHAR(64) NULL,       -- дедупликация одинаковых постов из разных источников
  fetched_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_messages_source_msg (source_id, source_message_id),
  KEY idx_messages_hash (content_hash),
  KEY idx_messages_group (source_id, grouped_id),
  CONSTRAINT fk_messages_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE publications (              -- доставка поста в конкретный канал
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id      INT UNSIGNED NOT NULL,
  route_id        INT UNSIGNED NOT NULL,
  destination_id  INT UNSIGNED NOT NULL,
  status          ENUM('NEW','PROCESSING','PUBLISHED','SKIPPED','ERROR','RETRY') NOT NULL DEFAULT 'NEW',
  processed_text  MEDIUMTEXT NULL,
  skip_reason     VARCHAR(255) NULL,
  dest_message_id BIGINT NULL,
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      TEXT NULL,
  scheduled_at    DATETIME NOT NULL,     -- now + routes.delay_seconds
  processed_at    DATETIME NULL,
  published_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pub_route_message (route_id, message_id),   -- защита от дублей
  KEY idx_pub_queue (status, scheduled_at),
  KEY idx_pub_dest_time (destination_id, published_at),
  CONSTRAINT fk_pub_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_pub_route   FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pub_dest    FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE logs (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level          ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  component      VARCHAR(32) NOT NULL,       -- ingest|process|publish|telegram|panel|cron
  publication_id INT UNSIGNED NULL,
  source_id      INT UNSIGNED NULL,
  destination_id INT UNSIGNED NULL,
  message        TEXT NOT NULL,
  context        JSON NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_logs_time (created_at),
  KEY idx_logs_level (level, created_at),
  KEY idx_logs_pub (publication_id)
) ENGINE=InnoDB;

CREATE TABLE cron_runs (                 -- «жив ли воркер» для дашборда
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  ingested    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  published   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  skipped     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  errors      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  status      ENUM('ok','partial','locked','error') NOT NULL DEFAULT 'ok',
  note        VARCHAR(255) NULL,
  KEY idx_cron_started (started_at)
) ENGINE=InnoDB;

CREATE TABLE settings (
  `key` VARCHAR(64) PRIMARY KEY,
  value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE locks (                     -- запасной механизм, если GET_LOCK недоступен
  name VARCHAR(64) PRIMARY KEY,
  holder VARCHAR(64) NOT NULL, acquired_at DATETIME NOT NULL, expires_at DATETIME NOT NULL
) ENGINE=InnoDB;
```

**Гарантии от дублей** (три уровня, не считая курсора `sources.last_message_id`):

1. `UNIQUE (source_id, source_message_id)` — один пост источника не попадёт в очередь дважды;
2. `UNIQUE (route_id, message_id)` — один пост не уйдёт дважды в один канал даже при гонке;
3. `content_hash` — один и тот же текст из двух источников публикуется один раз (опционально).

Захват задачи атомарен:
`UPDATE publications SET status='PROCESSING' WHERE id=? AND status IN ('NEW','RETRY')` —
если `affected_rows = 0`, задачу забрал другой процесс.

---

## 6. Движок очистки текста

### 6.1 Почему не «удалить N последних строк»

Работаем с текстом **вместе с entities**: MTProto отдаёт `message` (чистый текст) и
`entities` (ссылки, жирный, курсив со смещениями в UTF-16). Подпись вида
`Prezident.uz|Facebook|Instagram|YouTube|X` — это чистый текст плюс пять
`messageEntityTextUrl`; в самом тексте доменов нет. Поэтому конвейер такой:

```
entities + text  →  HTML  →  правила  →  HTML  →  отправка с parse_mode=HTML
```

### 6.2 Встроенный детектор `SOCIAL_FOOTER`

Для каждой строки:

1. собрать все URL — из `href` ссылок и из голого текста (`Facebook (https://…)`);
2. отметить те, чей домен есть в списке (`president.uz`, `facebook.com`, `instagram.com`,
   `youtube.com`, `youtu.be`, `x.com`, `twitter.com`, `t.me`, `linkedin.com`, …);
3. убрать из строки URL и остаток разобрать на слова;
4. если **есть хотя бы одна ссылка из списка** и **все оставшиеся слова — метки соцсетей**
   (`Prezident`, `uz`, `Facebook`, `Instagram`, `YouTube`, `X`, `Telegram`, …) — строка целиком удаляется;
5. строка «Трансляция идёт на канале в прямом эфире» со ссылкой на youtube **остаётся** —
   в ней есть осмысленные слова; по настройке у такой ссылки снимается только гиперссылка.

Алгоритм уже проверен на реальных постах (в этом репозитории лежит его реализация на
Python с тестами, включая пост `t.me/shmirziyoyev/35508`) — в PHP переносится один в один.
Списки доменов и меток редактируются в панели, это не хардкод.

### 6.3 Порядок применения

```
DROP_MESSAGE (стоп-слова)  →  SOCIAL_FOOTER  →  REMOVE_BLOCK  →  REMOVE_LINE
→  REMOVE_URL  →  REGEX_REPLACE  →  REPLACE_TEXT  →  схлопывание пустых строк
→  PREPEND_TEXT / APPEND_TEXT  →  подпись маршрута
```

Внутри одного типа — по `priority`. Regex-правила исполняются с таймаутом
(`pcre.backtrack_limit`), некорректный шаблон проверяется при сохранении в панели,
а не в бою.

### 6.4 Preview

Страница **Preview**: слева исходный текст (можно вставить HTML поста или просто текст),
выбор маршрута/набора правил, справа — результат и список сработавших правил
(«SOCIAL_FOOTER удалил строку 7», «фильтр exclude_keyword: реклама → SKIPPED»).
Работает без Telegram, публикаций не делает.

---

## 7. Медиа: что поддерживается и какие есть ограничения

| Тип | От аккаунта (MTProto) | От бота (Bot API) |
|---|---|---|
| Текст | да, с форматированием | да |
| Фото | да, оригинальное качество | да, перезаливка, до 10 МБ |
| Альбом (2–10) | да, порядок сохраняется | да, порядок сохраняется |
| Видео | да, до 2 ГБ | перезаливка, **до 50 МБ** |
| Документ | да | перезаливка, до 50 МБ |
| Анимация (GIF) | да | да |
| Аудио / голосовые | да | да |
| Кружки, стикеры, опросы, геолокация | да (опрос — копией, не оригиналом) | частично |

Честные ограничения, которые попадут в документацию продукта:

* **альбом больше 10 элементов** Telegram не принимает — разбиваем на несколько групп;
* **подпись длиннее 1024 символов** — отправляем медиа с обрезанной подписью, остаток
  отдельным сообщением следом (настраивается);
* при публикации ботом файл **скачивается и заливается заново**: видео >50 МБ отправить
  нельзя → такая публикация уйдёт в `SKIPPED` с явной причиной, если назначение
  работает в режиме бота;
* качество не деградирует: медиа переиспользуется как есть, перекодирования нет.

---

## 8. Очередь, повторы, лимиты

* статусы: `NEW → PROCESSING → PUBLISHED`, отбой фильтром → `SKIPPED`,
  сбой → `RETRY` (с backoff 1/5/15/60 мин) → после `retry_max` (по умолчанию 4) → `ERROR`;
* `ERROR` можно перезапустить из панели кнопкой (статус → `RETRY`);
* FLOOD_WAIT/429: пауза по времени из ответа Telegram, публикация возвращается в очередь,
  воркер переходит к другому каналу, а не стоит;
* лимит на канал (`rate_limit_per_min`) считается по `publications.published_at`;
* «зависшие» `PROCESSING` старше 10 минут (процесс убит хостингом) автоматически
  возвращаются в `RETRY`.

---

## 9. Безопасность

| Требование | Решение |
|---|---|
| Авторизация | логин + пароль, `password_hash()` (Argon2id, fallback bcrypt); опционально вход через Telegram Login Widget с проверкой HMAC |
| Сессии | токен в БД, cookie `HttpOnly; SameSite=Lax; Secure`, ротация при входе, срок и принудительный разлогин из панели |
| Подбор пароля | `login_attempts` + блокировка аккаунта на 15 минут после 5 неудач, задержка ответа |
| CSRF | токен в каждой форме, привязан к сессии, проверка на всех POST |
| SQL-инъекции | только PDO prepared statements, имена таблиц/колонок не из пользовательского ввода |
| XSS | экранирование при выводе по умолчанию (`htmlspecialchars` в шаблонизаторе), HTML-подпись проходит через белый список тегов Telegram (`b,i,u,s,a,code,pre,blockquote,tg-spoiler`) |
| Валидация | строгие правила на все поля, проверка компиляции regex перед сохранением |
| Секреты | `.env` вне web-root, права `0600`; токены ботов в БД шифруются ключом из `.env`; в код секреты не попадают |
| Сессия MTProto | вне web-root, `0600`, каталог закрыт `.htaccess`, в git не попадает |
| Защита cron | CLI-запуск не требует секрета; URL-вариант `cron-web.php?key=…` — длинный ключ, сравнение `hash_equals`, ограничение частоты |
| Webhook | секретный путь + заголовок `X-Telegram-Bot-Api-Secret-Token`, сверка `hash_equals` |
| Служебные файлы | из веба доступен только `public_html/`; `.htaccess`: запрет листинга, запрет на `.env`, `.sql`, `.md`, `.log`, заголовки `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` |
| Логи | секреты и токены маскируются перед записью |

---

## 10. Админ-панель

Серверный рендеринг, один CSS-файл (~300 строк), vanilla JS для модалок, фильтров и
подтверждений. Адаптивная вёрстка, desktop-first. Без Bootstrap, React и сборщиков.

| Страница | Содержимое |
|---|---|
| **Dashboard** | источники/маршруты (всего и активных), обработано постов, опубликовано, пропущено, ошибок за 24 ч; статус воркера (последний `cron_runs`, «cron не отвечает N минут»); последние 10 публикаций и последние 10 ошибок |
| **Sources** | таблица с бейджами статуса, Add/Edit/Delete/Enable/Disable, **Test connection** (через очередь команд: резолв peer, проверка доступа, последний пост) |
| **Destinations** | то же + выбор «публиковать от аккаунта/бота», лимит в минуту, проверка прав на постинг |
| **Routes** | матрица Source → Destination, задержка, набор правил, подпись, фильтры, вкл/выкл |
| **Rules** | наборы правил, правила с типом, шаблоном, приоритетом, drag-free сортировка по полю priority, кнопка «Проверить на примере» |
| **Signatures** | редактор подписи (HTML Telegram), позиция, разделитель, предпросмотр, «по умолчанию» |
| **Filters** | наборы фильтров: include/exclude ключевые слова и regex, длина, требования к медиа |
| **Preview** | исходный текст → результат обработки + список сработавших правил |
| **Logs** | таблица публикаций и событий, фильтры по дате, источнику, назначению, статусу, поиск по message ID, кнопка «Повторить» |
| **Settings** | Telegram-аккаунты и боты (подключение/выход), cron-интервал и ключ, подпись и правила по умолчанию, retry, уровень логирования, хранение логов, таймзона (`Asia/Tashkent`), смена пароля |

---

## 11. План реализации

| Фаза | Содержание | Результат, который можно проверить |
|---|---|---|
| **0. Preflight** | `bin/preflight.php`: версия и разрядность PHP, расширения, `memory_limit`, запрещённые функции, права на запись, доступ к api.telegram.org и к дата-центрам Telegram, наличие cron | Отчёт по вашему хостингу и **решение: MadelineProto или план Б**. Без этого шага остальное — гадание |
| **1. Каркас + первый репост** | `.env`, PDO, миграции, микро-роутер, layout, авторизация, CSRF, CRUD Sources/Destinations/Routes, подключение Telegram-аккаунта через очередь команд, ingest + publish текста, cron с блокировкой | Пост из реального канала появляется в вашем канале, дубликатов нет |
| **2. Обработка текста** | EntityHtml, детектор SOCIAL_FOOTER, RuleEngine (все типы правил), подписи, Preview, фильтры | Пост `t.me/shmirziyoyev/35508` публикуется без блока соцсетей и с вашей подписью |
| **3. Медиа и очередь** | фото, видео, документы, анимации, аудио, альбомы, задержки, retry с backoff, лимиты на канал, режимы медиа | Альбом из 10 фото уходит в порядке и с подписью; сбой сети не теряет пост |
| **4. Логи и мониторинг** | таблица логов, страница Logs с фильтрами, Dashboard, health-check cron, кнопка «Повторить», ретеншен логов | Видно каждую публикацию: когда получена, обработана, опубликована, с каким результатом |
| **5. Безопасность и деплой** | аудит по чеклисту раздела 9, rate limiting, маскирование секретов, `DEPLOYMENT.md`, скрипт создания админа, инструкция восстановления | Проект разворачивается на чистом хостинге по инструкции за ~30 минут |

Фазы 1–5 идут последовательно, каждая заканчивается рабочим состоянием — «наполовину
сделанных» функций в репозитории не остаётся.

---

## 12. Что технически невозможно или ограничено (честный список)

1. **Bot API не может читать чужие каналы** — обход только MTProto либо права админа в источнике.
2. **Мгновенная доставка на shared hosting недостижима** для каналов, где нас нет в админах:
   минимальная задержка = интервал cron (1 минута). Webhook даёт мгновенность только там,
   где наш бот администратор.
3. **MadelineProto может не запуститься** на конкретном хостинге (старый PHP, нет gmp,
   мало памяти, отключён `proc_open`). Проверяется в Phase 0, план Б описан в разделе 3.
4. **Публикация ботом ограничена 50 МБ** на файл — видео крупнее уйдёт только от аккаунта.
5. **Альбом — максимум 10 элементов**, подпись — 1024 символа.
6. **MTProto-аккаунт можно ограничить или заблокировать** при агрессивной работе:
   отдельный номер, щадящие лимиты, без заливки истории при старте.
7. **Отложенные и отредактированные посты**: редактирование поста в источнике после
   публикации не переносится автоматически (можно добавить отдельной задачей — это
   `updateEditChannelMessage`, но для cron-опроса он виден только при повторном чтении).

---

## 13. Что нужно от вас, чтобы стартовать

### Шаг 1 — preflight (готов, лежит в `php/preflight.php`)

Загрузите файл в корень сайта (`public_html`) и откройте:

```
https://ваш-домен/preflight.php?key=g1LNVlGjcXzFDnK3ugzVDFB4
```

Либо, если есть SSH: `php preflight.php` (с проверкой базы —
`php preflight.php --db-host=localhost --db-name=… --db-user=… --db-pass=…`).

Скрипт проверяет версию и разрядность PHP, расширения (включая `gmp`), лимиты памяти и
времени, запрещённые функции, права на запись, доступность Bot API и дата-центров
Telegram, читаемость страниц `t.me/s/` (план Б), скорость криптографии, MySQL с
`GET_LOCK()` и InnoDB, путь к PHP для cron. Внизу страницы — готовый текстовый блок,
его и нужно прислать. **После проверки файл удалить** — он показывает параметры сервера.

### Шаг 2 — остальное

1. MySQL: база, пользователь, пароль (или возможность создать).
2. Отдельный номер телефона для Telegram-аккаунта-читателя (не личный) и включённый 2FA.
3. Список источников и целевых каналов; в целевых аккаунт-читатель должен быть
   администратором, а опция «Подписывать сообщения» — выключена.
4. Домен или поддомен для панели (HTTPS обязателен).
5. Доступ к cron в панели хостинга с интервалом в 1 минуту.

## 14. Что уже есть в этом репозитории

Рабочий сервис на Python (Telethon) с той же логикой: очистка подписей с тестами на
реальном посте, дедупликация, медиа и альбомы, веб-панель со входом через Telegram,
режим cron и режим слежения. Он остаётся полезен в двух ролях:

* **эталон логики** — алгоритм очистки и обработка альбомов переносятся в PHP;
* **план Б** — если MadelineProto не заведётся, а Python на хостинге есть, читающую
  часть можно оставить на нём, а панель и БД сделать на PHP по этой архитектуре.

Решение — за вами: сохранить как `legacy/` или удалить после Phase 1.
