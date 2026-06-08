<div
    wire:poll.{{ $this->getRecordLockPollInterval() }}="refreshRecordLock"
    class="sr-only"
    aria-hidden="true"
></div>
