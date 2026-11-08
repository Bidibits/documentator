<?php

namespace App\Console\Commands;

use App\Documents\DemoData as Demo;
use Illuminate\Console\Command;

class DemoData extends Command
{
    protected $signature = 'documentator:demo {--remove : Take the sample documents and their empty folders away again}';

    protected $description = 'Load sample folders and documents (code DEMO-...) with files of different kinds and deadlines, to try the app with.';

    public function handle(): int
    {
        if ($this->option('remove')) {
            $result = Demo::remove();
            $this->info("Removed {$result['documents']} sample documents and {$result['folders']} empty sample folders.");

            return self::SUCCESS;
        }

        try {
            $result = Demo::create();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Made {$result['documents']} sample documents and {$result['folders']} folders ({$result['skipped']} were already there).");
        $this->line('Their deadlines are counted from today. Take them away again with: php artisan documentator:demo --remove');

        return self::SUCCESS;
    }
}
