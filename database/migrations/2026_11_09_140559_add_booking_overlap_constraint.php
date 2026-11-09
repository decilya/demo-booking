<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Миграция: защита от пересечения броней на уровне схемы БД.
 *
 * Добавляет в таблицу `bookings` три инварианта:
 *
 * 1. `bookings_valid_interval` — CHECK-констрейнт, гарантирующий, что
 *    `ends_at > starts_at`. Без него инвертированный интервал превращается
 *    в пустой `tstzrange`, который не пересекается ни с чем и проскакивает
 *    мимо EXCLUDE-констрейнта.
 *
 * 2. `bookings_no_overlap` — EXCLUDE-констрейнт на пересечение временных
 *    диапазонов одной комнаты. Главная защита от race condition (TOCTOU):
 *    даже если два параллельных запроса прошли валидацию в приложении,
 *    БД отклонит второй `INSERT` с `SQLSTATE 23P01`.
 *
 * 3. Перевод колонок `starts_at` и `ends_at` из `timestamp` в `timestamptz`,
 *    чтобы корректно работать с часовыми поясами офисов (Europe/Moscow,
 *    Asia/Novosibirsk).
 *
 * ВНИМАНИЕ. Если в БД уже есть брони с `ends_at <= starts_at` (баг исходного
 * кода), применение миграции на существующей БД упадёт с
 * `violates check constraint "bookings_valid_interval"`. Сценарии:
 * - dev/CI: `php artisan migrate:fresh --seed` (данные будут потеряны);
 * - prod: предварительно вычистить некорректные строки или применить CHECK
 *   с `NOT VALID`, затем `VALIDATE CONSTRAINT` после очистки.
 *
 * Требования к окружению:
 * - PostgreSQL 12+ (EXCLUDE-констрейнты поддерживаются с 9.x, но `tstzrange`
 *   с `WITH &&` надёжнее на 12+).
 * - Расширение `btree_gist` (создаётся в `up()`).
 * - Пользователь БД должен иметь право на `CREATE EXTENSION` (в стандартном
 *   docker-compose это пользователь `postgres`).
 */
return new class extends Migration
{
    /**
     * Применить миграцию.
     *
     * Порядок операций критичен:
     * 1. `btree_gist` — без него `EXCLUDE ... room_id WITH =` не создастся
     *    (для integer нет операторного класса gist по умолчанию).
     * 2. `timestamptz` — `tstzrange` требует колонки с TZ.
     * 3. `CHECK (ends_at > starts_at)` — до `EXCLUDE`, чтобы инвертированные
     *    интервалы ловились более точной ошибкой `23514`, а не размытой `23P01`.
     * 4. `EXCLUDE` — последним, требует всё остальное.
     *
     * @return void
     */
    public function up(): void
    {
        // btree_gist обязателен для EXCLUDE по integer-колонке (room_id).
        // Без него: "data type integer has no default operator class for access method gist".
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // timestamp → timestamptz.
        // USING ... AT TIME ZONE 'UTC' гарантирует трактовку существующих значений
        // как UTC, а не TZ текущей сессии PostgreSQL (иначе данные "съедут"
        // при смене TimeZone).
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                ALTER COLUMN starts_at TYPE timestamptz USING starts_at AT TIME ZONE 'UTC',
                ALTER COLUMN ends_at   TYPE timestamptz USING ends_at   AT TIME ZONE 'UTC'
        SQL);

        // CHECK обязателен: инвертированный интервал = empty tstzrange,
        // который не пересекается ни с чем (empty && anything = false),
        // и EXCLUDE пропустит такую запись. Ловим раньше EXCLUDE.
        DB::statement('ALTER TABLE bookings
            ADD CONSTRAINT bookings_valid_interval
            CHECK (ends_at > starts_at)');

        // Главная защита от гонок — инвариант на уровне схемы.
        DB::statement('ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                room_id WITH =,
                tstzrange(starts_at, ends_at) WITH &&
            )');

        // Составной индекс для запроса в NoOverlappingBooking:
        // WHERE room_id = ? AND starts_at < ? AND ends_at > ?
        // GiST от EXCLUDE покрывает room_id + tstzrange, но btree
        // (room_id, starts_at, ends_at) — страховка для планировщика
        // на случай, если GiST окажется неоптимальным.
        DB::statement('CREATE INDEX bookings_room_time_idx
            ON bookings (room_id, starts_at, ends_at)');
    }

    /**
     * Откатить миграцию.
     *
     * Удаляет констрейнты и возвращает колонки к `timestamp without time zone`.
     * Расширение `btree_gist` НЕ удаляется: оно может использоваться другими
     * таблицами в этой же БД. Если уверены, что оно больше не нужно —
     * раскомментируйте `DROP EXTENSION` в конце.
     *
     * @return void
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bookings_room_time_idx');

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_valid_interval');

        // Обратная конвертация timestamptz → timestamp.
        // AT TIME ZONE 'UTC' возвращает наивное время в UTC.
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                ALTER COLUMN starts_at TYPE timestamp USING starts_at AT TIME ZONE 'UTC',
                ALTER COLUMN ends_at   TYPE timestamp USING ends_at   AT TIME ZONE 'UTC'
        SQL);

        // Раскомментируйте, если уверены, что btree_gist больше не нужен:
        // DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
