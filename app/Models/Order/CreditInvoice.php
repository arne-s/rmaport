<?php

namespace App\Models\Order;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

class CreditInvoice extends BaseOrder
{
    protected $table = 'orders';

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('type',
            fn (Builder $builder) => $builder->whereIn('type', ['credit_invoice']));
    }

    public function submitCreditInvoice(): CreditInvoice
    {
        $this
            ->setSentAt(null)
            ->setExpiresAt(null)
            ->setType('credit_invoice')
            ->setStatus('sent')
            ->setUid($this->getNewUid())
            ->setExactId(null)
            ->setExactSyncedAt(null)
            ->setExactErrorAt(null);

        $this->save();

        Document::createFromOrder($this);

        $parentInvoice = $this->getInvoice();
        if ($parentInvoice) {
            $parentInvoice->setCreditInvoiceId($this->getId());
            $parentInvoice->save();
        }

        info('Credit invoice submitted with ID ' . $this->getId()
            . ', from invoice with ID ' . ($parentInvoice?->getId() ?? 'N/A'));

        return $this;
    }
}
