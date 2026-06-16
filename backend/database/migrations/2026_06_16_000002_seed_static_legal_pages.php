<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $pages = [
            [
                'slug' => 'terms',
                'title' => '利用規約',
                'body' => "本ページは利用規約の編集用初期文です。\n管理画面の設定から内容を編集してください。",
            ],
            [
                'slug' => 'privacy',
                'title' => 'プライバシーポリシー',
                'body' => "本ページはプライバシーポリシーの編集用初期文です。\n管理画面の設定から内容を編集してください。",
            ],
            [
                'slug' => 'commercial-law',
                'title' => '特定商取引法に基づく表記',
                'body' => "本ページは特定商取引法に基づく表記の編集用初期文です。\n管理画面の設定から内容を編集してください。",
            ],
        ];

        foreach ($pages as $page) {
            DB::table('static_pages')->updateOrInsert(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'body' => $page['body'],
                    'status' => 'published',
                    'published_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('static_pages')
            ->whereIn('slug', ['terms', 'privacy', 'commercial-law'])
            ->delete();
    }
};
