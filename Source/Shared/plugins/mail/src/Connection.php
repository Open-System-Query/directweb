<?php
declare(strict_types=1);
namespace DirectWebMail;
final class Connection extends \PHPMailer\PHPMailer\SMTP
{
    public int $failureCode=0;
    protected function setError($message,$detail='',$smtp_code='',$smtp_code_ex='')
    {
        parent::setError($message,$detail,$smtp_code,$smtp_code_ex);
        if((int)$smtp_code>=400){$this->failureCode=(int)$smtp_code;}
    }
}
