<?php

namespace App\Console\Commands;

use App\Services\WastageWaiverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WaiverClosePeriod extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'waiver:close-period {period? : Month to close as YYYY-MM; every closed month when omitted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire the unused wastage waiver of months that have ended';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(WastageWaiverService $waiver)
    {
        $period = $this->argument('period');

        if ($period && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error('The period must look like 2026-09.');

            return 1;
        }

        if ($period && $period >= $waiver->currentPeriod()) {
            $this->error('Only a month that has ended can be closed.');

            return 1;
        }

        $closed = DB::transaction(fn () => $waiver->closePastPeriods($period));

        $this->info("Expired waiver for {$closed} supplier month(s).");

        return 0;
    }
}
