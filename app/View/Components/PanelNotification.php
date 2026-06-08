<?php

namespace App\View\Components;

use Filament\Notifications\Notification;
use Filament\Notifications\View\Components\NotificationComponent;
use Filament\Notifications\View\Components\NotificationComponent\IconComponent;
use Filament\Notifications\View\NotificationsIconAlias;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Illuminate\View\ComponentAttributeBag;
use Override;

use function Filament\Support\generate_icon_html;

class PanelNotification extends Notification
{
    /**
     * https://github.com/filamentphp/filament/blob/02a639c4e453762da2df37d7ef94350610c74628/packages/notifications/src/Notification.php#L323
     */
    #[Override]
    public function toEmbeddedHtml(): string
    {
        $status = $this->getStatus();
        $title = $this->getTitle();
        $hasTitle = filled($title);
        $date = $this->getDate();
        $hasDate = filled($date);
        $body = $this->getBody();
        $hasBody = filled($body);

        $attributes = (new ComponentAttributeBag)
            ->merge([
                'wire:key' => "{$this->getId()}.notifications.{$this->getId()}",
                'x-on:close-notification.window' => "if (\$event.detail.id == '{$this->getId()}') close()",
                // Changes:
                'x-on:click' => !empty($this->getActions())
                    ? $this->getActions()[0]->getAlpineClickHandler() . "; \$dispatch('markedNotificationAsRead', { id: '{$this->getId()}' });"
                    : null,
            ], escape: false)
            ->color(NotificationComponent::class, $this->getColor() ?? 'gray')
            ->class([
                'fi-no-notification',
                'fi-inline' => $this->isInline,
                "fi-status-{$status}" => $status,
            ]);

        ob_start(); ?>

        <div
            x-data="notificationComponent({ notification: <?= Js::from($this->toArray()) ?> })"
            x-transition:enter-start="fi-transition-enter-start"
            x-transition:enter-end="fi-transition-enter-end"
            x-transition:leave-start="fi-transition-leave-start"
            x-transition:leave-end="fi-transition-leave-end"
            <?= $attributes ?>
        >
            <?= generate_icon_html(
                $this->getIcon(),
                attributes: (new ComponentAttributeBag)->color(IconComponent::class, $this->getIconColor())->class(['fi-no-notification-icon']),
                size: $this->getIconSize(),
            )?->toHtml() ?>

            <div class="fi-no-notification-main">
                <?php if ($hasTitle || $hasDate || $hasBody) { ?>
                    <div class="fi-no-notification-text">
                        <?php if ($hasTitle) { ?>
                            <h3 class="fi-no-notification-title">
                                <?= str($title)->sanitizeHtml() ?>
                            </h3>
                        <?php } ?>

                        <?php if ($hasDate) { ?>
                            <time class="fi-no-notification-date">
                                <?= e($date) ?>
                            </time>
                        <?php } ?>

                        <?php if ($hasBody) { ?>
                            <div class="fi-no-notification-body">
                                <?= str($body)->sanitizeHtml() ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php
                // Changes:
                /* if ($actions = $this->getActions()) { ?>
                    <div class="fi-ac fi-no-notification-actions">
                        <?php foreach ($actions as $action) { ?>
                            <?= $action->toHtml() ?>
                        <?php } ?>
                    </div>
                <?php } */ ?>
            </div>

            <button
                type="button"
                x-on:click.stop="close"
                class="fi-icon-btn fi-no-notification-close-btn"
            >
                <?= generate_icon_html(Heroicon::XMark, alias: NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON)->toHtml() ?>
            </button>
        </div>

        <?php return ob_get_clean();
    }
}
