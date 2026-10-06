# Pathology Network Platform — Phase-wise Build Plan

Source of truth: `pathology-backend-spec.md` (the spec). Binding coding rules: `.agents/AGENT_DEV.md` (code quality) and `.agents/AGENT_RESTAPI.md` (API design). This plan turns the spec's nine phases (spec §12) into buildable steps, adds a Phase 0 for groundwork, fixes ordering gaps between phases, and resolves the places where the spec and the agent guides disagree.

---

## 0. Where we start from

| Item | Current state | Needed |
| --- | --- | --- |
| Framework | Laravel 12 skeleton, untouched | Keep; add Sanctum (`php artisan install:api`) |
| PHP | 8.2.33 locally | **Decided: stay on PHP 8.2** (`composer.json` requires `^8.2`; CI runs 8.2). Do not use 8.3+ language features. Pin 8.2 in the Hostinger panel |
| Database | `DB_CONNECTION=sqlite` | **Done:** local MariaDB 13 through Laravel's `mysql` driver (works with MySQL 8 and MariaDB). Databases `pathology_api` and `pathology_api_test`, collation `utf8mb4_0900_ai_ci`, session time zone UTC. CI tests on both MySQL 8 and MariaDB |
| Default migrations | Laravel `users` (int id, password) | Replace: spec `users` has uuid ids and no password (credentials live in `accounts`) |
| Version control | Not a git repo | `git init` first; every phase lands as reviewable commits |
| Tests | PHPUnit 11 | Keep PHPUnit; add architecture tests (see 0.6) |

---

## 1. Decisions: where the spec and `.agents/` disagree

The agent guides are binding for code the agent writes; the spec is the build reference. Where they clash, this plan picks one answer so every endpoint is consistent (AGENT_RESTAPI rule 8). **Confirm these before Phase 0 code starts** — changing them later is a breaking API change.

