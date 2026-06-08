<?php

namespace App\Http\Livewire;

use App\Models\MicrosoftToken;
use App\Models\Role;
use App\Services\MicrosoftCalendarService;
use Livewire\Attributes\On;
use Livewire\Component;

class MicrosoftCalendarConnect extends Component
{
    public int $tokenId;

    public ?MicrosoftToken $token = null;

    /** @var array<int, array{id: string, name: string, isDefault: bool}> */
    public array $calendars = [];

    /** @var array<int, string> */
    public array $generalCategoryOptions = [];

    public ?string $selectedCalendarId = null;

    public ?int $roleId = null;

    public ?string $calendarDisplayName = null;

    public ?string $selectedGeneralCategoryName = null;

    public ?string $roleConflictMessage = null;

    public function mount(): void
    {
        $this->token = MicrosoftToken::find($this->tokenId);

        if ($this->token) {
            $this->selectedCalendarId = $this->token->calendar_id;
            $this->roleId = $this->token->role_id;
            $this->calendarDisplayName = $this->token->calendar_display_name;
            $this->loadCalendars();
            $this->loadGeneralCategoryOptions();
            $this->selectedGeneralCategoryName = $this->token->general_category_name;
        }
    }

    private function loadCalendars(): void
    {
        try {
            $this->calendars = app(MicrosoftCalendarService::class)->getCalendars($this->tokenId);
        } catch (\Throwable) {
            $this->calendars = [];
        }
    }

    private function loadGeneralCategoryOptions(): void
    {
        $this->generalCategoryOptions = [];

        try {
            $categories = app(MicrosoftCalendarService::class)->getCategories($this->tokenId);
        } catch (\Throwable) {
            $categories = [];
        }

        foreach ($categories as $category) {
            $name = trim((string) ($category['displayName'] ?? ''));

            if ($name === '') {
                continue;
            }

            $this->generalCategoryOptions[$name] = $name;
        }

        ksort($this->generalCategoryOptions, SORT_NATURAL | SORT_FLAG_CASE);
    }

    public function saveCalendar(): void
    {
        $token = $this->token ?? MicrosoftToken::find($this->tokenId);

        if ($token && $this->selectedCalendarId) {
            $token->update(['calendar_id' => $this->selectedCalendarId]);
        }
    }

    public function saveCalendarSettings(): void
    {
        $this->roleConflictMessage = null;

        $token = $this->token ?? MicrosoftToken::find($this->tokenId);

        if ($token === null) {
            return;
        }

        $roleId = $this->roleId !== null && $this->roleId !== 0 ? (int) $this->roleId : null;

        if ($roleId !== null) {
            $conflict = MicrosoftToken::query()
                ->where('role_id', $roleId)
                ->where('id', '!=', $token->id)
                ->exists();

            if ($conflict) {
                $role = Role::query()->find($roleId);
                $roleLabel = $role?->getDisplayName() ?? 'deze rol';

                $this->roleConflictMessage = 'Er is al een ander Outlook-account gekoppeld aan '
                    . $roleLabel
                    . '. Kies een andere rol of pas het andere account aan.';

                return;
            }
        }

        $token->update([
            'role_id' => $roleId,
            'calendar_display_name' => filled($this->calendarDisplayName)
                ? trim((string) $this->calendarDisplayName)
                : null,
            'general_category_name' => filled($this->selectedGeneralCategoryName)
                ? trim((string) $this->selectedGeneralCategoryName)
                : null,
        ]);

        $this->token = $token->fresh(['role']);
        $this->calendarDisplayName = $this->token->calendar_display_name;
        $this->selectedGeneralCategoryName = $this->token->general_category_name;
        $this->dispatch('calendar-role-changed', tokenId: $this->tokenId);
    }

    public function updatedCalendarDisplayName(): void
    {
        $this->saveCalendarSettings();
    }

    public function updatedRoleId(): void
    {
        $this->saveCalendarSettings();
    }

    public function updatedSelectedGeneralCategoryName(): void
    {
        $this->saveCalendarSettings();
    }

    #[On('outlook-categories-updated')]
    public function onOutlookCategoriesUpdated(int $tokenId): void
    {
        if ($tokenId !== $this->tokenId) {
            return;
        }

        $this->loadGeneralCategoryOptions();
    }

    #[On('profile-saved')]
    public function onProfileSaved(): void
    {
        $this->saveCalendar();
        $this->saveCalendarSettings();
    }

    public function disconnect(): void
    {
        app(MicrosoftCalendarService::class)->disconnect($this->tokenId);

        $url = route('filament.app.resources.customers.settings').'?area=outlook';

        $this->redirect(
            $url,
            navigate: false,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function roleOptions(): array
    {
        return Role::query()
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Role $role): array => [
                $role->id => $role->getDisplayName(),
            ])
            ->all();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.microsoft-calendar-connect', [
            'roleOptions' => self::roleOptions(),
        ]);
    }
}
