<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Fixtures\ScratchItem;
use Tests\Support\Fixtures\ScratchItemResource;
use Tests\TestCase;

/** Every list endpoint shares one envelope and one set of query rules (D2, D3). */
final class CursorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ScratchItem::createTable();

        $organizationId = (string) Str::uuid();
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $index => $name) {
            ScratchItem::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'status' => $index % 2 === 0 ? 'active' : 'draft',
                'amount' => '10.00',
            ]);
        }

        Route::middleware('api')->get('/api/v1/_test/items', function (Request $request) {
            $query = ListQuery::from($request)
                ->allowFilters(['status' => 'status'])
                ->allowSorts(['name'])
                ->apply(ScratchItem::query());

            return CursorPage::respond($query, $request, ScratchItemResource::class);
        });
    }

    public function test_it_pages_through_every_row_with_the_cursor(): void
    {
        $first = $this->getJson('/api/v1/_test/items?limit=2&sort=name')->assertOk();

        $first->assertJsonPath('pagination.limit', 2)
            ->assertJsonPath('data.0.name', 'alpha')
            ->assertJsonPath('data.1.name', 'bravo')
            ->assertJsonPath('data.0.amount', '10.00');

        $seen = array_column($first->json('data'), 'name');
        $cursor = $first->json('pagination.next_cursor');

        while ($cursor !== null) {
            $page = $this->getJson('/api/v1/_test/items?limit=2&sort=name&cursor='.urlencode($cursor))->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'name')];
            $cursor = $page->json('pagination.next_cursor');
        }

        $this->assertSame(['alpha', 'bravo', 'charlie', 'delta', 'echo'], $seen);
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->getJson('/api/v1/_test/items?filter[status]=active')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.next_cursor', null);
    }

    public function test_unknown_filters_and_sorts_are_rejected_not_ignored(): void
    {
        $this->getJson('/api/v1/_test/items?filter[branch_id]=x')
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'filter.branch_id');

        $this->getJson('/api/v1/_test/items?sort=-amount')
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'sort');
    }

    public function test_limit_is_capped_at_200(): void
    {
        $this->getJson('/api/v1/_test/items?limit=201')
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'limit');
    }
}
