<?php

declare(strict_types=1);

namespace App\Service\Pricing;

/**
 * Strategy deseni (SRS 4.7, tasarim.md 5.2): her fiyat kuralı ayrı bir sınıftır.
 * Yeni bir kural (ör. erken rezervasyon indirimi) eklemek yeni bir sınıf yazmaktır;
 * PricingService ve diğer stratejiler değişmez (Açık/Kapalı ilkesi).
 */
interface PricingStrategyInterface
{
    public function apply(PricingContext $context): void;
}
