<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Yedekleme sözleşmesi (NFR-09). Depodaki tüm tabloların tutarlı anlık görüntüsünü alır ve geri yükler.
 * Yedek biçimi depodan bağımsızdır (tablo => satırlar); Aşama 3'te MySQL uygulaması da aynı biçimi kullanacağı için bir depodan alınan yedek
 * diğerine de yüklenebilir.
 */
interface DataSnapshotInterface
{
    /**
     * Tüm tabloların tek işlem içinde (tutarlı) okunmuş satırları.
     *
     * @return array<string, list<array<string, mixed>>> tablo => satırlar
     */
    public function exportTables(): array;

    /**
     * Tüm tabloların içeriğini verilen satırlarla değiştirir. Tek işlemdir: herhangi bir satır
     * yazılamazsa hiçbir değişiklik kalmaz.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     */
    public function importTables(array $tables): void;
}
