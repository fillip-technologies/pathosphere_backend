<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\StateMachines\InvalidStatusTransition;
use App\Modules\Shared\StateMachines\StateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\Fixtures\SampleStatus;
use Tests\Support\Fixtures\ScratchItem;
use Tests\TestCase;

/** Every status change is checked, saved and audited together (spec §5). */
final class AuditAndStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private StateMachine $machine;

    private ScratchItem $item;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        ScratchItem::createTable();

        $organizationId = (string) Str::uuid();
        $this->userId = (string) Str::uuid();
        app(CurrentActor::class)->set(Actor::user($this->userId, $organizationId));
        Context::add('request_id', 'req-test-0001');

        $this->item = ScratchItem::query()->create([
            'organization_id' => $organizationId,
            'name' => 'lipid profile',
            'status' => SampleStatus::Draft,
        ]);

        $this->machine = new class(app(AuditLogger::class)) extends StateMachine
        {
            protected function transitions(): array
            {
                return ['draft' => ['active'], 'active' => ['closed']];
            }

            protected function entityName(): string
            {
                return 'sample';
            }
        };
    }

    public function test_actor_columns_are_filled_from_the_current_actor(): void
    {
        $this->assertSame($this->userId, $this->item->created_by);
        $this->assertSame($this->userId, $this->item->updated_by);
    }

    public function test_an_allowed_transition_saves_and_writes_one_audit_row(): void
    {
        $this->machine->transition($this->item, SampleStatus::Active);

        $this->assertSame(SampleStatus::Active, $this->item->fresh()->status);

        $audit = AuditLog::query()->where('entity_id', $this->item->id)->sole();
        $this->assertSame('sample.status_changed', $audit->action);
        $this->assertSame($this->userId, $audit->user_id);
        $this->assertSame(['status' => 'draft'], $audit->old_value);
        $this->assertSame(['status' => 'active'], $audit->new_value);
        $this->assertSame('req-test-0001', $audit->request_id);
    }

    public function test_a_disallowed_transition_changes_nothing(): void
    {
        try {
            $this->machine->transition($this->item, SampleStatus::Closed);
            $this->fail('Expected the transition to be refused.');
        } catch (InvalidStatusTransition $error) {
            $this->assertSame(409, $error->httpStatus);
            $this->assertSame('INVALID_STATUS_TRANSITION', $error->errorCode);
        }

        $this->assertSame(SampleStatus::Draft, $this->item->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('entity_id', $this->item->id)->count());
    }

    public function test_a_created_row_is_audited_with_its_values_and_no_old_values(): void
    {
        $audit = app(AuditLogger::class)->recordCreated('sample.created', $this->item);

        $this->assertNull($audit->old_value);
        $this->assertSame('lipid profile', $audit->new_value['name']);
        $this->assertSame($this->item->organization_id, $audit->organization_id);
    }

    public function test_audit_rows_cannot_be_changed_or_deleted(): void
    {
        $this->machine->transition($this->item, SampleStatus::Active);
        $audit = AuditLog::query()->where('entity_id', $this->item->id)->sole();

        $this->expectException(LogicException::class);
        $audit->delete();
    }
}
