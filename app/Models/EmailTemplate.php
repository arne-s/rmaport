<?php
namespace App\Models;

use App\Enums\EmailTemplateAudience;
use App\Enums\EmailTemplateType;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * App\Models\EmailTemplate
 *
 * @property int $id
 * @property string $subject
 * @property string $class
 * @property string|null $content
 * @property string|null $additional
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|EmailTemplate newModelQuery()
 * @method static Builder|EmailTemplate newQuery()
 * @method static Builder|EmailTemplate query()
 * @method static Builder|EmailTemplate whereAdditional($value)
 * @method static Builder|EmailTemplate whereClass($value)
 * @method static Builder|EmailTemplate whereContent($value)
 * @method static Builder|EmailTemplate whereCreatedAt($value)
 * @method static Builder|EmailTemplate whereId($value)
 * @method static Builder|EmailTemplate whereSubject($value)
 * @method static Builder|EmailTemplate whereUpdatedAt($value)
 * @property string|null $name
 * @method static Builder|EmailTemplate whereName($value)
 * @property string|null $description
 * @method static Builder|customeEmailTemplate whereDescription($value)
 * @property EmailTemplateType $type
 * @property EmailTemplateAudience $audience
 * @method static Builder|EmailTemplate whereType($value)
 * @method static Builder|EmailTemplate whereAudience($value)
 * @mixin Eloquent
 */
class EmailTemplate extends Model
{
    protected $fillable = [
        'subject',
        'class',
        'content',
        'name',
        'description',
        'type',
        'audience',
        'mail_sender_profile_id',
        'cc_sender_profile_uid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EmailTemplateType::class,
            'audience' => EmailTemplateAudience::class,
        ];
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * @param string $subject
     */
    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * @param string $class
     */
    public function setClass(string $class): void
    {
        $this->class = $class;
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @param string|null $content
     */
    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    /**
     * @return Carbon|null
     */
    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    /**
     * @return Carbon|null
     */
    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string|null $name
     */
    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getType(): EmailTemplateType
    {
        return $this->type;
    }

    public function setType(EmailTemplateType|string $type): void
    {
        $this->type = $type instanceof EmailTemplateType
            ? $type
            : EmailTemplateType::from($type);
    }

    public function getAudience(): EmailTemplateAudience
    {
        return $this->audience;
    }

    public function setAudience(EmailTemplateAudience|string $audience): void
    {
        $this->audience = $audience instanceof EmailTemplateAudience
            ? $audience
            : EmailTemplateAudience::from($audience);
    }

    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(MailSenderProfile::class, 'mail_sender_profile_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailTemplateRecipient::class)->with('user');
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsersTo(): Collection
    {
        return $this->recipients()
            ->where('type', EmailTemplateRecipient::TYPE_TO)
            ->get()
            ->pluck('user')
            ->filter();
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsersCc(): Collection
    {
        return $this->recipients()
            ->where('type', EmailTemplateRecipient::TYPE_CC)
            ->get()
            ->pluck('user')
            ->filter();
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsersBcc(): Collection
    {
        return $this->recipients()
            ->where('type', EmailTemplateRecipient::TYPE_BCC)
            ->get()
            ->pluck('user')
            ->filter();
    }
}
