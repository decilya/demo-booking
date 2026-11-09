<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Исключение: конфликт бронирования (race condition через EXCLUDE-констрейнт).
 *
 * Бросается из `CreateBookingAction`, когда PostgreSQL отклоняет `INSERT`
 * с `SQLSTATE 23P01` (exclusion violation) — значит, параллельный запрос
 * уже занял слот, а наш запрос прошёл soft-check в `FormRequest` до того,
 * как параллельный успел вставить бронь.
 *
 * Метод `render()` автоматически вызывается Laravel при пробросе
 * исключения из контроллера, превращая его в HTTP 409 с телом
 * `{message, errors}`, консистентным с ошибками валидации (422).
 */
final class BookingConflictException extends RuntimeException
{
    /**
     * Преобразовать исключение в HTTP-ответ.
     *
     * @param  Request  $request  Текущий запрос (не используется, но требуется сигнатурой)
     * @return JsonResponse Ответ 409 Conflict в формате {message, errors}
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'starts_at' => [$this->getMessage()],
            ],
        ], Response::HTTP_CONFLICT);
    }
}
