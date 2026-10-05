<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\Patient;
use App\Modules\Booking\Services\PatientService;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Organization;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Enums\Gender;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Name search uses the FULLTEXT index (spec §3). InnoDB full-text indexes only
 * see committed rows, so this test commits its own data and removes it
 * afterwards instead of using RefreshDatabase.
 */
final class PatientNameSearchTest extends TestCase
{
    private ?string $organizationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('patients')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) {
            DB::table('patients')->where('organization_id', $this->organizationId)->delete();
            DB::table('branches')->where('organization_id', $this->organizationId)->delete();
            DB::table('regions')->where('organization_id', $this->organizationId)->delete();
            DB::table('organizations')->where('id', $this->organizationId)->delete();
        }

        parent::tearDown();
    }

    public function test_patients_are_found_by_the_start_of_any_name_word(): void
    {
        $this->asSystem(function (): void {
            $organization = Organization::factory()->create();
            $this->organizationId = $organization->id;
            $branch = Branch::factory()->in(Region::factory()->create(['organization_id' => $organization->id]))->create();

            foreach (['Asha Kumari', 'Ashok Kumar Singh', 'Ravi Shankar'] as $index => $name) {
                $patient = new Patient(['name' => $name, 'gender' => Gender::Female, 'age_years' => 30, 'phone' => '98765000'.$index.'0']);
                $patient->organization_id = $organization->id;
                $patient->registered_branch_id = $branch->id;
                $patient->uhid = 'TS'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
                $patient->save();
            }
        });

        $this->assertSame(['Asha Kumari', 'Ashok Kumar Singh'], $this->search('ash'));
        $this->assertSame(['Ashok Kumar Singh'], $this->search('kum sin'));
        $this->assertSame(['Ravi Shankar'], $this->search('shankar'));

        // Typed boolean operators are stripped: "-kum" cannot exclude, it is just a word.
        $this->assertSame(['Asha Kumari', 'Ashok Kumar Singh'], $this->search('+ash* -kum'));
    }

    /** @return list<string> */
    private function search(string $text): array
    {
        return $this->asSystem(function () use ($text): array {
            $query = Patient::query()->where('organization_id', $this->organizationId)->orderBy('name');
            PatientService::applySearch($query, $text);

            return $query->pluck('name')->all();
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asSystem(callable $callback): mixed
    {
        return app(CurrentScope::class)->runAs(ScopeContext::system(), $callback);
    }
}
