<?php

namespace Tests\Support\Architecture;

/**
 * Static checks for rules MySQL cannot enforce for us (spec §4.2, BUILD_PLAN
 * Phase 0 step 7). Each rule is a small regex check over comment-free source.
 */
final class Rules
{
    /** @return list<ArchitectureRule> */
    public static function all(): array
    {
        return [
            new NoRawTableQueries,
            new ModulesUseOtherModulesThroughServices,
            new ControllersDoNotWriteStatus,
            new VendorCodeStaysInInfrastructure,
            new NoFloatInMoneyCode,
        ];
    }
}
