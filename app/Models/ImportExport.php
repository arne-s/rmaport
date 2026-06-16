<?php

namespace App\Models;

use App\Support\ImportExportNumberSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $import_id
 * @property string $uid
 * @property string $file_disk
 * @property string $file_name
 * @property int $user_id
 * @property Carbon|null $sent_at
 */
class ImportExport extends Model
{
    protected $table = 'exports';

    protected $fillable = [
        'import_id',
        'uid',
        'file_disk',
        'file_name',
        'user_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ImportExport $export): void {
            if (blank($export->uid)) {
                $export->uid = static::generateNextUid();
            }
        });
    }

    public static function generateNextUid(): string
    {
        return DB::transaction(fn (): string => ImportExportNumberSequence::next());
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
