<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Модель брони переговорной комнаты.
 *
 * Представляет факт бронирования: комната + автор + временной интервал.
 *
 * Инварианты целостности (нет пересечений, `ends_at > starts_at`) обеспечиваются
 * на уровне схемы БД: EXCLUDE-констрейнт `bookings_no_overlap` и CHECK
 * `bookings_valid_interval` (см. миграцию `add_booking_overlap_constraint`).
 * Модель намеренно не дублирует эти проверки — единственный источник правды
 * по инвариантам это схема БД.
 *
 * @property int $id Идентификатор брони
 * @property int $room_id Идентификатор комнаты
 * @property int $user_id Идентификатор автора брони
 * @property string $title Название встречи
 * @property Carbon $starts_at Начало интервала
 * @property Carbon $ends_at Конец интервала
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Room $room Комната, к которой относится бронь
 * @property-read User $user Автор брони
 */
#[Fillable(['room_id', 'starts_at', 'ends_at', 'title'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * Приведение типов атрибутов.
     *
     * `starts_at` и `ends_at` хранятся в БД как `timestamptz` и при чтении
     * приводятся к `Carbon` с сохранением абсолютного момента времени.
     * Локальный час (для проверки рабочего времени) вычисляется отдельно
     * через `setTimezone($room->office->timezone)`.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Комната, к которой относится бронь.
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Автор брони.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
