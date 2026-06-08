<?php
/** Exact online service */

return [
    'enabled' => env('EXACT_ENABLED', true),
    'testmode' => env('EXACT_TESTMODE', false),
    'client_id' => env('EXACT_CLIENT_ID'),
    'payment_condition_id' => '14',
    'journal_id' => env('EXACT_JOURNAL_ID'),
    'sales_journal_code' => env('EXACT_SALES_JOURNAL_CODE', '40'),
    'purchase_journal_code' => env('EXACT_PURCHASE_JOURNAL_CODE', '30'),
    'gl_account_debtors' => env('EXACT_GL_ACCOUNT_DEBTORS'),
    'deposit_product_code' => env('EXACT_DEPOSIT_PRODUCT_CODE', 'RD.AANB.01'),
    'deposit_vat_code' => env('EXACT_DEPOSIT_VAT_CODE', 1),
    'client_secret' => env('EXACT_CLIENT_SECRET'),
    'redirect_uri' => env('EXACT_REDIRECT_URI'),
    'division' => env('EXACT_DIVISION'),
    'gl_account_8110' => env('EXACT_GL_ACCOUNT_8110', 'e860d01a-caaa-4666-917d-5d73b7461f86'), // buitenzonwering
    'gl_account_8000' => env('EXACT_GL_ACCOUNT_8000', '1ba44299-db10-447b-8b6d-349834324d5c'),  // binnenzonwering

    'testdata' => [
        'product_item_group' => 'a857b970-942d-4a05-8c0c-6c904dd9bf49', //GLAccount / Grootboekrekening
        'warehouse_id' => '16496203-72ba-406b-84a4-97f6137e394f', // WarehouseID
        'company_id' => '0cc23aa0-70f8-4ff8-9320-bd49646aa554', // OrderedBy
        'costcenter_id' => 'ALG', // CostCenter
    ]
];
