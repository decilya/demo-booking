<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature-тесты на создание брони (POST /api/bookings).
 *
 * Покрывают все бизнес-правила ТЗ:
 * - пересечение (включая «впритык»),
 * - длительность 15–240 минут,
 * - шаг 15 минут,
 * - рабочее время 09:00–20:00 по TZ офиса,
 * - mass assignment (`user_id` из тела игнорируется),
 * - валидация полей (422) и аутентификация (401).
 */
final class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Создать комнату в московском офисе (Europe/Moscow).
     *
     * @return Room Комната с офисом в TZ Europe/Moscow
     */
    private function createMoscowRoom(): Room
    {
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);

        return Room::factory()->for($office)->create();
    }

    /**
     * Создать комнату в новосибирском офисе (Asia/Novosibirsk).
     *
     * @return Room Комната с офисом в TZ Asia/Novosibirsk
     */
    private function createNovosibirskRoom(): Room
    {
        $office = Office::factory()->novosibirsk()->create();

        return Room::factory()->for($office)->create();
    }

    /**
     * Собрать payload для POST /api/bookings.
     *
     * @param  int  $roomId  Идентификатор комнаты
     * @param  string  $startsAt  Начало в ISO 8601 с оффсетом
     * @param  string  $endsAt  Конец в ISO 8601 с оффсетом
     * @param  array<string, mixed>  $overrides  Дополнительные/переопределяющие поля
     * @return array<string, mixed> Тело запроса
     */
    private function payload(int $roomId, string $startsAt, string $endsAt, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $roomId,
            'title' => 'Созвон',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ], $overrides);
    }

    /**
     * Успешное создание брони → 201 + корректные `room_id` / `user_id`.
     *
     * @return void
     */
    public function test_creates_booking(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T11:00:00+03:00',
        ))
            ->assertCreated()
            ->assertJsonPath('data.room_id', $room->id);

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Пересечение с существующей бронью → 422.
     *
     * @return void
     */
    public function test_rejects_overlapping_booking(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Booking::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-10 10:00:00+03',
            'ends_at' => '2026-11-10 11:00:00+03',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:30:00+03:00',
            '2026-11-10T11:30:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * «Впритык» после существующей брони (11:00 после 10:00–11:00) → 201.
     *
     * @return void
     */
    public function test_allows_back_to_back_booking_after_existing(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Booking::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-10 10:00:00+03',
            'ends_at' => '2026-11-10 11:00:00+03',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T11:00:00+03:00',
            '2026-11-10T12:00:00+03:00',
        ))->assertCreated();
    }

    /**
     * «Впритык» перед существующей бронью (10:00 до 10:00–11:00) → 201.
     *
     * @return void
     */
    public function test_allows_back_to_back_booking_before_existing(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Booking::factory()->create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-10 10:00:00+03',
            'ends_at' => '2026-11-10 11:00:00+03',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T09:00:00+03:00',
            '2026-11-10T10:00:00+03:00',
        ))->assertCreated();
    }

    /**
     * Длительность меньше 15 минут → 422.
     *
     * @return void
     */
    public function test_rejects_duration_shorter_than_15_minutes(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T10:10:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    /**
     * Длительность больше 4 часов → 422.
     *
     * @return void
     */
    public function test_rejects_duration_longer_than_4_hours(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T15:00:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    /**
     * Ровно 15 минут (нижняя граница) → 201.
     *
     * @return void
     */
    public function test_accepts_exactly_15_minutes(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T10:15:00+03:00',
        ))->assertCreated();
    }

    /**
     * Ровно 4 часа (верхняя граница) → 201.
     *
     * @return void
     */
    public function test_accepts_exactly_4_hours(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T14:00:00+03:00',
        ))->assertCreated();
    }

    /**
     * Время не кратно 15 минутам → 422.
     *
     * @return void
     */
    public function test_rejects_time_not_aligned_to_15_minutes(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:07:00+03:00',
            '2026-11-10T11:07:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * Начало раньше 09:00 МСК → 422.
     *
     * @return void
     */
    public function test_rejects_start_before_working_hours(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T08:45:00+03:00',
            '2026-11-10T10:00:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * Конец позже 20:00 МСК → 422.
     *
     * @return void
     */
    public function test_rejects_end_after_working_hours(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T19:00:00+03:00',
            '2026-11-10T21:00:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * Граница ровно 09:00 МСК → 201.
     *
     * @return void
     */
    public function test_accepts_boundary_at_09_00(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T09:00:00+03:00',
            '2026-11-10T10:00:00+03:00',
        ))->assertCreated();
    }

    /**
     * Граница ровно 20:00 МСК → 201.
     *
     * @return void
     */
    public function test_accepts_boundary_at_20_00(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T19:00:00+03:00',
            '2026-11-10T20:00:00+03:00',
        ))->assertCreated();
    }

    /**
     * Новосибирская комната, 10:00 НСК (= 06:00 МСК) → 201.
     * Проверяется TZ офиса, а не TZ сервера.
     *
     * @return void
     */
    public function test_accepts_novosibirsk_room_local_time(): void
    {
        $user = User::factory()->create();
        $room = $this->createNovosibirskRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+07:00',
            '2026-11-10T11:00:00+07:00',
        ))->assertCreated();
    }

    /**
     * Московская комната, время передано в TZ Новосибирска (10:00 НСК = 06:00 МСК) → 422.
     * Регрессионный тест на исходный баг «TZ из строки, а не офиса».
     *
     * @return void
     */
    public function test_rejects_moscow_room_given_time_in_novosibirsk_timezone(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+07:00',
            '2026-11-10T11:00:00+07:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * Новосибирская комната, время передано в TZ Москвы (19:00 МСК = 23:00 НСК) → 422.
     * Регрессионный тест: конец выходит за 20:00 НСК.
     *
     * @return void
     */
    public function test_rejects_novosibirsk_room_given_time_in_moscow_timezone(): void
    {
        $user = User::factory()->create();
        $room = $this->createNovosibirskRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T19:00:00+03:00',
            '2026-11-10T20:00:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * Mass assignment: `user_id` в теле игнорируется, автор берётся из `auth()`.
     * Регрессионный тест на исходный баг «create($request->all())».
     *
     * @return void
     */
    public function test_ignores_user_id_from_body(): void
    {
        $anna = User::factory()->create();
        $boris = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($anna);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T11:00:00+03:00',
            ['user_id' => $boris->id],
        ))->assertCreated();

        // Ищем строго созданную бронь: по комнате. `firstOrFail()` без
        // условия мог бы вернуть другую запись, если в тесте появятся
        // дополнительные брони — тест проверял бы не то.
        $booking = Booking::query()
            ->where('room_id', $room->id)
            ->firstOrFail();

        $this->assertSame($anna->id, $booking->user_id);
    }

    /**
     * Несуществующий `room_id` → 422.
     *
     * @return void
     */
    public function test_rejects_unknown_room_id(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            99999,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T11:00:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');
    }

    /**
     * Отсутствует обязательное поле `title` → 422.
     *
     * @return void
     */
    public function test_rejects_missing_title(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    /**
     * `ends_at < starts_at` → 422 (правило `after:starts_at`).
     *
     * @return void
     */
    public function test_rejects_ends_at_before_starts_at(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T12:00:00+03:00',
            '2026-11-10T11:00:00+03:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    /**
     * Дата без оффсета (`2026-11-10T10:00:00`) → 422.
     * Требование `date_format:Y-m-d\TH:i:sP` — оффсет обязателен.
     *
     * @return void
     */
    public function test_rejects_datetime_without_timezone_offset(): void
    {
        $user = User::factory()->create();
        $room = $this->createMoscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00',
            '2026-11-10T11:00:00',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);
    }

    /**
     * Без токена → 401.
     *
     * @return void
     */
    public function test_requires_authentication(): void
    {
        $room = $this->createMoscowRoom();

        $this->postJson('/api/bookings', $this->payload(
            $room->id,
            '2026-11-10T10:00:00+03:00',
            '2026-11-10T11:00:00+03:00',
        ))->assertUnauthorized();
    }
}
