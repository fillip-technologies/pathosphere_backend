<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * Where tests booked at a source branch are processed (spec §7.4
 * test_routing_rules). A null test is the default rule for every test.
 * Always queried by branch IDs taken from scoped branch lookups.
 *
 * @property string $id
 * @property string $source_branch_id
 * @property string|null $test_id
 * @property string $processing_branch_id
 * @property int $priority
 * @property bool $is_active
 */
final class RoutingRule extends BaseModel
{
    protected $table = 'test_routing_rules';

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['priority' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
