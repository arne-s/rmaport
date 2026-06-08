<?php

namespace App\Providers;

use App\Models\Order\Quote;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Observers\OrderProductObserver;
use App\Observers\ProductObserver;
use App\Observers\ProductStockObserver;
use App\Observers\QuoteObserver;
use App\Observers\UserObserver;
use App\Services\MailPreview\MsgPreviewCacheService;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        OrderProduct::observe(OrderProductObserver::class);
        Product::observe(ProductObserver::class);
        ProductStock::observe(ProductStockObserver::class);
        Quote::observe(QuoteObserver::class);
        User::observe(UserObserver::class);

        Event::listen(MediaHasBeenAddedEvent::class, function (MediaHasBeenAddedEvent $event): void {
            app(MsgPreviewCacheService::class)->queueWarmCache($event->media);
        });
    }
}
