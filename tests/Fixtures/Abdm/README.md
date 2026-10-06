# ABDM HIP callback payloads

Callback bodies as the ABDM gateway (v3 HIP APIs) sends them to our
`/api/v1/abdm/callbacks/hip/{type}` routes. Written from ABDM's published
documentation before sandbox access; replace each file with the recorded
sandbox payload during onboarding and keep the contract tests green
(`tests/Unit/Locker/AbdmHipMessagesTest.php`).

The request ID travels in the `REQUEST-ID` header and the facility in
`X-HIP-ID`; bodies alone do not carry them.

| File | Route type | ABDM API |
| --- | --- | --- |
| on-generate-token.json | link-token | on-generate-token |
| on-carecontext.json | care-contexts-linked | on_carecontext |
| discover.json | discover | patient/care-context/discover |
| link-init.json | link-init | link/care-context/init |
| link-confirm.json | link-confirm | link/care-context/confirm |
| consent-notify-granted.json | consent-notify | consent/request/hip/notify |
| consent-notify-revoked.json | consent-notify | consent/request/hip/notify |
| health-information-request.json | health-information-request | health-information/request |
