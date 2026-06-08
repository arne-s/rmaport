<?php

return [
    'host' => env('ELASTICSEARCH_HOST'),
    'user' => env('ELASTICSEARCH_USER'),
    'password' => env('ELASTICSEARCH_PASSWORD'),
    'cloud_id' => env('ELASTICSEARCH_CLOUD_ID'),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'queue' => [
        'timeout' => env('SCOUT_QUEUE_TIMEOUT'),
    ],
    'indices' => [
        'mappings' => [
            'products' => [
                'properties' => [
                    'id' => [
                        'type' => 'keyword',
                    ],
                    'name' => [
                        'type' => 'keyword',
                    ],
                    'sku' => [
                        'type' => 'keyword',
                    ],
                    'attributes_formatted' => [
                        "type" => "nested",
                        "properties" => [
                            "kleur" => [
                                "type" => "keyword",
                            ],
                            "Hoogte" => [
                                "type" => "text"
                            ],
                            "Zijgeleiders" => [
                                "type" => "text"
                            ],
                        ]
                    ],
                ],
            ],
        ],
        'settings' => [
            'default' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
            ],
        ],
    ],
];
