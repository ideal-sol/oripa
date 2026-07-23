<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Probability\Exceptions\ProbabilityValidationException;
use App\Domain\Probability\Services\ProbabilityValidator;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Http\Requests\Admin\PublishProbabilityVersionRequest;
use App\Http\Resources\AdminProbabilityMatrixResource;
use App\Http\Resources\AdminProbabilityPreviewResource;
use App\Http\Resources\AdminProbabilityVersionResource;
use App\Models\Gacha;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class AdminProbabilityController extends Controller
{
    public function matrix(Gacha $gacha): AdminProbabilityMatrixResource
    {
        return new AdminProbabilityMatrixResource($gacha->load([
            'ranks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'ranks.prizes' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'currentProbabilityVersion.stages' => fn ($query) => $query->orderBy('sort_order')->orderBy('min_draw_number'),
            'currentProbabilityVersion.stages.probabilities' => fn ($query) => $query->orderBy('id'),
        ]));
    }

    public function preview(
        PublishProbabilityVersionRequest $request,
        Gacha $gacha,
        ProbabilityValidator $validator,
    ): AdminProbabilityPreviewResource {
        try {
            $stages = $validator->validateForPublish($gacha, $request->stages());
        } catch (ProbabilityValidationException $exception) {
            throw ValidationException::withMessages([
                'stages' => $exception->errors,
            ]);
        }

        return new AdminProbabilityPreviewResource([
            'gacha_id' => $gacha->id,
            'total_ppm' => ProbabilityValidator::TOTAL_PPM,
            'stages' => array_map(fn (array $stage): array => [
                ...$stage,
                'total_ppm' => array_sum(array_column($stage['probabilities'], 'probability_ppm')),
                'minimum_guarantee_ppm' => array_sum(array_map(
                    fn (array $probability): int => $probability['is_minimum_guarantee'] ? $probability['probability_ppm'] : 0,
                    $stage['probabilities'],
                )),
                'prize_count' => count(array_filter(
                    $stage['probabilities'],
                    fn (array $probability): bool => ! $probability['is_minimum_guarantee'],
                )),
            ], $stages),
        ]);
    }

    public function publish(
        PublishProbabilityVersionRequest $request,
        Gacha $gacha,
        ProbabilityVersionPublisher $publisher,
        AuditLogService $auditLogService,
    ): AdminProbabilityVersionResource {
        try {
            $version = $publisher->publish(
                gacha: $gacha,
                stages: $request->stages(),
                publisher: $request->user(),
                changeReason: $request->changeReason(),
            );
        } catch (ProbabilityValidationException $exception) {
            throw ValidationException::withMessages([
                'stages' => $exception->errors,
            ]);
        }

        $auditLogService->record(
            action: 'admin.probability_version.published',
            adminUser: $request->user(),
            auditable: $version,
            request: $request,
            metadata: [
                'gacha_id' => $gacha->id,
                'version_number' => $version->version_number,
                'change_reason' => $request->changeReason(),
            ],
        );

        return new AdminProbabilityVersionResource($version->load('stages.probabilities'));
    }
}
