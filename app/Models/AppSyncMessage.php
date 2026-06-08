<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppSyncMessage extends Model
{
    public const KIND_EXACT_CUSTOMER_SYNC = 'exact_customer_sync';

    public const KIND_EXACT_SUPPLIER_SYNC = 'exact_supplier_sync';

    public const KIND_EXACT_PRODUCT_SYNC = 'exact_product_sync';

    public const KIND_EXACT_INVOICE_SYNC = 'exact_invoice_sync';

    /**
     * Session key: na redirect (bijv. factuur verzonden) even pollen voor queue-toasts.
     */
    public const SESSION_DEFERRED_EXACT_SYNC_TOAST_POLLING = 'start_exact_sync_toast_polling';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    /** Toasts worden niet meer getoond als het bericht ouder is dan dit aantal minuten. */
    public const DISPLAY_MAX_AGE_MINUTES = 5;

    protected $fillable = [
        'user_id',
        'kind',
        'status',
        'title',
        'body',
        'metadata',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function queueForUser(
        int $userId,
        string $kind,
        string $status,
        string $title,
        ?string $body = null,
        ?array $metadata = null,
    ): void {
        self::query()->create([
            'user_id' => $userId,
            'kind' => $kind,
            'status' => $status,
            'title' => $title,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<string>
     */
    public static function exactSyncKinds(): array
    {
        return [
            self::KIND_EXACT_CUSTOMER_SYNC,
            self::KIND_EXACT_SUPPLIER_SYNC,
            self::KIND_EXACT_PRODUCT_SYNC,
            self::KIND_EXACT_INVOICE_SYNC,
        ];
    }

    public static function flashDeferredExactSyncToastPolling(): void
    {
        session()->flash(self::SESSION_DEFERRED_EXACT_SYNC_TOAST_POLLING, true);
    }
}
