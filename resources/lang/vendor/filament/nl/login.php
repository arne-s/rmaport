<?php

return [
    'title' => 'Inloggen',
    'heading' => 'Login',
    'buttons' => [
        'submit' => [
            'label' => 'Login',
        ],
    ],
    'fields' => [
        'email' => [
            'label' => 'E-mailadres',
        ],
        'password' => [
            'label' => 'Wachtwoord',
        ],
        'remember' => [
            'label' => 'Onthoud mij',
        ],
    ],
    'messages' => [
        'failed' => 'Onjuiste inloggegevens.',
        'throttled' => 'Te veel inlogpogingen. Probeer opnieuw over :seconds seconden.',
    ],
];
