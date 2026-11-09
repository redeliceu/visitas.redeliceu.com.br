<?php

namespace Tests\Unit;

use App\Support\SellFlux\LeadPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeadPhoneTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function samePhones(): array
    {
        return [
            'mask and country code' => ['(11) 99999-0000', '5511999990000'],
            'integer from the API' => ['11999990000', 11999990000],
            'without the ninth digit' => ['11999990000', '1199990000'],
            'country code and no ninth digit' => ['+55 11 9999-0000', '11999990000'],
        ];
    }

    #[DataProvider('samePhones')]
    public function test_it_matches_the_same_phone_in_different_formats(mixed $first, mixed $second): void
    {
        $this->assertTrue(LeadPhone::matches($first, $second));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function differentPhones(): array
    {
        return [
            'other number' => ['11999990000', '11999990001'],
            'other area code' => ['11999990000', '21999990000'],
            'empty values' => ['', ''],
            'null values' => [null, null],
            'too short' => ['99990000', '99990000'],
            'foreign number' => ['+1 415 555 2671 000', '+1 415 555 2671 000'],
        ];
    }

    #[DataProvider('differentPhones')]
    public function test_it_does_not_match_different_or_invalid_phones(mixed $first, mixed $second): void
    {
        $this->assertFalse(LeadPhone::matches($first, $second));
    }

    public function test_search_terms_cover_country_code_and_ninth_digit(): void
    {
        $this->assertSame([
            'ddi_ddd_numero' => '5511999990000',
            'ddd_numero' => '11999990000',
            'ddi_ddd_numero_outro_nono_digito' => '551199990000',
            'ddd_numero_outro_nono_digito' => '1199990000',
        ], LeadPhone::searchTerms('(11) 99999-0000'));
    }

    public function test_landlines_do_not_get_a_ninth_digit_variant(): void
    {
        $this->assertSame([
            'ddi_ddd_numero' => '551133330000',
            'ddd_numero' => '1133330000',
        ], LeadPhone::searchTerms('(11) 3333-0000'));
    }

    public function test_invalid_phones_have_no_search_terms(): void
    {
        $this->assertSame([], LeadPhone::searchTerms('não informado'));
    }
}
