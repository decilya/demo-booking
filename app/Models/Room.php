<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Модель переговорной комнаты.
 *
 * Принадлежит офису; через `office` доступен часовой пояс (`timezone`),
 * который используется для проверки рабочего времени 09:00–20:00 по
 * местному времени офиса.
 *
 * @property int $id Идентификатор комнаты
 * @property int $office_id Идентификатор офиса
 * @property string $name Название комнаты (например, «Арбат», «Обь»)
 * @property int $capacity Вместимость (число мест)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Office $office Офис, в котором находится комната
 * @property-read Collection<int, Booking> $bookings
 *     Брони этой комнаты
 */
#[Fillable(['office_id', 'name', 'capacity'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * Офис, в котором находится комната.
     *
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Брони этой комнаты.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
