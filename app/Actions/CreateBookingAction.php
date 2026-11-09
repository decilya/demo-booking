<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTO\CreateBookingData;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Действие: создать бронь переговорной комнаты.
 *
 * Инкапсулирует бизнес-логику создания брони, отделяя её от HTTP-слоя:
 *
 * 1. Собирает модель из DTO (явное присвоение полей — защита от mass assignment).
 * 2. Сохраняет в транзакции через `DB::transaction(fn () => $booking->save())`.
 *    Обёртка вокруг `save()` нужна для savepoint в тестах с RefreshDatabase:
 *    при падении откатывается только savepoint, внешняя транзакция остаётся
 *    живой, и тест может дальше читать БД.
 * 3. Транслирует нарушения констрейнтов PostgreSQL в доменные исключения:
 *    - `SQLSTATE 23P01` (exclusion) → `BookingConflictException` (HTTP 409).
 *    - `SQLSTATE 23514` (check) → `ValidationException` (HTTP 422) + лог
 *      уровня error: это баг валидации, а не race condition.
 * 4. Логирует успешное создание в канал `bookings` с `booking_id` в контексте.
 */
final class CreateBookingAction
{
    /**
     * Выполнить создание брони.
     *
     * @param  CreateBookingData  $data  Валидированные данные из FormRequest
     * @return Booking Созданная модель (с заполненным `id`, `created_at`, `updated_at`)
     *
     * @throws BookingConflictException Если слот занят параллельным запросом (race)
     * @throws ValidationException Если нарушен CHECK (баг валидации)
     */
    public function execute(CreateBookingData $data): Booking
    {
        $booking = $this->buildBooking($data);

        try {
            DB::transaction(function () use ($booking): void {
                $booking->save();
            });
        } catch (QueryException $exception) {
            throw $this->translateDatabaseFailure($exception);
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
        ]);

        return $booking;
    }

    /**
     * Собрать модель брони из DTO.
     *
     * Поля присваиваются явно: `user_id` берётся из `$data->userId`
     * (который заполнен из `auth()->id()` в FormRequest), а не из тела
     * запроса. Это исключает mass assignment даже при наличии `#[Fillable]`.
     *
     * @param  CreateBookingData  $data
     * @return Booking Несохранённая модель
     */
    private function buildBooking(CreateBookingData $data): Booking
    {
        $booking = new Booking;
        $booking->room_id = $data->roomId;
        $booking->user_id = $data->userId;
        $booking->title = $data->title;
        $booking->starts_at = $data->startsAt;
        $booking->ends_at = $data->endsAt;

        return $booking;
    }

    /**
     * Транслировать `QueryException` в доменное исключение.
     *
     * `errorInfo[0]` надёжнее `getCode()`: PDO для некоторых драйверов
     * возвращает `HY000` вместо SQLSTATE.
     *
     * @param  QueryException  $exception
     * @return \Throwable Исключение для проброса наружу
     */
    private function translateDatabaseFailure(QueryException $exception): \Throwable
    {
        $sqlstate = $exception->errorInfo[0] ?? null;

        if ($sqlstate === '23P01') {
            return new BookingConflictException('Комната уже занята на выбранное время.');
        }

        if ($sqlstate === '23514') {
            Log::channel('bookings')->error('CHECK violation on bookings — validation bypassed', [
                'exception' => $exception->getMessage(),
                'sqlstate' => $sqlstate,
            ]);

            return ValidationException::withMessages([
                'ends_at' => 'Некорректный интервал брони.',
            ]);
        }

        return $exception;
    }
}
