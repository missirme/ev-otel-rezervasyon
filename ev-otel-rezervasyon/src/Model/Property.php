<?php
namespace App\Model;

final class Property
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type // 'otel' veya 'ev'
    ) {}
}