<?php

declare(strict_types=1);

namespace App\Service\Pricing;

/**
 * Hesap sırası 1 (BR-23): her gecenin temel fiyatı. Takvim istisnasında özel fiyat varsa
 * o fiyat kesindir; o geceye sezon ve hafta sonu farkı uygulanmaz.
 */
final class BaseRateStrategy implements PricingStrategyInterface
{
    public function apply(PricingContext $context): void
    {
        foreach ($context->nights as $night) {
            $exception = $context->exceptions[$night->date->format('Y-m-d')] ?? null;
            if ($exception !== null && $exception->specialPrice !== null) {
                $night->base = $exception->specialPrice;
                $night->isException = true;
                $night->labels[] = 'özel gece fiyatı';
            } else {
                $night->base = $context->unit->basePrice;
            }
        }
    }
}
