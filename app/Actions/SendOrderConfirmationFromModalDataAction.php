<?php

namespace App\Actions;

use App\Filament\Resources\OrderResource\Actions\ApproveOrderEmailAction;
use App\Models\Order\Order;

class SendOrderConfirmationFromModalDataAction
{
    public function __construct(
        protected SendOrderConfirmMailAction $sendOrderConfirmMailAction,
    ) {}

    /**
     * Send order confirmation using modal data (recipients already resolved to e-mail addresses).
     *
     * @param array{
     *     to?: array<int, string>,
     *     cc?: array<int, string>,
     *     bcc?: array<int, string>,
     *     subject?: string|null,
     *     message?: string|null,
     *     attachments?: array<int, string>
     * } $data
     */
    public function execute(Order $order, array $data): void
    {
        $resolvedAttachments = [];
        if (! empty($data['attachments'])) {
            $resolvedAttachments = ApproveOrderEmailAction::resolveAttachments($order, $data['attachments']);
        }

        $pdfMedia = $order->getFirstMedia('order');
        if ($pdfMedia !== null) {
            $resolvedAttachments[] = [
                'path' => $pdfMedia->getPath(),
                'name' => $pdfMedia->file_name,
                'mime' => 'application/pdf',
            ];
        }

        $order->updateQuietly(['sent_at' => now()]);
        $order->refresh();

        $this->sendOrderConfirmMailAction->execute(
            order: $order,
            to: (array) ($data['to'] ?? []),
            cc: (array) ($data['cc'] ?? []),
            bcc: (array) ($data['bcc'] ?? []),
            subject: $data['subject'] ?? null,
            message: $data['message'] ?? null,
            attachments: $resolvedAttachments,
        );
    }
}
