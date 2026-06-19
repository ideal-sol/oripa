<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $groupedRequests = DB::table('shipping_requests')
            ->join('shipping_items', 'shipping_items.shipping_request_id', '=', 'shipping_requests.id')
            ->select('shipping_requests.*')
            ->groupBy('shipping_requests.id')
            ->havingRaw('count(shipping_items.id) > 1')
            ->orderBy('shipping_requests.id')
            ->get();

        foreach ($groupedRequests as $request) {
            $items = DB::table('shipping_items')
                ->where('shipping_request_id', $request->id)
                ->orderBy('id')
                ->get();

            $items->skip(1)->each(function ($item) use ($request): void {
                $newRequestId = DB::table('shipping_requests')->insertGetId([
                    'user_id' => $request->user_id,
                    'status' => $item->status ?? $request->status,
                    'recipient_name' => $request->recipient_name,
                    'postal_code' => $request->postal_code,
                    'prefecture' => $request->prefecture,
                    'city' => $request->city,
                    'address_line1' => $request->address_line1,
                    'address_line2' => $request->address_line2,
                    'phone_number' => $request->phone_number,
                    'tracking_number' => $item->tracking_number,
                    'requested_at' => $request->requested_at,
                    'shipped_at' => $item->shipped_at,
                    'created_at' => $request->created_at,
                    'updated_at' => now(),
                ]);

                DB::table('shipping_items')
                    ->where('id', $item->id)
                    ->update([
                        'shipping_request_id' => $newRequestId,
                    ]);
            });

            $firstItem = $items->first();

            if ($firstItem) {
                DB::table('shipping_requests')
                    ->where('id', $request->id)
                    ->update([
                        'status' => $firstItem->status ?? $request->status,
                        'tracking_number' => $firstItem->tracking_number,
                        'shipped_at' => $firstItem->shipped_at,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // 個別配送への分割は運用データの正規化のため、downでは結合しません。
    }
};
