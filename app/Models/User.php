<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Модель пользователя (сотрудника компании).
 *
 * Аутентификация через Sanctum (`HasApiTokens`). Пользователь может
 * создавать и отменять брони переговорных комнат; отменять чужую бронь
 * нельзя (проверяется в BookingPolicy).
 *
 * @property int $id Идентификатор пользователя
 * @property string $name Имя
 * @property string $email Email (уникальный)
 * @property Carbon|null $email_verified_at
 * @property string $password Хэш пароля (bcrypt/argon)
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Booking> $bookings
 *     Брони, автором которых является пользователь
 * @property-read Collection<int, PersonalAccessToken> $tokens
 *     Sanctum-токены пользователя
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Приведение типов атрибутов.
     *
     * `password` кастуется в `hashed`: при установке значения Laravel
     * автоматически хэширует пароль.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Брони, автором которых является пользователь.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
