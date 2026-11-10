<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\CalcMethod;
use App\Model\PriceRule;
use App\Model\PriceRuleType;
use App\Model\PricingRule;
use App\Model\PricingRuleType;
use App\Model\Property;
use App\Model\ValueType;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\PriceRuleRepositoryInterface;
use App\Repository\PricingRuleRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Support\Text;
use DateTimeImmutable;

/**
 * İlan sahibinin fiyat kurallarını yönetmesi (FR-43). Hesap PricingService'tedir;
 * bu sınıf yalnızca kuralları doğrulayıp kaydeder (BR-26 dahil).
 */
final class PriceRuleService
{
    public function __construct(
        private readonly PropertyRepositoryInterface $properties,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly PriceRuleRepositoryInterface $priceRules,
        private readonly PricingRuleRepositoryInterface $pricingRules,
        private readonly TransactionManagerInterface $tx,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{price: list<PriceRule>, pricing: list<PricingRule>} */
    public function rulesFor(int $ownerId, int $propertyId): array
    {
        $this->ownedProperty($ownerId, $propertyId);

        return [
            'price' => $this->priceRules->findByProperty($propertyId),
            'pricing' => $this->pricingRules->findByProperty($propertyId),
        ];
    }

    /**
     * Sezon farkı. BR-26: aynı geceyi kapsayan ve önceliği eşit iki sezon kuralı tanımlanamaz.
     */
    public function addSeasonRule(
        int $ownerId,
        int $propertyId,
        ?int $unitId,
        string $name,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $percentBp,
        int $priority,
    ): PriceRule {
        return $this->tx->transactional(function () use ($ownerId, $propertyId, $unitId, $name, $start, $end, $percentBp, $priority): PriceRule {
            $this->ownedProperty($ownerId, $propertyId);
            $this->assertUnitOfProperty($unitId, $propertyId);
            $errors = [];
            if (trim($name) === '' || Text::length($name) > 80) {
                $errors['ad'] = 'Kural adı zorunludur (en çok 80 karakter).';
            }
            if ($end < $start) {
                $errors['bitis'] = 'Sezon bitişi başlangıçtan önce olamaz.';
            }
            if ($percentBp < -9000 || $percentBp > 30000 || $percentBp === 0) {
                $errors['deger'] = 'Sezon farkı %-90 ile %300 arasında ve sıfırdan farklı olmalıdır.';
            }
            if ($priority < 1 || $priority > 100) {
                $errors['oncelik'] = 'Öncelik 1 ile 100 arasında olmalıdır.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            foreach ($this->priceRules->findByProperty($propertyId) as $other) {
                $sameScope = $other->unitId === null || $unitId === null || $other->unitId === $unitId;
                $overlaps = $other->startDate !== null && $other->endDate !== null && $start <= $other->endDate && $other->startDate <= $end;
                if ($other->type === PriceRuleType::Sezon && $sameScope && $overlaps && $other->priority === $priority) {
                    throw ValidationException::single('oncelik', sprintf(
                        'Aynı tarihleri kapsayan "%s" kuralıyla öncelik eşit (%d). Farklı bir öncelik seçin.',
                        $other->name,
                        $priority
                    ));
                }
            }
            $rule = $this->priceRules->save(new PriceRule(
                null,
                $propertyId,
                $unitId,
                PriceRuleType::Sezon,
                trim($name),
                $start,
                $end,
                null,
                $percentBp,
                ValueType::Yuzde,
                $priority,
            ));
            $this->audit->log($ownerId, 'property', $propertyId, 'fiyat_kurali_eklendi', null, 'sezon: ' . $rule->name, null);

            return $rule;
        });
    }

    /** Hafta sonu farkı: ilan (veya birim) başına tek kural; varsa güncellenir. */
    public function setWeekendRule(int $ownerId, int $propertyId, ?int $unitId, int $percentBp): PriceRule
    {
        return $this->tx->transactional(function () use ($ownerId, $propertyId, $unitId, $percentBp): PriceRule {
            $this->ownedProperty($ownerId, $propertyId);
            $this->assertUnitOfProperty($unitId, $propertyId);
            if ($percentBp <= 0 || $percentBp > 30000) {
                throw ValidationException::single('deger', 'Hafta sonu farkı %0 ile %300 arasında olmalıdır.');
            }
            foreach ($this->priceRules->findByProperty($propertyId) as $other) {
                if ($other->type === PriceRuleType::HaftaSonu && $other->unitId === $unitId) {
                    $this->priceRules->delete((int) $other->id);
                }
            }
            $rule = $this->priceRules->save(new PriceRule(null, $propertyId, $unitId, PriceRuleType::HaftaSonu, 'Hafta sonu', null, null, null, $percentBp, ValueType::Yuzde, 1));
            $this->audit->log($ownerId, 'property', $propertyId, 'fiyat_kurali_eklendi', null, 'hafta sonu', null);

            return $rule;
        });
    }

    /** Uzun konaklama indirimi: asgari gece eşiği ve yüzde ya da sabit tutar. */
    public function addLongStayRule(int $ownerId, int $propertyId, ?int $unitId, int $minNights, int $value, ValueType $valueType): PriceRule
    {
        return $this->tx->transactional(function () use ($ownerId, $propertyId, $unitId, $minNights, $value, $valueType): PriceRule {
            $this->ownedProperty($ownerId, $propertyId);
            $this->assertUnitOfProperty($unitId, $propertyId);
            $errors = [];
            if ($minNights < 2 || $minNights > 30) {
                $errors['en_az_gece'] = 'Asgari gece sayısı 2 ile 30 arasında olmalıdır.';
            }
            if ($valueType === ValueType::Yuzde && ($value <= 0 || $value > 9000)) {
                $errors['deger'] = 'İndirim yüzdesi %0 ile %90 arasında olmalıdır.';
            }
            if ($valueType === ValueType::Sabit && $value <= 0) {
                $errors['deger'] = 'İndirim tutarı sıfırdan büyük olmalıdır.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $rule = $this->priceRules->save(new PriceRule(
                null,
                $propertyId,
                $unitId,
                PriceRuleType::UzunKonaklama,
                'Uzun konaklama',
                null,
                null,
                $minNights,
                $value,
                $valueType,
                1,
            ));
            $this->audit->log($ownerId, 'property', $propertyId, 'fiyat_kurali_eklendi', null, 'uzun konaklama: ' . $minNights . '+ gece', null);

            return $rule;
        });
    }

