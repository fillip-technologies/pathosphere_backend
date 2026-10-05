<?php

namespace Tests\Feature\Network;

use App\Modules\Auth\Permissions\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

final class OrganizationTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    public function test_super_admin_updates_settings_by_merging_keys(): void
    {
        $this->setUpOrganization();
        $this->actingAsStaff($this->staff(SystemRole::SuperAdmin));

        $etag = $this->getJson('/api/v1/organization')->assertOk()->headers->get('ETag');

        $etag = $this->patchJson('/api/v1/organization', ['settings' => ['report_footer' => 'Verified']], ['If-Match' => $etag])
            ->assertOk()
            ->headers->get('ETag');

        $this->patchJson('/api/v1/organization', ['settings' => ['tat_target_hours' => 24], 'gstin' => '10AABCP1234C1Z5'], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.settings', ['report_footer' => 'Verified', 'tat_target_hours' => 24])
            ->assertJsonPath('data.gstin', '10AABCP1234C1Z5');
    }

    public function test_only_organization_managers_can_read_settings(): void
    {
        $this->setUpOrganization();

        $this->actingAsStaff($this->staff(SystemRole::HqOperations))
            ->getJson('/api/v1/organization')
            ->assertForbidden();
    }
}
