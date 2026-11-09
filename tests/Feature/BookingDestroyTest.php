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
 * Feature-тесты на отмену брони (DELETE /api/bookings/{id}).
 *
 * Проверяют правило ТЗ: отменить бронь может только её автор (защита от
 * IDOR), а также корректные HTTP-статусы: 204 / 403 / 404.
 */
final class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Автор удаляет свою бронь → 204, запись исчезает из БД.
     *
     * @return void
     */
    public function test_author_can_cancel_own_booking(): void
    {
        $anna = User::factory()->create();
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);
        $room = Room::factory()->for($office)->create();

        $booking = Booking::factory()->create([
            'room_id' => $room->id,
            'user_id' => $anna->id,
            'starts_at' => '2026-11-10 10:00:00+03',
            'ends_at' => '2026-11-10 11:00:00+03',
        ]);

        Sanctum::actingAs($anna);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    /**
     * Чужой пользователь пытается удалить бронь → 403, запись остаётся.
     * Регрессионный тест на исходный IDOR.
     *
     * @return void
     */
    public function test_non_author_cannot_cancel_foreign_booking(): void
    {
        $anna = User::factory()->create();
        $boris = User::factory()->create();
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);
        $room = Room::factory()->for($office)->create();

        $booking = Booking::factory()->create([
            'room_id' => $room->id,
            'user_id' => $anna->id,
            'starts_at' => '2026-11-10 10:00:00+03',
            'ends_at' => '2026-11-10 11:00:00+03',
        ]);

        Sanctum::actingAs($boris);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    /**
     * Несуществующий `id` → 404.
     *
     * @return void
     */
    public function test_returns_404_for_unknown_booking(): void
    {
        $anna = User::factory()->create();
        Sanctum::actingAs($anna);

        $this->deleteJson('/api/bookings/99999')
            ->assertNotFound();
    }

    /**
     * После отмены слот освобождается — можно создать новую бронь на то же время.
     *
     * @return void
     */
    public function test_after_cancellation_slot_is_free(): void
    {
        $anna = User::factory()->create();
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);
        $room = Room::factory()->for($office)->create();

        $booking = Booking::factory()->create([
            'room_id' => $room->id,
            'user_id' => $anna->id,
            'starts_at' => '2026-11-10 10:00:00+03',
            'ends_at' => '2026-11-10 11:00:00+03',
        ]);

        Sanctum::actingAs($anna);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Повторно',
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ])->assertCreated();
    }
}
