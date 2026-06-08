<?php

namespace App\Console\Commands\ExactOnline;

use Illuminate\Console\Command;

class ImportCustomersFromExact extends Command
{
    protected $signature = 'exact-online:import-customers
                            {--limit= : Maximum number of accounts to import}
                            {--code= : Import only the account with this Exact Code (debiteur-/relatiecode)}
                            {--prune-deleted-from-exact : After import, delete local customers removed from Exact (full sync only)}
                            {--no-progress : Do not show a progress bar}';

    protected $description = 'Alias voor exact-online:import-accounts: klanten waaronder klant+leverancier; alleen-leveranciers worden overgeslagen.';

    public function handle(): int
    {
        $arguments = [];

        if ($this->option('limit') !== null) {
            $arguments['--limit'] = $this->option('limit');
        }

        if ($this->option('code') !== null) {
            $arguments['--code'] = $this->option('code');
        }

        if ($this->option('prune-deleted-from-exact')) {
            $arguments['--prune-deleted-from-exact'] = true;
        }

        if ($this->option('no-progress')) {
            $arguments['--no-progress'] = true;
        }

        return $this->call('exact-online:import-accounts', $arguments);
    }
}
