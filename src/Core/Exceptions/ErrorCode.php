<?php

namespace LicenseKit\Core\Exceptions;

enum ErrorCode: string
{
    case AlreadyLifetime = 'already_lifetime';
    case BatchConflict = 'batch_conflict';
    case Cancelled = 'cancelled';
    case CannotExtendLicense = 'cannot_extend_license';
    case CannotPauseLicense = 'cannot_pause_license';
    case CannotRestoreLicense = 'cannot_restore_license';
    case CannotResumeLicense = 'cannot_resume_license';
    case CannotSuspendLicense = 'cannot_suspend_license';
    case CannotUnsuspendLicense = 'cannot_unsuspend_license';
    case CustomerResetDisabled = 'customer_reset_disabled';
    case DeviceBanned = 'device_banned';
    case DeviceMismatch = 'device_mismatch';
    case DeviceNotBound = 'device_not_bound';
    case EmailNotVerified = 'email_not_verified';
    case Expired = 'expired';
    case InvalidCredentials = 'invalid_credentials';
    case InvalidDevice = 'invalid_device';
    case InvalidDuration = 'invalid_duration';
    case InvalidEligibilityDate = 'invalid_eligibility_date';
    case InvalidKey = 'invalid_key';
    case InvalidKeyNote = 'invalid_key_note';
    case InvalidKeyStatus = 'invalid_key_status';
    case InvalidPageSize = 'invalid_page_size';
    case InvalidReason = 'invalid_reason';
    case InvalidResetReason = 'invalid_reset_reason';
    case InvalidRevocationReason = 'invalid_revocation_reason';
    case InvalidSuspensionEnd = 'invalid_suspension_end';
    case InvalidToken = 'invalid_token';
    case KeyAlreadyUsed = 'key_already_used';
    case KeyGenerationFailed = 'key_generation_failed';
    case KeyRevocationForbidden = 'key_revocation_forbidden';
    case LicenseStillActive = 'license_still_active';
    case LicensingUnavailable = 'licensing_unavailable';
    case Maintenance = 'maintenance';
    case MoveCooldown = 'move_cooldown';
    case NoLicense = 'no_license';
    case Paused = 'paused';
    case PlanProductIsImmutable = 'plan_product_is_immutable';
    case PlanUnavailable = 'plan_unavailable';
    case SigningUnavailable = 'signing_unavailable';
    case Suspended = 'suspended';
    case UserUnavailable = 'user_unavailable';

    public function status(): int
    {
        return match ($this) {
            self::AlreadyLifetime, self::BatchConflict, self::CannotExtendLicense, self::CannotPauseLicense, self::CannotResumeLicense,
            self::CannotSuspendLicense, self::CannotUnsuspendLicense, self::CannotRestoreLicense, self::DeviceNotBound, self::KeyAlreadyUsed,
            self::KeyRevocationForbidden, self::LicenseStillActive, self::PlanProductIsImmutable => 409,
            self::InvalidCredentials, self::InvalidToken => 401,
            self::KeyGenerationFailed, self::LicensingUnavailable, self::SigningUnavailable => 503,
            self::InvalidDevice, self::InvalidDuration, self::InvalidEligibilityDate, self::InvalidKey, self::InvalidKeyNote, self::InvalidKeyStatus, self::InvalidPageSize,
            self::InvalidReason, self::InvalidResetReason, self::InvalidRevocationReason, self::InvalidSuspensionEnd => 422,
            self::MoveCooldown => 429,
            default => 403,
        };
    }
}
