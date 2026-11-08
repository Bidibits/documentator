<?php

namespace App\Console\Commands;

use App\Documents\ArchiveRules;
use App\Support\ChangeLog;
use Illuminate\Console\Command;

class ArchiveDue extends Command
{
    protected $signature = 'documentator:archive-due {--limit=500 : The most versions to archive in one run} {--dry-run : Only say what is due}';

    protected $description = 'Archive the old versions the archive rules say are due (run every night by the scheduler).';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $due = ArchiveRules::due();
            $this->info('Due now: '.$due['idle']->merge($due['old'])->unique('id')->count().' (not opened for a while: '.$due['idle']->count().', replaced by a newer version: '.$due['old']->count().')');

            return self::SUCCESS;
        }

        $result = ArchiveRules::run(null, ChangeLog::AUTOMATIC, max(1, (int) $this->option('limit')));
        $this->info("Archived {$result['archived']} of {$result['due']} due.");
        foreach ($result['failed'] as $failure) {
            $this->warn("{$failure['code']} Ver {$failure['rev']}: {$failure['error']}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
