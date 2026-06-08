<?php

namespace App\Filament\Resources\ReleaseOrders\Pages;

use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListReleaseOrders extends ListRecords
{
    /** @var class-string<\App\Filament\Resources\ReleaseOrders\ReleaseOrderResource> */
    protected static string $resource = \App\Filament\Resources\ReleaseOrders\ReleaseOrderResource::class;

    public function getTableQuery(): Builder
    {
        return \App\Filament\Resources\ReleaseOrders\ReleaseOrderResource::getEloquentQuery()
            ->where('status', '!=', ReleaseOrderStatus::Initial);
    }
}
