<?php
declare(strict_types=1);
namespace DirectWeb\Mail;
interface Transport
{
    public function ready(): bool;
    public function send(string $recipient,string $subject,string $body): void;
}
