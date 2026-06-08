<?php
namespace App\Console\Commands\ExactOnline;

use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class RefreshTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Exact Online oAuth tokens';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $result = (new ExactOnlineService())->refreshAccessCode();

        if ($result === false) {
            $this->info('No token refreshed, continuing');
            return 0; // success status code
        }
        $this->info('Refreshed token');
        return 0; // success status code
    }
}
