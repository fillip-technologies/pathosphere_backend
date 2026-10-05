<?php

/*
| Business settings for the pathology network platform. Values that differ per
| environment come from the server's environment file (spec §3: never commit
| real secrets).
*/

return [

    // The head-office organization created by the seeder (spec §7.1).
    'organization' => [
        'name' => env('ORGANIZATION_NAME', 'Pathology Network'),
        'legal_name' => env('ORGANIZATION_LEGAL_NAME', 'Pathology Network Private Limited'),
        'hq_address' => env('ORGANIZATION_HQ_ADDRESS', 'Head Office'),
    ],

    // First Super Admin. Leave the password empty to generate a random one.
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@example.com'),
        'phone' => env('SUPER_ADMIN_PHONE', '9000000000'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    // Payments (spec §3). `fake` behaves like Razorpay without network calls.
    'payments' => [
        'gateway' => env('PAYMENT_GATEWAY', 'fake'),
        'link_expiry_hours' => 48,
    ],

    // Booking defaults; HQ can override most of them in organization settings.
    'booking' => [
        'walk_in_advance_percent' => '100',
        'discount_approval_percent' => '10',
    ],

    // Samples and logistics (spec §5.4). Rejection reasons are a lookup list
    // (spec §6): add reasons here, never rename a key that is in use.
    'samples' => [
        'rejection_reasons' => [
            'haemolysed' => 'Haemolysed',
            'clotted' => 'Clotted',
            'insufficient' => 'Insufficient volume',
            'leaked' => 'Leaked',
            'wrong_container' => 'Wrong container',
            'unlabelled' => 'Unlabelled',
            'temperature' => 'Temperature breach',
            'delayed' => 'Delayed beyond stability',
        ],
        // Grace period after a lab starts scanning a manifest before unscanned samples are flagged missing.
        'missing_after_minutes' => 120,
    ],

    // Staff authentication (spec §8.1, §10.3–10.4).
    'auth' => [
        'access_token_minutes' => 15,
        'refresh_token_days' => 30,
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
        'mfa_challenge_minutes' => 5,
        'mfa_max_attempts' => 5,
        'mfa_issuer' => env('MFA_ISSUER', env('ORGANIZATION_NAME', 'Pathology Network')),
    ],

];
