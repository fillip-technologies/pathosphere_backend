<?php

namespace Tests\Support\Architecture;

interface ArchitectureRule
{
    /** One-line statement of the rule, shown when it fails. */
    public function description(): string;

    /**
     * @param  list<SourceFile>  $files
     * @return list<string> one message per violation, naming the file
     */
    public function violations(array $files): array;
}
