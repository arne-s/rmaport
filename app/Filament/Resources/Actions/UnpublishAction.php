<?php
namespace App\Filament\Resources\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Actions\Concerns\InteractsWithRecord;
use Illuminate\Database\Eloquent\Model;

// Unused
class UnpublishAction extends Action
{
    use InteractsWithRecord, CanCustomizeProcess;

    protected ?string $name = 'unpublish';
    protected array $extraAttributes = [['class'=>'white']];


    protected string|null $returnUrl;
    protected ?Closure $mutateRecordDataUsing = null;


    protected function setUp(): void
    {
        parent::setUp();
        $this->label('Terugzetten naar concept');

        $this->action(function (): void {
            $this->process(function (array $data, Model $record) {
                //$record->is_active = 0;
            });

            $this->success();
        });
    }

    public function mutateRecordDataUsing(?Closure $callback): static
    {
        $this->mutateRecordDataUsing = $callback;

        return $this;
    }


}