| # | Topic | Spec says | Guide says | Plan decision |
| --- | --- | --- | --- | --- |
| D1 | Error body | RFC 7807 problem JSON with `code` | `{ "error": { code, message, status, details[] } }` | Use the guide's shape everywhere, keeping the spec's stable codes (`PRICE_MISSING`, `WALLET_INSUFFICIENT`, …). One exception renderer produces it |
| D2 | Pagination | Cursor, `?cursor=&limit=` (max 200), `next_cursor` | `page/pageSize` example, but the hard rule is "same envelope on every list" | Cursor pagination (needed at 200k rows/day) in one envelope: `{ "data": [...], "pagination": { "next_cursor": "...", "limit": 50 } }` on every list endpoint |
| D3 | JSON casing | snake_case | Pick one, never mix | snake_case for JSON bodies **and** query keys (`filter[branch_id]`, `sort=-created_at`) |
| D4 | Verbs in URLs | `/orders/quote`, `/payments/online/create-link`, `/routing/resolve` | No verbs as resource names | Rename: `POST /order-quotes`, `POST /payment-links`, `GET /routing-resolutions?branch_id=&test_ids[]=`. Same rule for any new endpoint |
| D5 | State transitions | `POST /orders/{id}/cancel`, `/franchises/{id}/suspend`, `/reports/{id}/sign` … | Prefer `PATCH {status}`; POST action sub-resource allowed for non-CRUD triggers | Keep `POST /{resource}/{id}/{action}` (the guide's allowed exception). Transitions carry side effects, different permissions per transition and must go through one state-machine service (spec §5), which a generic `PATCH status` hides. `PATCH` is never allowed to change a `status` column |
| D6 | Status codes | 404 for out-of-scope rows | 403 for authenticated-but-not-allowed | Both: missing **permission** → 403; row outside **scope** → 404 (spec §4.4). Creates return 201 + `Location`; async work (PDF, settlement build) 202; business-rule failure 422; state conflict / stale `If-Match` → 409 / 412 |
| D7 | Money | Strings with two decimals | (silent) | `"1250.00"` strings in JSON; `decimal(12,2)` in DB; a `Money` value object in PHP (never float) |
| D8 | Versioning | `/api/v1` | Pick one strategy | URL versioning, `/api/v1`. Every response-shape change is classified breaking/non-breaking in the PR |

Spec open decisions (spec §12) assumed by this plan, per the spec's own recommendations: separate `test_reference_ranges` table; support both franchise billing models; franchise labs may sign with HQ-set signatory rows; report shows brand + collection centre + processing lab NABL; withhold reports for unpaid dues = B2B only, per client; wallet auto-hold = warn at 80%, block only with HQ Finance approval; VPS hosting (Chromium PDFs, Supervisor); one organization in v1 with `organization_id` everywhere; per-scope patient history. **Retention policy must be in writing before Phase 5.**

---

## 2. Code layout and rules applied in every phase

### Module layout (spec §2: modular monolith, eight modules + shared)

```
app/
  Modules/
    Auth/        Network/     Catalogue/   Booking/
    Samples/     Lab/         Ledger/      Locker/
    Shared/      (audit, notifications, numbering, files, money)
      <Module>/
        Models/         Eloquent models (scoped ones use BelongsToScope)
        Enums/          PHP backed enums mirroring the CHECK lists (spec §6)
        Services/       Public API of the module — the only thing other modules call
        Domain/         Pure decision logic (pricing, routing, flagging, settlement maths)
        StateMachines/  One per status column; the only writer of `status`
        Http/           Controllers, FormRequests, API Resources
        Events/ Listeners/ Jobs/
        Contracts/      Vendor interfaces (SmsSender, PaymentGateway, …)
        Infrastructure/ Vendor adapters + Fake adapters
  routes/api/v1/<module>.php
```

### Rules from `.agents/AGENT_DEV.md`, made concrete

| Guide rule | How it shows up in this codebase |
| --- | --- |
| Main path easy to follow | Controllers: validate (FormRequest) → call one service method → return Resource. Services guard with early returns / domain exceptions |
| Name by meaning | Domain words from the spec glossary: `ProcessingBranchResolver`, `PartnerPriceResolver`, `LedgerPostingService`, `ReportReleaseService` — no `Helper`, `Manager`, `Util`, `data` |
| External systems behind a boundary | Every vendor behind an interface in `Contracts/` (`SmsSender`, `WhatsAppSender`, `PaymentGateway`, `PdfRenderer`, `AbdmClient`, `KycVerifier`, `ESignProvider`, `Geocoder`). Adapters map vendor payloads into our DTOs; vendor field names never leave `Infrastructure/`. A `Fake*` adapter exists for each, used in local and tests |
| Invalid states harder to represent | Backed enums + DB CHECK; `CHECK ((a IS NULL) <> (b IS NULL))` for exactly-one-of columns; state machines with an explicit transition table; value objects (`Money`, `Barcode`, `Uhid`, `AbhaNumber`) |
| Separate decisions from actions | Pricing, routing, reference-range flagging, settlement maths, wallet sufficiency, signatory eligibility are pure classes in `Domain/` taking plain inputs and returning results — unit-tested without the DB. Services do the I/O around them |
| Useful errors | One `DomainException` base with `errorCode`, HTTP status, safe message, `details`. Logs carry `request_id`, `user_id`, `branch_id`; never names, phones, ABHA, OTPs, tokens (spec §11) |
| Focused changes | One step below ≈ one PR. No drive-by refactors across modules |

### Rules from `.agents/AGENT_RESTAPI.md`, made concrete

Every new endpoint passes the guide's pre-flight checklist. Built once in Phase 0 and reused: the error renderer (D1), the pagination envelope (D2), ISO-8601 UTC timestamps, 201 + `Location`, `Idempotency-Key` middleware on money/order POSTs (stored 24h), `ETag` / `If-Match` on mutable resources, query conventions (`filter[...]`, `sort`, `q`, `cursor`, `limit`). OpenAPI 3.1 generated from code (e.g. `dedoc/scramble`) and checked in CI so response-shape changes are visible in review.

### Definition of done for every phase

1. Migrations follow spec §6 exactly (uuid `char(36)`, `datetime(6)`, decimals, CHECK enums, named FK indexes, restrict/cascade as listed).
2. Every status change goes through its state machine and writes `audit_logs`.
3. Feature tests: each endpoint × each role, including the negative "other franchise gets 404" cases (spec §11).
4. Unit tests for every `Domain/` class.
5. Architecture tests green (0.6).
6. OpenAPI regenerated; breaking changes called out.
7. The phase's "done when" scenario from spec §12 runs as an automated end-to-end feature test against the seed data.

---

## Phase 0 — Groundwork (new; no business features)

Goal: every later phase only writes business code; conventions already exist.

1. **Repo and environment** — `git init`; `.gitignore` covers `.env`; local MariaDB (no Docker, by decision) with a separate test database; `.env.example` for MySQL/MariaDB. Two DB users in production: migration user (DDL) and app user (DML only; SELECT/INSERT only on `audit_logs`, `record_access_logs`, `partner_ledger`) — spec §4.5, §10.8; `php artisan db:app-user-grants <user>` prints the per-table grants.
2. **Packages** — Sanctum, Larastan (PHPStan), Pint, an OpenAPI generator, Sentry SDK. Nothing else until a phase needs it.
3. **Migration toolkit** — base conventions as Blueprint macros: `$table->uuidPrimary()`, `$table->standardTimestamps()` (datetime(6)), `$table->actorColumns()`, `$table->money('amount')`, `$table->enumString('status', StatusEnum::class)` (varchar(32) + CHECK built from the PHP enum so they cannot drift), `$table->exactlyOneOf(a, b)`, `$table->uniqueWhere([cols], condition, flag)` (partial uniqueness), `$table->searchableText(col)` (ngram on MySQL, plain FULLTEXT on MariaDB), `$table->sha256(col)`. Replace Laravel's default `users` migration.
   - **Deviation from spec §6:** the spec's `active_key = IF(status = 'active', franchise_id, NULL)` + UNIQUE is rejected by MariaDB (an indexed generated column may not return another text column). `uniqueWhere` uses a numeric flag `IF(condition, 1, NULL)` + UNIQUE(columns, flag) instead; same guarantee, works on both databases.
   - Index names over 64 characters fail on MySQL/MariaDB: name long composite indexes explicitly.
4. **Base model** — `HasUuids` (time-ordered), UTC, `created_by/updated_by` filled from the authenticated actor, soft deletes only on master tables (spec §6.4).
5. **API kernel** — `/api/v1` route group, error renderer (D1) mapping validation/auth/not-found/domain exceptions, pagination envelope (D2), `Idempotency-Key` middleware + `idempotency_keys` table, `ETag`/`If-Match` middleware, request-ID middleware, JSON structured logging with PII scrubbing, money-as-string casting.
6. **Shared services** — `NumberSequenceService` (`number_sequences` table, `SELECT … FOR UPDATE`, per-FY reset, configurable formats like `INV/{branch_code}/{FY}/{seq}`), `AuditLogger`, `StateMachine` base (transition table + guard + audit + event), `PrivateFileStore` (paths under `storage/app/private`, ID-based names, signed temporary routes, access logging hook).
7. **Architecture tests (CI static checks)** — fail the build if: `DB::table()` touches a scoped table; a module imports another module's `Models\` (must go through `Services\`); a controller writes a `status` attribute; vendor SDK classes are used outside `Infrastructure/`; `float` appears in money code.
8. **CI pipeline** — Pint, Larastan, migrations on MySQL 8, tests, OpenAPI diff, dependency audit.

Done when: `GET /api/v1/health` returns 200 on MySQL/MariaDB, an intentional domain exception renders the D1 shape, and arch tests fail on a deliberately bad sample.

**Status: complete.** 69 tests green on MariaDB 13 (unit, feature, architecture); Larastan level 6 clean; Pint clean; `docs/openapi.json` exported.

---

## Phase 1 — Foundation (spec §12 Phase 1)

Module: **Auth + RBAC**, **Network** (structure only), **Shared** (audit).

1. **Schema** — `organizations`, `regions`, `branches`, `roles`, `permissions`, `role_permissions`, `users`, `accounts`, `auth_sessions`, `otp_verifications`, `password_resets`, `audit_logs`. Also create the **bare** `franchises` and `b2b_clients` tables now (users and branches have FKs to them); their workflows arrive in Phase 6. Price-list FKs (`branches.mrp_price_list_id`, `franchises.partner_price_list_id`) are added by a Phase 2 migration once `price_lists` exists.
2. **Seeders** — permissions catalogue (every permission named in spec §4 and §8), system roles (`is_system = true`) with their scope levels, one organization, Super Admin account.
3. **Auth** — `POST /auth/login` (Argon2id; lock after 5 failures; MFA challenge for Super Admin / HQ Finance / Franchise Manager / signatories, TOTP), `POST /auth/refresh` (rotating refresh tokens, SHA-256 hash in `auth_sessions`), `POST /auth/logout`, `GET /me` (user, role, permissions, resolved scope). Access tokens 15 min. Patient/doctor OTP guards stubbed for Phase 3/7. Rate limits on login.
4. **Scope engine** (the riskiest piece; build it first and test it hardest)
   - `ScopeContext` resolved once per request: organization, region IDs incl. descendants, franchise ID, branch IDs, b2b client ID (spec §4.1).
   - `BelongsToScope` trait + global scope; each scoped model declares *how* it is scoped (by `branch_id`, `franchise_id`, `processing_branch_id`, `b2b_client_id`, `region_id`).
   - Explicit `SystemScope` for jobs/console; an empty scope throws instead of returning everything (spec §4.3).
   - Out-of-scope → 404 (D6).
   - Role guard: a role can never hold a permission above its scope level; a user's scope column must match their role's scope level (service rule, spec §7.2).
5. **Endpoints** — `GET/PATCH /organization`, CRUD `/regions` (tree), CRUD `/branches` (filters `owner_type`, `branch_type`, `region_id`), CRUD `/users`, `POST /users/{id}/disable`, CRUD `/roles`, `PUT /roles/{id}/permissions`, `GET /audit-logs`.
6. **Tests** — the scope matrix: for each scope level, a user sees exactly the rows they should and gets 404 on a sibling branch/region/franchise. This test suite is the guard the database cannot give us (no RLS).

Done when (spec): a Super Admin creates a branch and a branch user who sees only that branch.

**Status: complete.** 134 tests green (scope matrix, sign-in/MFA/refresh, staff and role management, branches/regions, end-to-end scenario); Larastan level 6 and Pint clean; OpenAPI regenerated.

Decisions made while building Phase 1:
- **`users` is owned by the Auth module**, not Network (spec §2 table). Role, account and scope checks always need user + role + account together; splitting them would force every query through cross-module services. Network keeps organizations, regions, branches, franchises, B2B clients.
- **Permission catalogue lives in code** (`Auth\Permissions\Permission` enum: module, allowed scope levels, MFA flag). The `permissions` table is synced from it on deploy (`PermissionSeeder`); the spec's schema is unchanged. System roles are defined in `SystemRole`.
- **Role assignment rule** (spec "cannot grant a role above own scope"): assignment checks scope only, so a Branch Admin can hire Front Desk staff without holding `create_order`. *Editing* a role's permissions additionally requires holding each permission granted.
- **MFA** is required for any role holding `manage_roles`, `manage_organization`, `approve_settlement`, `post_ledger_adjustment`, `onboard_franchise`, `approve_agreement`, `sign_report` or `amend_report`. Flow: `POST /auth/login` → `mfa_enrollment_required` / `mfa_required` + challenge token → `POST /auth/mfa-enrollments` (first time) → `POST /auth/mfa-verifications` → tokens. TOTP (RFC 6238), challenges encrypted in the database cache for 5 minutes.
- **`POST /auth/refresh` takes the refresh token in the body and needs no access token** (the access token has usually expired by then). Refresh rotates; the old pair stops working immediately.
- **Extra endpoints**: `POST /users/{id}/enable`; `POST /branches/{id}/activate|suspend|close` (branch state machine); `GET /permissions` (catalogue for role editors).
- **Scope engine**: `ScopeContext` (Shared) + `BelongsToScope`/`HasScopeColumns` on every scoped model; models declare their scope columns; a missing column for a level means "no rows" (fail closed); no scope set throws `MissingScope`. Organization-wide rows (the organization itself, later the catalogue) use `visibleToWholeOrganization`. Scope bypasses are only allowed in allowlisted files (architecture test).
- **Not yet built** (deferred to the phase that needs them): staff invitations (`users.status = invited`) need notifications (Phase 3); OTP login and password reset tables exist but their flows come with patient/doctor login (Phase 3/7).
- `DomainError`s (4xx business errors) are not reported to logs/Sentry; only unexpected failures are.

---

## Phase 2 — Catalogue, pricing and routing (spec §12 Phase 2)

Module: **Catalogue + routing**.

1. **Schema** — `departments`, `tests`, `test_parameters`, `test_reference_ranges`, `packages`, `package_tests`, `price_lists` (one default MRP per org via generated-column unique key), `price_list_items` (exactly one of test/package), `lab_test_capabilities`, `test_routing_rules`; add deferred price-list FKs to `branches`/`franchises`. FULLTEXT ngram on `tests.name`.
2. **Domain (pure, unit-tested)**
   - `MrpPriceResolver`: branch MRP list → org default list.
   - `PartnerPriceResolver`: franchise partner list or B2B client list; missing item → `PRICE_MISSING`.
   - `ProcessingBranchResolver`: rules for (source, test) by priority → (source, null) → source itself; first lab with an active capability wins; else `NO_ROUTE_FOR_TEST` (spec §7.4).
   - `ReferenceRangeSelector`: by gender and age in days; used in Phase 5 for flagging.
3. **Endpoints** — CRUD `/departments`, `/tests`, `/tests/{id}/parameters`, `/packages`, `/price-lists`; `PUT /price-lists/{id}/items` (bulk), `POST /price-lists/{id}/imports` (CSV, 202 + validation report); `PUT /branches/{id}/capabilities`; CRUD `/routing-rules`; `GET /routing-resolutions`; `POST /order-quotes` (D4 — prices, package expansion, routing, and a wallet-check placeholder that Phase 6 fills).
4. **Seed data** — 50 tests across 7 departments with parameters and ranges, 5 packages, MRP list, a partner list, a client list, capabilities and routing for the seed network (spec §11.6).

Done when (spec): `POST /order-quotes` returns correct price and processing lab for any branch.

**Status: complete.** 166 tests green; Larastan level 6 and Pint clean; OpenAPI regenerated. The done-when scenario runs against the full demo network (company PSC, branch MRP override, franchise PSC with partner price, B2B client at a lab, package expansion, analyser-off and suspended-lab fallback).

Decisions made while building Phase 2:
- **Quote logic is pure** (`Catalogue\Domain`: `QuoteBuilder`, `ProcessingBranchResolver`, `MrpPrices`, `PriceBook`); `OrderQuoteService` only loads data. Phase 3 booking must call the same service so a quote and its order always agree.
- **Every problem is reported at once**: a 422 carries the first problem's code (`PRICE_MISSING`, `NO_ROUTE_FOR_TEST`, `TEST_INACTIVE`, `DUPLICATE_TEST`, …) and one `details` entry per failing item (`field: items.N`).
- **Routing follows spec §7.4 literally**: rules before "the source itself". So a clinical lab must not have a default rule to the reference lab (it would send away routine tests it can run); it gets per-test rules for specialised tests only. Only active labs with an active capability receive work.
- **Partner price**: franchise branch → franchise's partner list; B2B booking → client list; company walk-in → 0. A franchise with no partner list yet charges partner price 0 (revenue-share model); a list that lacks the item blocks booking.
- **Visibility**: the catalogue, lab capabilities and routing rules are readable organization-wide (a front desk must route to labs outside its scope); price lists and price endpoints are `manage_price_lists` only. B2B clients are also visible to the branch that services them.
- **Found and fixed**: a franchise front desk (branch scope) cannot see the franchise row, which would have silently priced franchise bookings at partner price 0. The partner list is now read at organization level for a branch the caller can see; a regression test guards it.
- Models mirror database column defaults so create responses report stored values.
- `POST /price-lists/{id}/imports` is synchronous and all-or-nothing (CSV `item_type,code,price`); it returns 200 with created/updated counts or 422 with one detail per bad row.
- Extra endpoints: `GET /branches/{id}/capabilities`, `GET /price-lists/{id}/items`, scoped `/tests/{test}/parameters/{parameter}`.
---

## Phase 3 — Booking and billing, ABHA M1 (spec §12 Phase 3)

Module: **Booking + billing**. Pull forward from Phase 5 the **notification plumbing** (templates, `notifications`, `SmsSender`/`WhatsAppSender` interfaces with log/fake adapters) because booking confirmations need it.

1. **Schema** — `patients` (with ABHA columns from spec §5.7, FULLTEXT ngram on name, B-tree phone, unique UHID and ABHA number), `doctors` (no commission fields — legal rule), `orders`, `order_items`, `home_collections`, `invoices`, `payments`, `refunds`, `payment_webhook_events`, `abdm_requests`, `notification_templates`, `notifications`.
2. **Patients** — `GET /patients?q=` (phone / UHID / name / ABHA; network-wide search, scoped history), `POST /patients` (UHID from HQ sequence), `GET /patients/{id}`, `POST /patients/{id}/merge` (`merged_into_id`, reads follow the pointer). Masked phone in lists. CRUD `/doctors`.
3. **Orders** — `POST /orders` (Idempotency-Key) runs the spec §8 transaction: resolve prices + routing, expand packages into child lines (price 0, `parent_item_id`), create order, items, invoice (number from sequence), payment; events dispatched **after commit**. Order state machine (spec §5.2). `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel` (refund + ledger reversal hook), `POST /orders/{id}/items` (supplementary invoice). Discount above threshold needs approval.
4. **Partner-charge extension point** — `PartnerChargePolicy` interface called at confirmation. Phase 3 ships the company-branch implementation (no ledger). Phase 6 adds wholesale / revenue-share / B2B implementations without touching the booking service. Suspended franchise → booking blocked.
5. **Billing** — `GET /invoices`, `GET /invoices/{id}/pdf` (private storage, scope check, access log), `POST /invoices/{id}/payments`, `POST /payments/{id}/refunds`, `POST /payment-links` (D4). Invoice payment-status machine. `PaymentGateway` interface + Razorpay adapter; `POST /webhooks/razorpay`: store in `payment_webhook_events` first, verify signature, dedupe on (gateway, event_id) and (gateway, transaction_id), process in a job.
6. **Home collection (booking side)** — order source `home_collection` creates the row with geocode (`Geocoder` interface, cached on row); `GET /home-collections?date=`, `POST /home-collections/{id}/assign`, `POST /home-collections/{id}/status` (state machine, GPS on collect).
7. **ABHA M1** — `AbdmClient` interface (sandbox URL in dev/staging), the ten endpoints of spec §5.7, `abdm_requests` logging with masked payloads, gateway token cached in DB cache, ABDM public-key encryption for Aadhaar/OTP/mobile, consent version recorded, demographic mismatch shown never overwritten, **Aadhaar never stored**, ABHA never blocks booking. Prerequisite: ABDM sandbox onboarding request filed during Phase 1 (it takes time).
8. **Tests** — concurrency test: parallel invoice numbering never duplicates; idempotent `POST /orders` replay returns the original 201; webhook replay does not double-post.

Done when (spec): a company-owned PSC books, bills and collects payment end to end.

**Status: complete** (ABHA M1 against a fake ABDM client). 201 tests green; Larastan level 6 and Pint clean; OpenAPI regenerated. The done-when runs through the API: register patient → book → pay at the desk (UPI/cash) or online (payment link → signed Razorpay webhook, applied once despite retries) → order confirmed → booking message sent after commit.

Decisions made while building Phase 3:
- **Booking reuses `OrderQuoteService`** so a quote and the order it becomes always agree; prices are snapshotted on `order_items`.
- **Payment rule** (when draft → confirmed): B2B (credit) and home collection at once; online when fully paid; walk-in/camp when the advance in `organizations.settings.walk_in_advance_percent` is paid (default 100%). Invoice `payment_status` is derived from amounts, never written directly.
- **Bill-level discount** is spread over priced lines pro rata in whole paise (largest remainder), so each line's `net_price` is right for Phase 6 commissions. `invoices.amount` is the gross; `discount` the total discount; `total` = amount − discount + tax. Discounts above `discount_approval_percent` (default 10%) need `approve_discount` (new permission; Branch Admin and Super Admin).
- **Branch Admin** also holds the desk permissions (`register_patient`, `create_order`, `collect_payment`) — small PSCs run that way, and the approver must be able to book.
- **Additions to the spec schema**: `organization_id` on `invoices`, `home_collections` and `notifications` (for organization scoping); `abha_number_hash` (keyed blind index, the ABHA number itself is encrypted per §10.2); `consent_notice_version`, `consented_at`, `whatsapp_opted_in_at` on patients (§9 rule 3, DPDP).
- **ABHA endpoints renamed to nouns (D4)**: `POST /abha-verifications`, `POST /abha-verifications/{txn}/confirmation`, `POST /abha-qr-scans`, `POST /abha-enrolments`, `…/{txn}/confirmation`, `…/{txn}/address`, `GET /patients/{id}/abha-card`, `DELETE /patients/{id}/abha-link`, `GET /abha-profile-shares`, `POST /abha-profile-shares/{id}/link`, `POST /abdm/callbacks/{type}`.
- **ABDM**: only `FakeAbdmClient` exists (OTP 123456). The real adapter (public-key encryption, gateway token cache, callback JWT verification) is built after sandbox onboarding, against the same `AbdmClient` interface.
- **Payments**: `PaymentGateway` interface; `RazorpayGateway` (links, refunds, webhook HMAC) and `FakePaymentGateway` (same webhook format, no network). `PAYMENT_GATEWAY=fake|razorpay`. Gateway webhooks are stored first, verified, then processed by a job with system scope; payments are unique per (gateway, transaction_id).
- **Partner charges**: `PartnerChargePolicy` is called on confirm/cancel inside the transaction; Phase 3 binds `DeferredPartnerCharges` (no-op). Phase 6 replaces it.
- **Notifications** (pulled forward): templates only, WhatsApp → SMS → email with opt-in, quiet hours 21:00–08:00 IST except urgent, fallback to the next channel after 3 failed tries. Vendors are `LogMessageSender` until chosen. Delivery webhooks come in Phase 5.
- **Not built yet**: invoice/receipt PDFs (Phase 5 brings the PDF renderer); a cancelled order's unpaid invoice stays `unpaid` (no `cancelled` invoice status in the spec — a credit-note flow needs the CA's input); geocoding (`DisabledGeocoder` until a maps vendor is chosen).
- **Verified**: parallel invoice numbering with 4 forked processes (100 numbers, unique and gap-free); FULLTEXT name search on committed rows; the Aadhaar number never appears in `patients`, `abdm_requests` or `audit_logs`.
---

