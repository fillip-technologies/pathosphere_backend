<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Models\Organization;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class OrganizationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $changes  validated fields; `settings` is merged key by key
     */
    public function update(Organization $organization, array $changes): Organization
    {
        if (array_key_exists('settings', $changes)) {
            $changes['settings'] = array_replace($organization->settings, $changes['settings']);
        }

        return DB::transaction(function () use ($organization, $changes): Organization {
            $organization->fill($changes)->save();
            $this->auditLogger->recordChanges('organization.update', $organization);

            return $organization;
        });
    }
}
