<?php

namespace App\Filament\Forms\Components;

use Closure;

class RichEditor extends \Filament\Forms\Components\RichEditor
{
    protected array|Closure|null $toolbarButtons = [
        // 'attachFiles',
        // 'blockquote',
        'bold',
        'bulletList',
        //'codeBlock',
        //'h2',
        //'h3',
        'italic',
        'link',
        'orderedList',
        //'redo',
        //'strike',
        'underline',
        //'undo',
    ];

}
