<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\SerialNumber;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Updates 32 historical serial numbers (units_new.csv rows 2–33).
 * Run after {@see HistoricalSerialNumbersSeeder} on existing environments.
 *
 * Row indices: debtor_number | customer_name_exact | unit_name | type | serial_number | order_number | price_nl_raw | date_m_d_yy
 */
class HistoricalSerialNumbersV2Seeder extends Seeder
{
    /**
     * @var array<int, array{string, string, string, string, string, string, string, string}>
     */
    private array $rows = array (
  0 => 
  array (
    0 => '4000204',
    1 => 'Robert vd Bos',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901237',
    5 => '25693',
    6 => '4.632,95',
    7 => '10/12/23',
  ),
  1 => 
  array (
    0 => '4001083',
    1 => 'Ron Scheepbouwer',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901304',
    5 => '24462',
    6 => '6.126,22',
    7 => '2/20/23',
  ),
  2 => 
  array (
    0 => '4001848',
    1 => 'Gerben de Vlaming',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901312',
    5 => '24701',
    6 => '5.828,84',
    7 => '4/5/23',
  ),
  3 => 
  array (
    0 => '4001186',
    1 => 'Bas Peek',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901318',
    5 => '24342',
    6 => '6.025,72',
    7 => '5/8/23',
  ),
  4 => 
  array (
    0 => '4001071',
    1 => 'Robin ten Have',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901332',
    5 => '24920',
    6 => '5663,63',
    7 => '5/22/23',
  ),
  5 => 
  array (
    0 => '1549',
    1 => 'Ricardo de Vries',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901384',
    5 => '25199',
    6 => '5.494,02',
    7 => '7/10/23',
  ),
  6 => 
  array (
    0 => '1269',
    1 => 'Jeroen Ephraim',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901398',
    5 => '26081',
    6 => '6200,26',
    7 => '12/21/23',
  ),
  7 => 
  array (
    0 => '4001827',
    1 => 'Brenda Guis',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901407',
    5 => '25813',
    6 => '6.473,73',
    7 => '11/8/23',
  ),
  8 => 
  array (
    0 => '4002020',
    1 => 'Martijn Koot',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901421',
    5 => '25720',
    6 => '6.004,17',
    7 => '10/18/23',
  ),
  9 => 
  array (
    0 => '4002155',
    1 => 'Margriet Crezee',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901425',
    5 => '26452',
    6 => '6742,20',
    7 => '2/28/24',
  ),
  10 => 
  array (
    0 => '4001526',
    1 => 'Barbara Wendt',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901440',
    5 => '26607',
    6 => '4.196,50',
    7 => '4/4/24',
  ),
  11 => 
  array (
    0 => '4002245',
    1 => 'Maud Schreurs',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901447',
    5 => '26672',
    6 => '8879,79',
    7 => '4/19/24',
  ),
  12 => 
  array (
    0 => '4002227',
    1 => 'Nico Min',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901511',
    5 => '26542',
    6 => '6.324,86',
    7 => '3/18/24',
  ),
  13 => 
  array (
    0 => '1482',
    1 => 'Niels Sleijser',
    2 => 'FrontlineAankoppelfiets (E)',
    3 => 'AANKOPPELFIETS',
    4 => 'F1901522',
    5 => '27319',
    6 => '5.929,13',
    7 => '7/25/24',
  ),
  14 => 
  array (
    0 => '4002923',
    1 => 'Wout Sterrenburg',
    2 => 'FrontlineHandbike',
    3 => 'AANKOPPELFIETS',
    4 => 'F2501617',
    5 => '30121',
    6 => '6.635,92',
    7 => '11/17/25',
  ),
  15 => 
  array (
    0 => '4002275',
    1 => 'Karin Lijbers',
    2 => 'FrontlineHandbike',
    3 => 'AANKOPPELFIETS',
    4 => 'F2501502',
    5 => '28224',
    6 => '6738,38',
    7 => '1/13/25',
  ),
  16 => 
  array (
    0 => '4002611',
    1 => 'Joke Wouters',
    2 => 'FrontlineHandbike',
    3 => 'AANKOPPELFIETS',
    4 => 'F2501540',
    5 => '28353',
    6 => '5.387,30',
    7 => '2/3/25',
  ),
  17 => 
  array (
    0 => '4001449',
    1 => 'Johan Meijer',
    2 => 'frontlineHandbike',
    3 => 'AANKOPPELFIETS',
    4 => 'F2501638',
    5 => '30529',
    6 => '6708,31',
    7 => '1/29/26',
  ),
  18 => 
  array (
    0 => '4001514',
    1 => 'Marieke Roelofs',
    2 => 'PermobilSmart drive',
    3 => 'ELECTRISCHE ONDERSTEUNING',
    4 => '253484',
    5 => '24630',
    6 => '7.156,94',
    7 => '3/25/23',
  ),
  19 => 
  array (
    0 => '1269',
    1 => 'Jeroen Ephraim',
    2 => 'ATECSwiss- trac',
    3 => 'Swiss-Trac',
    4 => 'H-10410',
    5 => '24393',
    6 => '10.771,77',
    7 => '2/2/23',
  ),
  20 => 
  array (
    0 => '4001835',
    1 => 'Marie-Christine DECROCK',
    2 => 'ATECSwiss- trac',
    3 => 'Swiss-Trac',
    4 => 'H-10429',
    5 => '24683',
    6 => '9894,92',
    7 => '4/2/23',
  ),
  21 => 
  array (
    0 => '4001731',
    1 => 'Jacqueline Godinetje Koninckx-Kollen',
    2 => 'ATECSwiss- trac',
    3 => 'Swiss-Trac',
    4 => 'H-10435',
    5 => '24709',
    6 => '9.281,23',
    7 => '4/6/23',
  ),
  22 => 
  array (
    0 => '1006',
    1 => 'Adriaan Boele',
    2 => 'ATECSwiss- trac',
    3 => 'Swiss-Trac',
    4 => 'H-10524',
    5 => '26455',
    6 => '9795,45',
    7 => '2/29/24',
  ),
  23 => 
  array (
    0 => '4002399',
    1 => 'Bert Tack',
    2 => 'ATECSwiss- trac',
    3 => 'Swiss-Trac',
    4 => 'OCC.2024.01',
    5 => '28031',
    6 => '4.500,00',
    7 => '12/9/24',
  ),
  24 => 
  array (
    0 => '4000210',
    1 => 'Ronald Herijgers',
    2 => 'ATECSwiss- trac',
    3 => 'Swiss-Trac',
    4 => 'ONT.26685',
    5 => '26685',
    6 => '4.500,00',
    7 => '4/16/24',
  ),
  25 => 
  array (
    0 => '4000886',
    1 => 'Mariette van Meerwijk',
    2 => 'RehasensePAWS',
    3 => 'PAWS',
    4 => '210252',
    5 => '24720',
    6 => '6.556,74',
    7 => '4/11/23',
  ),
  26 => 
  array (
    0 => '4001803',
    1 => 'Mirjam Kenselaar',
    2 => 'RehasensePAWS',
    3 => 'PAWS',
    4 => '210086',
    5 => '24440',
    6 => '5.825,08',
    7 => '2/16/23',
  ),
  27 => 
  array (
    0 => '4001426',
    1 => 'Ingrid Schoenmakers',
    2 => 'RGKADL',
    3 => 'ADL',
    4 => 'HLT5245',
    5 => '26471',
    6 => '10.194,66',
    7 => '2/28/24',
  ),
  28 => 
  array (
    0 => '4002251',
    1 => 'Sonja Mooren',
    2 => 'RGKSportstoel',
    3 => 'Sportstoel',
    4 => 'ALA307',
    5 => '26719',
    6 => '6.495,31',
    7 => '4/23/24',
  ),
  29 => 
  array (
    0 => '4002283',
    1 => 'Charliza Postma',
    2 => 'WolturnusADL',
    3 => 'ADL',
    4 => '44763',
    5 => '27552',
    6 => '8.230,70',
    7 => '9/9/24',
  ),
  30 => 
  array (
    0 => '4002188',
    1 => 'Derk-Jan Karrenbeld',
    2 => 'WolturnusADL',
    3 => 'ADL',
    4 => '44200',
    5 => '26406',
    6 => '9.972,68',
    7 => '2/20/24',
  ),
  31 => 
  array (
    0 => '4000990',
    1 => 'Ed Bijman',
    2 => 'WolturnusHandbike',
    3 => 'Handbike',
    4 => '44745',
    5 => '27071',
    6 => '8.632,54',
    7 => '6/17/24',
  ),

    );

