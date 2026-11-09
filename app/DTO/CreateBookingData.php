<?php

declare(strict_types=1);

namespace App\DTO;

use Carbon\CarbonImmutable;

/**
 * DTO входных данных для создания брони.
 *
 * Передаётся из `StoreBookingRequest::toData()` в `CreateBookingAction`.
 * Иммутабельный: поля только для чтения, `readonly` гарантирует
 * невозможность подмены после валидации.
 */
final readonly class CreateBookingData
{
    /**
     * @param  int  $userId  Идентификатор автора (из `auth()->id()`)
     * @param  int  $roomId  Идентификатор комнаты
     * @param  string  $title  Название встречи
     * @param  CarbonImmutable  $startsAt  Начало (ISO 8601 с оффсетом, уже распарсено)
     * @param  CarbonImmutable  $endsAt  Конец (ISO 8601 с оффсетом, уже распарсено)
     */
    public function __construct(
        public int $userId,
        public int $roomId,
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}
}
