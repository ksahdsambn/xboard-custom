<?php
namespace Plugin\Task005Probe\Commands;

class Probe extends \Illuminate\Console\Command
{
    protected $signature = 'task005:probe';
    protected $description = 'Non-production lifecycle probe with no business effects';
    public function handle(): int { $this->line('probe-ok'); return 0; }
}
