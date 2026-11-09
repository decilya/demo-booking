# REVIEW.md — Аудит кода API бронирования

**Методология.** Код прочитан по файлам репозитория. Все Critical-баги
воспроизведены локально (`docker compose up -d`, `php artisan migrate:fresh --seed`)
через `curl`. Маркеры:

- `[verified: reproduced]` — подтверждено реальным запросом;
- `[verified: code]` — подтверждено чтением файла;
- `[consensus]` — интерпретация ТЗ;
- `[verified: not a problem]` — проверено и признано корректным.

Файлы под ревью: `app/Http/Controllers/BookingController.php`, `app/Models/*`,
`database/migrations/*bookings*`, `routes/api.php`, `config/logging.php`,
`tests/Feature/BookingIndexTest.php`.

---

## 1. Критические проблемы (Critical)

### 1.1. SQL-инъекция в `store` `[verified: reproduced]`

`app/Http/Controllers/BookingController.php:4-6`:

```php
$conflict = DB::select("SELECT id FROM bookings WHERE room_id = {$request->room_id}
  AND starts_at <= '{$request->ends_at}' AND ends_at >= '{$request->starts_at}'");
```

`room_id`, `starts_at`, `ends_at` интерполируются в SQL без параметризации.

**Воспроизведение.** В БД одна бронь в комнате 1. Запрос на **свободную**
комнату 2 с payload `starts_at: "2026-11-10T14:00:00+03:00' OR '1'='1"`:

```bash
curl -X POST /api/bookings \
  -d '{"room_id": 2, "user_id": 1, "title": "Injection",
       "starts_at": "2026-11-10T14:00:00+03:00'"'"' OR '"'"'1'"'"'='"'"'1",
       "ends_at": "2026-11-10T15:00:00+03:00"}'
```

**Результат:** `{"error": "Комната занята"}` (409), хотя комната 2 свободна.
SQL превращается в `(room_id = 2 AND ...) OR ('1'='1')` → всегда истинно.

**Fix:** параметризованный запрос / Query Builder:

```php
Booking::where('room_id', $roomId)
    ->where('starts_at', '<', $endsAt)
    ->where('ends_at', '>', $startsAt)
    ->exists();
```

### 1.2. Mass assignment `user_id` `[verified: reproduced]`

`BookingController.php:9` — `Booking::create($request->all())`. В моделях
`Booking`, `Room`, `Office`, `User` нет `$fillable`/`$guarded`.

**Воспроизведение.** Токен Анны (`id=1`), в теле `"user_id": 2`:

```bash
curl -X POST /api/bookings -H "Authorization: Bearer anna-token" \
  -d '{"room_id": 1, "user_id": 2, "title": "Plan", ...}'
```

**Результат:** `{"room_id": 1, "user_id": 2, ...}` — бронь создана от Бориса
по токену Анны.

**Fix:** явно `user_id = auth()->id()`; `$fillable` без `user_id`;
`$request->validated()` вместо `$request->all()`.

### 1.3. API требует `user_id` в теле запроса `[verified: reproduced]`

Контроллер не устанавливает `user_id` из `auth()->id()` — полагается на
`$request->all()`. Запрос **без** `user_id`:

```bash
curl -X POST /api/bookings -H "Authorization: Bearer anna-token" \
  -d '{"room_id": 4, "title": "NSK", ...}'
```

**Результат:** `SQLSTATE[23502] Not null violation: column "user_id"` → 500.
Корень тот же, что в 1.2: атрибуция отсутствует. Проявляется с другой стороны.

**Fix:** см. 1.2.

### 1.4. Пересечение «впритык» считается конфликтом `[verified: reproduced]`

`BookingController.php:4-6` — `starts_at <= ends_at AND ends_at >= starts_at`.
Нестрогие неравенства → касающиеся интервалы считаются пересечением.

**Воспроизведение.** Существующая бронь `10:00–11:00` в комнате 1, новая
`11:00–12:00` в той же комнате → **409 «Комната занята»**. По ТЗ — **201**.

**Fix:** строгие `<` / `>` (см. 1.1).

### 1.5. Часовой пояс офиса игнорируется `[verified: reproduced]`

`BookingController.php:7-8` — `Carbon::parse($request->starts_at)->hour < 9`.
Проверка **извлекает час из переданной строки (с её оффсетом), не конвертируя
его в часовой пояс офиса**.

**Воспроизведение.** Московская комната (id=1), `starts_at: "2026-11-10T10:00:00+07:00"`
(= **06:00 МСК** — вне рабочего времени):

