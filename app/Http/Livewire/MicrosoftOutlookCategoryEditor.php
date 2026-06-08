<?php

namespace App\Http\Livewire;

use App\Models\MicrosoftCategoryMapping;
use App\Models\MicrosoftToken;
use App\Services\MicrosoftCalendarService;
use Illuminate\Support\Str;
use Livewire\Component;

class MicrosoftOutlookCategoryEditor extends Component
{
    public int $tokenId;

    public bool $showModal = false;

    /** @var list<array{key: string, outlook_category_id: ?string, displayName: string, color: string, is_new: bool, can_delete: bool, linked_user_count: int, removed: bool}> */
    public array $rows = [];

    /** @var list<array{key: string, outlook_category_id: ?string, displayName: string, color: string, is_new: bool, can_delete: bool, linked_user_count: int, removed: bool}> */
    public array $initialRows = [];

    public ?string $errorMessage = null;

    public ?string $tokenExpiredMessage = null;

    public function mount(int $tokenId): void
    {
        $this->tokenId = $tokenId;
    }

    public function openModal(): void
    {
        $this->errorMessage = null;
        $this->tokenExpiredMessage = null;
        $this->loadRows();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->rows = [];
        $this->initialRows = [];
        $this->errorMessage = null;
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function markRowRemoved(string $key): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['key'] !== $key) {
                continue;
            }

            if (! $row['can_delete']) {
                return;
            }

            $this->rows[$index]['removed'] = true;

