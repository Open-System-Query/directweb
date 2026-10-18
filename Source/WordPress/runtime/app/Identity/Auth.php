<?php
declare(strict_types=1);
namespace DirectWeb\Identity;
use DirectWeb\Core\{App,Files,Problem};
final class Auth
{
    public function __construct(private App $app) {}
    public function installed(): bool { return $this->app->db->one('SELECT id FROM users LIMIT 1')!==null; }
    public function install(string $name,string $username,string $password): array
    {
        return $this->app->lock(function()use($name,$username,$password){
            if($this->installed()) { throw new Problem('Already installed.',409); }
            return $this->create($name,$username,$password,'owner');
        });
    }
    public function create(string $name,string $username,string $password,string $role): array
    {
        if(trim($name)==='' || strlen($name)>100 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._@+-]{2,253}$/D',$username) || strlen($password)<12 || strlen($password)>1024 || !in_array($role,['owner','admin','editor','reader'],true)) { throw new Problem('Check name, username and password (12+ characters).'); }
        if($this->app->db->one('SELECT id FROM users WHERE username=?',[$username])) { throw new Problem('Username already exists.'); }
        $id=Files::id(); $this->app->db->execute('INSERT INTO users(id,name,username,password,role,created_at) VALUES(?,?,?,?,?,?)',[$id,trim($name),strtolower($username),password_hash($password,PASSWORD_ARGON2ID),$role,gmdate('c')]);
        return $this->user($id);
    }
    public function user(string $id): array { $u=$this->app->db->one('SELECT * FROM users WHERE id=?',[$id]); if(!$u || !$u['enabled']) { throw new Problem('Access denied.',403); } \DirectWebWordPress\Bridge::validate($this->app,$u);return $u; }
    public function start(): void
    {
        if(session_status()===PHP_SESSION_ACTIVE) { return; }
        $base=$this->app->config['base_path']??'';$suffix=$base===''?'':'-'.substr(hash('sha256',$base),0,10);session_name(($this->app->config['secure']?($base===''?'__Host-directweb':'__Secure-directweb'):'directweb').$suffix);
        session_set_cookie_params(['lifetime'=>0,'path'=>($this->app->config['base_path']??'').'/','secure'=>$this->app->config['secure'],'httponly'=>true,'samesite'=>'Strict']);
        ini_set('session.use_strict_mode','1'); session_start();
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
    }
    public function current(): ?array
    {
        $this->start();return \DirectWebWordPress\Bridge::current($this->app);
    }
    public function limit(string $key,int $max=8,int $seconds=900): void
    {
        $this->app->db->transaction(function()use($key,$max,$seconds){
            $r=$this->app->db->one('SELECT * FROM rate_limits WHERE key=?',[$key]);
            if($r && $r['until_at']>time() && $r['count']>=$max) { throw new Problem('Try again later.',429); }
            $count=$r && $r['until_at']>time()?$r['count']+1:1; $until=$r && $r['until_at']>time()?$r['until_at']:time()+$seconds;
            $this->app->db->execute('INSERT INTO rate_limits VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET count=excluded.count,until_at=excluded.until_at',[$key,$count,$until]);
        });
    }
    public function hasFactor(array $u): bool {return !empty($u['totp'])||!empty($u['email_mfa']);}
    public function login(string $username,string $password,string $code,string $ip): array
    {
        $this->limit('login-ip:'.hash('sha256',$ip),30);$this->limit('login-user:'.hash('sha256',strtolower($username)));
        $u=$this->app->db->one('SELECT * FROM users WHERE username=?',[$username]);$hash=$u['password']??'$argon2id$v=19$m=65536,t=4,p=1$b29vb29vb29vb29vb29vbw$IOFbHWLpTi/xt4opIxTVPmAnSu09jEYyRxgxKcRLGv4';
        if(!password_verify($password,$hash)||!$u||!$u['enabled']){throw new Problem('Sign in failed.',403);}
        $this->start();
        if($this->hasFactor($u)&&$code===''){session_regenerate_id(true);$after=$_SESSION['after_login']??null;$_SESSION=['pending_login'=>['user'=>$u['id'],'version'=>$u['session_version'],'expires'=>time()+300],'csrf'=>bin2hex(random_bytes(32))];if($after!==null){$_SESSION['after_login']=$after;}return $u;}
        if($this->hasFactor($u)){$this->verifyFactor($u,$code);}$this->establish($u);return $u;
    }
    private function establish(array $u): void
    {
        $this->start();$after=$_SESSION['after_login']??null;session_regenerate_id(true);$_SESSION=['user'=>$u['id'],'version'=>$u['session_version'],'expires'=>time()+28800,'csrf'=>bin2hex(random_bytes(32))];if($after!==null){$_SESSION['after_login']=$after;}
        $this->app->db->execute('INSERT INTO sessions VALUES(?,?,?,?)',[hash('sha256',session_id()),$u['id'],gmdate('c'),time()]);$this->app->db->execute('DELETE FROM rate_limits WHERE key=?',['login-user:'.hash('sha256',strtolower($u['username']))]);$this->app->audit($u['id'],'login');
    }
    public function pending(): ?array
    {
        $p=$_SESSION['pending_login']??null;if(!$p||$p['expires']<time()){unset($_SESSION['pending_login']);return null;}
        try{$u=$this->user($p['user']);}catch(Problem){unset($_SESSION['pending_login']);return null;}
        if($p['version']!==$u['session_version']){unset($_SESSION['pending_login']);return null;}return $u;
    }
    public function finish(string $method,string $code): void
    {
        $u=$this->pending()??throw new Problem('Session expired.',403);$this->verifyFactor($u,$code,$method,'login');$this->establish($this->user($u['id']));
    }
    public function verifyFactor(array $u,string $code,string $method='auto',string $purpose='proof'): void
    {
        $this->limit('factor:'.$u['id'],12,900);$fresh=$this->user($u['id']);
        if(in_array($method,['auto','totp'],true)&&$fresh['totp']){
            $ok=$this->app->db->transaction(function()use($u,$code){$fresh=$this->user($u['id']);$step=Totp::verify($this->app->unseal($fresh['totp']),$code,(int)$fresh['totp_step']);if($step===null){return false;}$this->app->db->execute('UPDATE users SET totp_step=? WHERE id=?',[$step,$u['id']]);return true;});if($ok){return;}
        }
        if(in_array($method,['auto','email'],true)&&$fresh['email_mfa']&&$fresh['email_verified']&&$this->app->mail->ready()){
            if($this->consumeEmail($fresh,$purpose,$code)!==null){return;}
        }
        if(in_array($method,['auto','recovery'],true)){
            $ok=$this->app->db->transaction(function()use($u,$code){$fresh=$this->user($u['id']);$codes=json_decode($fresh['recovery'],true);foreach($codes as $i=>$hash){if(password_verify($code,$hash)){unset($codes[$i]);$this->app->db->execute('UPDATE users SET recovery=? WHERE id=?',[Files::json(array_values($codes)),$u['id']]);return true;}}return false;});if($ok){return;}
        }
        throw new Problem('Invalid code.',403);
    }
    public function password(array $u,string $password): void
    {
        \DirectWebWordPress\Bridge::password($this->app,$u,$password);
    }
    public function authorizeChange(array $u,string $password,string $proof): void
    {
        $this->password($u,$password);if($this->hasFactor($this->user($u['id']))){$this->verifyFactor($u,$proof);}
    }
    private function recovery(array $u): array
    {
        if($this->hasFactor($u)){return [];}$codes=[];for($i=0;$i<8;$i++){$codes[]=bin2hex(random_bytes(8));}$this->app->db->execute('UPDATE users SET recovery=? WHERE id=?',[Files::json(array_map(fn($v)=>password_hash($v,PASSWORD_ARGON2ID),$codes)),$u['id']]);return $codes;
    }
    private function changed(string $id): void
    {
        $this->app->db->execute('UPDATE users SET session_version=session_version+1 WHERE id=?',[$id]);$this->app->db->execute('DELETE FROM tokens WHERE user_id=?',[$id]);$this->app->db->execute('DELETE FROM email_challenges WHERE user_id=?',[$id]);$this->app->db->execute('DELETE FROM sessions WHERE user_id=? AND id<>?',[$id,hash('sha256',session_id())]);if(($_SESSION['user']??'')===$id){$_SESSION['version']=$this->user($id)['session_version'];}
    }
    public function enroll(array $u,string $secret,string $code,string $password='',string $proof=''): array
    {
        $u=$this->user($u['id']);if($u['totp']){throw new Problem('2FA is already enabled.');}$this->authorizeChange($u,$password,$proof);
        $step=Totp::verify($secret,$code);if($step===null){throw new Problem('Invalid code.');}
        return $this->app->db->transaction(function()use($u,$secret,$step){$fresh=$this->user($u['id']);if($fresh['session_version']!==$u['session_version']||$fresh['totp']){throw new Problem('Session expired.',403);}$codes=$this->recovery($fresh);$this->app->db->execute('UPDATE users SET totp=?,totp_step=? WHERE id=?',[$this->app->seal($secret),$step,$u['id']]);$this->changed($u['id']);$this->app->audit($u['id'],'2fa.enabled','totp');return $codes;});
    }
    private function challengeHash(string $id,string $code): string {return hash_hmac('sha256',$id.':'.$code,file_get_contents($this->app->storage('database/key')));}
    public function sendEmail(array $u,string $purpose,string $destination=''): void
    {
        $u=$this->user($u['id']);if(!$this->app->mail->ready()){throw new Problem('Email is not configured.');}
        if(!in_array($purpose,['enroll','login','proof'],true)){throw new Problem('Invalid request.');}
        if($purpose!=='enroll'){if(!$u['email_mfa']||!$u['email_verified']){throw new Problem('Email is not verified.');}$destination=$u['email'];}
        if(!filter_var($destination,FILTER_VALIDATE_EMAIL)||strlen($destination)>254){throw new Problem('Invalid email address.');}
        $this->start();$this->limit('mail:'.$u['id'],5,900);$this->limit('mail-cooldown:'.$u['id'],1,60);$session=hash('sha256',session_id());$id=Files::id();$code=(string)random_int(10000000,99999999);
        $this->app->db->transaction(function()use($id,$u,$purpose,$destination,$session,$code){$this->app->db->execute('DELETE FROM email_challenges WHERE user_id=? AND purpose=? AND session_hash=?',[$u['id'],$purpose,$session]);$this->app->db->execute('INSERT INTO email_challenges VALUES(?,?,?,?,?,?,?,?,0,?)',[$id,$u['id'],$purpose,strtolower($destination),$this->challengeHash($id,$code),$session,$u['session_version'],time()+300,time()]);});
        try{$this->app->mail->sendCode($destination,$code);}catch(\Throwable $e){$this->app->db->execute('DELETE FROM email_challenges WHERE id=?',[$id]);throw $e;}
    }
    private function consumeEmail(array $u,string $purpose,string $code): ?string
    {
        $code=trim($code);
        return $this->app->db->transaction(function()use($u,$purpose,$code){$fresh=$this->user($u['id']);$r=$this->app->db->one('SELECT * FROM email_challenges WHERE user_id=? AND purpose=? AND session_hash=? ORDER BY created_at DESC LIMIT 1',[$u['id'],$purpose,hash('sha256',session_id())]);if(!$r||$r['expires_at']<time()||$r['version']!==$fresh['session_version']||$r['attempts']>=5){return null;}$this->app->db->execute('UPDATE email_challenges SET attempts=attempts+1 WHERE id=?',[$r['id']]);if(!hash_equals($r['hash'],$this->challengeHash($r['id'],$code))){return null;}$this->app->db->execute('DELETE FROM email_challenges WHERE id=?',[$r['id']]);return $r['destination'];});
    }
    public function beginEmail(array $u,string $email,string $password,string $proof): void
    {
        $this->authorizeChange($u,$password,$proof);$email=strtolower(trim($email));if($this->app->db->one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$u['id']])){throw new Problem('Email already exists.');}$this->sendEmail($u,'enroll',$email);
    }
    public function enrollEmail(array $u,string $code): array
    {
        if(!$this->app->mail->ready()){throw new Problem('Email is not configured.');}$this->limit('enroll-email:'.$u['id']);$u=$this->user($u['id']);$email=$this->consumeEmail($u,'enroll',$code);if($email===null){throw new Problem('Invalid code.',403);}
        return $this->app->db->transaction(function()use($u,$email){if(!$this->app->mail->ready()){throw new Problem('Email is not configured.');}$fresh=$this->user($u['id']);if($fresh['session_version']!==$u['session_version']){throw new Problem('Session expired.',403);}$other=$this->app->db->one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$u['id']]);if($other){throw new Problem('Email already exists.');}$codes=$this->recovery($fresh);$this->app->db->execute('UPDATE users SET email=?,email_verified=1,email_mfa=1 WHERE id=?',[$email,$u['id']]);$this->changed($u['id']);$this->app->audit($u['id'],'2fa.enabled','email');return $codes;});
    }
    public function removeFactor(array $u,string $method,string $password,string $proof): void
    {
        $this->authorizeChange($u,$password,$proof);$u=$this->user($u['id']);
        if(!in_array($method,['totp','email'],true)||($method==='totp'&&!$u['totp'])||($method==='email'&&!$u['email_mfa'])){throw new Problem('Invalid request.');}
        $this->app->db->transaction(function()use($u,$method){
            if($this->user($u['id'])['session_version']!==$u['session_version']){throw new Problem('Session expired.',403);}
            if($method==='totp'&&$u['email_mfa']&&!$this->app->mail->ready()){throw new Problem('Disable email 2FA before removing the authenticator.');}
            $this->app->db->execute($method==='totp'?'UPDATE users SET totp=NULL,totp_step=0 WHERE id=?':'UPDATE users SET email_mfa=0 WHERE id=?',[$u['id']]);
            if(!$this->hasFactor($this->user($u['id']))){$this->app->db->execute("UPDATE users SET recovery='[]' WHERE id=?",[$u['id']]);}
            $this->changed($u['id']);$this->app->audit($u['id'],'2fa.disabled',$method);
        });
    }
    public function logout(): void
    {
        $this->start(); $this->app->db->execute('DELETE FROM sessions WHERE id=?',[hash('sha256',session_id())]); $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function csrf(string $token): void { $this->start(); if(!hash_equals($_SESSION['csrf'],$token)) { throw new Problem('Session expired.',403); } }
    public function requireOwner(array $u): void { if($u['role']!=='owner') { throw new Problem('Access denied.',403); } }
    public function access(array $u,string $site,string $ability='read'): void
    {
        if($u['role']!=='owner' && !$this->app->db->one('SELECT user_id FROM user_sites WHERE user_id=? AND site_id=?',[$u['id'],$site])) { throw new Problem('Access denied.',403); }
        if($ability==='write' && !in_array($u['role'],['owner','admin','editor'],true)) { throw new Problem('Access denied.',403); }
        if($ability==='manage' && !in_array($u['role'],['owner','admin'],true)) { throw new Problem('Access denied.',403); }
        if(isset($u['_token'])) {
            $t=$u['_token']; $allowed=json_decode($t['sites'],true); $scopes=json_decode($t['scopes'],true);
            if(!in_array($site,$allowed,true) || !in_array($ability==='read'?'read':'write',$scopes,true)) { throw new Problem('Access denied.',403); }
        }
    }
    public function tokenExpiry(int $days): int
    {
        if($days<0||$days>intdiv(PHP_INT_MAX-time(),86400)){throw new Problem('Invalid duration.');}
        return $days===0?0:time()+$days*86400;
    }
    public function issue(array $u,string $name,array $sites,array $scopes,int $days): string
    {
        foreach($sites as $site){$this->access($u,$site,in_array('write',$scopes,true)?'write':'read');}
        if(!$sites||!$scopes||array_diff($scopes,array_merge(['read','write'],array_column($this->app->plugins->tools,'scope')))||trim($name)===''){throw new Problem('Invalid access.');}
        $expires=$this->tokenExpiry($days);$token='dw_'.bin2hex(random_bytes(32));$id=Files::id();
        $this->app->db->transaction(function()use($u,$name,$sites,$scopes,$expires,$token,$id){
            $this->app->db->execute('INSERT INTO tokens(id,user_id,name,hash,scopes,sites,expires_at) VALUES(?,?,?,?,?,?,?)',[$id,$u['id'],$name,hash('sha256',$token),Files::json($scopes),Files::json($sites),$expires]);
            $this->app->db->execute('INSERT INTO token_secrets VALUES(?,?)',[$id,$this->app->seal($token)]);
            $this->app->audit($u['id'],'token.created',$id);
        });return $token;
    }
    public function ownedToken(array $u,string $id): array
    {
        return $this->app->db->one('SELECT t.*,s.secret FROM tokens t LEFT JOIN token_secrets s ON s.token_id=t.id WHERE t.id=? AND t.user_id=?',[$id,$u['id']])??throw new Problem('Token missing.',404);
    }
    public function tokenValue(array $u,string $id): string
    {
        $t=$this->ownedToken($u,$id);if(!$t['secret']){throw new Problem('Replace this token to enable Show and Copy.',409);}
        return $this->app->unseal($t['secret']);
    }
    private function manualToken(array $u,string $id): array
    {
        $t=$this->ownedToken($u,$id);
        if($this->app->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='oauth_refresh'")&&$this->app->db->one('SELECT hash FROM oauth_refresh WHERE token_id=?',[$id])){throw new Problem('Managed by OAuth.');}
        return $t;
    }
    public function tokenDuration(array $u,string $id,int $days): void
    {
        $this->manualToken($u,$id);$expires=$this->tokenExpiry($days);
        $this->app->db->execute('UPDATE tokens SET expires_at=? WHERE id=? AND user_id=?',[$expires,$id,$u['id']]);$this->app->audit($u['id'],'token.expiry.changed',$id);
    }
    public function replaceToken(array $u,string $id): void
    {
        $this->app->db->transaction(function()use($u,$id){
            $t=$this->manualToken($u,$id);foreach(json_decode($t['sites'],true) as $site){$this->access($u,$site,in_array('write',json_decode($t['scopes'],true),true)?'write':'read');}
            $value='dw_'.bin2hex(random_bytes(32));$this->app->db->execute('UPDATE tokens SET hash=?,last_used=NULL WHERE id=? AND user_id=?',[hash('sha256',$value),$id,$u['id']]);
            $this->app->db->execute('INSERT INTO token_secrets VALUES(?,?) ON CONFLICT(token_id) DO UPDATE SET secret=excluded.secret',[$id,$this->app->seal($value)]);
            $this->app->audit($u['id'],'token.replaced',$id);
        });
    }
    public function bearer(string $token): array
    {
        $t=$this->app->db->one('SELECT * FROM tokens WHERE hash=? AND (expires_at=0 OR expires_at>?)',[hash('sha256',$token),time()]); if(!$t) { throw new Problem('Access denied.',401); }
        $u=$this->user($t['user_id']); $u['_token']=$t;
        $this->app->db->execute('UPDATE tokens SET last_used=? WHERE id=?',[time(),$t['id']]); return $u;
    }
}
