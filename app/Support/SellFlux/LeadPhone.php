<?php

namespace App\Support\SellFlux;

/**
 * The phone number is the link between a lead here and a lead on SellFlux.
 * Our column is free text and SellFlux may store it with or without the
 * country code and the ninth digit, so numbers are compared by area code plus
 * the last eight digits. Anything that is not a Brazilian number has no
 * identity and never matches, which keeps a bad number from linking a seller.
 */
class LeadPhone
{
    private const COUNTRY_CODE = '55';

    public static function national(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_int($phone)) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, self::COUNTRY_CODE)) {
            $digits = substr($digits, strlen(self::COUNTRY_CODE));
        }

        return in_array(strlen($digits), [10, 11], true) ? $digits : null;
    }

    public static function identity(mixed $phone): ?string
    {
        $national = self::national($phone);

        return $national === null
            ? null
            : substr($national, 0, 2).substr($national, -8);
    }

    public static function matches(mixed $first, mixed $second): bool
    {
        $identity = self::identity($first);

        return $identity !== null && $identity === self::identity($second);
    }

    /**
     * Formats to try on SellFlux's text search, keyed by a label without data.
     *
     * @return array<string, string>
     */
    public static function searchTerms(mixed $phone): array
    {
        $national = self::national($phone);

        if ($national === null) {
            return [];
        }

        $terms = [
            'ddi_ddd_numero' => self::COUNTRY_CODE.$national,
            'ddd_numero' => $national,
        ];
        $alternative = self::withOtherNinthDigit($national);

        if ($alternative !== null) {
            $terms['ddi_ddd_numero_outro_nono_digito'] = self::COUNTRY_CODE.$alternative;
            $terms['ddd_numero_outro_nono_digito'] = $alternative;
        }

        return $terms;
    }

    private static function withOtherNinthDigit(string $national): ?string
    {
        $areaCode = substr($national, 0, 2);
        $number = substr($national, 2);

        if (strlen($number) === 9) {
            return $number[0] === '9' ? $areaCode.substr($number, 1) : null;
        }

        // Only mobile numbers (starting at 6) gained the ninth digit.
        return (int) $number[0] >= 6 ? $areaCode.'9'.$number : null;
    }
}
