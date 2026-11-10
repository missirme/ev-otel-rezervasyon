<?php

declare(strict_types=1);

namespace App\Service\Mail;

/** E-posta gönderimi. İlk sürümde harici e-posta servisi yoktur (SRS 2.5); gönderim simüle edilir. */
interface MailerInterface
{
    /** @throws \App\Exception\MailDeliveryException ileti gönderilemezse (çağıran "gönderildi" demez) */
    public function send(string $to, string $subject, string $body): void;
}
