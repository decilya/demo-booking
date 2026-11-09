<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Модель офиса компании.
 *
 * Каждый офис имеет собственный часовой пояс (`timezone`), который
 * используется для проверки рабочего времени броней: 09:00–20:00 по
 * местному времени офиса. В сидере заданы два офиса: Москва
 * (Europe/Moscow) и Новосибирск (Asia/Novosibirsk).
 *
 * @property int $id Идентификатор офиса
 * @property string $name Название офиса
 * @property string $timezone Часовой пояс в формате IANA (например, Europe/Moscow)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Room> $rooms
 *     Комнаты этого офиса
 */
#[Fillable(['name', 'timezone'])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    /**
     * Комнаты этого офиса.
     *
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
