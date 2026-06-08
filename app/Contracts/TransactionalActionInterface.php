<?php

namespace App\Contracts;

interface TransactionalActionInterface
{
    /**
     * Get the unique key for this action.
     * Returns an array of identifiers that uniquely identify this action.
     *
     * @return array
     */
    public function getKey(): array;

    /**
     * Execute the action logic.
     * This method contains the actual implementation of the action.
     * 
     * Return values:
     * - true: Success without return value
     * - false or string: Failure (string is error message)
     * - Any other value: Success with return value (e.g., ID, object, array)
     *
     * @param mixed ...$params
     * @return mixed
     */
    public function run(...$params): mixed;
}

