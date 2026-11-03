<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Auth;

/**
 * The shopper's "garage" — the vehicle they're currently shopping for.
 * Persisted on the user row when signed in, otherwise in the session.
 */
class GarageService
{
    private const KEY = 'garage.vehicle';

    public function set(Vehicle $vehicle): void
    {
        $payload = [
            'id' => $vehicle->id,
            'label' => $vehicle->label(),
        ];

        session([self::KEY => $payload]);

        if (Auth::check()) {
            Auth::user()->forceFill(['active_vehicle' => $payload])->save();
        }
    }

    public function clear(): void
    {
        session()->forget(self::KEY);

        if (Auth::check()) {
            Auth::user()->forceFill(['active_vehicle' => null])->save();
        }
    }

    /** @return array{id:int,label:string}|null */
    public function current(): ?array
    {
        $fromSession = session(self::KEY);

        if ($fromSession) {
            return $fromSession;
        }

        $fromUser = Auth::user()?->active_vehicle;

        if ($fromUser) {
            session([self::KEY => $fromUser]);

            return $fromUser;
        }

        return null;
    }

    public function vehicleId(): ?int
    {
        return $this->current()['id'] ?? null;
    }

    public function label(): ?string
    {
        return $this->current()['label'] ?? null;
    }
}
