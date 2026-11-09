<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\CreateBookingData;
use App\Models\Room;
use App\Rules\AlignedToBookingStep;
use App\Rules\BookingDurationInRange;
use App\Rules\NoOverlappingBooking;
use App\Rules\WithinOfficeWorkingHours;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Запрос на создание брони переговорной комнаты.
 *
 * Валидация разделена на два уровня:
 *
 * 1. Базовые правила (`rules()`): обязательность полей, формат ISO 8601
 *    с оффсетом, `ends_at > starts_at`, существование комнаты. Ошибки
 *    возвращаются стандартным механизмом Laravel (422).
 *
 * 2. Бизнес-правила (`withValidator()`): длительность 15–240 минут, шаг
 *    15 минут, рабочее время 09:00–20:00 по TZ офиса, отсутствие
 *    пересечений. Выполняются только если базовые правила прошли —
 *    иначе бессмысленны (например, `CarbonImmutable::parse` на мусоре
 *    упадёт).
 *
 * Поле `user_id` намеренно отсутствует в правилах: атрибуция
 * устанавливается из `auth()->id()` в `toData()`, чтобы клиент не мог
 * подменить автора через тело запроса (mass assignment).
 */
final class StoreBookingRequest extends FormRequest
{
    /**
     * Авторизация запроса.
     *
     * Middleware `auth:sanctum` уже отсекает неавторизованных (401) до
     * вызова этого метода. Проверка `$this->user() !== null` —
     * подстраховка на случай, если middleware будет убран.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Базовые правила валидации.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:starts_at'],
        ];
    }

    /**
     * Преобразовать валидированные данные в DTO.
     *
     * `user_id` берётся из `auth()->id()`, а не из тела запроса —
     * это исключает mass assignment на уровне транспорта.
     *
     * @return CreateBookingData
     */
    public function toData(): CreateBookingData
    {
        return new CreateBookingData(
            userId: $this->user()->id,
            roomId: $this->integer('room_id'),
            title: $this->string('title')->toString(),
            startsAt: CarbonImmutable::parse($this->string('starts_at')->toString()),
            endsAt: CarbonImmutable::parse($this->string('ends_at')->toString()),
        );
    }

    /**
     * Бизнес-правила, выполняемые после базовых.
     *
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $room = Room::query()->with('office')->find($this->input('room_id'));

            if (! $room instanceof Room) {
                return;
            }

            $startsAt = CarbonImmutable::parse($this->string('starts_at')->toString());
            $endsAt = CarbonImmutable::parse($this->string('ends_at')->toString());

            $this->applyDurationRule($validator, $startsAt, $endsAt);
            $this->applyStepRule($validator, $startsAt, $endsAt);
            $this->applyWorkingHoursRule($validator, $room, $startsAt, $endsAt);
            $this->applyOverlapRule($validator, $room, $startsAt, $endsAt);
        });
    }

    /**
     * Проверить длительность брони (15–240 минут).
     *
     * @param  Validator  $validator
     * @param  CarbonImmutable  $startsAt
     * @param  CarbonImmutable  $endsAt
     * @return void
     */
    private function applyDurationRule(
        Validator $validator,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $rule = new BookingDurationInRange($startsAt, $endsAt);
        $rule->validate('ends_at', null, $this->makeFailCallback($validator, 'ends_at'));
    }

    /**
     * Проверить шаг 15 минут.
     *
     * @param  Validator  $validator
     * @param  CarbonImmutable  $startsAt
     * @param  CarbonImmutable  $endsAt
     * @return void
     */
    private function applyStepRule(
        Validator $validator,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $rule = new AlignedToBookingStep($startsAt, $endsAt);
        $rule->validate('starts_at', null, $this->makeFailCallback($validator, 'starts_at'));
    }

    /**
     * Проверить рабочее время 09:00–20:00 по TZ офиса.
     *
     * @param  Validator  $validator
     * @param  Room  $room
     * @param  CarbonImmutable  $startsAt
     * @param  CarbonImmutable  $endsAt
     * @return void
     */
    private function applyWorkingHoursRule(
        Validator $validator,
        Room $room,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $rule = new WithinOfficeWorkingHours($room->office->timezone, $startsAt, $endsAt);
        $rule->validate('starts_at', null, $this->makeFailCallback($validator, 'starts_at'));
    }

    /**
     * Проверить отсутствие пересечений с существующими бронями.
     *
     * @param  Validator  $validator
     * @param  Room  $room
     * @param  CarbonImmutable  $startsAt
     * @param  CarbonImmutable  $endsAt
     * @return void
     */
    private function applyOverlapRule(
        Validator $validator,
        Room $room,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $rule = new NoOverlappingBooking($room->id, $startsAt, $endsAt);
        $rule->validate('starts_at', null, $this->makeFailCallback($validator, 'starts_at'));
    }

    /**
     * Создать колбэк `$fail` для Rule, привязывающий ошибку к полю.
     *
     * @param  Validator  $validator
     * @param  string  $attribute  Имя поля, к которому привязывается ошибка
     * @return Closure(string): void
     */
    private function makeFailCallback(Validator $validator, string $attribute): Closure
    {
        return function (string $message) use ($validator, $attribute): void {
            $validator->errors()->add($attribute, $message);
        };
    }
}
