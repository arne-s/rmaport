<?php

use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource;
use App\Filament\Resources\RmaResource\Pages\ListRmas;
use App\Filament\Resources\RmaResource\Pages\ListRmasConcept;
use App\Filament\Resources\RmaResource\Pages\ListRmasInProgress;
use App\Filament\Resources\RmaResource\Pages\ListRmasOpen;
use App\Filament\Widgets\Dashboard\ProductionOverviewWidget;
use App\Models\Rma;
use App\Models\User;
use App\Services\RmaOverviewQueries;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Permission::findOrCreate('manage sales', 'web');
    Permission::findOrCreate('access filament panel', 'web');

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['access filament panel', 'manage sales']);
    $this->actingAs($this->user);
});

it('shows no status tabs on the rma index page', function (): void {
    livewire(ListRmas::class)
        ->assertSuccessful()
        ->assertDontSee('1. Aanvraag (');
});

it('shows status tabs on rma status sub-pages', function (): void {
    livewire(ListRmasOpen::class)
        ->assertSuccessful()
        ->assertSee('1. Aanvraag (')
        ->assertSee('2. Open (');
});

it('filters rmas by status on sub-pages', function (): void {
    $open = Rma::query()->create(['uid' => 'OPEN-SUB-1', 'status' => RmaStatus::Open, 'is_draft' => false]);
    $closed = Rma::query()->create(['uid' => 'CLOSED-SUB-1', 'status' => RmaStatus::Closed, 'is_draft' => false]);
    $inProgress = Rma::query()->create(['uid' => 'PROG-SUB-1', 'status' => RmaStatus::InProgress, 'is_draft' => false]);

    livewire(ListRmasOpen::class)
        ->assertCanSeeTableRecords(collect([$open]))
        ->assertCanNotSeeTableRecords(collect([$closed, $inProgress]));

    livewire(ListRmasInProgress::class)
        ->assertCanSeeTableRecords(collect([$inProgress]))
        ->assertCanNotSeeTableRecords(collect([$open, $closed]));
});

it('filters draft page by draft status only', function (): void {
    $draft = Rma::query()->create(['uid' => 'DRAFT-STATUS-1', 'status' => RmaStatus::Draft, 'is_draft' => false]);
    $open = Rma::query()->create(['uid' => 'OPEN-STATUS-1', 'status' => RmaStatus::Open, 'is_draft' => false]);

    livewire(ListRmasConcept::class)
        ->assertCanSeeTableRecords(collect([$draft]))
        ->assertCanNotSeeTableRecords(collect([$open]));
});

it('registers status sub-page routes', function (): void {
    expect(RmaResource::getUrl('draft'))->toContain('/rmas/draft')
        ->and(RmaResource::getUrl('open'))->toContain('/rmas/open')
        ->and(RmaResource::getUrl('in_progress'))->toContain('/rmas/in_progress')
        ->and(RmaResource::getUrl('returned'))->toContain('/rmas/returned');
});

it('links dashboard widget stats to status sub-pages', function (): void {
    livewire(ProductionOverviewWidget::class)->assertSuccessful();

    foreach (RmaStatus::overviewStatuses() as $status) {
        expect(RmaOverviewQueries::urlForStatus($status))
            ->toContain('/rmas/' . $status->overviewSlug())
            ->not->toContain('tableFilters');
    }
});
