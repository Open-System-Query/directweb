<?php
declare(strict_types=1);
namespace DirectWeb\Mail;
interface StatusTransport extends Transport
{
    public function status(): array;
}
