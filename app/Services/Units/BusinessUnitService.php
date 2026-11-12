<?php

namespace App\Services\Units;

use App\Models\Business;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessUnitService
{
    /** @return Collection<int, BusinessUnit> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->units()->orderBy('name')->get();
    }

    /** @param array{name: string, code?: string|null} $attributes */
    public function create(Business $business, User $user, array $attributes): BusinessUnit
    {
        return DB::transaction(function () use ($business, $user, $attributes): BusinessUnit {
            $this->assertNameIsFree($business, $attributes['name'], null);
            $unit = $business->units()->create(['name' => $attributes['name'], 'code' => $attributes['code'] ?? null]);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'business_unit.created',
                'subject_type' => BusinessUnit::class,
                'subject_id' => $unit->getKey(),
                'new_values' => $unit->only(['name', 'code']),
            ]);

            return $unit->refresh();
        });
    }

    /** @param array{name?: string, code?: string|null, is_active?: bool} $attributes */
    public function update(Business $business, User $user, int $unitId, array $attributes): BusinessUnit
    {
        return DB::transaction(function () use ($business, $user, $unitId, $attributes): BusinessUnit {
            $unit = $business->units()->whereKey($unitId)->lockForUpdate()->firstOrFail();

            if (isset($attributes['name'])) {
                $this->assertNameIsFree($business, $attributes['name'], $unitId);
            }

            $unit->fill($attributes);
            $newValues = $unit->getDirty();

            if ($newValues === []) {
                return $unit;
            }

            $oldValues = array_map(fn (string $attribute): mixed => $unit->getRawOriginal($attribute), array_combine(array_keys($newValues), array_keys($newValues)));
            $unit->save();
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'business_unit.updated',
                'subject_type' => BusinessUnit::class,
                'subject_id' => $unit->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);

            return $unit;
        });
    }

    /**
     * Check that a unit chosen for a document or a report belongs to the business.
     * New documents also need the unit to be in use; reports and inherited documents may use a retired one.
     */
    public function assertBelongs(Business $business, ?int $unitId, bool $mustBeActive = false): ?BusinessUnit
    {
        if ($unitId === null) {
            return null;
        }

        $unit = $business->units()->whereKey($unitId)->first();

        if ($unit === null || ($mustBeActive && ! $unit->is_active)) {
            throw ValidationException::withMessages(['business_unit_id' => [$unit === null ? 'Choose a unit of this business.' : 'This unit is no longer in use. Choose an active unit.']]);
        }

        return $unit;
    }

    private function assertNameIsFree(Business $business, string $name, ?int $exceptUnitId): void
    {
        $taken = $business->units()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptUnitId !== null, fn ($query) => $query->whereKeyNot($exceptUnitId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['This business already has a unit with this name.']]);
        }
    }
}
