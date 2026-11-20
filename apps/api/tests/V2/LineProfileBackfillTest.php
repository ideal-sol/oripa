<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Line\Services\V2LineProfileBackfill;
use App\Models\V2\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LineProfileBackfillTest extends TestCase
{
    public function test_bounded_profile_complement_rechecks_identity_generation_and_is_idempotent(): void
    {
        $original=config('database.connections.pgsql'); $schema='lineux_profile_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA '.$schema); config(['database.connections.pgsql.search_path'=>$schema]); DB::purge('pgsql');
        try {
            Artisan::call('migrate',['--path'=>'database/migrations-v2','--force'=>true]);
            config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'cache.default'=>'array',
                'v2_identity.sms_verification.phone_hmac_key'=>'base64:'.base64_encode(str_repeat('p',32))]);
            Http::preventStrayRequests();
            $botId=DB::table('line_bots')->insertGetId(['public_id'=>(string)Str::uuid(),'name'=>'Synthetic profile BOT','is_active'=>true,'connection_status'=>'succeeded','connected_at'=>now(),'channel_id'=>'1234567','channel_secret_ciphertext'=>Crypt::encryptString('synthetic-only')]);
            $bot=DB::table('line_bots')->where('id',$botId)->first();
            $subject='U'.str_repeat('a',32); $uuid=(string)Str::uuid();
            $user=User::query()->create(['email_display'=>$uuid.'@example.test','email_normalized'=>$uuid.'@example.test','email_verified_at'=>now(),'password_hash'=>'$argon2id$synthetic','state'=>'active','full_name'=>'会員氏名']);
            $identity=DB::table('external_identity_accounts')->insertGetId(['public_id'=>(string)Str::uuid(),'user_id'=>$user->id,'provider'=>'line','issuer'=>'https://access.line.me',
                'subject_hash'=>app(V2IdentityCorrelation::class)->hash('line|https://access.line.me|'.$subject),'subject_ciphertext'=>Crypt::encryptString($subject),'linked_at'=>now()]);
            $actor=(string)Str::uuid(); $service=app(V2LineProfileBackfill::class); $mode='mismatch'; $calls=0;
            Http::fake(function ($request) use (&$mode,&$calls,$subject,$bot) {
                if (str_ends_with($request->url(),'/token')) return Http::response(['access_token'=>'synthetic-token','expires_in'=>900]);
                $calls++;
                if ($mode==='generation') DB::table('line_bots')->where('id',$bot->id)->update(['authentication_revision'=>2,'revision'=>2]);
                return Http::response(['userId'=>$mode==='mismatch' ? 'U'.str_repeat('b',32) : $subject,'displayName'=>"LINE\n名前"]);
            });
            self::assertSame('unavailable',$service->execute($user->public_id,$actor));
            $mode='generation'; self::assertSame('unavailable',$service->execute($user->public_id,$actor));
            self::assertSame(2,DB::table('line_bots')->where('id',$bot->id)->value('authentication_revision'));
            self::assertNull($user->fresh()->display_name);
            $mode='success'; self::assertSame('updated',$service->execute($user->public_id,$actor));
            self::assertSame('LINE 名前',$user->fresh()->display_name); self::assertSame('会員氏名',$user->fresh()->full_name);
            self::assertSame('unchanged',$service->execute($user->public_id,$actor)); self::assertSame(3,$calls);
            self::assertSame(1,DB::table('audit_logs')->where('action_code','line.profile.complemented')->count());
            DB::table('external_identity_accounts')->where('id',$identity)->update(['revoked_at'=>now()]);
            $user->forceFill(['display_name'=>null])->save(); self::assertSame('unchanged',$service->execute($user->public_id,$actor)); self::assertSame(3,$calls);
        } finally {
            DB::purge('pgsql'); config(['database.connections.pgsql'=>$original]); DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}
