<?php
declare(strict_types=1);
namespace DirectWeb\Identity;
final class Totp
{
    public static function secret(): string { $s=''; for($i=0;$i<32;$i++) { $s.='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0,31)]; } return $s; }
    public static function code(string $secret, int $step): string
    {
        $bits=''; foreach(str_split($secret) as $c) { $n=strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c); if($n===false) { throw new \InvalidArgumentException('TOTP'); } $bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT); }
        $key=''; for($i=0;$i+8<=strlen($bits);$i+=8) { $key.=chr(bindec(substr($bits,$i,8))); }
        $hash=hash_hmac('sha1',pack('N2',0,$step),$key,true); $offset=ord($hash[19])&15; $n=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
        return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
    }
    public static function verify(string $secret,string $code,int $last=0): ?int
    {
        if(!preg_match('/^[0-9]{6}$/D',$code)) { return null; }
        $now=(int)floor(time()/30); foreach([$now,$now-1,$now+1] as $step) { if($step>$last && hash_equals(self::code($secret,$step),$code)) { return $step; } } return null;
    }
}