            return;
        }
    }

    public function restoreRow(string $key): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['key'] === $key) {
                $this->rows[$index]['removed'] = false;

                return;
            }
        }
    }

    public function save(): void
    {
        $this->errorMessage = null;

        $validationError = $this->validateRows();

        if ($validationError !== null) {
            $this->errorMessage = $validationError;

            return;
        }

        $service = app(MicrosoftCalendarService::class);

        if ($service->getValidToken($this->tokenId) === null) {
            $this->tokenExpiredMessage = 'Outlook-token is verlopen of ongeldig. Koppel het account opnieuw om categorieën op te slaan.';
            $this->errorMessage = $this->tokenExpiredMessage;

            return;
        }

        $initialByKey = collect($this->initialRows)->keyBy('key');

        foreach ($this->rows as $row) {
            if ($row['removed'] && ! $row['is_new']) {
                if (! $row['can_delete']) {
                    $this->errorMessage = 'Categorie "' . $row['displayName'] . '" kan niet worden verwijderd: er zijn medewerkers aan gekoppeld.';

                    return;
                }

                if (filled($row['outlook_category_id'])) {
                    $result = $service->deleteMasterCategory($this->tokenId, (string) $row['outlook_category_id']);

                    if (! $result['success']) {
                        $this->errorMessage = $result['error'] ?? 'Verwijderen in Outlook mislukt.';

                        return;
                    }
                }

                $this->deleteLocalCategory((string) $row['displayName'], $row['outlook_category_id']);

                continue;
            }

            if ($row['removed']) {
                continue;
            }

            if ($row['is_new']) {
                $result = $service->createMasterCategory(
                    $this->tokenId,
                    trim($row['displayName']),
                    $row['color'],
                );

                if (! $result['success']) {
                    $this->errorMessage = $result['error'] ?? 'Aanmaken in Outlook mislukt.';

                    return;
                }

                $this->upsertLocalCategory(
                    displayName: (string) ($result['displayName'] ?? $row['displayName']),
                    color: (string) ($result['color'] ?? $row['color']),
                    outlookCategoryId: (string) $result['id'],
                );

                continue;
            }

            $initial = $initialByKey->get($row['key']);

            if ($initial === null) {
                continue;
            }

            if ($initial['color'] === $row['color']) {
                $this->upsertLocalCategory(
                    displayName: $row['displayName'],
                    color: $row['color'],
                    outlookCategoryId: $row['outlook_category_id'],
                );

                continue;
            }

            if (! filled($row['outlook_category_id'])) {
                $this->errorMessage = 'Categorie "' . $row['displayName'] . '" heeft geen Outlook-id; kleur kan niet worden bijgewerkt.';

                return;
            }

            $result = $service->updateMasterCategoryColor(
                $this->tokenId,
                (string) $row['outlook_category_id'],
                $row['color'],
            );

            if (! $result['success']) {
                $this->errorMessage = $result['error'] ?? 'Kleur bijwerken in Outlook mislukt.';

                return;
            }

            $this->syncLocalCategoryColor($row['displayName'], $row['color'], $row['outlook_category_id']);
        }

        $this->dispatch('outlook-categories-updated', tokenId: $this->tokenId);
        $this->closeModal();
    }

    /**
     * @return array<int, string>
     */
    public function presetColorKeys(): array
    {
        return array_keys(MicrosoftCategoryMappings::PRESET_COLORS);
    }

    public function presetHex(string $preset): string
    {
        return MicrosoftCategoryMappings::PRESET_COLORS[$preset] ?? MicrosoftCategoryMappings::PRESET_COLORS['none'];
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.microsoft-outlook-category-editor');
    }

    private function loadRows(): void
    {
        $service = app(MicrosoftCalendarService::class);
        $validToken = $service->getValidToken($this->tokenId);

        if ($validToken === null) {
            $this->tokenExpiredMessage = 'Outlook-token is verlopen of ongeldig. Koppel het account opnieuw om categorieën te beheren.';
        }

        $apiCategories = $validToken !== null ? $service->getCategories($this->tokenId) : [];

        $dbMappings = MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $this->tokenId)
            ->get();

        $rowsByName = [];

        foreach ($apiCategories as $category) {
            $name = trim($category['displayName']);
            $key = mb_strtolower($name);

            if ($key === '') {
                continue;
            }

            $linkedCount = $this->linkedUserCount($dbMappings, $name);

            $dbRow = $dbMappings->first(
                fn (MicrosoftCategoryMapping $mapping): bool => mb_strtolower(trim($mapping->category_name)) === $key,
            );

            $rowsByName[$key] = [
                'key' => (string) Str::uuid(),
                'outlook_category_id' => $category['id'],
                'displayName' => $name,
                'color' => $dbRow?->category_color ?? $category['color'] ?? 'none',
                'is_new' => false,
                'can_delete' => $linkedCount === 0,
                'linked_user_count' => $linkedCount,
                'removed' => false,
            ];
        }

        foreach ($dbMappings->unique(
            fn (MicrosoftCategoryMapping $mapping): string => mb_strtolower(trim($mapping->category_name)),
        ) as $mapping) {
            $name = trim($mapping->category_name);
            $key = mb_strtolower($name);

            if ($key === '' || isset($rowsByName[$key])) {
                continue;
            }

            $linkedCount = $this->linkedUserCount($dbMappings, $name);

            $rowsByName[$key] = [
                'key' => (string) Str::uuid(),
                'outlook_category_id' => $mapping->outlook_category_id,
                'displayName' => $name,
                'color' => $mapping->category_color ?? 'none',
                'is_new' => false,
                'can_delete' => $linkedCount === 0,
                'linked_user_count' => $linkedCount,
                'removed' => false,
            ];
        }

        $this->rows = array_values($rowsByName);
        $this->initialRows = array_map(fn (array $row): array => [...$row], $this->rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MicrosoftCategoryMapping>  $mappings
     */
    private function linkedUserCount($mappings, string $categoryName): int
    {
        $key = mb_strtolower(trim($categoryName));

        return $mappings
            ->filter(
                fn (MicrosoftCategoryMapping $mapping): bool => mb_strtolower(trim($mapping->category_name)) === $key
                    && $mapping->user_id !== null,
            )
            ->count();
    }

    private function validateRows(): ?string
    {
        $activeRows = collect($this->rows)->reject(fn (array $row): bool => $row['removed']);

        $existingNames = collect($this->initialRows)
            ->reject(fn (array $row): bool => $row['removed'])
            ->map(fn (array $row): string => mb_strtolower(trim($row['displayName'])))
            ->filter(fn (string $name): bool => $name !== '')
            ->flip();

        $names = [];

        foreach ($activeRows as $row) {
            $name = trim($row['displayName']);

            if ($row['is_new'] && $name === '') {
                return 'Vul een naam in voor elke nieuwe categorie.';
            }

            if ($name === '') {
                continue;
            }

            $nameKey = mb_strtolower($name);

            if ($row['is_new'] && isset($existingNames[$nameKey])) {
                return 'Categorienaam "' . $name . '" bestaat al in Outlook.';
            }

            if (isset($names[$nameKey])) {
                return 'Categorienaam "' . $name . '" komt meerdere keren voor.';
            }

            $names[$nameKey] = true;

            if (! array_key_exists($row['color'], MicrosoftCategoryMappings::PRESET_COLORS)) {
                return 'Ongeldige kleur voor categorie "' . $name . '".';
            }
        }

        return null;
    }

    /**
     * @return array{key: string, outlook_category_id: ?string, displayName: string, color: string, is_new: bool, can_delete: bool, linked_user_count: int, removed: bool}
     */
    private function blankRow(): array
    {
        return [
            'key' => (string) Str::uuid(),
            'outlook_category_id' => null,
            'displayName' => '',
            'color' => 'none',
            'is_new' => true,
            'can_delete' => true,
            'linked_user_count' => 0,
            'removed' => false,
        ];
    }

    private function upsertLocalCategory(string $displayName, string $color, ?string $outlookCategoryId): void
    {
        $hexColor = MicrosoftCategoryMappings::PRESET_COLORS[$color] ?? MicrosoftCategoryMappings::PRESET_COLORS['none'];

        $query = MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $this->tokenId)
            ->where('category_name', $displayName);

        if ($query->exists()) {
            $query->update([
                'outlook_category_id' => $outlookCategoryId,
                'category_color' => $color,
                'hex_color' => $hexColor,
            ]);

            return;
        }

        MicrosoftCategoryMapping::query()->create([
            'microsoft_token_id' => $this->tokenId,
            'category_name' => $displayName,
            'outlook_category_id' => $outlookCategoryId,
            'category_color' => $color,
            'hex_color' => $hexColor,
            'user_id' => null,
        ]);
    }

    private function syncLocalCategoryColor(string $displayName, string $color, ?string $outlookCategoryId): void
    {
        $hexColor = MicrosoftCategoryMappings::PRESET_COLORS[$color] ?? MicrosoftCategoryMappings::PRESET_COLORS['none'];

        MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $this->tokenId)
            ->where('category_name', $displayName)
            ->update([
                'category_color' => $color,
                'hex_color' => $hexColor,
                'outlook_category_id' => $outlookCategoryId,
            ]);
    }

    private function deleteLocalCategory(string $displayName, ?string $outlookCategoryId): void
    {
        MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $this->tokenId)
            ->where(function ($query) use ($displayName, $outlookCategoryId): void {
                $query->where('category_name', $displayName);

                if (filled($outlookCategoryId)) {
                    $query->orWhere('outlook_category_id', $outlookCategoryId);
                }
            })
            ->delete();
    }
}
