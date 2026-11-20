<?php

namespace App\Console\Commands\V2;

use App\Domain\Line\Services\V2LineProfileBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class BackfillLineProfileNameCommand extends Command
{
    protected $signature = 'v2:line:backfill-profile-name {member} {--human-approved} {--same-provider-confirmed} {--actor=}';
    protected $description = 'Explicitly complement one linked member profile without enumerating followers';

    public function handle(V2LineProfileBackfill $service): int
    {
        $member = $this->argument('member'); $actor = $this->option('actor');
        if (! $this->option('human-approved') || ! $this->option('same-provider-confirmed')
            || ! is_string($member) || ! Str::isUuid($member) || ! is_string($actor) || ! Str::isUuid($actor)) {
            $this->error('Explicit approval, same-provider confirmation and valid identifiers required.');
            return self::INVALID;
        }
        try { $state = $service->execute($member, $actor); }
        catch (\Throwable) { $state = 'unavailable'; }
        $this->line($state);
        return $state === 'unavailable' ? self::FAILURE : self::SUCCESS;
    }
}
