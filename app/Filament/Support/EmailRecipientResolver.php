<?php

namespace App\Filament\Support;

use App\Models\Customer;
use App\Models\User;

final class EmailRecipientResolver
{
    /**
     * @return array<string, string>
     */
    public static function getRecipientOptions(): array
    {
        $options = [];

        foreach (User::query()->whereNotNull('email')->orderBy('first_name')->orderBy('last_name')->get() as $user) {
            $name = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email;
            $options['user_'.$user->id] = 'Gebruiker: '.$name.' <'.$user->email.'>';
        }

        return $options;
    }

    /**
     * Resolve selected keys (user_*, dealer_*) to array of email addresses.
     *
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveRecipients(array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;

                continue;
            }

            if (str_starts_with($key, 'user_')) {
                $id = (int) str_replace('user_', '', $key);
                $user = User::find($id);
                if ($user !== null && $user->email !== null && $user->email !== '') {
                    $emails[] = $user->email;
                }

                continue;
            }

            if (str_starts_with($key, 'dealer_')) {
                $id = (int) str_replace('dealer_', '', $key);
                $dealer = Customer::find($id);
                $email = $dealer?->getEmail();
                if ($dealer !== null && $email !== null && $email !== '') {
                    $emails[] = $email;
                }
            }
        }

        return array_values(array_unique($emails));
    }
}
