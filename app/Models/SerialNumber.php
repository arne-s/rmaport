<?php

namespace App\Models;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Carbon\Carbon;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $serial_number
 * @property int|null $owner_id
 * @property int|null $main_id
 * @property string|null $note
 * @property string|null $name
 * @property string $order_sub_type
 * @property string|null $type
 * @property string|null $color
 * @property string|null $customer_name
 * @property string|null $customer_debtor_number
 * @property \Illuminate\Support\Carbon|null $order_date
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property string|null $order_number
 * @property float|null $total_price_inc
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Customer|null $owner
 * @method static Builder<static>|SerialNumber newModelQuery()
 * @method static Builder<static>|SerialNumber newQuery()
 * @method static Builder<static>|SerialNumber query()
 * @method static Builder<static>|SerialNumber whereCreatedAt($value)
 * @method static Builder<static>|SerialNumber whereId($value)
 * @method static Builder<static>|SerialNumber whereNote($value)
 * @method static Builder<static>|SerialNumber whereOwnerId($value)
 * @method static Builder<static>|SerialNumber whereSerialNumber($value)
 * @method static Builder<static>|SerialNumber whereUpdatedAt($value)
 * @mixin Eloquent
 */
class SerialNumber extends Model
{
    protected $fillable = [
        'serial_number',
        'order_sub_type',
        'owner_id',
        'order_id',
        'main_id',
        'note',
        'name',
        'type',
        'color',
        'customer_name',
        'customer_debtor_number',
        'order_date',
        'delivered_at',
        'order_number',
        'total_price_inc',
    ];

    protected function casts(): array
    {
        return [
            'order_date'      => 'datetime',
            'delivered_at'    => 'datetime',
            'total_price_inc' => 'float',
            'order_sub_type'  => OrderSubtype::class,
        ];
    }

    public function scopeUnitRows(Builder $query): Builder
    {
        return $query->where('order_sub_type', OrderSubtype::Unit->value);
    }

    public function scopeForSerialNumberValue(Builder $query, string $serialNumber): Builder
    {
        return $query->where('serial_number', $serialNumber);
    }

    public static function totalCostForSerialNumber(string $serialNumber): float
    {
        return (float) self::query()
            ->where('serial_number', $serialNumber)
            ->sum('total_price_inc');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SerialNumber>
     */
    public static function ledgerEntriesForSerialNumber(string $serialNumber): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()
            ->where('serial_number', $serialNumber)
            ->with(['main', 'order.main', 'order.orderProducts.product'])
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(Main::class, 'main_id');
    }

    public function serialNumberEvents(): HasMany
    {
        return $this->hasMany(SerialNumberEvent::class, 'serial_number_id', 'id');
    }


    public function getTotalAmount()
    {
        $order = $this->order;
        if ($order === null) {
            return 0;
        }

        if ($order->type !== OrderType::Order || in_array($order->status, [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft], true)) {
            return 0;
        }

        return (float) $order->company_sales_price_total;
    }

    public function getFrameName(): string
    {
        return $this->order
            ?->frameProduct
            ?->getName() ?? '-';
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSerialNumber(): string
    {
        return $this->serial_number;
    }

    public function setSerialNumber(string $serialNumber): self
    {
        $this->serial_number = $serialNumber;
        return $this;
    }

    public function getOrderSubType(): OrderSubtype
    {
        $value = $this->order_sub_type;

        if ($value instanceof OrderSubtype) {
            return $value;
        }

        return OrderSubtype::tryFrom((string) $value) ?? OrderSubtype::Unit;
    }

    public function setOrderSubType(OrderSubtype|string $orderSubType): self
    {
        $this->order_sub_type = $orderSubType instanceof OrderSubtype
            ? $orderSubType->value
            : $orderSubType;

        return $this;
    }

    public function isUnitRow(): bool
    {
        return $this->getOrderSubType() === OrderSubtype::Unit;
    }

    public function getOwnerId(): ?int
    {
        return $this->owner_id;
    }

    public function setOwnerId(?int $ownerId): self
    {
        $this->owner_id = $ownerId;
        return $this;
    }

    public function getOrderId(): ?int
    {
        return $this->order_id;
    }

    public function setOrderId(?int $orderId): self
    {
        $this->order_id = $orderId;

        return $this;
    }

    public function getMainId(): ?int
    {
        return $this->main_id;
    }

    public function setMainId(?int $mainId): self
    {
        $this->main_id = $mainId;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getCustomerName(): ?string
    {
        return $this->customer_name;
    }

    public function setCustomerName(?string $customerName): self
    {
        $this->customer_name = $customerName;
        return $this;
    }

    public function getCustomerDebtorNumber(): ?string
    {
        return $this->customer_debtor_number;
    }

    public function setCustomerDebtorNumber(?string $customerDebtorNumber): self
    {
        $this->customer_debtor_number = $customerDebtorNumber;
        return $this;
    }

    public function getOrderDate(): ?Carbon
    {
        return $this->order_date;
    }

    public function setOrderDate(?Carbon $orderDate): self
    {
        $this->order_date = $orderDate;
        return $this;
    }

    public function getOrderNumber(): ?string
    {
        return $this->order_number;
    }

    public function setOrderNumber(?string $orderNumber): self
    {
        $this->order_number = $orderNumber;
        return $this;
    }

    public function getTotalPriceInc(): ?float
    {
        return $this->total_price_inc;
    }

    public function setTotalPriceInc(?float $totalPriceInc): self
    {
        $this->total_price_inc = $totalPriceInc;
        return $this;
    }

    public function getDeliveredAt(): ?Carbon
    {
        return $this->delivered_at;
    }

    public function setDeliveredAt(?Carbon $deliveredAt): self
    {
        $this->delivered_at = $deliveredAt;

        return $this;
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
