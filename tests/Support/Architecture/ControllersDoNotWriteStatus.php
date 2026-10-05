<?php

namespace Tests\Support\Architecture;

/**
 * Status columns change only through a StateMachine (spec §5).
 */
final class ControllersDoNotWriteStatus implements ArchitectureRule
{
    public function description(): string
    {
        return 'Controllers must not set a status column; call the entity\'s state machine.';
    }

    public function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            if (! $file->isUnder('/Http/Controllers/')) {
                continue;
            }

            if ($this->writesStatus($file->code)) {
                $violations[] = "{$file->path} writes a status value.";
            }
        }

        return $violations;
    }

    /** Model writes only; response arrays like ['status' => 'ok'] are fine. */
    private function writesStatus(string $code): bool
    {
        $patterns = [
            '/->status\s*=(?!=)/',
            '/->setAttribute\(\s*[\'"]status[\'"]/',
            '/->(?:update|fill|forceFill|create|updateOrCreate|firstOrCreate)\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return false;
    }
}
