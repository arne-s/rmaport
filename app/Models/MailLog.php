<?php

namespace App\Models;

use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\MailLogStatus;
use App\Models\Order\Main;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\MailLog
 *
 * @property int $id
 * @property string|null $from
 * @property string|null $to
 * @property string|null $cc
 * @property string|null $bcc
 * @property string $subject
 * @property string $body
 * @property string|null $headers
 * @property string|null $attachments
 * @property string|null $message_id
 * @property MailLogStatus|null $status
 * @property array|null $data
 * @property int $is_test
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $data_json
 * @method static Builder|MailLog newModelQuery()
 * @method static Builder|MailLog newQuery()
 * @method static Builder|MailLog query()
 * @method static Builder|MailLog whereAttachments($value)
 * @method static Builder|MailLog whereBcc($value)
 * @method static Builder|MailLog whereBody($value)
 * @method static Builder|MailLog whereCc($value)
 * @method static Builder|MailLog whereCreatedAt($value)
 * @method static Builder|MailLog whereData($value)
 * @method static Builder|MailLog whereFrom($value)
 * @method static Builder|MailLog whereHeaders($value)
 * @method static Builder|MailLog whereId($value)
 * @method static Builder|MailLog whereIsTest($value)
 * @method static Builder|MailLog whereMessageId($value)
 * @method static Builder|MailLog whereStatus($value)
 * @method static Builder|MailLog whereSubject($value)
 * @method static Builder|MailLog whereTo($value)
 * @method static Builder|MailLog whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class MailLog extends Model
{
    use HasFactory;

    const EMAIL_HEADER_MESSAGE_ID = 'X-SP-Message-Id';

    public const EMAIL_HEADER_TEMPLATE_ID = 'X-Email-Template-Id';

    public const EMAIL_HEADER_MAIN_ID = 'X-Main-Id';

    protected $fillable = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'body',
        'headers',
        'attachments',
        'message_id',
        'status',
        'is_test',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MailLogStatus::class,
            'attachments' => 'array',
        ];
    }

    public function getAttachmentsJsonAttribute()
    {
        try {
            return json_decode($this->attachments, true);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isTestModeEnabled(): bool
    {
        if (config('mail.default') === 'microsoft-graph') {
            return false;
        }

        $smtpHost = config('mail.mailers.smtp.host', '');

        return ! empty($smtpHost) && (str_contains($smtpHost, 'mailtrap') || str_contains($smtpHost, 'localhost'));
    }

    public function resolveEmailTemplateId(): ?int
    {
        $headers = $this->headers;

        if (! is_string($headers) || $headers === '') {
            return null;
        }

        if (preg_match('/^' . preg_quote(self::EMAIL_HEADER_TEMPLATE_ID, '/') . ':\s*(\d+)/mi', $headers, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public function resolveMainId(): ?int
    {
        $headers = $this->headers;

        if (is_string($headers) && $headers !== '') {
            if (preg_match('/^' . preg_quote(self::EMAIL_HEADER_MAIN_ID, '/') . ':\s*(\d+)/mi', $headers, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return $this->resolveMainIdFromBody();
    }

    protected function resolveMainIdFromBody(): ?int
    {
        $body = $this->body;

        if (! is_string($body) || $body === '') {
            return null;
        }

        if (preg_match('#/mains/(\d+)#', $body, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public function resolveMain(): ?Main
    {
        $mainId = $this->resolveMainId();

        if ($mainId === null) {
            return null;
        }

        return Main::query()->find($mainId);
    }
}
