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

---

## Phase 7 — Health locker, Engine 17 (spec §12 Phase 7)

Module: **Health locker**.

1. **Schema** — the 12 tables of spec §7.11.
2. **Patient guard** — OTP login (`/auth/otp/request`, `/auth/otp/verify`; 5-min expiry, 5 attempts, 30 s resend, daily cap), family switch on one phone.
3. **Bridge** — `ReportReleased` listener creates `medical_records` + `pathology_reports` + `pathology_results` from final results (idempotent).
4. **Endpoints** — `GET /me/reports`, `GET /me/records` (timeline), `POST /me/records` (upload → `private/uploads/locker/{patient_id}/`, versions never overwritten), `POST /me/shares`, `DELETE /me/shares/{id}`, `GET /me/family`, trends per parameter code. Doctor guard: reports shared by consent only.
5. **Rules** — every view/download writes `record_access_logs`; a share is valid only while its consent is granted and unexpired; revocation is immediate; reminders job.

Done when (spec): a patient sees all their reports and trends across branches.

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
