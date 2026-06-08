<?php

namespace App\Models;

use App\Models\Order\Main;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostNLShipment extends Model
{
    protected $table = 'postnl_shipments';

    protected $fillable = [
        'order_id',
        'barcode',
        'recipient_name',
        'recipient_company',
        'recipient_street',
        'recipient_house_nr',
        'recipient_house_nr_addition',
        'recipient_postcode',
        'recipient_city',
        'recipient_country',
        'reference',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Main::class, 'order_id');
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }
}
