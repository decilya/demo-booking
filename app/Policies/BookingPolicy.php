<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Политика авторизации действий над бронью.
 *
 * По ТЗ отменить бронь может только её автор. Проверка IDOR (Insecure
 * Direct Object Reference): пользователь не может удалить чужую бронь,
 * даже зная её `id`.
 *
 * Политика обнаруживается автоматически по конвенции `App\Policies\BookingPolicy`
 * для `App\Models\Booking` — регистрация в `AppServiceProvider` не требуется.
 */
final class BookingPolicy
{
    /**
     * Может ли пользователь удалить бронь.
     *
     * @param  User  $user  Аутентифицированный пользователь
     * @param  Booking  $booking  Бронь, которую пытаются удалить
     * @return bool true — если пользователь автор брони
     */
    public function delete(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }
}