    public function deletePriceRule(int $ownerId, int $ruleId): void
    {
        $this->tx->transactional(function () use ($ownerId, $ruleId): void {
            $rule = $this->priceRules->findById($ruleId) ?? throw new NotFoundException('Kural bulunamadı.');
            $this->ownedProperty($ownerId, $rule->propertyId);
            $this->priceRules->delete($ruleId);
            $this->audit->log($ownerId, 'property', $rule->propertyId, 'fiyat_kurali_silindi', $rule->type->value . ': ' . $rule->name, null, null);
        });
    }

    /** Vergi veya ek ücret kuralı (FR-14). */
    public function addPricingRule(int $ownerId, int $propertyId, string $name, PricingRuleType $type, CalcMethod $method, int $value): PricingRule
    {
        return $this->tx->transactional(function () use ($ownerId, $propertyId, $name, $type, $method, $value): PricingRule {
            $this->ownedProperty($ownerId, $propertyId);
            $errors = [];
            if (trim($name) === '' || Text::length($name) > 80) {
                $errors['ad'] = 'Kalem adı zorunludur (en çok 80 karakter).';
            }
            if ($method === CalcMethod::Yuzde && ($value <= 0 || $value > 10000)) {
                $errors['deger'] = 'Yüzde değeri %0 ile %100 arasında olmalıdır.';
            }
            if ($method !== CalcMethod::Yuzde && $value <= 0) {
                $errors['deger'] = 'Tutar sıfırdan büyük olmalıdır.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $rule = $this->pricingRules->save(new PricingRule(null, $propertyId, trim($name), $type, $method, $value));
            $this->audit->log($ownerId, 'property', $propertyId, 'vergi_ucret_eklendi', null, $rule->name, null);

            return $rule;
        });
    }

    public function deletePricingRule(int $ownerId, int $ruleId): void
    {
        $this->tx->transactional(function () use ($ownerId, $ruleId): void {
            $rule = $this->pricingRules->findById($ruleId) ?? throw new NotFoundException('Kalem bulunamadı.');
            $this->ownedProperty($ownerId, $rule->propertyId);
            $this->pricingRules->delete($ruleId);
            $this->audit->log($ownerId, 'property', $rule->propertyId, 'vergi_ucret_silindi', $rule->name, null, null);
        });
    }

    private function ownedProperty(int $ownerId, int $propertyId): Property
    {
        $property = $this->properties->findById($propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
        if ($property->ownerId !== $ownerId) {
            throw new ForbiddenException('Yalnızca kendi ilanınızın fiyat kurallarını yönetebilirsiniz.');
        }

        return $property;
    }

    private function assertUnitOfProperty(?int $unitId, int $propertyId): void
    {
        if ($unitId === null) {
            return;
        }
        $unit = $this->units->findById($unitId);
        if ($unit === null || $unit->propertyId !== $propertyId) {
            throw ValidationException::single('birim', 'Seçilen oda bu ilana ait değil.');
        }
    }
}
