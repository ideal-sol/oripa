<?php

namespace App\Console\Commands;

use App\Domain\Line\Services\V2LineDeliverySender;
use App\Domain\Line\Services\V2LineDeliveryService;
use App\Domain\Line\Services\V2LineMessagingSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class V2LineSendDeliveries extends Command
{
    protected $signature = 'v2:line:work-delivery-batches {--limit=100}';
    protected $description = 'Dispatch due LINE batches; explicit line-delivery runtime profile only';

    public function handle(V2LineDeliverySender $sender): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Invalid LINE work limit.');
            // Rebase only overdue, never-attempted work. Retry backoff is independent.
            foreach (DB::table('line_deliveries')->where('status', 'sending')->pluck('id') as $id) {
                DB::transaction(function () use ($id, $sender): void {
                    app(V2LineMessagingSettingService::class)->setting(true);
                    $d = DB::table('line_deliveries')->where('id', $id)->lockForUpdate()->firstOrFail();
                    $pending = DB::table('line_delivery_batches')->where('delivery_id', $id)->where('status', 'pending')
                        ->whereNull('first_attempted_at')->orderBy('position')->get();
                    $now = $sender->clock();
                    if ($pending->isEmpty() || CarbonImmutable::parse($pending[0]->not_before)->gte($now->subSeconds(2))) return;
                    $pacing = app(V2LineDeliveryService::class)->pacing($d->pacing);
                    foreach ($pacing->resumeSchedule($pending[0]->method, $pending->pluck('id')->all(), $now) as $batch => $at) {
                        DB::table('line_delivery_batches')->where('id', $batch)->update(['not_before' => $at]);
                    }
                }, 1);
            }
            for ($i = 0; $i < $limit; $i++) {
                $id = DB::table('line_delivery_batches')->whereIn('status', ['pending', 'retrying', 'sending'])
                    ->whereRaw('not_before <= clock_timestamp()')->whereRaw('(lease_until IS NULL OR lease_until <= clock_timestamp())')
                    ->orderBy('not_before')->orderBy('id')->value('id');
                if ($id === null) break;
                $sender->send($id);
            }
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('LINE dispatch deferred; immutable retry identity retained.');
            return self::FAILURE;
        }
    }
}