```bash
curl -X POST /api/bookings -H "Authorization: Bearer anna-token" \
  -d '{"room_id": 1, "title": "TZ bug",
       "starts_at": "2026-11-10T10:00:00+07:00",
       "ends_at": "2026-11-10T11:00:00+07:00"}'
```

**Результат:** `201 Created`. `$start->hour = 10` (offset +07:00) → проверка
`9 ≤ 10 < 20` проходит, но в Москве это **06:00** — нерабочее время.

**Fix:** `Carbon::parse($request->starts_at)->setTimezone($room->office->timezone)`;
проверять **весь интервал** `[starts_at, ends_at]` на попадание в `[09:00, 20:00)`.

### 1.6. Нет проверки длительности и шага 15 минут `[verified: code]`

Требование ТЗ «15 минут – 4 часа, шаг 15 минут» в `store` **отсутствует
полностью**.

**Fix:** добавить в `StoreBookingRequest` (см. Roadmap).

---

## 2. Серьёзные проблемы (Major)

### 2.1. IDOR в `destroy` `[verified: reproduced]`

`BookingController.php:12-13` — `Booking::findOrFail($id)->delete()` без
проверки владельца. Middleware `auth:sanctum` есть — значит, это именно
**IDOR** (Insecure Direct Object Reference), а не отсутствие аутентификации.

**Воспроизведение.** Анна создаёт бронь (id=1), Борис удаляет её:

```bash
curl -X DELETE /api/bookings/1 -H "Authorization: Bearer boris-token"
```

**Результат:** `204 No Content` — чужая бронь удалена.

**Fix:** `abort_unless($booking->user_id === auth()->id(), 403)` или `BookingPolicy`.

### 2.2. Нет проверки `ends_at > starts_at` `[verified: code]`

Инвертированный интервал ломает `diffInMinutes` и SQL-проверку пересечения.
Это **не бизнес-ограничение на дату** (ТЗ явно разрешает «других ограничений
на даты нет»), а **условие существования интервала** (data validity): без
него модель «бронь» теряет смысл.

**Fix:** `'ends_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:starts_at']`
в FormRequest.

### 2.3. Race condition (TOCTOU) `[verified: code]`

Проверка пересечения и `INSERT` не атомарны. Два параллельных запроса
создадут пересекающиеся брони.

**Fix:** `EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at, ends_at) WITH &&)`
в PostgreSQL. Требует `btree_gist` и перевода колонок в `timestamptz`
(см. README).

### 2.4. `Log::withContext` теряет контекст `[verified: code]`

`BookingController.php:10-11`:

```php
Log::withContext(['booking_id' => $booking->id]);
Log::channel('bookings')->info('Booking created');
```

`withContext` устанавливает **глобальный** контекст на весь запрос, а не на
сообщение. При нескольких логах в одном запросе `booking_id` будет ложным.

**Fix:** передавать контекст вторым аргументом:

```php
Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);
```

### 2.5. `today()` игнорирует TZ офиса `[verified: code]`

`BookingController::today()` использует `today()` в TZ приложения, а не офиса
комнаты. Для новосибирских комнат «сегодня» может отличаться от московского
на границе суток. По ТЗ исправлять **не требуется** — отмечено для полноты.

### 2.6. Неструктурированные тела ошибок `[consensus]`

`{"error": "Комната занята"}` не соответствует формату `{message, errors}`.
Статус **409 для конфликта семантически корректен** — проблема именно в теле.
Правильно:

- для ошибок валидации полей (формат даты, `after:starts_at`, длительность) —
  **422** + `{message, errors: {field: [msg]}}`;
- для конфликта комнаты — **409** + `{message, errors: {...}}`;
- для чужой брони при удалении — **403** + `{message}`.

---

## 3. Незначительные проблемы (Minor)

- `orderByRaw('starts_at DESC')` без параметров `[verified: code]` — достаточно
  `orderBy('starts_at', 'desc')`.
- `BookingFactory` фиксирует `now()->addDay()->setTime(10, 0)` `[verified: code]`
  — детерминированные пересечения в тестах; нужны state-методы или параметры.
- Нет `FormRequest`, `BookingResource`, `BookingPolicy` `[verified: code]` —
  нарушение SRP, неявный контракт API, ручная авторизация.
- `timestamp` вместо `timestamptz` в миграции `bookings` `[verified: code]` —
  DST-переходы исказят данные.
