<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Правило валидации: время брони кратно шагу 15 минут.
 *
 * По ТЗ встречи начинаются и заканчиваются на 15-минутной сетке
 * (10:00, 10:15, 10:30, ...). Секунды должны быть нулевыми.
 *
 * Сообщение: «Время должно быть кратно 15 минутам (секунды — нулевые).»
 */
final readonly class AlignedToBookingStep implements ValidationRule
{
    /**
     * Шаг сетки бронирования в минутах.
     */
    private const int STEP_MINUTES = 15;

    /**
     * @param  CarbonImmutable  $startsAt  Начало брони (абсолютный момент)
     * @param  CarbonImmutable  $endsAt  Конец брони (абсолютный момент)
     */
    public function __construct(
        private CarbonImmutable $startsAt,
        private CarbonImmutable $endsAt,
    ) {}

    /**
     * Проверить кратность 15 минутам.
     *
     * @param  string  $attribute  Имя атрибута (для совместимости с интерфейсом)
     * @param  mixed  $value  Значение атрибута (не используется, данные в конструкторе)
     * @param  Closure(string): void  $fail  Колбэк для регистрации ошибки
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->startsAt->minute % self::STEP_MINUTES !== 0
            || $this->endsAt->minute % self::STEP_MINUTES !== 0
            || $this->startsAt->second !== 0
            || $this->endsAt->second !== 0
        ) {
            $fail(sprintf(
                'Время должно быть кратно %d минутам (секунды — нулевые).',
                self::STEP_MINUTES,
            ));
        }
    }
}
