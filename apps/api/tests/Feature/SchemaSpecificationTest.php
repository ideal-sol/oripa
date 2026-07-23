<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaSpecificationTest extends TestCase
{
    public function test_gacha_prizes_do_not_store_probability(): void
    {
        $this->assertTrue(Schema::hasTable('gacha_prizes'));
        $this->assertFalse(Schema::hasColumn('gacha_prizes', 'probability'));
        $this->assertFalse(Schema::hasColumn('gacha_prizes', 'probability_ppm'));
    }

    public function test_draw_results_use_only_allowed_result_types(): void
    {
        $constraints = DB::table('pg_constraint')
            ->whereRaw("conrelid = 'draw_results'::regclass")
            ->pluck('conname')
            ->all();

        $this->assertContains('draw_results_type_check', $constraints);
        $this->assertContains('draw_results_gacha_id_draw_sequence_number_unique', $constraints);
    }

    public function test_probability_rows_have_ppm_and_one_minimum_guarantee_index(): void
    {
        $this->assertTrue(Schema::hasColumn('gacha_probability_version_prize_probabilities', 'probability_ppm'));

        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'gacha_probability_version_prize_probabilities')
            ->pluck('indexname')
            ->all();

        $this->assertContains('prob_stage_one_minimum_unique', $indexes);
    }

    public function test_point_lots_enforce_paid_and_free_expiration_rule(): void
    {
        $constraints = DB::table('pg_constraint')
            ->whereRaw("conrelid = 'point_lots'::regclass")
            ->pluck('conname')
            ->all();

        $this->assertContains('point_lots_expire_rule_check', $constraints);
    }

    public function test_shipping_request_histories_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('shipping_request_histories'));
        $this->assertTrue(Schema::hasColumn('shipping_request_histories', 'from_status'));
        $this->assertTrue(Schema::hasColumn('shipping_request_histories', 'to_status'));
        $this->assertTrue(Schema::hasColumn('shipping_request_histories', 'tracking_number'));
    }
}
