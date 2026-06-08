<?php

namespace App\Providers;

use App\Mail\Transport\MicrosoftGraphTransport;
use App\Services\MicrosoftMailService;
use Filament\Actions\Action;
use Illuminate\Foundation\Application;
use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Filament\Forms\Components\Field;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/** @property Application $app */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->isLocal()) {
            $this->app->register(IdeHelperServiceProvider::class);
        }

        // LaraDumps requires an HTTP request (LogObserver), so only load when not in console
        if (! $this->app->runningInConsole()) {
            $this->app->register(\LaraDumps\LaraDumps\LaraDumpsServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureFilamentEchoForRequest();

        Mail::extend('microsoft-graph', fn () => new MicrosoftGraphTransport(app(MicrosoftMailService::class)));

        Table::configureUsing(fn (Table $table) => $table
            ->deferFilters(false)
            ->paginationPageOptions([5, 10, 25, 50, 'all'])
            ->defaultPaginationPageOption(50)
            ->striped()
        );

        Field::macro('tooltip', function (string $tooltip) {
            /** @var Field $this */
            return $this->hintAction(
                Action::make('help')
                    ->icon('heroicon-o-question-mark-circle')
                    ->extraAttributes([
                        'class' => 'text-gray-500 position-absolute',
                        'style' => 'color: #000',
                    ])
                    ->label('')
                    ->tooltip($tooltip)
            );
        });


        Blade::directive('money', function ($expression) {
            return "<?php echo '&euro; ' . number_format($expression, 2, ',', '.'); ?>";
        });

        Blade::directive('moneyNoSpace', function ($expression) {
            return "<?php echo '&euro;' . number_format($expression, 2, ',', '.'); ?>";
        });

        Blade::directive('moneyShort', function ($expression) {
            return "<?php echo '&euro; ' . preg_replace('/,00$/', '',number_format( (float)$expression, 2, ',', '.')); ?>";
        });

        Blade::directive('svgImg', function ($arguments) {
            return "<?php
        \$path = public_path($arguments);

        if(file_exists(\$path)){
            \$svg = new \DOMDocument();
            \$svg->load(\$path);

            // Assuming you want to add a class, though you haven't passed a second argument for class here.
            // If you don't want to add a class, you can remove the following line.
            \$svg->documentElement->setAttribute('class', '');

            echo \$svg->saveXML(\$svg->documentElement);
        }
    ?>";
        });

        FilamentAsset::register([
            Js::make('filament_scripts', __DIR__ . '/../../resources/js/filament_scripts.js'),
            AlpineComponent::make(
                'wolturnus-chart',
                resource_path('js/filament/wolturnus-chart.bundle.js'),
            ),
        ]);

        Livewire::component('global-edit-note', \App\Livewire\GlobalEditNote::class);
        Livewire::component('global-create-main', \App\Livewire\GlobalCreateMain::class);
        Livewire::component('global-quote-preview-placeholder', \App\Livewire\GlobalQuotePreviewPlaceholder::class);
        Livewire::component('note-documents-panel', \App\Livewire\NoteDocumentsPanel::class);
        Livewire::component('note-pending-attachments-upload', \App\Livewire\NotePendingAttachmentsUpload::class);
    }

    protected function configureFilamentEchoForRequest(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $echo = config('filament.broadcasting.echo');

        if (! is_array($echo)) {
            return;
        }

        $reverbHost = (string) env('REVERB_HOST', 'localhost');
        $reverbScheme = (string) env('REVERB_SCHEME', 'http');
        $usesLocalReverbBackend = in_array($reverbHost, ['localhost', '127.0.0.1'], true)
            || $reverbScheme === 'http';

        if (! $usesLocalReverbBackend) {
            return;
        }

        $requestHost = request()->getHost();

        if (request()->secure()) {
            config([
                'filament.broadcasting.echo.wsHost' => $requestHost,
                'filament.broadcasting.echo.forceTLS' => true,
                'filament.broadcasting.echo.wsPort' => 443,
                'filament.broadcasting.echo.wssPort' => 443,
            ]);

            return;
        }

        config([
            'filament.broadcasting.echo.wsHost' => in_array($reverbHost, ['localhost', '127.0.0.1'], true)
                ? $reverbHost
                : $requestHost,
            'filament.broadcasting.echo.forceTLS' => false,
            'filament.broadcasting.echo.wsPort' => (int) env('REVERB_PORT', 8493),
            'filament.broadcasting.echo.wssPort' => (int) env('REVERB_PORT', 8493),
        ]);
    }
}
