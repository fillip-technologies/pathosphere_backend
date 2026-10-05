<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_health_reports_ok_when_the_database_answers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', 'ok');
    }
}
