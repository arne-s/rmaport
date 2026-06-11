<?php

use App\Enums\CustomerStatus;
use App\Enums\RmaAssessment;
use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource;
use App\Filament\Resources\RmaResource\Pages\ViewRma;
use App\Models\Customer;
use App\Models\Rma;
use App\Models\RmaEvent;
use App\Models\RmaStatusChange;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('manage sales', 'web');
    Permission::findOrCreate('access filament panel', 'web');
});

function actingAsSalesUser(): User
{
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Test',
        'last_name' => 'User',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    test()->actingAs($user);

    return $user;
}

function createVisibleRma(array $attributes = []): Rma
{
    return Rma::query()->create(array_merge([
        'uid' => 'RMA-VIEW-'.uniqid(),
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ], $attributes));
}

it('loads the rma view page with sections and editable retour fields', function (): void {
    actingAsSalesUser();

    $rma = createVisibleRma([
        'uid' => 'RMA-VIEW-001',
        'service' => 'Bestaande werkzaamheden',
        'notes' => 'Interne opmerking',
    ]);

    Livewire::test(ViewRma::class, ['record' => $rma->getKey()])
        ->assertSuccessful()
        ->assertSee('RMA:')
        ->assertSee('RMA-VIEW-001')
        ->assertSee('Algemeen')
        ->assertSee('Artikel')
        ->assertSee('Artikelnaam')
        ->assertSee('Retour')
        ->assertSee('Financiële documenten')
        ->assertSee('Offerte')
        ->assertSee('Order')
        ->assertSee('Factuur')
        ->assertSee('Credit')
        ->assertSee('Beoordeling')
        ->assertSee('Interne notities')
        ->assertSee('Documenten en Afbeeldingen')
        ->assertSet('service', 'Bestaande werkzaamheden')
        ->assertSet('internalNotes', 'Interne opmerking');
});

it('shows customer name in view heading when linked', function (): void {
    actingAsSalesUser();

    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant BV',
    ]);

    $rma = createVisibleRma([
        'uid' => 'RMA-CUST-001',
        'customer_id' => $customer->getKey(),
    ]);

    Livewire::test(ViewRma::class, ['record' => $rma->getKey()])
        ->assertSee('RMA:')
        ->assertSee('RMA-CUST-001')
        ->assertSee('Test Klant BV');
});

it('saves werkzaamheden and interne notities from the view page', function (): void {
    actingAsSalesUser();

    $rma = createVisibleRma([
        'uid' => 'RMA-SAVE-001',
        'service' => null,
        'notes' => null,
    ]);

    Livewire::test(ViewRma::class, ['record' => $rma->getKey()])
        ->set('service', 'Reparatie uitgevoerd')
        ->set('internalNotes', 'Alleen voor intern gebruik')
        ->call('saveRmaWorkNotes')
        ->assertNotified();

    $rma->refresh();

    expect($rma->service)->toBe('Reparatie uitgevoerd')
        ->and($rma->notes)->toBe('Alleen voor intern gebruik');

    expect(RmaEvent::query()->where('rma_id', $rma->getKey())->value('type'))
        ->toBe('Werkzaamheden/notities bijgewerkt');
});

it('saves beoordeling from the view page', function (): void {
    actingAsSalesUser();

    $rma = createVisibleRma([
        'uid' => 'RMA-ASSESS-001',
        'assessment' => null,
    ]);

    Livewire::test(ViewRma::class, ['record' => $rma->getKey()])
        ->set('assessment', RmaAssessment::DefectRepair->value)
        ->call('saveRmaWorkNotes')
        ->assertNotified();

    $rma->refresh();

    expect($rma->assessment)->toBe(RmaAssessment::DefectRepair);
});

it('logs status changes from the view page', function (): void {
    actingAsSalesUser();

    $rma = createVisibleRma([
        'uid' => 'RMA-STATUS-001',
        'status' => RmaStatus::Open,
    ]);

    Livewire::test(ViewRma::class, ['record' => $rma->getKey()])
        ->set('rmaStatus', RmaStatus::Received->value)
        ->assertNotified();

    $rma->refresh();

    expect($rma->status)->toBe(RmaStatus::Received);

    expect(RmaStatusChange::query()->where('rma_id', $rma->getKey())->count())->toBe(1)
        ->and(RmaEvent::query()->where('rma_id', $rma->getKey())->where('type', 'like', 'RMA-status gewijzigd:%')->exists())->toBeTrue();
});

it('shows document upload zone on the view page', function (): void {
    actingAsSalesUser();

    $rma = createVisibleRma(['uid' => 'RMA-DOC-001']);

    Livewire::test(ViewRma::class, ['record' => $rma->getKey()])
        ->assertSuccessful()
        ->assertSee('Documenten en Afbeeldingen')
        ->assertSee('Drag & Drop je bestanden');
});

it('links rma uid in list to view page', function (): void {
    actingAsSalesUser();

    $rma = createVisibleRma(['uid' => 'RMA-LINK-001']);

    $this->get(RmaResource::getUrl('view', ['record' => $rma]))
        ->assertSuccessful();
});
