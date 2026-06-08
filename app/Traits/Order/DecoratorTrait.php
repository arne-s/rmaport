<?php

namespace App\Traits\Order;

use Carbon\Carbon;

trait DecoratorTrait
{
    /**
     * Get a short, formatted date e.g. "15 juni 2025"
     *
     * @param $value
     * @return string
     */
    public function getCreatedAtShortAttribute(): string
    {
        return Carbon::parse($this->created_at)->translatedFormat('j F Y');
    }

    /**
     * @return string
     */
    public function getTypeTranslatedAttribute(): string
    {
        return join(',',[$this->type, $this->status]);
//        ]); return join(',',[
//            __(sprintf('orders.type.%s', $this->type)),
//            __(sprintf('orders.status.%s', $this->status))
//        ]);
    }
}
