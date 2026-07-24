<?php

namespace App\Domain\Identity\Services;

use App\Models\V2\Admin;
use App\Models\V2\AdminRecoveryCode;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2RecoveryCodeService
{
    /**
     * @return list<string>
     */
    public function regenerate(Admin $admin): array
    {
        return DB::transaction(function () use ($admin): array {
            AdminRecoveryCode::query()
                ->where('admin_id', $admin->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $codes = [];
            for ($index = 0; $index < 10; $index++) {
                $code = bin2hex(random_bytes(16));
                $codes[] = $code;
                AdminRecoveryCode::query()->create([
                    'admin_id' => $admin->getKey(),
                    'code_hash' => hash('sha256', $code),
                ]);
            }

            return $codes;
        });
    }

    public function consume(Admin $admin, #[SensitiveParameter] string $code): bool
    {
        if (! preg_match('/\A[0-9a-f]{32}\z/', $code)) {
            return false;
        }

        return DB::transaction(function () use ($admin, $code): bool {
            $record = AdminRecoveryCode::query()
                ->where('admin_id', $admin->getKey())
                ->where('code_hash', hash('sha256', $code))
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($record === null) {
                return false;
            }
            $record->forceFill(['used_at' => now()])->save();

            return true;
        });
    }
}
