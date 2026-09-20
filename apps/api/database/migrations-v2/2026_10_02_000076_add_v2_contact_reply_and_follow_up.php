<?php

use App\Support\V2DatabaseTimestamp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_user_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('contact_inquiry_id')->constrained('contact_inquiries')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('message_ciphertext');
            $table->uuid('request_id');
            $table->timestampTz('created_at');
            $table->index(['contact_inquiry_id', 'id']);
        });
        DB::statement('CREATE TRIGGER contact_user_messages_reject_mutation BEFORE UPDATE OR DELETE ON contact_user_messages FOR EACH ROW EXECUTE FUNCTION v2_contact_reject_history_mutation()');
        DB::statement('CREATE TRIGGER contact_user_messages_reject_truncate BEFORE TRUNCATE ON contact_user_messages FOR EACH STATEMENT EXECUTE FUNCTION v2_contact_reject_history_mutation()');
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        DB::table('mail_templates')->insert([
            'public_id' => (string) Str::uuid7(),
            'template_key' => 'contact_reply',
            'label' => 'お問い合わせ返信',
            'subject_template' => 'お問い合わせへのご返信',
            'body_html' => '<p>{{full_name}} 様</p><p>お問い合わせいただきありがとうございます。</p><p>{{reply_content}}</p><p>その他ご不明点がございましたら、再度お問い合わせください。</p>',
            'revision' => 1,
            'created_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
            'updated_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
        ]);
        $this->restoreGuard();
    }

    public function down(): void
    {
        if (DB::table('contact_user_messages')->exists()
            || DB::table('outbox_messages')->where('event_type', 'contact.reply.email.requested')->exists()) {
            throw new RuntimeException('Contact history and delivery records must be retained.');
        }
        Schema::dropIfExists('contact_user_messages');
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        DB::table('mail_templates')->where('template_key', 'contact_reply')->delete();
        $this->restoreGuard();
    }

    private function restoreGuard(): void
    {
        DB::statement('CREATE TRIGGER mail_templates_fixed_set_guard BEFORE INSERT OR UPDATE OR DELETE ON mail_templates FOR EACH ROW EXECUTE FUNCTION v2_mail_templates_guard_fixed_rows()');
    }
};
