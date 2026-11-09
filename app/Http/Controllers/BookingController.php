<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateBookingAction;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Контроллер бронирования переговорных комнат.
 *
 * Тонкий HTTP-слой:
 * - `store` делегирует бизнес-логику в `CreateBookingAction`;
 * - `destroy` проверяет права через `BookingPolicy`;
 * - `index` и `today` оставлены как были (по ТЗ их исправлять не нужно).
 */
final class BookingController extends Controller
{
    /**
     * @var CreateBookingAction Действие создания брони
     */
    private CreateBookingAction $createBookingAction;

    /**
     * @param  CreateBookingAction  $createBookingAction
     */
    public function __construct(CreateBookingAction $createBookingAction)
    {
        $this->createBookingAction = $createBookingAction;
    }

    /**
     * Мои брони (постранично).
     *
     * По ТЗ метод не исправляем: оставлен как в исходном коде.
     *
     * @param  Request  $request
     * @return LengthAwarePaginator
     */
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * Создать бронь.
     *
     * @param  StoreBookingRequest  $request  Валидированный запрос
     * @return JsonResponse 201 Created + BookingResource
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->createBookingAction->execute($request->toData());

        return (new BookingResource($booking))
            ->response()
            ->setStatusCode(HttpStatus::HTTP_CREATED);
    }

    /**
     * Отменить бронь. Только автор (проверка в BookingPolicy).
     *
     * @param  int  $id  Идентификатор брони
     * @return Response 204 No Content
     */
    public function destroy(int $id): Response
    {
        $booking = Booking::findOrFail($id);

        Gate::authorize('delete', $booking);

        $booking->delete();

        return response()->noContent();
    }

    /**
     * Брони комнаты на сегодня.
     *
     * По ТЗ метод не исправляем: оставлен как в исходном коде.
     *
     * @param  Room  $room
     * @return Collection<int, array{time: string, author: string}>
     */
    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
