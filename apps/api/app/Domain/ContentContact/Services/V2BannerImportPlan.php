<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use Illuminate\Support\Facades\DB;
use Normalizer;
use SensitiveParameter;

final class V2BannerImportPlan
{
    public function __construct(private readonly V2BannerImportCsv $csv, private readonly V2BannerImportUrlPolicy $urls) {}

    public function build(#[SensitiveParameter] array $parsed, string $defaultCategory, bool $refresh): array
    {
        $categories = DB::table('content_banner_categories')->get();
        $default = $categories->firstWhere('public_id', $defaultCategory);
        if ($default === null) {
            $this->csv->validation([['row' => null, 'column' => 'カテゴリ', 'code' => 'BANNER_CATEGORY_NOT_FOUND',
                'message' => '既定カテゴリを選択してください。']]);
        }
        $banners = DB::table('content_banners')->where('status', '<>', 'archived')
            ->whereIn('external_id', array_column(array_column($parsed['rows'], 'values'), '管理ID'))->get()->keyBy('external_id');
        $errors = [];
        $seen = [];
        $rows = [];
        foreach ($parsed['rows'] as $record) {
            $values = $record['values'];
            $externalId = $values['管理ID'] ?? '';
            $title = Normalizer::normalize($values['タイトル'] ?? '', Normalizer::FORM_C);
            $categoryName = Normalizer::normalize($values['カテゴリ'] ?? '', Normalizer::FORM_C);
            $category = $categoryName === '' ? $default : $categories->firstWhere('normalized_name', mb_strtolower($categoryName));
            $rowErrors = [];
            if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $externalId) !== 1) {
                $rowErrors['管理ID'] = 'EXTERNAL_ID_INVALID';
            } elseif (isset($seen[$externalId])) {
                $rowErrors['管理ID'] = 'EXTERNAL_ID_DUPLICATED';
            }
            $seen[$externalId] = true;
            if (! is_string($title) || mb_strlen($title) < 1 || mb_strlen($title) > 191
                || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title)) {
                $rowErrors['タイトル'] = 'TITLE_INVALID';
            }
            if ($category === null) {
                $rowErrors['カテゴリ'] = 'BANNER_CATEGORY_NOT_FOUND';
            }
            $fetch = ! $banners->has($externalId) || $refresh;
            $url = $fetch ? ($values['画像URL'] ?? '') : null;
            if ($fetch) {
                try {
                    $this->urls->validate($url);
                } catch (V2ContentContactException $exception) {
                    $rowErrors['画像URL'] = $exception->errorCode;
                }
            }
            foreach ($rowErrors as $column => $code) {
                $errors[] = ['row' => $record['row'], 'column' => $column, 'code' => $code, 'message' => '指定された項目を確認してください。'];
            }
            $rows[] = ['row_number' => $record['row'], 'external_id' => $externalId, 'title' => $title,
                'category_id' => $category?->id, 'category_name' => $category?->name,
                'fetch_image' => $fetch, 'url' => $url];
        }
        if ($errors !== []) {
            $this->csv->validation($errors);
        }
        return $rows;
    }
}
