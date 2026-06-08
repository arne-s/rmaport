<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\SettingFormState;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettingService
{
    /**
     * @param  array<string, mixed>  $formState
     */
    public function saveFromFormState(array $formState): void
    {
        foreach (SettingFormState::toFlat($formState) as $uid => $value) {
            if (! is_string($uid)) {
                continue;
            }

            $record = Setting::query()->where('uid', $uid)->first()
                ?? Setting::makeFromDefaults($uid);

            if ($record === null) {
                continue;
            }

            $definition = $record->definition();

            $messages = [
                'value.required' => 'Dit veld is verplicht.',
                'value.string' => 'Selecteer een geldige waarde.',
                'value.in' => 'Selecteer een geldige waarde.',
                'value.integer' => 'Voer een geldig aantal in.',
                'value.numeric' => 'Voer een geldig getal in.',
                'value.min' => 'De waarde is te laag.',
                'value.max' => 'De waarde is te hoog.',
            ];

            if (method_exists($definition, 'validationMessages')) {
                $messages = array_merge($messages, $definition->validationMessages());
            }

            $validator = Validator::make(
                ['value' => $value],
                ['value' => $definition->rules()],
                $messages,
                ['value' => $record->name],
            );

            if ($validator->fails()) {
                throw ValidationException::withMessages([
                    "settings.{$uid}" => $validator->errors()->first('value'),
                ]);
            }

            if (! $record->exists) {
                $record->save();
            }

            $record->value = $definition->serialize($value);
            $record->save();
        }

        Setting::clearCache();
    }
}
