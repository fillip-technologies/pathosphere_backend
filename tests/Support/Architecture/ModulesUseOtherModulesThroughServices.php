<?php

namespace Tests\Support\Architecture;

/**
 * A module reads another module's tables only through that module's service
 * classes (spec §2), so modules can later be split into services.
 */
final class ModulesUseOtherModulesThroughServices implements ArchitectureRule
{
    public function description(): string
    {
        return "Modules must not use another module's Models directly, and Shared must not depend on business modules.";
    }

    public function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            $ownModule = $file->module();

            if ($ownModule === null) {
                continue;
            }

            preg_match_all('/App\\\\Modules\\\\([A-Za-z]+)\\\\([A-Za-z]+)/', $file->code, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $usedModule, $layer]) {
                if ($usedModule === $ownModule || $usedModule === 'Shared') {
                    continue;
                }

                if ($ownModule === 'Shared') {
                    $violations[] = "{$file->path} (Shared) depends on the {$usedModule} module.";
                } elseif ($layer === 'Models') {
                    $violations[] = "{$file->path} uses {$usedModule}\\Models directly; call {$usedModule}\\Services instead.";
                }
            }
        }

        return array_values(array_unique($violations));
    }
}
