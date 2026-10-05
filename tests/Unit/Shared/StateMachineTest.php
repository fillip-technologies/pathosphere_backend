<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\StateMachines\StateMachine;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures\SampleStatus;

final class StateMachineTest extends TestCase
{
    private StateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new class(new AuditLogger(new CurrentActor)) extends StateMachine
        {
            protected function transitions(): array
            {
                return [
                    'draft' => ['active'],
                    'active' => ['draft'],
                    self::ANY => ['closed'],
                ];
            }

            protected function entityName(): string
            {
                return 'sample';
            }
        };
    }

    public function test_listed_transitions_are_allowed(): void
    {
        $this->assertTrue($this->machine->canTransition(SampleStatus::Draft, SampleStatus::Active));
        $this->assertTrue($this->machine->canTransition('active', 'draft'));
    }

    public function test_unlisted_transitions_are_refused(): void
    {
        $this->assertFalse($this->machine->canTransition(SampleStatus::Closed, SampleStatus::Active));
        $this->assertFalse($this->machine->canTransition('unknown', 'active'));
    }

    public function test_wildcard_transitions_apply_from_any_status(): void
    {
        $this->assertTrue($this->machine->canTransition(SampleStatus::Draft, SampleStatus::Closed));
        $this->assertTrue($this->machine->canTransition(SampleStatus::Active, SampleStatus::Closed));
    }
}
