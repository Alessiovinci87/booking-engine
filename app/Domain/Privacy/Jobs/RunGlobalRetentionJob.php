<?php

namespace App\Domain\Privacy\Jobs;

use App\Domain\Privacy\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Categorie globali della conservazione: link di reimpostazione (H) e audit di piattaforma (F). */
final class RunGlobalRetentionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function handle(RetentionService $retention): void
    {
        $retention->runGlobal(CarbonImmutable::now());
    }
}
