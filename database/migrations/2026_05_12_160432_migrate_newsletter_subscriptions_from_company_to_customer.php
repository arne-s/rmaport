<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy morph type stored before {@see \App\Models\Company} was removed in favour of {@see Customer}.
     *
     * @var list<string>
     */
    private const LEGACY_COMPANY_TYPES = [
        'App\Models\Company',
        'App\\Models\\Company',
    ];

    public function up(): void
    {
        $targetType = Customer::class;

        $legacyIds = DB::table('newsletter_subscriptions')
            ->whereIn('subscribable_type', self::LEGACY_COMPANY_TYPES)
            ->orderBy('id')
            ->pluck('id');

        foreach ($legacyIds as $id) {
            $row = DB::table('newsletter_subscriptions')->where('id', $id)->first();
            if ($row === null) {
                continue;
            }

            if (! in_array($row->subscribable_type, self::LEGACY_COMPANY_TYPES, true)) {
                continue;
            }

            $customerId = DB::table('customers')
                ->where('company_legacy_id', $row->subscribable_id)
                ->value('id');

            if ($customerId === null) {
                DB::table('newsletter_subscriptions')->where('id', $row->id)->delete();

                continue;
            }

            $conflict = DB::table('newsletter_subscriptions')
                ->where('subscribable_type', $targetType)
                ->where('subscribable_id', $customerId)
                ->where('segment_key', $row->segment_key)
                ->where('id', '!=', $row->id)
                ->first();

            if ($conflict !== null) {
                DB::table('newsletter_subscriptions')->where('id', $conflict->id)->update([
                    'subscribed' => (bool) $conflict->subscribed || (bool) $row->subscribed,
                    'needs_sync' => (bool) $conflict->needs_sync || (bool) $row->needs_sync,
                    'email' => $this->nonEmptyString($conflict->email) ? $conflict->email : $row->email,
                    'consented_at' => $this->laterNullableTimestamp($conflict->consented_at, $row->consented_at),
                    'consent_source' => $this->nonEmptyString($conflict->consent_source) ? $conflict->consent_source : $row->consent_source,
                    'last_synced_at' => $this->laterNullableTimestamp($conflict->last_synced_at, $row->last_synced_at),
                    'last_error' => $this->nonEmptyString($conflict->last_error) ? $conflict->last_error : $row->last_error,
                    'updated_at' => now(),
                ]);
                DB::table('newsletter_subscriptions')->where('id', $row->id)->delete();
            } else {
                DB::table('newsletter_subscriptions')->where('id', $row->id)->update([
                    'subscribable_type' => $targetType,
                    'subscribable_id' => $customerId,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible: legacy company ids cannot be restored from customer rows alone.
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function laterNullableTimestamp(mixed $a, mixed $b): ?Carbon
    {
        $ca = $this->parseNullableCarbon($a);
        $cb = $this->parseNullableCarbon($b);
        if ($ca === null) {
            return $cb;
        }
        if ($cb === null) {
            return $ca;
        }

        return $ca->greaterThan($cb) ? $ca : $cb;
    }

    private function parseNullableCarbon(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
};
