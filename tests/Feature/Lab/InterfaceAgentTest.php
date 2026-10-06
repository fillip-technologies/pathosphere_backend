<?php

namespace Tests\Feature\Lab;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Lab\Models\LabResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/**
 * Contract tests for the lab interface agent (spec §8 Interface agent, §11
 * contract tests) with a recorded upload from a haematology analyser.
 */
final class InterfaceAgentTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesSamples;
    use RefreshDatabase;
    use RunsLab;

    private User $admin;

    private User $technician;

    private User $supervisor;

    /** @var array<string, mixed> */
    private array $order;

    private string $barcode;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');

        $this->admin = $this->staff(SystemRole::SuperAdmin);
        $this->technician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATCL1')]);
        $this->supervisor = $this->staff(SystemRole::LabSupervisor, ['branch_id' => $this->branchId('PATCL1')]);
        $this->signatory('PATCL1', ['Haematology'], SigningDiscipline::Pathology);
        $frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $patientId = $this->actingAsStaff($frontDesk)->registerPatient()['id'];
        $this->order = $this->orderReceivedAtLab(
            $frontDesk,
            $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]),
            $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATPSC1')]),
            $this->technician,
            $patientId,
            ['CBC'],
            '350.00',
        );
        $this->barcode = $this->actingAsStaff($this->technician)->getJson('/api/v1/worklist')->json('data.0.sample.barcode');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_hq_issues_a_key_that_is_shown_once_and_only_works_from_allowed_addresses(): void
    {
        $this->actingAsStaff($this->technician)->postJson('/api/v1/interface-agents', [])->assertForbidden();
        $this->actingAsStaff($this->admin)->postJson('/api/v1/interface-agents', [
            'branch_id' => $this->branchId('PATCL1'), 'name' => 'Bench PC', 'allowed_ips' => ['not-an-ip'],
        ])->assertStatus(422);

        $agent = $this->createAgent(['10.20.0.0/16']);
        $this->assertStringStartsWith('pla_', $agent['api_key']);
        $this->actingAsStaff($this->admin)->getJson('/api/v1/interface-agents')
            ->assertOk()
            ->assertJsonMissingPath('data.0.api_key')
            ->assertJsonPath('data.0.key_prefix', substr($agent['api_key'], 0, 12));

        $this->agentGet($agent['api_key'], '/api/v1/agent/orders', '10.20.4.7')->assertOk();
        $this->agentGet($agent['api_key'], '/api/v1/agent/orders', '192.0.2.10')->assertForbidden();
        $this->agentGet('pla_wrong', '/api/v1/agent/orders', '10.20.4.7')->assertUnauthorized();

        $this->actingAsStaff($this->admin)->postJson("/api/v1/interface-agents/{$agent['id']}/revoke")->assertOk()->assertJsonPath('data.is_active', false);
        $this->agentGet($agent['api_key'], '/api/v1/agent/orders', '10.20.4.7')->assertUnauthorized();
    }

    public function test_the_host_query_lists_the_labs_open_tests_with_the_codes_analysers_report(): void
    {
        $agent = $this->createAgent(['127.0.0.1']);

        $this->agentGet($agent['api_key'], '/api/v1/agent/orders?since=2026-10-12T00:00:00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.barcode', $this->barcode)
            ->assertJsonPath('data.0.test_code', 'CBC')
            ->assertJsonPath('data.0.run_no', 1)
            ->assertJsonPath('data.0.parameter_codes', ['HB', 'TLC', 'PLT', 'RBC', 'PCV'])
            ->assertJsonPath('data.0.patient.gender', 'female')
            ->assertJsonMissingPath('data.0.patient.name');
        $this->agentGet($agent['api_key'], '/api/v1/agent/orders?since=2026-10-13T00:00:00Z')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recorded_analyser_results_are_stored_once_with_their_original_time_even_when_replayed(): void
    {
        $agent = $this->createAgent(['127.0.0.1']);
        $upload = $this->recordedUpload();

        $this->agentPost($agent['api_key'], $upload)->assertOk()->assertJsonPath('data', ['accepted' => 5, 'unchanged' => 0, 'rejected' => []]);

        $haemoglobin = $this->asSystem(fn () => LabResult::query()->where('value', '13.6')->firstOrFail());
        $this->assertNull($haemoglobin->entered_by);
        $this->assertSame('analyser', $haemoglobin->source->value);
        $this->assertSame('Sysmex XN-550 #2', $haemoglobin->instrument);
        $this->assertSame('2026-10-12T05:11:07Z', $haemoglobin->entered_at->toIso8601ZuluString());
        $this->assertSame('8.25', $this->asSystem(fn () => LabResult::query()->where('value_numeric', '8.25')->value('value')));

        // The agent replays its buffer after an outage: nothing changes, before or after verification.
        $this->agentPost($agent['api_key'], $upload)->assertOk()->assertJsonPath('data', ['accepted' => 0, 'unchanged' => 5, 'rejected' => []]);
        $this->verifyTest($this->supervisor, $this->orderItemId($this->order, 'CBC'))->assertOk()->assertJsonPath('data.status', 'verified');
        $this->agentPost($agent['api_key'], $upload)->assertOk()->assertJsonPath('data.unchanged', 5);
        $this->assertSame(5, $this->asSystem(fn () => LabResult::query()->count()));

        // A different value for a verified test is refused, per row, with a reason.
        $changed = $upload;
        $changed['results'][0]['value'] = '9.9';
        $this->agentPost($agent['api_key'], $changed)
            ->assertOk()
            ->assertJsonPath('data.accepted', 0)
            ->assertJsonPath('data.rejected.0.code', 'TEST_NOT_OPEN_FOR_RESULTS')
            ->assertJsonCount(5, 'data.rejected');
    }

    public function test_results_for_unknown_samples_or_closed_runs_are_rejected_without_blocking_the_rest(): void
    {
        $agent = $this->createAgent(['127.0.0.1']);
        $upload = $this->recordedUpload();
        $upload['results'][] = ['barcode' => 'S999999999', 'test_code' => 'CBC', 'parameter_code' => 'HB', 'value' => '12', 'measured_at' => '2026-10-12T05:00:00Z'];
        $upload['results'][] = ['barcode' => $this->barcode, 'test_code' => 'ESR', 'parameter_code' => 'ESR', 'value' => '12', 'measured_at' => '2026-10-12T05:00:00Z'];

        $this->agentPost($agent['api_key'], $upload)
            ->assertOk()
            ->assertJsonPath('data.accepted', 5)
            ->assertJsonPath('data.rejected.0', ['index' => 5, 'code' => 'TEST_NOT_AT_LAB', 'message' => 'No open test with this barcode and test code at this lab.'])
            ->assertJsonPath('data.rejected.1.index', 6);

        $this->actingAsStaff($this->supervisor)->postJson("/api/v1/order-items/{$this->orderItemId($this->order, 'CBC')}/rerun", ['reason' => 'Flagged by QC'])->assertOk();
        $closedRun = ['results' => array_map(fn (array $result) => ['run_no' => 1] + $result, $upload['results'])];
        $this->agentPost($agent['api_key'], ['results' => array_slice($closedRun['results'], 0, 5)])
            ->assertOk()
            ->assertJsonPath('data.rejected.0.code', 'RUN_CLOSED');
    }

    /**
     * @param  list<string>  $allowedIps
     * @return array<string, mixed> the agent with its one-time key
     */
    private function createAgent(array $allowedIps): array
    {
        return $this->actingAsStaff($this->admin)->postJson('/api/v1/interface-agents', [
            'branch_id' => $this->branchId('PATCL1'),
            'name' => 'Haematology bench PC',
            'allowed_ips' => $allowedIps,
        ])->assertCreated()->json('data');
    }

    /** @return array{results: list<array<string, mixed>>} */
    private function recordedUpload(): array
    {
        $json = str_replace('{{barcode}}', $this->barcode, (string) file_get_contents(base_path('tests/Fixtures/Agent/sysmex-cbc-upload.json')));

        return json_decode($json, true);
    }

    /** @return TestResponse<JsonResponse> */
    private function agentGet(string $key, string $uri, string $ip = '127.0.0.1'): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withToken($key)->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $upload
     * @return TestResponse<JsonResponse>
     */
    private function agentPost(string $key, array $upload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withToken($key)->postJson('/api/v1/agent/results', $upload);
    }
}