## Phase 4 — Samples and logistics (spec §12 Phase 4)

Module: **Samples + logistics**.

1. **Schema** — `samples` (barcode unique org-wide), `sample_order_items`, `sample_transfers` (from ≠ to), `sample_transfer_items`.
2. **Domain** — `SampleGrouper`: one sample per container type, linked to the order items it serves; `StabilityChecker` for transit limits.
3. **Endpoints** — `POST /orders/{id}/samples` (barcodes from sequence, ZPL label payload), `GET /samples/{barcode}`, `POST /samples/{id}/collect`, `POST /samples/{id}/assign-barcode` (pre-printed stock for offline desks), `POST /manifests`, `POST /manifests/{id}/dispatch`, `POST /manifests/{id}/receive` (list of barcode + condition; <1 s per scan), `POST /samples/{id}/reject`.
4. **State machines** — samples and manifests per spec §5.4. Rejection is terminal and creates a no-charge recollection item linked via `recollection_of_id`; lab can re-route a sample onward with a new manifest.
5. **Events/jobs** — `SampleCollected` (add to open manifest for its lab), `ManifestDispatched/Received` (missing-sample flag after 2 h), `SampleRejected` (notify branch + patient, recollection, wallet reversal hook for Phase 6), Transit-delay monitor (every 30 min).
6. **Scope check** — processing lab sees other branches' samples by `processing_branch_id` but **not** their invoices/payments/ledger (spec §4 special rule) — explicit tests.

