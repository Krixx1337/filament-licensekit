# Individual license extension and pause

## Summary

Add staff-only `extend`, `pause`, and `resume` to existing entitlement management. Reuse clock, authorization, audit, and invalidation event. Two nullable columns; no new module, dependency, endpoint, queue, or scheduler.

TODO needs integration fixes before implementation: outage compensation can overwrite extensions, paused records currently appear expired or lifetime, and session invalidation requires host listeners.

## Core behavior

- Add `ManageEntitlements::extend(Entitlement $entitlement, int $seconds, Model $actor, string $reason): void`, plus `pause` and `resume` with same arguments except seconds.
- Require separate `extend`, `pause`, and `resume` policy abilities. Trim reasons; require 1–2000 characters. Extension accepts 1–315360000 seconds, matching existing compensation limit.
- Reject lifetime, revoked, or ownerless entitlements. Blocked licenses remain manageable; clock changes never clear block or revocation flags.
- Extend active licenses from current expiry; expired licenses from now. Extend paused licenses by adding to banked seconds. Keep arithmetic in `EntitlementClock`.
- Pause requires positive remaining time. Store UTC time rounded to seconds, bank remaining seconds, clear expiry. Repeated pause returns conflict.
- Resume requires paused state with positive banked seconds. Set expiry to now plus balance; clear pause fields. Repeated resume returns conflict.
- Audit successful operations as `entitlement_extended`, `entitlement_paused`, and `entitlement_resumed`, recording before/after clock fields and added seconds where applicable.
- Dispatch existing `EntitlementAccessInvalidated` on pause, inside transaction, following current event convention. Host owns session deletion; bind and verify reject paused access independently.

## Storage and integration

- Edit initial core migration. Add nullable `dateTime('paused_at')` and nullable `unsignedBigInteger('remaining_seconds')`; update model casts, PHPDoc, and factory state. No conversion migration or default database reset.
- Paused state has timestamp and positive balance, with null expiry. Running state has null pause fields. `EntitlementClock::active()` returns false while paused.
- Reject paused access before host-free fallback with `subscription_paused`, HTTP 403. Apply restriction to shared access checks too.
- Reject timed and lifetime key claims while paused, leaving key unclaimed. Guard clock grants against accidental paused-state bypass.
- Add guard in existing `OutageState`: individual clock changes return `operation_conflict`, HTTP 409, while product has unfinished outage or pending, processing, or failed compensation. Honor stored operations even when module disabled; tolerate absent optional tables when disabled.
- Exclude paused entitlements from product-wide eligibility and compensation application. This includes historical manual-credit selection. No overlapping pause/outage accounting.
- Run mutations transactionally with existing lock order: actor/owner rows in stable order, product, entitlement, then operation checks. Reload records before authorization and calculation. Use three transaction attempts. Laravel recommends transactional pessimistic locking for these checks. [Laravel locking docs](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking)

## Admin and public interfaces

- Add “Extend time”, “Pause”, and “Resume” row actions through existing `LicensingActions`. Extension form accepts positive integer duration, Hours or Days, and required reason. Pause/resume require reason.
- Show eligible actions based on state and permission; enforce every rule again in backend. Surface operation conflicts with clear instruction to finish or cancel product compensation. Filament supports modal schemas and action authorization. [Filament actions](https://filamentphp.com/docs/5.x/actions/overview)
- Show paused state and banked duration. Fix “Ends” placeholder so only lifetime records display “Lifetime”.
- Add `paused_at` and `remaining_seconds` to entitlement JSON resource and customer summary. Summary precedence: revoked, blocked, paused, expired, active.
- Update existing client and installation docs with PHP calls, errors, outage restriction, host invalidation responsibility, and Shield abilities. No new documentation files.

## Verification and defaults

- Cover active, expired, and paused extension; pause/resume across elapsed time; exact expiry boundary; UTC and second precision.
- Cover invalid duration/reason, unauthorized calls, lifetime/revoked/deleted-owner records, repeated transitions, preserved block flags, and transaction rollback when listener fails.
- Verify paid and host-free access denied while paused, host session cleanup event, rejected timed/lifetime claims, and successful access after resume.
- Verify outage conflicts, disabled-module stored operations, paused exclusion from credits, and successful changes after completion/cancellation.
- Extend existing MySQL concurrency tests for competing extension/pause, claim/pause, and outage-start/pause. Assert serialized outcomes with no lost time.
- Test Filament permissions, forms, action eligibility, and paused display. Use fresh disposable databases and existing compatibility CI.
- Run affected Pest tests, PHPStan, and required Pint formatting. Then request full-suite run using package’s `composer test`.

Confirmed defaults: staff controls only; paused claims rejected; pause blocks host-free access; individual clock changes wait for unsettled product operations. Extension can reactivate expired licenses.
