<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API-ресурс: представление брони в JSON.
 *
 * Единый контракт ответа для `store` (201 Created). `index` и `today`
 * по ТЗ не трогаем — они остаются как были, но в будущем могут быть
 * переведены на этот же ресурс для консистентности.
 *
 * Даты сериализуются в ISO 8601 с оффсетом (`toIso8601String()`),
 * чтобы клиент получал время в том же формате, в котором передавал,
 * и мог однозначно распарсить его обратно.
 *
 * @mixin Booking
 */
final class BookingResource extends JsonResource
{
    /**
     * Преобразовать модель в массив для JSON-ответа.
     *
     * @param  Request  $request
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
