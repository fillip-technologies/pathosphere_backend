<?php

namespace Tests\Feature\Shared;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Fixtures\SampleStatus;
use Tests\TestCase;

/**
 * The migration helpers really enforce the spec §6 rules in the database.
 * DDL commits implicitly in MySQL, so this test creates and drops its own
 * table instead of using RefreshDatabase.
 */
final class SchemaMacrosTest extends TestCase
{
    private const TABLE = 'schema_macro_probes';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuidPrimary();
            $table->uuid('owner_id');
            $table->uuid('franchise_id')->nullable();
            $table->uuid('b2b_client_id')->nullable();
            $table->string('name', 150);
            $table->enumString('status', SampleStatus::class);
            $table->money('price');
            $table->exactlyOneOf('franchise_id', 'b2b_client_id');
            $table->uniqueWhere(['owner_id'], "`status` = 'active'", 'is_active');
            $table->searchableText('name');
            $table->standardTimestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    public function test_a_valid_row_is_accepted(): void
    {
        $this->insertRow(['status' => 'active']);

        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_the_enum_check_rejects_unknown_values(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['status' => 'archived']);
    }

    public function test_exactly_one_party_must_be_set(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['franchise_id' => (string) Str::uuid(), 'b2b_client_id' => (string) Str::uuid()]);
    }

    public function test_only_one_active_row_per_owner_but_any_number_of_inactive_rows(): void
    {
        $ownerId = (string) Str::uuid();
        $this->insertRow(['owner_id' => $ownerId, 'status' => 'draft']);
        $this->insertRow(['owner_id' => $ownerId, 'status' => 'closed']);
        $this->insertRow(['owner_id' => $ownerId, 'status' => 'active']);

        $this->expectException(QueryException::class);
        $this->insertRow(['owner_id' => $ownerId, 'status' => 'active']);
    }

    public function test_money_is_stored_as_an_exact_decimal(): void
    {
        $this->insertRow(['price' => '1250.05']);

        $this->assertSame('1250.05', DB::table(self::TABLE)->value('price'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertRow(array $overrides = []): void
    {
        DB::table(self::TABLE)->insert($overrides + [
            'id' => (string) Str::uuid7(),
            'owner_id' => (string) Str::uuid(),
            'franchise_id' => (string) Str::uuid(),
            'b2b_client_id' => null,
            'name' => 'Complete blood count',
            'status' => 'draft',
            'price' => '100.00',
        ]);
    }
}
