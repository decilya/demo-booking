<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Правило валидации: бронь целиком укладывается в рабочее время офиса.
 *
 * Рабочее время — 09:00–20:00 по местному времени офиса. Интервал
 * `[starts_at, ends_at]` конвертируется в TZ офиса и проверяется:
 * начало не раньше 09:00, конец не позже 20:00. Проверяется весь
 * интервал, а не только час начала.
 *
 * Сообщение: «Бронь должна укладываться в рабочее время 09:00–20:00
 * по местному времени офиса.»
 */
final readonly class WithinOfficeWorkingHours implements ValidationRule
{
    /**
     * Начало рабочего дня (час, локальное время офиса).
     */
    private const int WORK_START_HOUR = 9;

    /**
     * Конец рабочего дня (час, локальное время офиса).
     */
    private const int WORK_END_HOUR = 20;

    /**
     * @param  string  $officeTimezone  Часовой пояс офиса (IANA, e.g. Europe/Moscow)
     * @param  CarbonImmutable  $startsAt  Начало брони (абсолютный момент)
     * @param  CarbonImmutable  $endsAt  Конец брони (абсолютный момент)
     */
    public function __construct(
        private string $officeTimezone,
        private CarbonImmutable $startsAt,
        private CarbonImmutable $endsAt,
    ) {}

    /**
     * Проверить попадание в рабочее время.
     *
     * @param  string  $attribute  Имя атрибута (для совместимости с интерфейсом)
     * @param  mixed  $value  Значение атрибута (не используется, данные в конструкторе)
     * @param  Closure(string): void  $fail  Колбэк для регистрации ошибки
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $startLocal = $this->startsAt->setTimezone($this->officeTimezone);
        $endLocal = $this->endsAt->setTimezone($this->officeTimezone);

        $workStart = $startLocal->setTime(self::WORK_START_HOUR, 0);
        $workEnd = $startLocal->setTime(self::WORK_END_HOUR, 0);

        if ($startLocal->lt($workStart) || $endLocal->gt($workEnd)) {
            $fail(sprintf(
                'Бронь должна укладываться в рабочее время %02d:00–%02d:00 по местному времени офиса.',
                self::WORK_START_HOUR,
                self::WORK_END_HOUR,
            ));
        }
    }
}
