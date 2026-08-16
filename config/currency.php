<?php

return [
    /*
     * The Inquiry operator boundary currently supports only currencies whose
     * published minor-unit exponent is exactly two. A configured exponent of
     * zero or three is intentionally rejected until a matching conversion
     * contract exists.
     */
    'minor_unit_scales' => [
        'AUD' => 2,
        'CAD' => 2,
        'CHF' => 2,
        'CNY' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'HKD' => 2,
        'SGD' => 2,
        'THB' => 2,
        'USD' => 2,
    ],
];