    public function run(): void
    {
        /** @var array<string, int|null> $customerCache */
        $customerCache = [];

        foreach ($this->rows as $row) {
            [
                $debtorNumber,
                $customerName,
                $unitName,
                $type,
                $serialNumber,
                $orderNumber,
                $priceRaw,
                $dateRaw,
            ] = $row;

            $ownerId = $this->resolveOwnerId($debtorNumber, $customerCache);

            SerialNumber::updateOrCreate(
                ['serial_number' => trim($serialNumber)],
                [
                    'owner_id'               => $ownerId,
                    'order_id'               => null,
                    'main_id'                => null,
                    'name'                   => trim($unitName),
                    'type'                   => filled($type) ? trim($type) : null,
                    'customer_name'          => trim($customerName),
                    'customer_debtor_number' => trim($debtorNumber),
                    'order_number'           => trim($orderNumber),
                    'order_date'             => $this->parseDate($dateRaw),
                    'total_price_inc'        => $this->parsePrice($priceRaw),
                ],
            );
        }
    }

    /**
     * @param  array<string, int|null> $cache
     */
    private function resolveOwnerId(string $debtorNumber, array &$cache): ?int
    {
        if (array_key_exists($debtorNumber, $cache)) {
            return $cache[$debtorNumber];
        }

        $id = Customer::query()
            ->where('debtor_number', $debtorNumber)
            ->value('id');

        $cache[$debtorNumber] = $id;

        return $id;
    }

    private function parsePrice(string $raw): float
    {
        $normalized = preg_replace('/\s+/', '', trim($raw));
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }

    private function parseDate(string $raw): Carbon
    {
        return Carbon::createFromFormat('n/j/y', trim($raw))->startOfDay();
    }
}
