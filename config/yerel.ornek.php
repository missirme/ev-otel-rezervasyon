<?php

declare(strict_types=1);

/*
 * Yerel ayarlar örneği. Kullanmak için bu dosyayı config/yerel.php adıyla kopyalayın ve doldurun;
 * config/yerel.php git'e girmez, parola depoya gönderilmez.
 *
 * Gerçek e-posta (Gmail) için:
 *   1. Google Hesabı → Güvenlik → 2 Adımlı Doğrulama'yı açın.
 *   2. Google Hesabı → "Uygulama şifreleri" bölümünden yeni bir şifre oluşturun (16 karakter).
 *      Buraya Gmail parolanızı değil, bu uygulama şifresini yazın.
 *   3. Deneyin: php bin/eposta-dene.php --kontrol   ve   php bin/eposta-dene.php adiniz@gmail.com
 */

return [
    'mail_driver' => 'smtp',
    'mail_from' => 'adiniz@gmail.com',
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'secure' => 'tls',
        'user' => 'adiniz@gmail.com',
        'password' => 'uygulama-sifresi',
    ],
];
