<?php
// this file can be removed!
namespace App\Console\Commands;

use Illuminate\Console\Command;

class InitDefaultMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:init-default-media';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set default media for new customers (sliders, carousels, etc)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('No default media initialisation needed.');
    }
}
