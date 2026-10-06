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

    // Lab and reports (spec §5.5, §9).
    'lab' => [
        // Patient and doctor links to a report PDF expire after this many hours (spec §9 rule 2: 24–72).
        'report_link_hours' => 48,
        // Public QR verification requests per minute per IP address (spec §10.5).
        'verify_requests_per_minute' => 30,
    ],

    // Franchise onboarding (spec §5.1). Papers every franchise must have verified
    // before its KYC is approved; the GST certificate is added for GST-registered ones.
    'franchise' => [
        'mandatory_documents' => ['pan', 'address_proof', 'bank_proof', 'premises_photo'],
    ],

    // Partner ledger, wallets and settlements (spec §5.6, §9, §12 open decisions).
    'ledger' => [
        // Reasons for manual adjustments, a lookup list (spec §6): add, never rename a key in use.
        'adjustment_reasons' => [
            'billing_correction' => 'Billing correction',
            'goodwill' => 'Goodwill credit',
            'deposit_refund' => 'Security deposit refund',
            'min_business_shortfall' => 'Minimum business shortfall',
            'penalty' => 'Penalty per agreement',
            'opening_balance' => 'Opening balance',
            'other' => 'Other',
        ],
        // Warn a wholesale franchise when it uses this share of its credit limit (spec §12: 80%).
        'credit_warning_percent' => 80,
        // …or when its available money (balance + credit limit) falls below this.
        'low_balance_threshold' => '2000.00',
        // Rejected samples are redrawn free, so the original charge stands unless HQ
        // turns this on (organization setting `reverse_partner_charge_on_rejection`).
        'reverse_partner_charge_on_rejection' => false,
        // Franchise auto-hold (spec §9): off by default; blocks only with HQ Finance's say-so.
        'auto_hold' => [
            'enabled' => (bool) env('LEDGER_AUTO_HOLD', false),
            'grace_days' => 15,
        ],
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

    // Patient and doctor sign-in by OTP (spec §8.1, §10.3).
    'otp' => [
        'length' => 6,
        'expiry_minutes' => 5,
        'max_attempts' => 5,
        'resend_after_seconds' => 30,
        'daily_limit_per_phone' => 10,
        // DLT template ID of the OTP SMS (spec §3); fill in once registered.
        'sms_template_id' => env('OTP_SMS_TEMPLATE_ID'),
        'care_context_link_sms_template_id' => env('OTP_LINK_SMS_TEMPLATE_ID'),
    ],

    // Patient health locker (spec §7.11, Engine 17).
    'locker' => [
        // Patient uploads: PDF or photo.
        'upload_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'upload_max_kb' => 10240,
        // A share lasts this many days unless the patient picks fewer.
        'share_default_days' => 7,
        'share_max_days' => 30,
        // Public share-link views per minute per IP address (spec §10.5).
        'share_link_requests_per_minute' => 30,
    ],

    // ABDM milestone M2: our labs as Health Information Providers (spec §5.7).
    'abdm' => [
        // Link each released report to the patient's ABHA when they have one.
        'link_on_release' => (bool) env('ABDM_LINK_ON_RELEASE', true),
        // ABDM's link token for a patient at one of our facilities, kept encrypted in the cache.
        'link_token_ttl_minutes' => 43200,
        // A link request with no answer after this long is sent again by the retry job.
        'link_retry_after_minutes' => 30,
        'link_max_attempts' => 5,
        // From the patient's choice of reports in their ABHA app to the code they confirm with.
        'link_session_minutes' => 10,
        // Care contexts per page pushed to a health information user.
        'transfer_page_size' => 10,
        // How long the public key we send with encrypted data is valid.
        'transfer_key_valid_minutes' => 1440,
    ],

];
