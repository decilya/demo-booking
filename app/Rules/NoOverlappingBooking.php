<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Правило валидации: новая бронь не пересекается с существующими.
 *
 * Проверка идёт по строгим неравенствам (`<` / `>`), поэтому встречи
 * «впритык» разрешены: 10:00–11:00 и 11:00–12:00 не считаются
 * пересечением. Именно это требует ТЗ.
 *
 * Сообщение: «Комната уже занята на выбранное время.»
 *
 * Это soft-check для UX. Жёсткая гарантия от race condition —
 * EXCLUDE-констрейнт `bookings_no_overlap` в БД (см. миграцию).
 */
final readonly class NoOverlappingBooking implements ValidationRule
{
    /**
     * @param  int  $roomId  Идентификатор комнаты
     * @param  CarbonImmutable  $startsAt  Начало новой брони (абсолютный момент)
     * @param  CarbonImmutable  $endsAt  Конец новой брони (абсолютный момент)
     */
    public function __construct(
        private int $roomId,
        private CarbonImmutable $startsAt,
        private CarbonImmutable $endsAt,
    ) {}

    /**
     * Проверить отсутствие пересечений.
     *
     * @param  string  $attribute  Имя атрибута (для совместимости с интерфейсом)
     * @param  mixed  $value  Значение атрибута (не используется, данные в конструкторе)
     * @param  Closure(string): void  $fail  Колбэк для регистрации ошибки
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hasConflict = Booking::query()
            ->where('room_id', $this->roomId)
            ->where('starts_at', '<', $this->endsAt)
            ->where('ends_at', '>', $this->startsAt)
            ->exists();

        if ($hasConflict) {
            $fail('Комната уже занята на выбранное время.');
        }
    }
}
