<?php

namespace App\Modules\Shared\Scoping;

/**
 * Declares which columns of a model place its rows in the network. Each
 * scoped model returns one of these from scopeColumns(); the filter uses
 * whichever columns exist for the caller's scope level.
 *
 * A model with no column relevant to a scope level shows no rows at that
 * level: the safe default when a rule is missing.
 */
final class ScopeColumns
{
    public function __construct(
        public readonly ?string $organization = 'organization_id',
        public readonly ?string $region = null,
        public readonly ?string $franchise = null,
        public readonly ?string $branch = null,
        /** Processing labs also see rows they test (spec §4: samples, results). */
        public readonly ?string $processingBranch = null,
        public readonly ?string $b2bClient = null,
        /** Rows every user of the organization may read, e.g. the test catalogue. */
        public readonly bool $visibleToWholeOrganization = false,
    ) {}
}
