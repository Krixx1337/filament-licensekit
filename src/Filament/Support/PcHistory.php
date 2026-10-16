<?php

namespace LicenseKit\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;

/** The PC lock history of a license, read from the activity log, and the "changes PC too often" check. */
final class PcHistory
{
    public const EVENTS = ['device_locked', 'device_moved', 'device_reset'];

    /** Moves and resets in WINDOW_DAYS that count as suspicious, for example key sharing. */
    public const FREQUENT_CHANGES = 3;

    public const WINDOW_DAYS = 30;

    /** @return list<array{when: string, exact: string, what: string, who: string, pc: string, note: string|null}> */
    public static function for(License $license, int $limit = 50): array
    {
        return array_values(self::entries($license)->latest('id')->limit($limit)->get()->map(fn (ActivityEntry $entry): array => [
            'when' => $entry->created_at->diffForHumans(),
            'exact' => TimeLeft::date($entry->created_at),
            'what' => match (true) {
                $entry->event === 'device_locked' => 'Locked on first login',
                $entry->event === 'device_moved' => 'Moved by customer',
                ($entry->meta['after']['performed_by'] ?? null) === 'customer' => 'Reset by customer',
                default => 'Reset by staff',
            },
            'who' => (string) ($entry->meta['actor_label'] ?? $entry->actor),
            'pc' => match ($entry->event) {
                'device_locked' => self::label($entry->meta['after']['device'] ?? null),
                'device_moved' => self::label($entry->meta['before']['device'] ?? null).' → '.self::label($entry->meta['after']['device'] ?? null),
                default => self::label($entry->meta['before']['device'] ?? null).' → unlocked',
            },
            'note' => $entry->meta['note'] ?? null,
        ])->all());
    }

    /** Moves and resets in the last WINDOW_DAYS. The first lock does not count. */
    public static function recentChanges(License $license): int
    {
        return self::entries($license)->where('event', '!=', 'device_locked')->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))->count();
    }

    /**
     * IDs of licenses that changed PC FREQUENT_CHANGES times or more in the last WINDOW_DAYS.
     *
     * @return Builder<ActivityEntry>
     */
    public static function frequentChangerIds(): Builder
    {
        return ActivityEntry::query()->select('subject_id')->where('subject_type', (new License)->getMorphClass())
            ->whereIn('event', ['device_moved', 'device_reset'])->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->groupBy('subject_id')->havingRaw('COUNT(*) >= ?', [self::FREQUENT_CHANGES]);
    }

    /** A short name for a PC, the same every time it appears, without showing the fingerprint itself. */
    public static function label(?string $device): string
    {
        return $device === null || $device === '' ? 'unknown PC' : 'PC '.strtoupper(substr(hash('sha256', $device), 0, 6));
    }

    /** @return Builder<ActivityEntry> */
    private static function entries(License $license): Builder
    {
        return ActivityEntry::query()->where('subject_type', $license->getMorphClass())->where('subject_id', (string) $license->id)->whereIn('event', self::EVENTS);
    }
}
