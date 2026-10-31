<?php

namespace Tests\V2;

use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\PrizeShipping\Services\V2B2ShippingCsv;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class B2ShippingCsvTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['v2_b2_shipping.sender_name' => '架空発送元',
            'v2_b2_shipping.sender_phone' => '000-0000-0000',
            'v2_b2_shipping.sender_postal_code' => '0000000',
            'v2_b2_shipping.sender_address' => '架空県架空市1-2-3',
            'v2_b2_shipping.billing_customer_code' => '000000000001',
            'v2_b2_shipping.freight_number' => '01']);
    }

    public function test_numbered_mapping_unused_columns_and_header_replacement_are_independent(): void
    {
        $writer = app(V2B2ShippingCsv::class);
        $result = $this->row();
        self::assertSame([], $result['codes']);
        $expected = [1 => '01910191019171918191019101910191', 2 => '0', 3 => '0', 5 => '2026/11/01',
            9 => '090-1234-5678', 11 => '123-4567', 12 => '架空県架空市町1 2-3', 13 => '',
            16 => '架空受取人', 20 => '000-0000-0000', 22 => '000-0000', 23 => '架空県架空市1-2-3',
            24 => '', 25 => '架空発送元', 28 => 'トレーディングカード(置き配禁止)', 30 => '計3点',
            38 => '1', 40 => '000000000001', 41 => '', 42 => '01', 48 => '0', 52 => '0'];
        self::assertCount(97, $result['row']);
        foreach ($result['row'] as $index => $value) {
            self::assertSame($expected[$index + 1] ?? '', $value, 'column '.($index + 1));
        }
        $before = $writer->encode([$result['row']]);
        config(['v2_b2_shipping.headers' => array_fill(0, 97, '差替テスト')]);
        $after = $writer->encode([$result['row']]);
        self::assertSame(explode("\r\n", $before)[1], explode("\r\n", $after)[1]);
        self::assertNotSame(explode("\r\n", $before)[0], explode("\r\n", $after)[0]);
    }

    public function test_cp932_without_bom_crlf_and_csv_quoting_roundtrip(): void
    {
        $writer = app(V2B2ShippingCsv::class);
        $row = $this->row()['row'];
        $row[15] = "架空,\"氏名\"\r\n次行";
        $csv = $writer->encode([$row, $row]);
        self::assertFalse(str_starts_with($csv, "\xEF\xBB\xBF"));
        self::assertStringContainsString(mb_convert_encoding('架空', 'CP932', 'UTF-8'), $csv);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $csv));
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, mb_convert_encoding($csv, 'UTF-8', 'CP932'));
        rewind($stream);
        self::assertCount(97, fgetcsv($stream, escape: ''));
        self::assertSame($row, fgetcsv($stream, escape: ''));
        self::assertSame($row, fgetcsv($stream, escape: ''));
        self::assertFalse(fgetcsv($stream, escape: ''));
        fclose($stream);
    }

    #[DataProvider('textCases')]
    public function test_actual_output_width_and_character_warnings(array $address, int $column, string $expected, array $codes): void
    {
        $result = $this->row($address);
        self::assertSame($expected, $result['row'][$column - 1]);
        self::assertSame($codes, $result['codes']);
    }

    public static function textCases(): array
    {
        return [
            'ordinary JIS' => [['recipient_name' => '山田太郎齋藤'], 16, '山田太郎齋藤', []],
            'JIS symbol Windows mapping' => [['building' => '〜～−'], 13, '〜～−', []],
            'CP932 variant' => [['recipient_name' => '髙橋'], 16, '髙橋', ['UNSUPPORTED_CHARACTER']],
            'CP932 circled' => [['building' => '①号'], 13, '①号', ['UNSUPPORTED_CHARACTER']],
            'emoji replacement' => [['recipient_name' => '合成😀'], 16, '合成〓', ['UNSUPPORTED_CHARACTER']],
            'free text controls' => [['recipient_name' => "\t=+@\r\n合成"], 16, '合成', []],
            'name boundary' => [['recipient_name' => str_repeat('あ', 16)], 16, str_repeat('あ', 16), []],
            'name too long' => [['recipient_name' => str_repeat('あ', 17)], 16, str_repeat('あ', 17), ['NAME_TOO_LONG']],
            'mixed name width' => [['recipient_name' => str_repeat('あ', 15).'abc'], 16, str_repeat('あ', 15).'abc', ['NAME_TOO_LONG']],
            'halfwidth kana' => [['recipient_name' => str_repeat('ｱ', 32)], 16, str_repeat('ｱ', 32), []],
            'JIS Greek fullwidth' => [['recipient_name' => str_repeat('α', 17)], 16, str_repeat('α', 17), ['NAME_TOO_LONG']],
            'CP932 circled fullwidth' => [['recipient_name' => str_repeat('①', 17)], 16, str_repeat('①', 17), ['NAME_TOO_LONG', 'UNSUPPORTED_CHARACTER']],
            'building long' => [['building' => str_repeat('あ', 17)], 13, str_repeat('あ', 17), ['BUILDING_TOO_LONG']],
            'address long' => [['prefecture' => '', 'city' => '', 'street' => str_repeat('あ', 33)], 12, str_repeat('あ', 33), ['ADDRESS_TOO_LONG']],
            'international phone' => [['phone_number' => '+819012345678'], 9, '09012345678', []],
            'international phone with hyphens' => [['phone_number' => '+81-90-1234-5678'], 9, '090-1234-5678', []],
            'international phone repeated separator' => [['phone_number' => '+81--90-1234-5678'], 9, '+81--90-1234-5678', ['PHONE_INVALID']],
            'international phone missing subscriber' => [['phone_number' => '+81'], 9, '+81', ['PHONE_INVALID']],
            'international phone empty subscriber' => [['phone_number' => '+81-'], 9, '+81-', ['PHONE_INVALID']],
            'international phone invalid subscriber' => [['phone_number' => '+81-abc'], 9, '+81-abc', ['PHONE_INVALID']],
            'phone unchanged' => [['phone_number' => '090-1234-5678'], 9, '090-1234-5678', []],
            'phone empty' => [['phone_number' => ''], 9, '', ['PHONE_INVALID']],
            'phone invalid' => [['phone_number' => 'abc'], 9, 'abc', ['PHONE_INVALID']],
            'phone too long' => [['phone_number' => str_repeat('1', 16)], 9, str_repeat('1', 16), ['PHONE_INVALID']],
            'postal already formatted' => [['postal_code' => '123-4567'], 11, '123-4567', []],
            'digit space preserved' => [['street' => '町 １　２ 号'], 12, '架空県架空市町１　２号', []],
            'all warnings retained' => [['recipient_name' => str_repeat('髙', 17), 'street' => str_repeat('あ', 33), 'building' => str_repeat('あ', 17), 'phone_number' => ''], 16, str_repeat('髙', 17), ['ADDRESS_TOO_LONG', 'BUILDING_TOO_LONG', 'NAME_TOO_LONG', 'PHONE_INVALID', 'UNSUPPORTED_CHARACTER']],
        ];
    }

    public function test_split_only_at_original_street_last_space_when_both_fields_fit(): void
    {
        $street = str_repeat('あ', 29).' 建物';
        $result = $this->row(['prefecture' => '県', 'city' => '市', 'street' => $street]);
        self::assertSame('県市'.str_repeat('あ', 29), $result['row'][11]);
        self::assertSame('建物', $result['row'][12]);
        self::assertSame([], $result['codes']);
        foreach ([['building' => '既存'], ['street' => str_repeat('あ', 33).' 建物'],
            ['street' => str_repeat('あ', 29).' '.str_repeat('建', 17)]] as $override) {
            $address = array_replace(['prefecture' => '県', 'city' => '市', 'street' => $street, 'building' => ''], $override);
            $result = $this->row($address);
            self::assertContains('ADDRESS_TOO_LONG', $result['codes']);
            self::assertSame($address['building'], $result['row'][12]);
        }
    }

    public function test_sender_phone_uses_the_same_country_prefix_conversion(): void
    {
        foreach (['+819012345678' => '09012345678', '+81-90-1234-5678' => '090-1234-5678',
            '090-1234-5678' => '090-1234-5678'] as $input => $expected) {
            config(['v2_b2_shipping.sender_phone' => $input]);
            self::assertSame($expected, $this->row()['row'][19]);
        }
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_or_missing_settings_fail_without_disclosing_values(string $key, mixed $value): void
    {
        config(['v2_b2_shipping.'.$key => $value]);
        try {
            app(V2B2ShippingCsv::class)->settings();
            self::fail('Invalid configuration was accepted.');
        } catch (V2PrizeShippingException $exception) {
            self::assertSame(503, $exception->status);
            self::assertSame('B2_EXPORT_NOT_CONFIGURED', $exception->errorCode);
            self::assertSame('B2 export is not configured.', $exception->getMessage());
        }
    }

    public static function invalidSettings(): array
    {
        return [['sender_name', ''], ['sender_phone', ''], ['sender_postal_code', ''],
            ['sender_address', ''], ['billing_customer_code', ''], ['freight_number', ''],
            ['billing_customer_code', '12345678'], ['billing_customer_code', '1234567890123'],
            ['billing_class_code', '12'], ['billing_class_code', 'abc'], ['freight_number', '1'],
            ['sender_phone', 'invalid'], ['sender_postal_code', '123'], ['invoice_type', 'invalid'],
            ['item_name', str_repeat('あ', 26)], ['item_name', str_repeat('α', 26)],
            ['item_name', '😀'], ['sender_name', null]];
    }

    private function row(array $address = []): array
    {
        $writer = app(V2B2ShippingCsv::class);

        return $writer->row('01910191-0191-7191-8191-019101910191', array_replace([
            'recipient_name' => '架空受取人', 'postal_code' => '1234567', 'prefecture' => '架空県',
            'city' => '架空市', 'street' => '町 1 2-3', 'building' => '', 'phone_number' => '090-1234-5678',
        ], $address), 3, $writer->settings(), CarbonImmutable::parse('2026-10-31T15:01:00Z'));
    }
}
