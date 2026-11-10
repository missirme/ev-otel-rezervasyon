<?php

declare(strict_types=1);

/*
 * Testler için sahte SMTP sunucusu (tests/Unit/Service/Mail/SmtpMailerTest).
 * Kullanım: php tests/bin/sahte-smtp.php <kayit.json> [tamam|red]
 * 127.0.0.1 üzerinde boş bir porta bağlanır, portu standart çıktıya yazar, tek bağlantıyı karşılar ve
 * gelen komutları (DATA içeriği dahil) kayıt dosyasına JSON olarak yazar. TLS desteklemez (STARTTLS bildirmez).
 * "red" kipinde kimlik doğrulamayı 535 ile reddeder.
 */

$kayit = $argv[1] ?? '';
$kip = $argv[2] ?? 'tamam';
$sunucu = stream_socket_server('tcp://127.0.0.1:0', $hataKodu, $hata);
if ($kayit === '' || $sunucu === false) {
    fwrite(STDERR, 'Sahte SMTP sunucusu başlatılamadı: ' . $hata . "\n");
    exit(1);
}
$ad = (string) stream_socket_get_name($sunucu, false);
echo substr($ad, (int) strrpos($ad, ':') + 1), "\n";
fflush(STDOUT);

$baglanti = @stream_socket_accept($sunucu, 15);
if ($baglanti === false) {
    exit(2);
}
stream_set_timeout($baglanti, 15);
$yaz = static function (string $satir) use ($baglanti): void {
    fwrite($baglanti, $satir . "\r\n");
};

$gelenler = [];
$veri = null;
$yaz('220 sahte.smtp ESMTP hazir');
while (($satir = fgets($baglanti)) !== false) {
    if ($veri !== null) {
        if ($satir === ".\r\n") {
            $gelenler[] = ['DATA', $veri];
            $veri = null;
            $yaz('250 2.0.0 ileti kabul edildi');
        } else {
            $veri .= $satir;
        }
        continue;
    }
    $komut = rtrim($satir, "\r\n");
    $gelenler[] = $komut;
    $buyuk = strtoupper($komut);
    if (str_starts_with($buyuk, 'EHLO ')) {
        $yaz('250-sahte.smtp merhaba');
        $yaz('250-AUTH PLAIN LOGIN');
        $yaz('250 8BITMIME');
    } elseif (str_starts_with($buyuk, 'AUTH PLAIN ')) {
        $yaz($kip === 'red' ? '535 5.7.8 kullanici adi veya parola kabul edilmedi' : '235 2.7.0 kimlik dogrulandi');
    } elseif (str_starts_with($buyuk, 'MAIL FROM:') || str_starts_with($buyuk, 'RCPT TO:')) {
        $yaz('250 2.1.0 tamam');
    } elseif ($buyuk === 'DATA') {
        $veri = '';
        $yaz('354 veriyi gonderin, "." ile bitirin');
    } elseif ($buyuk === 'QUIT') {
        $yaz('221 2.0.0 hoscakal');
        break;
    } else {
        $yaz('502 5.5.2 bilinmeyen komut');
    }
}
fclose($baglanti);
file_put_contents($kayit, json_encode($gelenler, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
