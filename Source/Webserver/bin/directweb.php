<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$command=$argv[1]??'help';
if($command==='restore'){
    require $root.'/app/Core/Problem.php';require $root.'/app/Core/Files.php';require $root.'/app/Backups/Backups.php';
    try{if(!isset($argv[2],$argv[3])){throw new RuntimeException('restore <backup.zip> <empty-storage-directory>');}$r=DirectWeb\Backups\Backups::inspect($argv[2],$argv[3]);echo json_encode($r)."\n";exit(0);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
}
$app=require $root.'/app/bootstrap.php';
try{
    if($command==='install'){
        if(!isset($argv[2],$argv[3],$argv[4])){throw new RuntimeException('install <admin-host> <username> <name>');}$host=DirectWeb\Core\Files::host($argv[2]);$password=rtrim(stream_get_contents(STDIN),"\r\n");$u=$app->auth->install($argv[4],$argv[3],$password);DirectWeb\Core\Files::write($root.'/config/local.php',"<?php\ndeclare(strict_types=1);\nreturn ".var_export(['admin_host'=>$host,'secure'=>true],true).";\n");echo "DirectWeb installed. Sign in at /admin.\n";
    }elseif($command==='worker'){
        $app->plugins->boot();$owner=$app->db->one("SELECT * FROM users WHERE role='owner' AND enabled=1 LIMIT 1");if(!$owner){throw new RuntimeException('Install DirectWeb.');}
        $app->extensions->dispatch();$app->jobs->work();if($app->setting('worker_restart')==='1'){$app->set('worker_restart','0');echo "Restored.\n";exit(0);}$app->backups->prune();
        foreach($app->plugins->tasks as $name=>$task){try{$task();}catch(Throwable $error){$app->audit($owner['id'],'task.failed',$name,['message'=>$error->getMessage()]);}}
        $app->sites->recover();
        $app->db->execute('DELETE FROM rate_limits WHERE until_at<?',[time()]);$app->db->execute('DELETE FROM email_challenges WHERE expires_at<?',[time()]);$app->db->execute('DELETE FROM sessions WHERE seen_at<?',[time()-86400]);$app->set('worker_last',gmdate('c'));echo "Done.\n";
    }elseif($command==='reset-account'){
        $username=$argv[2]??'';$u=$app->db->one('SELECT * FROM users WHERE username=?',[$username]);$password=rtrim(stream_get_contents(STDIN),"\r\n");if(!$u||strlen($password)<12||strlen($password)>1024){throw new RuntimeException('Check account and password (12+ characters).');}$app->db->transaction(function()use($app,$u,$password){$app->db->execute("UPDATE users SET password=?,totp=NULL,email_mfa=0,email_verified=0,recovery='[]',totp_step=0,enabled=1,session_version=session_version+1 WHERE id=?",[password_hash($password,PASSWORD_ARGON2ID),$u['id']]);$app->db->execute('DELETE FROM tokens WHERE user_id=?',[$u['id']]);$app->db->execute('DELETE FROM sessions WHERE user_id=?',[$u['id']]);$app->db->execute('DELETE FROM email_challenges WHERE user_id=?',[$u['id']]);$app->audit('cli','account.reset',$u['id']);});echo "Account recovered.\n";
    }elseif($command==='check'){
        echo json_encode(['version'=>$app->config['version'],'php'=>PHP_VERSION,'database'=>$app->db->pdo->query('PRAGMA integrity_check')->fetchColumn(),'installed'=>$app->auth->installed(),'worker'=>$app->setting('worker_last')],JSON_PRETTY_PRINT)."\n";
    }else{echo "DirectWeb\n\ninstall <admin-host> <username> <name>  Password through stdin\nworker\ncheck\nreset-account <username>  Password through stdin\nrestore <backup.zip> <empty-storage-directory>\n";}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
