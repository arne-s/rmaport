<?php

namespace App\Models;

use App\Enums\AppointmentType;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use App\Models\Pivots\AppointmentAdvisor;
use App\Models\Pivots\AppointmentMechanic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property AppointmentType $type
 * @property Carbon $datetime
 * @property Carbon|null $datetime_end
 * @property string|null $segment
 * @property string|null $comment
 * @property string|null $title
 * @property string|null $description
 * @property bool $notify_customer
 * @property bool $notify_advisor
 * @property bool $notify_workshop
 * @property Carbon|null $customer_datetime_start
 * @property int|null $customer_duration
 * @property string $travel_time_before
 * @property string $travel_time_after
 * @property int|null $microsoft_category_mapping_id
 * @property bool $workshop_category_by_user
 * @property Carbon|null $reminder_sent_at
 * @property bool $is_active
 * @property string|null $outlook_event_id
 * @property list<string>|null $outlook_event_ids
 * @property int $order_id
 * @property int|null $location_customer_id
 * @property string|null $location_type
 * @property string|null $location_custom
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BaseOrder $order
 * @property-read Customer|null $locationCustomer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $mechanics
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $advisors
 */
class Appointment extends Model
{
    protected $fillable = [
        'type',
        'datetime',
        'datetime_end',
        'segment',
        'comment',
        'title',
        'description',
        'notify_customer',
        'notify_advisor',
        'notify_workshop',
        'customer_datetime_start',
        'customer_duration',
        'travel_time_before',
        'travel_time_after',
        'microsoft_category_mapping_id',
        'workshop_category_by_user',
        'reminder_sent_at',
        'is_active',
        'outlook_event_id',
        'outlook_event_ids',
        'order_id',
        'location_customer_id',
        'location_type',
        'location_custom',
    ];

    protected function casts(): array
    {
        return [
            'type'              => AppointmentType::class,
            'datetime'          => 'datetime',
            'datetime_end'      => 'datetime',
            'reminder_sent_at'  => 'datetime',
            'is_active'         => 'boolean',
            'notify_customer'        => 'boolean',
            'notify_advisor'         => 'boolean',
            'notify_workshop'        => 'boolean',
            'workshop_category_by_user' => 'boolean',
            'customer_datetime_start' => 'datetime',
            'customer_duration'      => 'integer',
            'outlook_event_ids'      => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BaseOrder::class, 'order_id');
    }

    public function locationCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'location_customer_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'appointment_mechanic')
            ->using(AppointmentMechanic::class)
            ->withPivot(['outlook_event_id', 'outlook_event_ids']);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function advisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'appointment_advisor')
            ->using(AppointmentAdvisor::class)
            ->withPivot(['outlook_event_id', 'outlook_event_ids']);
    }

    public function getLocationAddress(): ?Address
    {
        if ($this->location_type === 'custom') {
            $data = is_string($this->location_custom)
                ? json_decode($this->location_custom, true)
                : $this->location_custom;

            if (is_array($data)) {
                $address = new Address();
                foreach ($data as $key => $value) {
                    $address->$key = $value;
                }
                return $address;
            }
            return null;
        }

        $this->loadMissing('locationCustomer.billingAddress');
        return $this->locationCustomer?->billingAddress;
    }

    public function getLocationName(): ?string
    {
        if ($this->location_type === 'phone') {
            return 'Telefonisch';
        }

        $this->loadMissing('locationCustomer');
        return $this->locationCustomer?->getName();
    }

    public function getLocationCustomerId(): ?int
    {
        return $this->location_customer_id;
    }

    public function setLocationCustomerId(?int $value): self
    {
        $this->location_customer_id = $value;
        return $this;
    }

    public function getLocationType(): ?string
    {
        return $this->location_type;
    }

    public function setLocationType(?string $value): self
    {
        $this->location_type = $value;
        return $this;
    }

    public function getLocationCustom(): ?array
    {
        if ($this->location_custom === null) {
            return null;
        }
        return is_string($this->location_custom)
            ? json_decode($this->location_custom, true)
            : $this->location_custom;
    }

    public function setLocationCustom(?array $value): self
    {
        $this->location_custom = $value !== null ? json_encode($value) : null;
        return $this;
    }

    public function getType(): AppointmentType
    {
        return $this->type;
    }

    public function setType(AppointmentType|string $value): self
    {
        $this->type = $value instanceof AppointmentType ? $value : AppointmentType::from($value);

        return $this;
    }

    public function getDatetime(): Carbon
    {
        return $this->datetime;
    }

    public function setDatetime(Carbon|string $value): self
    {
        $this->datetime = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $this;
    }

    public function getDatetimeEnd(): ?Carbon
    {
        return $this->datetime_end;
    }

    public function setDatetimeEnd(Carbon|string|null $value): self
    {
        $this->datetime_end = is_string($value) ? Carbon::parse($value) : $value;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $value): self
    {
        $this->comment = $value;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $value): self
    {
        $this->title = $value;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $value): self
    {
        $this->description = $value;

        return $this;
    }

    public function isNotifyCustomer(): bool
    {
        return $this->notify_customer;
    }

    public function setNotifyCustomer(bool $value): self
    {
        $this->notify_customer = $value;

        return $this;
    }

    public function isNotifyAdvisor(): bool
    {
        return $this->notify_advisor;
    }

    public function setNotifyAdvisor(bool $value): self
    {
        $this->notify_advisor = $value;

        return $this;
    }

    public function getCustomerDatetimeStart(): ?Carbon
    {
        return $this->customer_datetime_start;
    }

    public function setCustomerDatetimeStart(Carbon|string|null $value): self
    {
        $this->customer_datetime_start = is_string($value) ? Carbon::parse($value) : $value;

        return $this;
    }

    public function getCustomerDuration(): ?int
    {
        return $this->customer_duration;
    }

    public function setCustomerDuration(?int $value): self
    {
        $this->customer_duration = $value;

        return $this;
    }

    public function getOutlookEventId(): ?string
    {
        return $this->outlook_event_id;
    }

    public function setOutlookEventId(?string $value): self
    {
        $this->outlook_event_id = $value;

        return $this;
    }

    public function getReminderSentAt(): ?Carbon
    {
        return $this->reminder_sent_at;
    }

    public function setReminderSentAt(?Carbon $value): self
    {
        $this->reminder_sent_at = $value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $value): self
    {
        $this->is_active = $value;

        return $this;
    }

    public function getOrderId(): int
    {
        return $this->order_id;
    }

    public function setOrderId(int $value): self
    {
        $this->order_id = $value;

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }


}
