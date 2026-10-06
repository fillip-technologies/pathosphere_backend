<?php

namespace Tests\Support\Architecture;

/**
 * Vendor SDKs and outbound HTTP stay behind adapters in Infrastructure/
 * (AGENT_DEV rule 3, spec §3).
 */
final class VendorCodeStaysInInfrastructure implements ArchitectureRule
{
    private const VENDOR_NAMESPACES = ['Razorpay', 'Spatie\\\\Browsershot', 'Aws', 'SendGrid', 'Twilio', 'Google', 'GuzzleHttp', 'chillerlan'];

    public function description(): string
    {
        return 'Vendor SDKs and Http:: calls are only allowed in Infrastructure/ adapters.';
    }

    public function violations(array $files): array
    {
        $vendorPattern = '/\buse\s+\\\\?(?:'.implode('|', self::VENDOR_NAMESPACES).')\\\\/';
        $violations = [];

        foreach ($files as $file) {
            if ($file->isUnder('/Infrastructure/')) {
                continue;
            }

            if (preg_match($vendorPattern, $file->code) === 1) {
                $violations[] = "{$file->path} imports a vendor SDK outside Infrastructure/.";
            }

            if (preg_match('/\bHttp::\w+\s*\(/', $file->code) === 1) {
                $violations[] = "{$file->path} makes outbound HTTP calls outside Infrastructure/.";
            }
        }

        return $violations;
    }
}
