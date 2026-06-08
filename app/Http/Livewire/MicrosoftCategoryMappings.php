<?php

namespace App\Http\Livewire;

use App\Models\MicrosoftCategoryMapping;
use App\Models\MicrosoftToken;
use App\Models\User;
use App\Services\MicrosoftCalendarService;
use Livewire\Attributes\On;
use Livewire\Component;

class MicrosoftCategoryMappings extends Component
{
    /** Outlook preset → pastel hex (matches new Outlook category colors) */
    public const PRESET_COLORS = [
        'preset0'  => '#fad6d8',
        'preset1'  => '#ffddb3',
        'preset2'  => '#f0dac8',
        'preset3'  => '#fef7c3',
        'preset4'  => '#d8f5d0',
        'preset5'  => '#d3f4f5',
        'preset6'  => '#e8edcd',
        'preset7'  => '#d6e8fb',
        'preset8'  => '#e8ddf7',
        'preset9'  => '#f7d2e4',
        'preset10' => '#d8e8f0',
        'preset11' => '#cfe0e2',
        'preset12' => '#e5e5e5',
        'preset13' => '#d0d0d0',
        'preset14' => '#b8b8b8',
        'preset15' => '#f5c8c8',
        'preset16' => '#f5dfc0',
        'preset17' => '#ecdacb',
        'preset18' => '#f5eecc',
        'preset19' => '#c8e8c8',
        'preset20' => '#c8e8ea',
        'preset21' => '#dce5c8',
        'preset22' => '#c8d8ed',
        'preset23' => '#ddd0f0',
        'preset24' => '#f0c8d8',
        'none'     => '#e8e8e8',
    ];

    /** @var array<int, array{displayName: string, color: string}> */
    public array $categories = [];

    /** @var array<int, string|null> user_id => category_name */
    public array $userMappings = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $users = [];

    public int $tokenId;

    public bool $hasToken = false;

    public ?string $statusMessage = null;

    public ?string $usageMessage = null;

    public bool $saved = false;

    public function mount(): void
    {
        $this->loadState();
    }

    #[On('calendar-role-changed')]
    public function onCalendarRoleChanged(int $tokenId): void
    {
        if ($tokenId !== $this->tokenId) {
            return;
        }

        $this->loadState();
    }

    #[On('outlook-categories-updated')]
    public function onOutlookCategoriesUpdated(int $tokenId): void
    {
        if ($tokenId !== $this->tokenId) {
            return;
        }

        $this->loadState();
        $this->saved = false;
    }

    private function loadState(): void
    {
        $token = MicrosoftToken::with('role')->find($this->tokenId);

        if ($token === null) {
            $this->hasToken = false;

            return;
        }

        $this->hasToken = true;
        $this->usageMessage = $this->resolveUsageMessage($token);

        $service = app(MicrosoftCalendarService::class);
        $this->loadCategories($token, $service);
        $this->loadUsers($token);

        $existing = MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->whereNotNull('user_id')
            ->get(['user_id', 'category_name']);

        $this->userMappings = [];
        foreach ($this->users as $user) {
            $mapping = $existing->firstWhere('user_id', $user['id']);
            $this->userMappings[$user['id']] = $mapping?->category_name;
        }
    }

    private function resolveUsageMessage(MicrosoftToken $token): ?string
    {
        if ($token->role_id === null) {
            return 'Selecteer eerst "Koppel aan rol" om gebruikers te koppelen aan categorieën.';
        }

        return null;
    }

