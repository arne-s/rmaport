<?php

namespace App\Actions;

use App\Contracts\TransactionalActionInterface;
use App\Exceptions\DuplicateTransactionalActionException;
use App\Exceptions\TransactionalActionCutoffException;
use App\Exceptions\TransactionalActionValidationException;
use App\Models\TransactionalAction as TransactionalActionModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

abstract class TransactionalAction implements TransactionalActionInterface
{
    abstract public function getKey(): array;

    abstract public function validate(): bool|string;

    abstract public function run(...$params): mixed;

    abstract public function verifyTime(): ?Carbon;

    public function execute(...$params): mixed
    {
        $key = $this->getKey();
        $keyString = $this->keyToString($key);
        $className = static::class;

        $validationResult = $this->validate();
        if ($validationResult !== true) {
            $errorMessage = is_string($validationResult) ? $validationResult : 'Validation failed';
            $this->logAction($className, $keyString, 'validation_failed', $errorMessage);
            $exceptionMessage = is_string($validationResult) ? $validationResult : '';

            throw new TransactionalActionValidationException(static::class, $exceptionMessage);
        }

        $cutoffTimestamp = $this->getCutoffTimestamp();
        if ($cutoffTimestamp) {
            $verifyTime = $this->verifyTime();

            if ($verifyTime === null) {
                $errorMessage = 'verifyTime() returned null. A valid timestamp is required when cutoff timestamp is configured.';
                $this->logAction($className, $keyString, 'cutoff_blocked', $errorMessage);

                throw new TransactionalActionCutoffException(static::class, $cutoffTimestamp, null);
            }

            if ($verifyTime->lt($cutoffTimestamp)) {
                $errorMessage = "Action cannot be executed because verifyTime ({$verifyTime->toDateTimeString()}) is before cutoff timestamp: {$cutoffTimestamp->toDateTimeString()}";
                $this->logAction($className, $keyString, 'cutoff_blocked', $errorMessage);

                throw new TransactionalActionCutoffException(static::class, $cutoffTimestamp, $verifyTime);
            }
        }

        if ($this->checkExecutionStatus($key)) {
            $this->logAction($className, $keyString, 'duplicate', 'Action already executed');
            $this->onDuplicate();

            return false;
        }

        $result = $this->run(...$params);

        $this->markAsExecuted($key);
        $this->logAction($className, $keyString, 'executed', null, now());

        return $result;
    }

    protected function getCutoffTimestamp(): ?Carbon
    {
        $cutoff = config('transactional-actions.cutoff_timestamp');
        if ($cutoff === null) {
            return null;
        }

        return Carbon::parse($cutoff);
    }

    protected function onDuplicate(): void
    {
        $key = $this->getKey();

        throw new DuplicateTransactionalActionException(static::class, $key);
    }

    protected function checkExecutionStatus(array $key): bool
    {
        $keyString = $this->keyToString($key);
        $className = static::class;

        return TransactionalActionModel::where('class', $className)
            ->where('key', $keyString)
            ->exists();
    }

    protected function markAsExecuted(array $key): void
    {
        $keyString = $this->keyToString($key);
        $className = static::class;

        TransactionalActionModel::updateOrCreate(
            [
                'class' => $className,
                'key' => $keyString,
            ],
            [
                'executed_at' => now(),
            ],
        );
    }

    protected function keyToString(array $key): string
    {
        return implode('|', $key);
    }

    protected function logAction(
        string $className,
        string $keyString,
        string $status,
        ?string $errorMessage = null,
        ?Carbon $executedAt = null,
        bool $forced = false,
    ): void {
        $logData = [
            'class' => $className,
            'key' => $keyString,
            'status' => $status,
            'forced' => $forced,
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email ?? 'system',
            'executed_at' => $executedAt?->toDateTimeString() ?? (($status === 'executed' || $status === 'forced') ? now()->toDateTimeString() : null),
        ];

        if ($errorMessage) {
            $logData['error_message'] = $errorMessage;
        }

        $logLevel = match ($status) {
            'executed' => 'info',
            'forced' => 'warning',
            'duplicate', 'validation_failed', 'cutoff_blocked' => 'warning',
            default => 'info',
        };

        Log::channel('transactional-actions')->{$logLevel}(
            $forced ? "Transactional Action: {$status} (FORCED)" : "Transactional Action: {$status}",
            $logData,
        );
    }
}
