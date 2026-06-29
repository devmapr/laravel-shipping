<?php

declare(strict_types=1);

return [
    'default' => env('SHIPPING_DRIVER', 'alonomic'),

    'drivers' => [
        'alonomic' => [
            'class' => Planx\Shipping\Drivers\AlonomicDriver::class,
            'config' => [
                'base_url' => env('ALONOMIC_BASE_URL', ''),
                'email' => env('ALONOMIC_EMAIL', ''),
                'password' => env('ALONOMIC_PASSWORD', ''),
            ],
        ],
        'tinex' => [
            'class' => Planx\Shipping\Drivers\TinexDriver::class,
            'config' => [
                'base_url' => env('TINEX_BASE_URL', 'https://stg-apiback.tinextco.com'),
                'username' => env('TINEX_USERNAME', env('TINEXT_USERNAME', '')),
                'password' => env('TINEX_PASSWORD', env('TINEXT_PASSWORD', '')),
                'origin_contact_id' => env('TINEX_ORIGIN_CONTACT_ID', '28'),
                'destination_id' => env('TINEX_DESTINATION_ID', '4'),
                'is_self_pickup' => env('TINEX_IS_SELF_PICKUP', true),
                'default_parcel_size_id' => env('TINEX_DEFAULT_PARCEL_SIZE_ID', 3),
                'barcode_prefix' => env('TINEX_BARCODE_PREFIX', 'TINX_'),
                'order_number_prefix' => env('TINEX_ORDER_NUMBER_PREFIX', 'ORD-'),
                'active' => env('TINEX_ACTIVE', (bool) env('TINEXT_ACTIVE', false)),
            ],
        ],
        'forward' => [
            'class' => Planx\Shipping\Drivers\ForwardDriver::class,
            'config' => [
                'active' => env('FORWARD_ACTIVE', false),
                'username' => env('FORWARD_USERNAME', ''),
                'password' => env('FORWARD_PASSWORD', ''),
                'sender_phone' => env('FORWARD_SENDER_PHONE', env('SENDER_PHONE', '')),
                'sender_address' => env('FORWARD_SENDER_ADDRESS', env('SENDER_ADDRESS', '')),
                'sender_location' => env('FORWARD_SENDER_LOCATION', env('SENDER_LOCATION', '')),
                'sender_postal_code' => env('FORWARD_SENDER_POSTAL_CODE', env('SENDER_POSTAL_CODE', '')),
            ],
        ],
        'snappbox' => [
            'class' => Planx\Shipping\Drivers\SnappBoxDriver::class,
            'config' => [
                'active' => env('SNAPPBOX_ACTIVE', false),
                'api_base_url' => env('SNAPPBOX_API_BASE_URL', 'https://stg-api.snappbox.com'),
                'api_token' => env('SNAPPBOX_API_TOKEN', ''),
                'username' => env('SNAPPBOX_USERNAME', ''),
                'password' => env('SNAPPBOX_PASSWORD', ''),
                'webhook_token' => env('SNAPPBOX_WEBHOOK_TOKEN', ''),
                // Default origin / sender information
                'origin_lat' => env('SNAPPBOX_ORIGIN_LAT', ''),
                'origin_lng' => env('SNAPPBOX_ORIGIN_LNG', ''),
                'origin_address' => env('SNAPPBOX_ORIGIN_ADDRESS', ''),
                'origin_contact_name' => env('SNAPPBOX_ORIGIN_CONTACT_NAME', ''),
                'origin_phone' => env('SNAPPBOX_ORIGIN_PHONE', ''),
                // Default delivery settings
                'delivery_category' => env('SNAPPBOX_DELIVERY_CATEGORY', 'bike'),
                'city' => env('SNAPPBOX_CITY', 'tehran'),
                'ref_id_prefix' => env('SNAPPBOX_REF_ID_PREFIX', 'SBX-'),
            ],
        ],
    ],
];
