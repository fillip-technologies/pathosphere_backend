<?php

namespace App\Modules\Network\Services;

use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Errors\DomainError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $directory,
        private readonly StaffDirectory $staffDirectory,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated name, region_type, parent_region_id
     */
    public function create(string $organizationId, array $attributes): Region
    {
        $this->assertParentIsVisible($attributes['parent_region_id'] ?? null);

        return DB::transaction(function () use ($organizationId, $attributes): Region {
            $region = new Region($attributes);
            $region->organization_id = $organizationId;
            $region->save();
            $this->auditLogger->recordCreated('region.create', $region);

            return $region;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Region $region, array $changes): Region
    {
        if (array_key_exists('parent_region_id', $changes)) {
            $this->assertValidNewParent($region, $changes['parent_region_id']);
        }

        return DB::transaction(function () use ($region, $changes): Region {
            $region->fill($changes)->save();
            $this->auditLogger->recordChanges('region.update', $region);

            return $region;
        });
    }

    /** Only an empty region can be removed; otherwise rows would point at a deleted region. */
    public function delete(Region $region): void
    {
        $inUse = $region->children()->exists()
            || Branch::query()->where('region_id', $region->id)->exists()
            || Franchise::query()->where('region_id', $region->id)->exists()
            || B2bClient::query()->where('region_id', $region->id)->exists()
            || $this->staffDirectory->hasStaffInRegion($region->id);

        if ($inUse) {
            throw new DomainError(
                'REGION_IN_USE',
                'This region still has sub-regions, branches, franchises, B2B clients or staff. Move them first.',
                409,
            );
        }

        DB::transaction(function () use ($region): void {
            $region->delete();
            $this->auditLogger->record('region.delete', $region);
        });
    }

    private function assertParentIsVisible(?string $parentRegionId): void
    {
        if ($parentRegionId !== null && ! $this->directory->regionIsVisible($parentRegionId)) {
            throw ValidationException::withMessages(['parent_region_id' => 'The selected parent region does not exist.']);
        }
    }

    private function assertValidNewParent(Region $region, ?string $newParentId): void
    {
        $this->assertParentIsVisible($newParentId);

        if ($newParentId !== null && in_array($newParentId, $this->directory->regionTreeIds($region->id), true)) {
            throw ValidationException::withMessages(['parent_region_id' => 'A region cannot be moved under itself or one of its sub-regions.']);
        }
    }
}
