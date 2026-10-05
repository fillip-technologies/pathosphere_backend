<?php

namespace Tests\Support\Architecture;

/**
 * Looking past the caller's scope is sometimes right (integrity checks such as
 * "is this branch still used by staff you cannot see?"), but each case must be
 * a reviewed decision. New bypasses fail the build until added here.
 */
final class ScopeBypassesAreAllowlisted implements ArchitectureRule
{
    private const ALLOWED_FILES = [
        'app/Modules/Auth/Services/StaffDirectory.php',
        'app/Modules/Auth/Services/RoleService.php',
    ];

    public function description(): string
    {
        return 'Scope bypasses (withoutGlobalScope(s)) are only allowed in allowlisted files.';
    }

    public function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            if (preg_match('/->withoutGlobalScopes?\s*\(/', $file->code) !== 1) {
                continue;
            }

            $allowed = array_filter(self::ALLOWED_FILES, fn (string $path): bool => str_ends_with($file->path, $path));

            if ($allowed === []) {
                $violations[] = "{$file->path} bypasses the scope filter; add it to the allowlist only after review.";
            }
        }

        return $violations;
    }
}
