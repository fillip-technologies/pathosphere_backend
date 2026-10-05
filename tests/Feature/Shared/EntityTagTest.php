<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\Fixtures\ScratchItem;
use Tests\TestCase;

/** Updates must prove the client saw the latest version (spec §8.7). */
final class EntityTagTest extends TestCase
{
    use RefreshDatabase;

    private ScratchItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        ScratchItem::createTable();

        $this->item = ScratchItem::query()->create([
            'organization_id' => (string) Str::uuid(),
            'name' => 'haemoglobin',
            'status' => 'draft',
        ]);
    }

    public function test_the_current_tag_passes(): void
    {
        EntityTag::assertIfMatch($this->requestWithIfMatch(EntityTag::for($this->item)), $this->item);

        $this->addToAssertionCount(1);
    }

    public function test_a_stale_tag_is_a_412(): void
    {
        $staleTag = EntityTag::for($this->item);
        $this->travel(1)->seconds();
        $this->item->update(['name' => 'changed by someone else']);

        $this->assertDomainError(412, 'PRECONDITION_FAILED', fn () => EntityTag::assertIfMatch($this->requestWithIfMatch($staleTag), $this->item));
    }

    public function test_a_missing_header_is_a_428(): void
    {
        $this->assertDomainError(428, 'PRECONDITION_REQUIRED', fn () => EntityTag::assertIfMatch(Request::create('/'), $this->item));
    }

    private function requestWithIfMatch(string $tag): Request
    {
        return Request::create('/', 'PATCH', server: ['HTTP_IF_MATCH' => $tag]);
    }

    private function assertDomainError(int $status, string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a DomainError.');
        } catch (DomainError $error) {
            $this->assertSame($status, $error->httpStatus);
            $this->assertSame($code, $error->errorCode);
        }
    }
}