Done when (spec): a sample travels PSC → clinical lab → reference lab with full trace.

**Status: complete.** 223 tests green; Larastan level 6 and Pint clean; OpenAPI regenerated (additive only: new endpoints, plus `recollection_of_item_id` on order items). The done-when runs through the API: book CBC + lipid at a PSC → containers and ZPL labels generated on confirmation → phlebotomist collects (order → `in_progress`) → tubes auto-bagged → runner dispatches → clinical lab scans in → its chemistry analyser goes down → lab re-routes the lipid tube to the reference lab → forwarded on a new manifest → reference lab scans in → `GET /samples/{barcode}` shows both legs to the reference lab and to the PSC, and every status change is audited.

Decisions made while building Phase 4:
- **Where a sample is vs where it is tested.** `samples.processing_branch_id` is the lab that runs its tests; a new `current_branch_id` is where the tube physically is. A manifest from X to Y only takes samples that are at X and destined for Y. That keeps "one manifest, one destination" (spec §5.4) and makes the hub path explicit: a lab re-routes a sample (`POST /samples/{id}/reroute`), which changes its destination and the order items' `processing_branch_id`, then forwards it on a new manifest.
- **Scope**: samples are visible to the collecting branch, the processing lab and the branch holding them now; manifests to both ends of the route. `ScopeColumns` gained `otherBranches` for the extra columns. Seeing is not acting: only the holding branch collects, rejects or re-routes, only the sender dispatches, only the receiver scans in (`ScopeContext::coversBranch`, 403 otherwise). A processing lab still gets 404 on the booking branch's orders and invoices (tested).
- **Sample state machine additions**: `collected → received` (a sample drawn at the lab that tests it is accessioned there, `POST /samples/{id}/receive`) and `received → in_transit` (forwarding after a re-route).
- **Containers**: one per (processing lab, container type, sample type); a tube never goes to two labs. Generated automatically on `OrderConfirmed` and again on demand by `POST /orders/{id}/samples` (idempotent; add-on tests get new containers; 201 when something was created, 200 otherwise). The order row is locked while planning so the listener and the desk never double up. Barcodes: organization series `S{seq:9}` (configurable `barcode_format`), never restarting; pre-printed stock is assigned with `assign-barcode` before collection and generated numbers skip any barcode already in use.
- **One open bag per route**, enforced by the database (`uniqueWhere` on from/to while `created`). Collected and re-routed samples join it automatically; `POST /manifests` for a route with an open bag answers 409 `MANIFEST_ALREADY_OPEN` with its ID. Extra endpoints: `GET /manifests`, `GET /manifests/{id}`, `POST /manifests/{id}/samples`, `DELETE /manifests/{id}/samples/{sample_id}`, `GET /samples` (filters, `q` = barcode).
- **Receipt is scan-driven**: one call per scan or per batch; repeating a scan with the same finding is a no-op, contradicting it is 409 `SAMPLE_ALREADY_RECEIVED`. The manifest is `partially_received` until every sample is scanned. A delayed job (`samples.missing_after_minutes`, 120) then alerts both ends about unscanned samples, once (`missing_flagged_at`).
- **Rejection**: reason from the `samples.rejection_reasons` config list plus a free-text note. The rejected sample's order items become `recollect`; free replacement lines (`order_items.recollection_of_item_id`, new column) keep the original turnaround from now; a redraw sample waits at the collecting branch with `recollection_of_id`. Branch (SMS) and patient (their channel) are told. The `SampleRejected` event is the Phase 6 hook for partner-charge reversal.
- **Stability**: `samples.stable_until` (new column) = collection time + the shortest `stability_hours` of its tests. The transit monitor (every 30 min) alerts both ends per manifest, once per sample (`delay_alerted_at`); manifests show `stability_exceeded` per sample.
- **Labs see patient identity, not contact details**: sample views carry name, UHID, age, gender and order number (for labels and accession) via `Booking\Services\OrderFulfilment`, never phone, address, invoices or payments.
- **Deviation from spec §7.6**: `sample_transfers.dispatched_by` is nullable, because a bag fills up before anyone dispatches it.
- Manifest numbers `M{branch_code}-{seq:6}` per sending branch (configurable `manifest_number_format`; the spec's 30-character limit rules out a financial-year form with long branch codes).
- **Not built yet**: samples of an order cancelled after confirmation stay `pending_collection` (collecting them is refused; the spec has no exit for them, so this needs a decision). Storage, discard and the sample discard list come with the retention policy (Phase 5 prerequisite). Phase 5 must treat `recollect` lines as superseded by their replacement when deciding an order is complete.
---

## Phase 5 — Lab, signing and reports (spec §12 Phase 5)

Module: **Lab + signing**. Prerequisite: written retention policy.

1. **Schema** — `signatories`, `lab_results` (run_no history), `reports` (versioned, `qr_code`, `pdf_sha256`), `report_signatures`.
2. **Domain** — `ResultFlagger` (low/high/critical from `ReferenceRangeSelector`), `CalculatedParameterEvaluator` (e.g. LDL formula; safe expression evaluator, never `eval`), `SignatoryEligibility` (active row for user + processing lab + department, `valid_till` not passed, discipline matches), `ReportReleaseReadiness` (every department signed).
3. **Endpoints** — CRUD `/signatories` (signature image to private storage), `GET /worklist?department=&status=`, `POST /results` (bulk, `If-Match`), `POST /results/{id}/verify`, `POST /order-items/{id}/rerun`, `GET /reports?status=`, `POST /reports/{id}/sign` (re-asks PIN/TOTP), `POST /reports/{id}/release`, `POST /reports/{id}/amend` (new version, reason required, old PDF kept), `GET /reports/{id}/pdf`, public `GET /verify/{qr_code}` (initials, test names, date, lab only; rate-limited).
4. **Interface agent API** — `POST /agent/results`, `GET /agent/orders?since=`; per-lab API key + IP allow-list; accepts replayed results with original timestamps, idempotent per (order item, parameter, run). The on-prem ASTM/HL7 agent itself is a separate deliverable (track separately; contract tests with recorded payloads here).
5. **PDF and delivery** — `PdfRenderer` interface (Browsershot adapter on VPS); render on `ReportReleased`, store under `private/reports/{yyyy}/{mm}/{id}_v{n}.pdf`, hash, never regenerate in place. Notifications: channel preference with WhatsApp → SMS → email fallback, WhatsApp PDF only after opt-in, quiet hours except critical values, delivery webhooks (`/webhooks/whatsapp`, `/webhooks/sms-dlr`), signed links 24–72 h.
6. **Events/jobs** — `ResultCritical` (immediate alert; synchronous after commit on shared hosting), `ResultsVerified`, `ReportReleased`, `ReportAmended`, TAT breach monitor (15 min), signatory auto-disable (daily), withheld-report policy for unpaid B2B (configurable).

Done when (spec): a signed report reaches the patient on WhatsApp within 2 minutes of release.

**Status: complete** (retention-dependent parts excepted, see below). 272 tests green; Larastan level 6 and Pint clean; OpenAPI regenerated (additive only: 22 new paths, no changed responses). The done-when runs through the API: CBC + lipid booked at a PSC → tubes scanned in at the clinical lab put both tests on its worklist and open its report → technician enters results (LDL/VLDL calculated, flags from the patient's range) → a correction needs the ETag → supervisor verifies → pathologist and biochemist each sign their department with an authenticator code → release → PDF rendered, stored and hashed → the patient's WhatsApp link arrives in the same minute, opens the stored PDF without sign-in, and the order is `completed`. The QR page confirms the report without showing results.

Decisions made while building Phase 5:
- **One report per order and processing lab** (spec: per order). An order split between labs (Phase 4 re-routing) gets one report from each, signed by that lab's signatories and printed with its NABL details; the order is `partially_reported` until every lab has released. Unique key `(order_id, processing_branch_id, version)`, plus one live version per pair.
- **Worklist entries** (new table `worklist_entries`): one per test at the lab that runs it, opened when its sample is accepted there (manifest scan or accession; new `SampleReceived` event). They carry the run counter, the TAT clock and the department, and are what the worklist, report readiness and the agent host query read. A sample re-routed onward or rejected withdraws its unfinished entries. Opening an entry moves the order item to `processing`.
- **Results**: `POST /results` takes one test (`order_item_id`) and its values by parameter code; every bad value is reported at once. Numbers may carry an analyser qualifier (`<0.5`). Calculated parameters use a small arithmetic parser (numbers, codes, `+ - * /`, parentheses; never `eval`) and are recalculated when inputs change. Changing stored results needs `If-Match` with the ETag from `GET /order-items/{id}/results`. `lab_results.source` adds `calculated`.
- **Verification and reruns**: `POST /results/{id}/verify` (one value) and `POST /order-items/{id}/verify` (all values of the current run). `POST /order-items/{id}/rerun` keeps the old run (no longer final), voids that department's signature and sends the report back to draft; refused once the report is released (amend first). Sample `processed → in_process` added for reruns.
- **Signing**: `POST /reports/{id}/sign` re-asks the TOTP code (spec §10.4; no separate PIN). A department can be signed as soon as its own tests are verified. Eligibility is a pure rule: active row for user + lab + department, `valid_till` not passed, discipline covers the department. **Pathologists may sign every discipline; biochemists and microbiologists only their own** — confirm with the lawyer (spec §10). `signatories.signing_discipline` is new (instead of parsing the free-text qualification). Signatures are revoked (`revoked_at`), never deleted.
- **Release is explicit** (`release_report`, lab staff only), not automatic on the last signature. **Withholding**: new `b2b_clients.withhold_reports_when_overdue` (default off); a signed report for such a client with any invoice past due and unpaid becomes `withheld`; releasing it again works once the dues are paid. No automatic release on payment yet.
- **Amendment**: the released version becomes `amended` (superseded), the next version starts with the reason and goes through signing again; its release sends `report_amended`. Tests that arrive after release (redraw, add-on) also start a new version automatically ("Tests added after the previous version was released"); the earlier release is marked `is_partial`.
- **PDF**: `PdfRenderer` with `ChromiumPdfRenderer` (headless Chromium via Symfony Process, no Browsershot package) and `FakePdfRenderer` (`PDF_RENDERER=fake|chromium`). QR codes via `chillerlan/php-qrcode` behind `QrCodeRenderer`. Rendered once on `ReportReleased` (critical queue), stored at `reports/{yyyy}/{mm}/{id}_v{n}.pdf`, hashed, never regenerated.
- **Delivery**: patient by preference (WhatsApp only after opt-in), doctor by `report_delivery`, both with a 48-hour signed link (`/report-links/{id}`); idempotent per report. Delivery webhooks `/webhooks/sms-dlr` and `/webhooks/whatsapp` accept our own signed JSON format (`X-Signature: sha256=…`) until vendors are chosen; statuses only move forward. A failed delivery receipt does not trigger the next channel yet.
- **Interface agent**: `interface_agents` table (per-lab key shown once, SHA-256 stored, IP/CIDR allow-list); `POST /interface-agents`, `…/{id}/revoke` (`manage_branches`). `GET /agent/orders?since=` and `POST /agent/results` (always 200 with accepted / unchanged / rejected per row; replays are unchanged even after verification; `run_no` older than the current run is `RUN_CLOSED`). Recorded payload in `tests/Fixtures/Agent/`.
- **Access**: report PDFs and signed-link views are audited as `report.pdf_viewed` until Phase 7 brings `record_access_logs`. Labs see patient identity on the worklist, never contact details or invoices.
- **Jobs**: TAT breach monitor (15 min, once per test, lab + booking branch), signatory auto-disable (daily 00:15 IST); critical values alert the booking branch, the lab and the doctor immediately, ignoring quiet hours.
- **Not built yet**: sample storage/discard and the discard list (waiting for the written retention policy); partial release by choice (a report covers every verified test at the lab); critical-value callback logging; invoice/receipt PDFs (the renderer now exists). Pre-existing issue found: `RequirePermission` runs after route-model binding, so a missing permission on an out-of-scope ID answers 404 instead of 403 (D6) — fix in the middleware priority.

---

## Phase 6 — Franchise, B2B and money (spec §12 Phase 6)

Modules: **Network + franchise** (workflows), **Ledger + settlement**, **Samples** (inventory).

1. **Schema** — `franchise_agreements` (one active per franchise via generated column), `franchise_territory_pincodes`, `franchise_documents`, `partner_ledger` (append-only), `settlements`, `settlement_items` (unique `ledger_entry_id`), `inventory_items`, `stock_transfers`.
2. **Onboarding** — franchise and agreement state machines (spec §5.1), `POST /franchises/{id}/documents` (multipart → private storage), `POST /franchise-documents/{id}/verify`, `KycVerifier` interface (store result + reference only), agreements CRUD, `POST /agreements/{id}/send-for-sign`, `ESignProvider` + `/webhooks/esign` → `AgreementSigned` (activate, post fee + deposit), `POST /franchises/{id}/suspend|activate|terminate`. Territory exclusivity check on pincodes. CRUD `/b2b-clients`.
3. **Ledger** — `LedgerPostingService` is the only writer: lock partner row, compute `balance_after`, insert, update `current_balance`, commit. Posting table from spec §7.8 implemented as pure `PostingRules` per model (wholesale / revenue-share / B2B).
4. **Plug into earlier phases** — `PartnerChargePolicy` implementations (wallet debit at confirmation, `WALLET_INSUFFICIENT` when balance + credit_limit is short), cancellation and rejection reversals, kit supply debit on stock-transfer receipt.
5. **Endpoints** — `GET /ledger?party=`, `POST /ledger/adjustments`, `POST /wallet/topups` (returns payment link; credit on `PaymentCaptured`), `GET /settlements`, `POST /settlements/{id}/approve|dispute|mark-settled`, `GET /settlements/{id}/statement`, CRUD `/inventory-items`, CRUD `/stock-transfers`, `POST /stock-transfers/{id}/receive`, dashboards `GET /dashboards/hq|region/{id}|franchise/{id}|branch/{id}` from nightly summary tables.
6. **Jobs** — settlement builder (daily 02:00 on cycle ends; pure `SettlementCalculator`), B2B dues reminder, wallet low-balance alert, franchise auto-hold (off by default), expiry alerts (agreements, NABL, KYC, signatories, inventory).
7. **Money tests** — property-based: sum of ledger rows = `current_balance`; a ledger row is never settled twice; parallel wallet debits never exceed `credit_limit`.

Done when (spec): one franchise per billing model and one B2B client run a full monthly settlement.

**Status: complete** (KYC and e-sign against fake vendors). 310 tests green; Larastan level 6 and Pint clean; OpenAPI regenerated (42 new paths; the only changed response is `wallet_check` on `POST /order-quotes`, which was always `null` and is now an object or `null`, so non-breaking). The done-when runs through the API and the nightly job: in October Gaya Diagnostics (wholesale) tops up its wallet through a payment link and webhook, books tests charged at partner price, cancels one (reversed), and receives kits from the reference lab (charged); Dhanbad Health Point (revenue share, 30%) takes cash at its desk and an online payment that HQ's gateway collects; Patna City Hospital books on credit at its rate list. On 1 November the job builds three settlements (wallet in credit → nil; revenue share → franchise pays HQ its share less commission; hospital → pays its charges). Statements are rendered and announced, HQ Finance approves and records the UTRs, the postpaid accounts return to zero, the hospital's invoice is marked paid, and rerunning the job builds nothing.

Decisions made while building Phase 6:
- **Module ownership**: Network owns franchises, agreements (with the territory child table), KYC papers and B2B clients; Ledger (new module) owns `partner_ledger`, `settlements`, `settlement_items` and `wallet_topups`; Samples owns `inventory_items` and `stock_transfers`; a small Dashboards module owns the nightly `daily_branch_metrics`. Ledger reads and locks partner rows only through `Network\Services\PartnerAccounts`.
- **Additions to the spec schema**: `organization_id` on agreements, documents, settlements, inventory and stock transfers (organization scoping); `franchise_agreements.signed_at`; `partner_ledger.idempotency_key` (unique: the same event can never post money twice, spec §9); `settlements.closing_balance` and `dispute_note`; `stock_transfers.dispatched_at` and `cancelled_reason`; tables `wallet_topups` and `daily_branch_metrics`.
- **Posting** (spec §7.8): `LedgerPostingService` is the only writer. It locks the partner row, skips postings whose idempotency key already exists, checks the wallet when asked, and dates each row strictly after the partner's previous one, so time order is posting order. One debit row per priced order item (package children and free recollections cost nothing). The posting rules are pure (`PostingRules`).
- **Partner charges**: `LedgerPartnerCharges` replaces the Phase 3 placeholder. Wholesale is debited at confirmation and refused with `WALLET_INSUFFICIENT` (422) when balance + credit limit is short; B2B is debited on credit without a check; revenue share is charged at settlement; company branches post nothing. A franchise without an agreement in force cannot take orders (`FRANCHISE_AGREEMENT_INACTIVE`). **Found and fixed**: add-on tests on a confirmed order were never charged; they are now. Cancellation reverses every unreversed charge. Rejected samples are redrawn free, so the charge stands unless the organization setting `reverse_partner_charge_on_rejection` is on.
- **Settlement maths** (pure `SettlementCalculator`): a settlement covers every unsettled row up to the period end, plus, for revenue share, the rows posted at close (debit desk cash collected, credit `commission_pct` × net billed; online payments are already HQ's). The amount to settle is the balance at the period end: negative → partner pays HQ; positive → paid out only to a revenue-share franchise (earned commission); a prepaid wallet or B2B advance stays in the account. Shares: revenue share → partner = commission; wholesale → HQ = test charges net of reversals, partner = billing minus that; B2B → all HQ. Tax is 0 until the CA confirms GST on royalty and fees. `mark-settled` posts the payment (`payment_received` / `payout`), links it to the settlement and needs the UTR unless nothing is payable.
- **Cycles and the builder**: weekly runs Monday to Sunday, fortnightly 1–15 / 16–end, monthly by calendar month (B2B clients monthly); the job runs daily at 02:00 IST. A partner has at most one open settlement: later cycles wait and are covered together once it is settled. Rerunning builds nothing.
- **B2B clients are billed at their rate list** (spec glossary). **Found and fixed**: Phase 3 raised B2B invoices at patient MRP while the ledger charged the client price. Invoices now use the client price, and discounts on B2B orders are refused (`B2B_DISCOUNT_NOT_ALLOWED`). Paying a B2B settlement also pays the client's open invoices of that period, oldest first, so overdue reminders stop and withheld reports can be released. A client on hold or closed cannot book (`B2B_CLIENT_ON_HOLD`). Clients are closed, never deleted (no DELETE route).
- **Onboarding** (spec §5.1): the first paper moves the franchise to `kyc_pending`; the KYC vendor check runs in a job and can only auto-verify; a person verifies or rejects every paper; once all mandatory papers are verified (config `franchise.mandatory_documents`, plus the GST certificate when the franchise has a GSTIN), the franchise is `approved`. An agreement goes for e-sign only once KYC is approved (or for a renewal), wholesale needs a partner price list first, and territory pincodes held by another franchise's agreement out for signature or in force are refused (`TERRITORY_CONFLICT`, 409, with the holder and the pincodes). The signed webhook stores the signed PDF, expires the previous agreement, activates the new one, posts the fee and deposit (`AgreementSigned`, in the same transaction) and takes the franchise live when it also has a branch; creating its first branch does the same. A declined or expired signature sends the agreement back to `draft` (addition to the state table). Franchise termination ends the agreement in force; termination is Super Admin only (`manage_organization`). Branches are not activated automatically at go-live.
- **Vendors**: `KycVerifier` and `ESignProvider` interfaces with `FakeKycVerifier` / `FakeESignProvider`. The e-sign webhook uses our own signed JSON format (`X-Signature: sha256=…`, secret `ESIGN_WEBHOOK_SECRET`) until a vendor is chosen. The agreement and settlement statement PDFs use the Lab module's `PdfRenderer`.
- **Wallet top-ups**: `POST /wallet/topups` creates a gateway payment link tagged `wallet_topup_id`; the Razorpay webhook job raises `PaymentCaptured` and the Ledger credits what was actually paid, once. Payment-link requests now carry a purpose (invoice or top-up). Credit limits on franchises and B2B clients can be set only by staff holding `post_ledger_adjustment` (`CREDIT_LIMIT_NEEDS_FINANCE`).
- **Inventory**: either end may request a transfer; only the sender dispatches (stock leaves; `STOCK_INSUFFICIENT` otherwise), only the receiver receives (stock lands, the batch is created if new), and cancelling a dispatched transfer returns the stock. Only a company branch sending to a franchise branch may charge, and only the sending side sets the charge; it is debited to the franchise on receipt (`kit_supply`). Quantities are exact two-decimal strings. Stock rows are deleted only when empty.
- **Jobs**: settlement builder (02:00), wallet low balance (hourly, once a day per franchise, at 80% of the credit limit or below `ledger.low_balance_threshold`), auto-hold (03:00, off unless `LEDGER_AUTO_HOLD`, 15 grace days, suspends franchises / puts B2B clients on hold), B2B dues reminder (10:00), agreement expiry (00:20), expiry alerts for agreements, KYC papers, NABL, signatories and stock exactly 60 and 30 days before (09:00), dashboard summary (01:30).
- **Dashboards**: `GET /dashboards/hq|region/{id}|franchise/{id}|branch/{id}?from=&to=` read only the nightly per-branch summary (orders, cancellations, tests, billing, collections, rejections, reports released, TAT breaches) and add dues: owed to HQ across partners on the HQ dashboard, the wallet on the franchise dashboard. Rebuilding a day replaces it.
- **Visibility**: branch staff never see the ledger, settlements or wallets; franchise owners and B2B client users see only their own; front desks read the B2B clients their branch services.
- **Not built yet**: the minimum-monthly-business shortfall rule (HQ Finance posts it as a `min_business_shortfall` adjustment until the rule is defined); GST on settlements (waiting for the CA); real KYC and e-sign adapters; Tally/Zoho export (Phase 9). The settlement builder settles a B2B client's invoices only through `mark-settled`; there is no separate invoice-level payment flow for B2B yet.

---

## Phase 7 — Health locker, Engine 17 (spec §12 Phase 7)

Module: **Health locker**.

1. **Schema** — the 12 tables of spec §7.11.
2. **Patient guard** — OTP login (`/auth/otp/request`, `/auth/otp/verify`; 5-min expiry, 5 attempts, 30 s resend, daily cap), family switch on one phone.
3. **Bridge** — `ReportReleased` listener creates `medical_records` + `pathology_reports` + `pathology_results` from final results (idempotent).
4. **Endpoints** — `GET /me/reports`, `GET /me/records` (timeline), `POST /me/records` (upload → `private/uploads/locker/{patient_id}/`, versions never overwritten), `POST /me/shares`, `DELETE /me/shares/{id}`, `GET /me/family`, trends per parameter code. Doctor guard: reports shared by consent only.
5. **Rules** — every view/download writes `record_access_logs`; a share is valid only while its consent is granted and unexpired; revocation is immediate; reminders job.

Done when (spec): a patient sees all their reports and trends across branches.

**Status: complete.** 330 tests green; Larastan level 6 and Pint clean; OpenAPI regenerated (additive: 23 new paths; no response changed. The export also drops `children` from the *documented* required fields of `GET /regions/{region}` — a Scramble inference-order artifact, the endpoint is untouched). The done-when runs through the API: Asha has a CBC in Patna in August (haemoglobin low) and in Ranchi in October → each release puts the report in her locker → she signs in with the code sent to her phone → `GET /me/reports` lists both labs, `GET /me/records/{id}/file` serves the very PDF the lab released, `GET /me/trends/HB` shows both values with labs and flags → Ranchi amends its report and the locker swaps to the new version without losing the trend → her daughter on the same phone is switchable, a stranger is a 404 both ways → every opening is in `record_access_logs`, and `locker:backfill` adds nothing twice.

Decisions made while building Phase 7:
- **Module ownership**: a new Locker module owns the twelve tables. Auth owns OTPs and person accounts (`OtpChallenges`, `PersonAccounts`, the `person:patient|doctor` guard); Booking answers who a phone belongs to (`PeopleDirectory`); Lab hands over released reports (`ReleasedReports`). Locker depends on all three, none on Locker; Lab tells it about PDF views by event.
- **OTP endpoints renamed to nouns (D4)**: `POST /auth/otp-challenges` (always 202 with the same body, whether or not the phone is known; a code is sent only to phones on record) and `POST /auth/otp-verifications` (tokens, as staff sign-in). Codes: 6 digits, HMAC-SHA-256 with the app key (a plain hash of six digits is brute-forced instantly), 5 minutes, 5 tries, newest code only, 30 s between codes, 10 a day per phone. Sent straight to `SmsSender`, never through `notifications`. Rate limits per IP and per phone, separately for requests and verifications. `POST /auth/logout` now works for any account.
- **One login per phone; family switch**: the account belongs to the earliest patient on the phone without a guardian (pure `AccountHolder`). At each sign-in everyone else on that phone becomes a family member (`child` when their guardian is the holder, else `other`); removed members stay removed (`family_members.deleted_at`). A family member is opened with the `X-Patient-Id` header; anyone else is 404 `PROFILE_NOT_FOUND`. Records of patients merged into a profile are included; a merged holder's login follows the merge.
- **Patient and doctor requests have no network scope**: the guard sets an `external` actor (no `users` row, so actor columns stay null) and no `ScopeContext`; locker tables are reached only through the viewer's patient IDs, and any stray query on a scoped table fails closed.
- **Bridge**: `ReportReleased` → queued copy of every printed result. Each released version gets its own record; a correction marks the previous one `superseded_by_id` (addition), so the timeline and trends show the live version while the old record and its access history remain. The file of a lab record is the lab's stored PDF, not a copy. `record_date` is the order date (when the sample was taken). Amended versions copied by the backfill use today's results (one worklist per order and lab) and are superseded anyway.
- **Access log**: detail views, file downloads, trends (one row per record behind the values), shares and revocations; lists of titles and dates are not logged. Staff and signed-link report PDF views moved from `report.pdf_viewed` audit rows to `record_access_logs` (`staff` / `report_link`). `GET /me/record-access-logs` shows the patient who looked (doctors and staff by name).
- **Shares**: one consent per share (granted at once by the patient, for a purpose and 1–30 days, default 7) and one `record_shares` row per record. Doctors are found by phone among registered referring doctors (`DOCTOR_NOT_REGISTERED` otherwise) and see shares under `GET /me/shared-records` after their own OTP sign-in; email and link shares get one 48-character token per record, stored as SHA-256 and shown once (link) or emailed (email; never SMS). The public `GET /shared-records/{token}` shows the record and who it is about (name, UHID, gender, age; no contact details). Validity is checked on every read (share and consent active and unexpired), so revocation and expiry are immediate; the nightly job only records `expired`. `consents.scope` holds the record IDs. Consent, share and reminder statuses have state machines.
- **Additions to the spec schema**: `record_categories.code`; `medical_records.superseded_by_id`; `pathology_results.test_code/test_name`; nullable `record_shares.access_token_hash` (doctor shares; CHECK keeps it required for links and email); `medical_reminders.sent_at`; `family_members.deleted_at`. `patient_health_profiles.abha_id` is left empty: the ABHA number stays encrypted on the patient (§10.2).
- **Extra endpoints**: `GET /me/records/{id}`, `GET /me/records/{id}/file?version=`, `POST /me/records/{id}/documents` (new version of an upload), `GET /me/trends`, `GET /me/trends/{code}`, `GET /me/shares`, `GET /me/shares/{id}`, `POST/GET/PATCH/DELETE /me/family/{id}` (dependants without a UHID; relation of linked members), `GET/PUT /me/health-profile`, `GET/POST /me/reminders`, `POST /me/reminders/{id}/dismiss`, `GET /me/record-access-logs`, `GET /me/shared-records{/id,/id/file}`, `GET /shared-records/{token}{,/file}`.
- **Jobs**: reminders every 15 minutes (quiet hours from the notification service); share expiry daily 00:30 IST; both page by ID. `php artisan locker:backfill` fills the locker with reports released before this phase (run once on deploy).
- **Not built yet**: log archiving of `record_access_logs` (with the cross-cutting archive job); patient erasure requests (§10 retention); ABDM consent artefacts (`requested`/`denied` consents, `external_health_records`) come with Phase 8, DigiLocker with Phase 9; push notifications.

---

## Phase 8 — ABDM M2 (and optional M3) (spec §12 Phase 8)

1. `abdm_care_contexts`; care-context linking on report release for ABHA-linked patients.
2. HIP flows: `/abdm/callbacks/*` with gateway signature verification, consent artefacts mirrored into `consents`, FHIR `DiagnosticReport` bundles (needs `tests.loinc_code`), encryption of shared data, HPR ID per signatory, HFR ID per lab.
3. Contract tests with recorded sandbox payloads; then sandbox certification.
4. M3 (HIU pull into the locker) only if prioritised.

Done when (spec): sandbox certification passed.

---

## Phase 9 — DigiLocker and extras (spec §12 Phase 9)

DigiLocker pull into the locker (separate onboarding), home-collection route optimisation (auto-assign by distance), Tally XML / Zoho export of sales and settlement journals. As prioritised.

---

## Cross-cutting work that runs alongside the phases

| Item | When |
| --- | --- |
| ABDM sandbox onboarding request, HFR registration for labs | **File now** (Phase 1 done; long lead time) |
| DLT template registration (SMS), WhatsApp template approval | File during Phase 2 for Phase 3/5 messages |
| Legal: retention periods, DPDP consent notice text, CA confirmation on GST exemption | Before Phase 5 / before launch |
| Scheduled ops jobs: orphan account check, log archiving to `*_archive`, cache/OTP pruning, backup restore drill, disk-usage alert | Introduced in the phase that creates the tables they touch; backup drill before first production deploy |
| Observability: Sentry, structured logs, queue-age and webhook-failure alerts | Phase 0 baseline, extended per phase |
| Deployment: Supervisor workers (`critical,default,low`), cron `schedule:run`, private storage permissions, India region | Before the first staging deploy (end of Phase 3) |

## Phase dependency summary

```
Phase 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9
                     │         │    ▲
                     │         └────┤  Phase 6 plugs PartnerChargePolicy and ledger
                     └──────────────┘  reversals into Phase 3–4 extension points
```
