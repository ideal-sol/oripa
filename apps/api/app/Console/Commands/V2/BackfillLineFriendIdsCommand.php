<?php

namespace App\Console\Commands\V2;

use App\Domain\Line\Services\V2LineFriendIdBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class BackfillLineFriendIdsCommand extends Command
{
    protected $signature = 'v2:line:backfill-friend-ids {--human-approved} {--same-provider-confirmed} {--actor=} {--resume=} {--cleanup-only}';

    protected $description = 'Manually recover same-BOT follower observations after explicit Human authorization';

    public function handle(V2LineFriendIdBackfill $service): int
    {
        $actor = $this->option('actor');
        $resume = $this->option('resume');
        if (! $this->option('human-approved') || (! $this->option('cleanup-only') && ! $this->option('same-provider-confirmed'))
            || ! is_string($actor) || ! Str::isUuid($actor) || ($resume !== null && (! is_string($resume) || ! Str::isUuid($resume)))
            || ($resume !== null && $this->option('cleanup-only'))) {
            $this->error('Explicit Human approval, operator UUID and same-provider confirmation are required.');
            return self::INVALID;
        }
        $result = $service->execute($actor, $resume, (bool) $this->option('cleanup-only'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['state'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
