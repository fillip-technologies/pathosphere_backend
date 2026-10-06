<?php

namespace App\Modules\Catalogue\Services;

/** A test as the lab reports it: its department and its parameters in print order. */
final class TestResultDefinition
{
    /** @param  list<ParameterDefinition>  $parameters */
    public function __construct(
        public readonly string $testId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $departmentId,
        public readonly ?string $method,
        public readonly array $parameters,
        /** For ABDM: the report is shared as a FHIR DiagnosticReport coded by LOINC. */
        public readonly ?string $loincCode = null,
    ) {}

    public function parameterByCode(string $code): ?ParameterDefinition
    {
        foreach ($this->parameters as $parameter) {
            if (strcasecmp($parameter->code, $code) === 0) {
                return $parameter;
            }
        }

        return null;
    }

    public function parameterById(string $id): ?ParameterDefinition
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->id === $id) {
                return $parameter;
            }
        }

        return null;
    }
}
