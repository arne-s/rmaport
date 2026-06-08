<?php

namespace App\Support\Planning;

use Illuminate\Support\Carbon;

final class PlanningCalendarEvent
{
    /**
     * @param  list<string>  $colors
     * @param  list<array{name: string, color: string}>  $categoryItems
     */
    public function __construct(
        public Carbon $startsAt,
        public Carbon $endsAt,
        public string $title,
        public string $description,
        public string $categoryName,
        public string $color,
        public bool $isAllDay,
        public ?string $eventId = null,
        public ?string $categoryKey = null,
        public array $colors = [],
        public array $categoryItems = [],
    ) {
        if ($colors === [] && $color !== '') {
            $this->colors = [$color];
        }

        if ($categoryItems === [] && trim($categoryName) !== '') {
            $this->categoryItems = [
                ['name' => trim($categoryName), 'color' => $color],
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function displayColors(): array
    {
        if ($this->colors !== []) {
            return $this->colors;
        }

        return $this->color !== '' ? [$this->color] : [];
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    public function displayCategoryItems(): array
    {
        if ($this->categoryItems !== []) {
            return $this->categoryItems;
        }

        $name = trim($this->categoryName);

        if ($name === '') {
            return [];
        }

        return [
            ['name' => $name, 'color' => $this->color],
        ];
    }

    public function timeLabel(): string
    {
        if ($this->isAllDay) {
            return 'Hele dag';
        }

        if ($this->startsAt->toDateString() !== $this->endsAt->toDateString()) {
            return $this->startsAt->format('H:i') . ' – ' . $this->endsAt->format('d-m H:i');
        }

        if ($this->startsAt->format('H:i') === $this->endsAt->format('H:i')) {
            return $this->startsAt->format('H:i');
        }

        return $this->startsAt->format('H:i') . ' – ' . $this->endsAt->format('H:i');
    }
}
