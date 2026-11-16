<?php

namespace App\Console\Commands;

use App\Domain\Line\Services\V2LineDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class V2LineResolveDeliveries extends Command
{
    protected $signature = 'v2:line:resolve-deliveries';
    protected $description = 'Resolve one due LINE delivery; explicit line-delivery runtime profile only';

    public function handle(V2LineDeliveryService $service): int
    {
        try {
            $id = DB::table('line_deliveries')->whereIn('status', ['scheduled', 'resolving'])
                ->whereRaw('scheduled_at <= clock_timestamp()')->whereRaw('(lease_until IS NULL OR lease_until <= clock_timestamp())')->orderBy('id')->value('id');
            if ($id !== null) $service->resolve($id);
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('LINE resolution deferred; inspect safe delivery state.');
            return self::FAILURE;
        }
    }
}