    private function loadUsers(MicrosoftToken $token): void
    {
        if ($token->role_id === null || $token->role === null) {
            $this->users = [];

            return;
        }

        $users = User::query()
            ->role($token->role->name)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $this->users = $users
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
            ])
            ->all();
    }

    private function loadCategories(MicrosoftToken $token, MicrosoftCalendarService $service): void
    {
        $validToken = $service->getValidToken($this->tokenId);
        $apiCategories = $validToken !== null ? $service->getCategories($this->tokenId) : [];

        if ($validToken === null) {
            $this->statusMessage = 'Outlook-token is verlopen of ongeldig. Koppel het account opnieuw om categorieën vanuit Outlook te laden.';
        }

        $storedCategories = MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->orderBy('category_name')
            ->get(['category_name', 'category_color'])
            ->map(fn (MicrosoftCategoryMapping $mapping): array => [
                'displayName' => $mapping->category_name,
                'color' => $mapping->category_color ?? 'none',
            ])
            ->all();

        $merged = collect($apiCategories)->keyBy('displayName');

        foreach ($storedCategories as $storedCategory) {
            $merged->put($storedCategory['displayName'], $storedCategory);
        }

        foreach ($apiCategories as $apiCategory) {
            MicrosoftCategoryMapping::query()
                ->where('microsoft_token_id', $token->id)
                ->where('category_name', $apiCategory['displayName'])
                ->where(function ($query) use ($apiCategory): void {
                    $query->whereNull('outlook_category_id')
                        ->orWhere('outlook_category_id', '!=', $apiCategory['id']);
                })
                ->update(['outlook_category_id' => $apiCategory['id']]);
        }

        $this->categories = $merged->values()->all();
    }

    #[On('profile-saved')]
    public function save(): void
    {
        $token = MicrosoftToken::find($this->tokenId);

        if ($token === null || $token->role_id === null) {
            return;
        }

        $userIds = collect($this->users)->pluck('id')->all();
        $assignedCategories = [];

        foreach ($this->userMappings as $userId => $categoryName) {
            $userId = (int) $userId;

            if (! in_array($userId, $userIds, true)) {
                continue;
            }

            if (! filled($categoryName)) {
                MicrosoftCategoryMapping::query()
                    ->where('microsoft_token_id', $token->id)
                    ->where('user_id', $userId)
                    ->delete();

                continue;
            }

            if (in_array($categoryName, $assignedCategories, true)) {
                continue;
            }

            $category = collect($this->categories)->firstWhere('displayName', $categoryName);

            MicrosoftCategoryMapping::query()
                ->where('microsoft_token_id', $token->id)
                ->where('category_name', $categoryName)
                ->where(function ($query) use ($userId): void {
                    $query->whereNull('user_id')
                        ->orWhere('user_id', '!=', $userId);
                })
                ->delete();

            MicrosoftCategoryMapping::updateOrCreate(
                [
                    'microsoft_token_id' => $token->id,
                    'user_id'            => $userId,
                ],
                [
                    'category_name'  => $categoryName,
                    'category_color' => $category['color'] ?? null,
                ],
            );

            $assignedCategories[] = $categoryName;
        }

        MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $userIds)
            ->delete();

        $this->saved = true;
    }

    public function setUserCategory(int $userId, ?string $categoryName): void
    {
        if (filled($categoryName)) {
            foreach ($this->userMappings as $otherUserId => $mappedCategory) {
                if ((int) $otherUserId !== $userId && $mappedCategory === $categoryName) {
                    $this->userMappings[(int) $otherUserId] = null;
                }
            }
        }

        $this->userMappings[$userId] = filled($categoryName) ? $categoryName : null;
        $this->saved = false;
    }

    /**
     * @return array<int, array{name: string, color: string}>
     */
    public function getAvailableCategoryOptionsForUser(int $userId): array
    {
        $assignedElsewhere = collect($this->userMappings)
            ->reject(fn (?string $name, int|string $id): bool => (int) $id === $userId || ! filled($name))
            ->values()
            ->all();

        return collect($this->getCategoryOptions())
            ->reject(fn (array $option): bool => in_array($option['name'], $assignedElsewhere, true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, color: string}>
     */
    public function getCategoryOptions(): array
    {
        return collect($this->categories)
            ->map(fn (array $category): array => [
                'name' => $category['displayName'],
                'color' => self::PRESET_COLORS[$category['color'] ?? 'none'] ?? self::PRESET_COLORS['none'],
            ])
            ->values()
            ->all();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.microsoft-category-mappings', [
            'categoryOptions' => $this->getCategoryOptions(),
        ]);
    }
}
