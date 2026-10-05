<?php

namespace Tests\Support\Architecture;

/**
 * Raw DB::table() queries bypass the scope engine (spec §4.2). Use Eloquent
 * models with BelongsToScope instead.
 */
final class NoRawTableQueries implements ArchitectureRule
{
    public function description(): string
    {
        return 'Business code must not use DB::table(); use scoped Eloquent models.';
    }

    public function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            if (preg_match('/\bDB::table\s*\(/', $file->code) === 1) {
                $violations[] = "{$file->path} uses DB::table().";
            }
        }

        return $violations;
    }
}
