# Decisions

2026-10-16. Pre-release simplification.

| Decision | Reason |
|---|---|
| D1. Account-first; keys are vouchers. | One customer/product license supports renewal and devices. |
| D2. Redeem on website or app after login. | One atomic service; host owns registration. |
| D3. Default replaceable app API. | Sellers need no custom protocol to begin. |
| D4. App move and website reset share cooldown. | Browser cannot read fingerprint; staff can override. |
| D5. Follow host MustVerifyEmail. | No second verification setting. |
| D6. Built-in device bans. | Catch returning accounts; warn about shared PCs. |
| D7. Scheduled per-product free window. | Set in admin, no host PHP parameter. |
| D8. Keep PHP 8.3+, Laravel 12/13, both Filament majors. | Preserve supported hosts and minimum versions. |
| D9. Delete temporary internal docs. | Replace contradictions with task guides. |
| D10. Keep pause/resume with banked time. | Redemptions and credits remain useful while paused. |

Owner clarifications during planning:

- Unlicensed login returns restricted redemption session. Token never implies software access.
- Suspension preserves pause; unsuspend restores it, then applies elapsed auto-resume from now.
- Cancellation blocks paid/free access and redemption until restored.
- Lifetime redemption clears pause and activates lifetime immediately.
- Keep flat user_guard and user_suspended_column integration options.
- Local staged commits; no push or merge unless requested.

Implementation defaults:

- Nine prefixed tables; one batch duration snapshot. Internal request hash checks idempotency without key duration copies.
- Hashed connection/session tokens replace Sanctum. Sessions store password fingerprint for invalidation.
- Ed25519 signs exact bytes plus nonce/timestamp. Persistent key remains private.
- Narrow row locks; issuance conflict recovery uses current MySQL reads to handle old host snapshots.
- Website 2FA is outside default app protocol. Custom protocols can add it.
- No deployed users on branch. Rewrite initial migration; rebuild earlier disposable alpha databases.
