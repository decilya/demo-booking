<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Правило валидации: длительность брони в допустимом диапазоне.
 *
 * По ТЗ длительность встречи — от 15 минут до 4 часов (240 минут).
 * Проверяется разница между `$endsAt` и `$startsAt` в минутах.
 *
 * Сообщения:
 * - «Длительность брони должна быть не меньше 15 минут.»
 * - «Длительность брони должна быть не больше 4 часов.»
 */
final readonly class BookingDurationInRange implements ValidationRule
{
    /**
     * Минимальная длительность брони в минутах.
     */
    private const int MIN_MINUTES = 15;

    /**
     * Максимальная длительность брони в минутах.
     */
    private const int MAX_MINUTES = 240;

    /**
     * @param  CarbonImmutable  $startsAt  Начало брони (абсолютный момент)
     * @param  CarbonImmutable  $endsAt  Конец брони (абсолютный момент)
     */
    public function __construct(
        private CarbonImmutable $startsAt,
        private CarbonImmutable $endsAt,
    ) {}

    /**
     * Проверить длительность.
     *
     * @param  string  $attribute  Имя атрибута (для совместимости с интерфейсом)
     * @param  mixed  $value  Значение атрибута (не используется, данные в конструкторе)
     * @param  Closure(string): void  $fail  Колбэк для регистрации ошибки
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $durationMinutes = $this->startsAt->diffInMinutes($this->endsAt);

        if ($durationMinutes < self::MIN_MINUTES) {
            $fail(sprintf(
                'Длительность брони должна быть не меньше %d минут.',
                self::MIN_MINUTES,
            ));

            return;
        }

        if ($durationMinutes > self::MAX_MINUTES) {
            $fail(sprintf(
                'Длительность брони должна быть не больше %d часов.',
                intdiv(self::MAX_MINUTES, 60),
            ));
        }
    }
}
