<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature-тесты на защиту от race condition на уровне схемы БД.
 *
 * Проверяют, что даже при обходе `FormRequest` (прямой `INSERT` в БД)
 * инварианты целостности срабатывают:
 *
 * - `EXCLUDE bookings_no_overlap` — запрещает пересечение интервалов
 *   одной комнаты (`SQLSTATE 23P01`).
 * - `CHECK bookings_valid_interval` — запрещает инвертированный интервал
 *   `ends_at <= starts_at` (`SQLSTATE 23514`).
 *
 * ПОЧЕМУ `DB::table()->insert()`, А НЕ `Booking::create()`.
 *
 * Цель этих тестов — проверить, что защита живёт в СХЕМЕ БД, а не в
 * Eloquent-модели. Если бы мы использовали `Booking::create()`, то
 * прошли бы model events (`creating`, `saved`), casts (`datetime`),
 * `#[Fillable]` — и тест проверял бы Eloquent, а не констрейнты.
 * `DB::table()->insert()` намеренно обходит всё приложение и эмулирует
 * худший сценарий: «что-то вклинилось в обход приложения (второй сервис,
 * psql-сессия, скрипт миграции данных) и пытается записать некорректный
 * интервал напрямую». Именно для этого сценария нужны констрейнты в БД.
 *
 * В обычном коде (`CreateBookingAction`, `NoOverlappingBooking`, контроллер)
 * используется Eloquent (`$booking->save()`, `Booking::query()`).
 * `DB::table` применяется ТОЛЬКО в миграциях и в этих двух тестах.
 *
 * ВАЖНО ПРО ДАТЫ. Значения передаются как `CarbonImmutable`, а НЕ как
 * сырые строки. Причина: PostgreSQL-грамматика Laravel сериализует
 * `DateTimeInterface` через `->format('Y-m-d H:i:s')` — оффсет теряется,
 * и `10:00+03` сохраняется как `10:00 UTC`. Если передать
 * `'2026-11-10 10:30:00+03'` сырой строкой, PostgreSQL применит оффсет
 * и сохранит `07:30 UTC` — интервалы перестанут пересекаться, EXCLUDE
 * не сработает. Передача через `CarbonImmutable` в оба места выравнивает
 * поведение с factory и делает тест детерминированным.
 *
 * ВАЖНО ПРО ТРАНЗАКЦИИ. Каждый `INSERT` обёрнут в `DB::transaction`,
 * чтобы при падении откатывался только savepoint, а внешняя транзакция
 * `RefreshDatabase` оставалась живой. Без этого PostgreSQL помечает её
 * как aborted (`SQLSTATE 25P02`), и тест не сможет прочитать БД после
 * исключения.
 */
final class BookingRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Создать комнату и пользователя для прямого INSERT в БД.
     *
     * @return array{0: int, 1: int} Кортеж [roomId, userId]
     */
    private function seedRoomAndUser(): array
    {
        $user = User::factory()->create();
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);
        $room = Room::factory()->for($office)->create();

        return [$room->id, $user->id];
    }

    /**
     * EXCLUDE-констрейнт блокирует пересекающийся INSERT в обход FormRequest.
     *
     * Сценарий:
     * 1. В БД есть бронь 10:00–11:00 (создана через Eloquent, как в проде).
     * 2. Кто-то пытается вставить 10:30–11:30 НАПРЯМУЮ через `DB::table`,
     *    минуя `StoreBookingRequest` и `NoOverlappingBooking`.
     * 3. PostgreSQL должен отклонить `INSERT` с `SQLSTATE 23P01`
     *    (exclusion violation), счётчик броней не должен увеличиться.
     *
     * Если бы мы использовали `Booking::create()` вместо `DB::table()->insert()`:
     * - сработал бы `#[Fillable]` и другие правила модели;
     * - тест проверял бы модель, а не констрейнт БД;
     * - при добавлении `creating`-event тест мог бы сломаться не по той причине.
     *
     * @return void
     */
    public function test_exclude_constraint_prevents_overlapping_insert(): void
    {
        [$roomId, $userId] = $this->seedRoomAndUser();

        // Существующая бронь — через Eloquent, как в реальном коде.
        Booking::factory()->create([
            'room_id' => $roomId,
            'user_id' => $userId,
            'starts_at' => CarbonImmutable::parse('2026-11-10 10:00:00+03:00'),
            'ends_at' => CarbonImmutable::parse('2026-11-10 11:00:00+03:00'),
        ]);

        $caught = null;

        try {
            // Намеренный обход Eloquent: `DB::table()->insert()` —
            // эмуляция «внешнего писателя», который не знает про модель.
            // Обёртка в транзакцию — для savepoint (см. docblock класса).
            DB::transaction(function () use ($roomId, $userId): void {
                DB::table('bookings')->insert([
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    'title' => 'Race',
                    'starts_at' => CarbonImmutable::parse('2026-11-10 10:30:00+03:00'),
                    'ends_at' => CarbonImmutable::parse('2026-11-10 11:30:00+03:00'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(
            QueryException::class,
            $caught,
            'Ожидалось, что EXCLUDE-констрейнт отклонит пересекающийся INSERT.',
        );
        $this->assertSame(
            '23P01',
            $caught->errorInfo[0] ?? null,
            'Ожидался SQLSTATE 23P01 (exclusion violation).',
        );
        $this->assertSame(1, Booking::query()->count());
    }

    /**
     * CHECK-констрейнт блокирует инвертированный интервал (`ends_at <= starts_at`).
     *
     * Сценарий:
     * 1. Кто-то пытается вставить бронь, где `ends_at < starts_at`
     *    (например, 12:00–11:00), напрямую через `DB::table`.
     * 2. PostgreSQL должен отклонить `INSERT` с `SQLSTATE 23514`
     *    (check violation), записей в БД не остаётся.
     *
     * Почему это важно отдельным тестом:
     * `tstzrange('12:00', '11:00')` — пустой диапазон, который НЕ
     * пересекается ни с чем (empty && anything = false). Значит, EXCLUDE
     * такую запись пропустит. Единственный рубеж для инвертированного
     * интервала — именно CHECK. Без него в БД попала бы запись, которая
     * ломает все последующие расчёты длительности и пересечений.
     *
     * @return void
     */
    public function test_check_constraint_prevents_inverted_interval(): void
    {
        [$roomId, $userId] = $this->seedRoomAndUser();

        $caught = null;

        try {
            // Намеренный обход Eloquent — эмуляция внешнего писателя
            // с некорректными данными. Обёртка в транзакцию — для savepoint.
            DB::transaction(function () use ($roomId, $userId): void {
                DB::table('bookings')->insert([
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    'title' => 'Inverted',
                    'starts_at' => CarbonImmutable::parse('2026-11-10 12:00:00+03:00'),
                    'ends_at' => CarbonImmutable::parse('2026-11-10 11:00:00+03:00'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(
            QueryException::class,
            $caught,
            'Ожидалось, что CHECK-констрейнт отклонит инвертированный интервал.',
        );
        $this->assertSame(
            '23514',
            $caught->errorInfo[0] ?? null,
            'Ожидался SQLSTATE 23514 (check violation).',
        );
        $this->assertSame(0, Booking::query()->count());
    }
}
