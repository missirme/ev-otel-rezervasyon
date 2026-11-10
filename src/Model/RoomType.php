<?php

declare(strict_types=1);

namespace App\Model;

/** Oda tipi (FR-42). Arama ve filtrede kullanılır; çakışma yine birim bazındadır. */
enum RoomType: string
{
    use EnumOptions;

    case TekKisilik = 'tek_kisilik';
    case CiftKisilik = 'cift_kisilik';
    case Aile = 'aile';
    case Suit = 'suit';
    case MustakilEv = 'mustakil_ev';
    case Daire = 'daire';

    public function label(): string
    {
        return match ($this) {
            self::TekKisilik => 'Tek kişilik',
            self::CiftKisilik => 'Çift kişilik',
            self::Aile => 'Aile odası',
            self::Suit => 'Süit',
            self::MustakilEv => 'Müstakil ev',
            self::Daire => 'Daire',
        };
    }
}
