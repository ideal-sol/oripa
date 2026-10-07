<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2PrizeImportCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrizeImportCsvTest extends TestCase
{
    #[DataProvider('validCsv')]
    public function test_csv_encoding_quotes_headers_and_physical_row_numbers(string $csv, string $name, int $line): void
    {
        $result = (new V2PrizeImportCsv())->parse($this->input($csv), false);
        self::assertCount(1, $result['rows']);
        self::assertSame($name, $result['rows'][0]['values']['カード名']);
        self::assertSame($line, $result['rows'][0]['row']);
        self::assertSame(hash('sha256', $csv), $result['sha256']);
    }

    public static function validCsv(): array
    {
        $header = '原価,管理ID,枚数,交換ポイント,ランク,カード名,未知';

        return [
            ["\xEF\xBB\xBF".$header."\r\n1,CARD,2,3,A, 確認 ,ignored\r\n", '確認', 2],
            [$header."\n,,,,,,\n1,CARD,2,3,A,\"a,\"\"b\"\"\",ignored", 'a,"b"', 3],
            [$header."\n1,CARD,2,3,A,\"line\nname\",ignored\n", "line\nname", 2],
            [$header."\n1,CARD,2,3,A,=SUM(A1),ignored", '=SUM(A1)', 2],
        ];
    }

    #[DataProvider('invalidCsv')]
    public function test_rejects_malformed_or_oversized_csv(string $csv): void
    {
        try {
            (new V2PrizeImportCsv())->parse($this->input($csv), false);
            self::fail('Invalid CSV accepted');
        } catch (V2CatalogException $exception) {
            self::assertSame('CSV_VALIDATION_FAILED', $exception->errorCode);
            self::assertSame('CSV_INVALID', $exception->details['errors'][0]['code']);
        }
    }

    public static function invalidCsv(): array
    {
        $header = "管理ID,ランク,枚数,交換ポイント,原価\n";

        return [
            [''], ["管理ID,ランク\nA,B"], [$header."A,B,1,2,3\xff"],
            ["管理ID,ランク,枚数,交換ポイント,原価,管理ID\n"],
            [$header.'"A,B,1,2,3'], [$header.'A"X,B,1,2,3'], [$header.'"A"x,B,1,2,3'],
            [$header."A,B,1,2,3\r"], [$header.'A,B,1,2'],
            [$header.str_repeat("A,B,1,2,3\n", 1001)], [str_repeat('a', 1048577)],
        ];
    }

    private function input(string $csv): array
    {
        return ['file_name' => 'prizes.csv', 'content_base64' => base64_encode($csv), 'expected_version_revision' => 1];
    }
}
