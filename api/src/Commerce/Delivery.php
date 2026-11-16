<?php

declare(strict_types=1);

namespace App\Commerce;

/** Способы доставки и цены в копейках. */
final class Delivery
{
    public const METHODS = [
        'pickup' => ['label' => 'Самовывоз', 'price' => 0, 'needs_address' => false],
        'courier' => ['label' => 'Курьер по городу', 'price' => 39000, 'needs_address' => true],
        'post' => ['label' => 'Почта России', 'price' => 29000, 'needs_address' => true],
    ];
}
