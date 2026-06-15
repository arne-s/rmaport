<?php

use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource;
use App\Filament\Resources\RmaResource\Pages\EditRma;
use App\Filament\Resources\RmaResource\Pages\ListRmas;
use App\Filament\Widgets\Dashboard\QuickLinksWidget;
use App\Models\Rma;
use App\Models\User;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Permission::findOrCreate('manage sales', 'web');
    Permission::findOrCreate('access filament panel', 'web');
});

it('denies rma resource access without manage sales permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('access filament panel');

    expect(RmaResource::canViewAny())->toBeFalse();

    $this->actingAs($user)
        ->get(RmaResource::getUrl('index'))
        ->assertForbidden();
});

it('allows sales users to access rma pages', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $this->actingAs($user);

    expect(RmaResource::canViewAny())->toBeTrue();

    livewire(ListRmas::class)->assertSuccessful();

    $rma = Rma::query()->create([
        'uid' => 'TEST-RMA-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    livewire(EditRma::class, ['record' => $rma->getKey()])->assertSuccessful();
    livewire(\App\Filament\Resources\RmaResource\Pages\ViewRma::class, ['record' => $rma->getKey()])->assertSuccessful();
});

it('creates a draft rma and redirects to edit when visiting create', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $this->actingAs($user);

    $response = $this->get(RmaResource::getUrl('create'));

    $draft = Rma::query()->where('is_draft', true)->latest('id')->first();

    expect($draft)->not->toBeNull()
        ->and($draft->uid)->toStartWith('DR-')
        ->and(strlen($draft->uid))->toBeLessThanOrEqual(20)
        ->and($draft->status)->toBe(RmaStatus::Open);

    $response->assertRedirect(RmaResource::getUrl('edit', ['record' => $draft]));
});

it('creates a draft rma from dashboard quick link action', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $this->actingAs($user);

    expect(Rma::query()->where('is_draft', true)->count())->toBe(0);

    livewire(QuickLinksWidget::class)
        ->callAction('create_rma');

    $draft = Rma::query()->where('is_draft', true)->latest('id')->first();

    expect($draft)->not->toBeNull()
        ->and($draft->uid)->toStartWith('DR-');
});

it('generates unique draft uids', function (): void {
    $first = Rma::createDraft();
    $second = Rma::createDraft();

    expect($first->uid)->not->toBe($second->uid);
});

it('hides draft rmas from the overview', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $visible = Rma::query()->create([
        'uid' => 'OPEN-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    Rma::query()->create([
        'uid' => 'DRAFT-1',
        'status' => RmaStatus::Open,
        'is_draft' => true,
    ]);

    $this->actingAs($user);

    livewire(ListRmas::class)
        ->assertCanSeeTableRecords(collect([$visible]))
        ->assertCanNotSeeTableRecords(Rma::query()->where('uid', 'DRAFT-1')->get());
});

it('clears draft status on first save', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $draft = Rma::createDraft();

    $this->actingAs($user);

    livewire(EditRma::class, ['record' => $draft->getKey()])
        ->fillForm([
            'uid' => 'FINAL-RMA-001',
            'status' => RmaStatus::Open->value,
            'complaint' => 'Scherm defect',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $draft->refresh();

    expect($draft->is_draft)->toBeFalse()
        ->and($draft->uid)->toBe('FINAL-RMA-001')
        ->and($draft->complaint)->toBe('Scherm defect');
});

it('shows all rma statuses by default', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    Rma::query()->create(['uid' => 'OPEN-1', 'status' => RmaStatus::Open, 'is_draft' => false]);
    Rma::query()->create(['uid' => 'CLOSED-1', 'status' => RmaStatus::Closed, 'is_draft' => false]);

    $this->actingAs($user);

    livewire(ListRmas::class)
        ->assertCanSeeTableRecords(Rma::query()->whereIn('uid', ['OPEN-1', 'CLOSED-1'])->get());
});

it('opens rma import modal from dashboard quick link action', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $this->actingAs($user);

    livewire(QuickLinksWidget::class)
        ->mountAction('import_rma')
        ->assertActionMounted('import_rma')
        ->assertSee('RMA\'s importeren');
});
