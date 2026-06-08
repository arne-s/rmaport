<?php

namespace App\Support\Planning;

final class PlanningCalendarListPage
{
    /**
     * @param  list<PlanningCalendarDay>  $days
     */
    public function __construct(
        public bool $hasLinkedTokens,
        public bool $isEmpty,
        public bool $hasPreviousPage,
        public bool $hasNextPage,
        public int $page,
        public string $pageLabel,
        public array $days,
    ) {}

    /**
     * @return array{
     *     hasLinkedTokens: bool,
     *     isEmpty: bool,
     *     hasPreviousPage: bool,
     *     hasNextPage: bool,
     *     page: int,
     *     pageLabel: string,
     *     days: list<array{
     *         label: string,
     *         isToday: bool,
     *         events: list<array{timeLabel: string, title: string, description: string, color: string, colors: list<string>, categories: list<array{name: string, color: string}>}>
     *     }>
     * }
     */
    public function toLivewireArray(): array
    {
        $days = [];

        foreach ($this->days as $day) {
            $events = [];

            foreach ($day->events as $event) {
                $colors = $event->displayColors();

                $events[] = [
                    'timeLabel' => $event->timeLabel(),
                    'title' => $event->title,
                    'description' => $event->description,
                    'color' => $colors[0] ?? $event->color,
                    'colors' => $colors,
                    'categories' => $event->displayCategoryItems(),
                ];
            }

            $days[] = [
                'label' => $day->label,
                'isToday' => $day->isToday,
                'events' => $events,
            ];
        }

        return [
            'hasLinkedTokens' => $this->hasLinkedTokens,
            'isEmpty' => $this->isEmpty,
            'hasPreviousPage' => $this->hasPreviousPage,
            'hasNextPage' => $this->hasNextPage,
            'page' => $this->page,
            'pageLabel' => $this->pageLabel,
            'days' => $days,
        ];
    }
}