- Нет составного индекса `(room_id, starts_at, ends_at)` `[verified: code]` —
  запрос пересечения будет seq scan.
- **Тестов на `store`/`destroy` нет** `[verified: code]` — единственный файл
  `BookingIndexTest.php`. Прямое невыполнение части 2 ТЗ.
- Отсутствие типизации возвращаемых значений методов контроллера `[verified: code]`.

---

## 4. Анализ архитектуры (SOLID / DDD)

### SOLID

- **S (SRP) — нарушен** `[verified: code]`: `store` делает 5 вещей (HTTP,
  валидация, бизнес-правила, персистентность, логирование, ответ).
- **O (OCP) — нарушен** `[verified: code]`: добавление правила требует правки
  контроллера.
- **L (LSP) — не применимо** `[consensus]`: наследование от `Controller` —
  конвенция Laravel, не проблема.
- **I (ISP) — не применимо** `[consensus]`: `Request $request` — не «толстый
  интерфейс» в терминах SOLID.
- **D (DIP) — не считается нарушением** `[consensus]`: фасады `DB::`, `Log::`
  резолвятся через Service Container и мокаются в тестах — стандарт Laravel.

### DDD

- Value Objects (`TimeSlot`, `WorkingHours`, `Duration`) — отсутствуют.
- Aggregate Root — нет; `Booking::create()` обходит модель, инварианты не защищены.
- Domain Events — нет; логирование ручное.
- Anemic Model — `Booking` содержит только `casts()` и связи.
- Command/Query — не разделены.
- Слои — один: `Controller → DB/Eloquent`. Полный рефакторинг в DDD —
  **вне объёма текущего ТЗ**.

### Laravel Best Practices

Из 16 ключевых практик нарушено **13**. Положительно `[verified: code]`:

- FK на `room_id` и `user_id` в миграции `bookings` — **есть**;
- канал логирования `bookings` — **определён** в `config/logging.php`;
- маршруты — **защищены** `auth:sanctum` (подтверждено тестом
  `BookingIndexTest::test_requires_authentication`, ожидает 401).

---

## 5. Подозрительно, но НЕ проблема `[verified: not a problem]`

- **Наследование от `Controller`** — конвенция Laravel для `Route::controller()`
  и middleware; убирать не нужно.
- **Фасады `DB::` / `Log::`** — стандарт фреймворка, не нарушение DIP. В
  частности, **прямое использование `Log::channel('bookings')`** в контроллере
  и в Action может выглядеть подозрительно («почему не вынести в сервис через
  DI?»). Это **не проблема**: фасад резолвится через Service Container, легко
  мокается в тестах (`Log::shouldReceive(...)`), а для трёх вызовов лога в
  одном файле обёртка была бы **преждевременной абстракцией**. Вынос в
  отдельный сервис был бы оправдан при 10+ местах с разными каналами и
  уровнями — здесь этого нет.
- **`Request $request`** — стандарт Laravel, не нарушение ISP.
- **`409 Conflict` для занятой комнаты** — семантически корректен; проблема
  в теле ответа, а не в статусе (см. 2.6).
- **Отсутствие проверки «не в прошлом»** — ТЗ явно говорит «других
  ограничений на даты нет».
- **Отсутствие `SoftDeletes`** — ТЗ не требует.
- **`today()` без правок** — по ТЗ исправлять не требуется (отмечено в 2.5
  для полноты).

---

## 6. План исправлений (Roadmap)

1. **Безопасность:** устранить SQLi (Query Builder); `user_id = auth()->id()`
   явно; `$fillable` во всех моделях; `$request->validated()`; `BookingPolicy`
   для `destroy`.
2. **Бизнес-логика:** `StoreBookingRequest` с правилами длительности (15–240 мин),
   шага (15 мин), TZ офиса, строгого непересечения, `after:starts_at`.
3. **Целостность БД:** миграция — `CREATE EXTENSION btree_gist`,
   `ALTER COLUMN ... TYPE timestamptz USING ... AT TIME ZONE 'UTC'`,
   `CHECK (ends_at > starts_at)`,
   `EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at, ends_at) WITH &&)`.
4. **Наблюдаемость и контракт:** исправить `Log::withContext` →
   `Log::channel('bookings')->info('Booking created', ['booking_id' => ...])`;
   `BookingResource` для ответов; `422` / `409` / `403` с телом `{message, errors}`.
5. **Тестирование:** Feature-тесты на все правила (пересечения, длительность,
   шаг, TZ обоих офисов, mass assignment, IDOR, race condition).
