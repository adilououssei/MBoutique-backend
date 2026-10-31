<?php

namespace App\Modules\Auth\Support;

/**
 * Pays proposés à l'inscription (code ISO 3166-1 alpha-2 => nom en français).
 * Sert aux statistiques de la plateforme : inscriptions et utilisation par pays.
 */
final class Countries
{
    /** @var array<string, string> */
    private const ALL = [
        'ZA' => 'Afrique du Sud', 'DZ' => 'Algérie', 'DE' => 'Allemagne', 'AO' => 'Angola', 'SA' => 'Arabie saoudite',
        'BE' => 'Belgique', 'BJ' => 'Bénin', 'BW' => 'Botswana', 'BR' => 'Brésil', 'BF' => 'Burkina Faso',
        'BI' => 'Burundi', 'CM' => 'Cameroun', 'CA' => 'Canada', 'CV' => 'Cap-Vert', 'CN' => 'Chine',
        'KM' => 'Comores', 'CG' => 'Congo', 'CD' => 'Congo (RDC)', 'CI' => 'Côte d’Ivoire', 'DJ' => 'Djibouti',
        'EG' => 'Égypte', 'AE' => 'Émirats arabes unis', 'ER' => 'Érythrée', 'ES' => 'Espagne', 'SZ' => 'Eswatini',
        'US' => 'États-Unis', 'ET' => 'Éthiopie', 'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambie',
        'GH' => 'Ghana', 'GN' => 'Guinée', 'GQ' => 'Guinée équatoriale', 'GW' => 'Guinée-Bissau', 'HT' => 'Haïti',
        'IN' => 'Inde', 'IT' => 'Italie', 'KE' => 'Kenya', 'LS' => 'Lesotho', 'LB' => 'Liban',
        'LR' => 'Liberia', 'LY' => 'Libye', 'LU' => 'Luxembourg', 'MG' => 'Madagascar', 'MW' => 'Malawi',
        'ML' => 'Mali', 'MA' => 'Maroc', 'MU' => 'Maurice', 'MR' => 'Mauritanie', 'MZ' => 'Mozambique',
        'NA' => 'Namibie', 'NE' => 'Niger', 'NG' => 'Nigeria', 'UG' => 'Ouganda', 'NL' => 'Pays-Bas',
        'PT' => 'Portugal', 'CF' => 'République centrafricaine', 'GB' => 'Royaume-Uni', 'RW' => 'Rwanda', 'ST' => 'Sao Tomé-et-Principe',
        'SN' => 'Sénégal', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SO' => 'Somalie', 'SD' => 'Soudan',
        'SS' => 'Soudan du Sud', 'CH' => 'Suisse', 'TZ' => 'Tanzanie', 'TD' => 'Tchad', 'TG' => 'Togo',
        'TN' => 'Tunisie', 'TR' => 'Turquie', 'ZM' => 'Zambie', 'ZW' => 'Zimbabwe',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::ALL;
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::ALL[$code] ?? $code);
    }
}
