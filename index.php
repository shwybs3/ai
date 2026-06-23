<?php
/**
 * ============================================================================
 *  YASSOTA  v2  —  منصة متجر + ربح + مجتمع، في ملف index.php واحد
 *  كل شيء يُدار من لوحة الإدارة. قاعدة البيانات SQLite تُنشأ ذاتياً.
 * ============================================================================
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set('UTC');

/* ===================== 1) الإعدادات ===================== */
const ADMIN_EMAIL   = 'sadoo1234999@gmail.com';
const SITE_NAME     = 'Yassota';
const COIN_NAME     = 'Yassota';
const SESSION_DAYS  = 7;
const MIN_WITHDRAW_USD = 25.0;
const COINS_PER_USD = 1000;
const CAPTCHA_REWARD = 5;
const TASK_REWARD   = 10;
const TASK_WAIT_SEC = 15;
const USER_AD_SHARE = 0.05;
const REF_TARGET    = 5;     // عدد الإحالات المطلوبة
const REF_REWARD_USD = 5.0;  // المكافأة عند بلوغ الهدف
const REF_INVITEE_COINS = 50;// مكافأة المدعو
const MSG_XP = 2; const MSG_COINS = 1; // مكافأة كل رسالة دردشة

const SHAM_CASH_WALLET = '5e87321b9ab229a23cdce035290b10cb';
const GOOGLE_CLIENT_ID = '';
const GOOGLE_CLIENT_SECRET = '';
const MONETAG_ZONE_ID = '';

const DB_FILE  = __DIR__ . '/yassota.sqlite';
const UPLOAD_DIR = __DIR__ . '/uploads';
const UPLOAD_URL = 'uploads';

// مستويات XP وإطارات البروفايل
const LEVELS_XP = [0, 100, 300, 700, 1500, 3000, 6000, 12000, 25000, 50000];
const LEVEL_NAMES = ['مبتدئ','صاعد','نشط','محترف','خبير','بطل','أسطورة','نخبة','ملك','إمبراطور'];
const LEVEL_FRAMES = ['#7a86b8','#19c37d','#00b4d8','#a29bfe','#6c5ce7','#f72585','#ff8800','#ffd166','#ff4d4d','#ffd700'];

/* ===================== 2) الجلسة ===================== */
session_set_cookie_params(['lifetime'=>SESSION_DAYS*86400,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
session_name('YASSOTA_SESS');
session_start();
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);

/* ===================== 3) قاعدة البيانات ===================== */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        init_db($pdo);
    }
    return $pdo;
}
function add_col(PDO $pdo, string $table, string $col, string $def): void {
    try { $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Throwable $e) {}
}
function init_db(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE, name TEXT, avatar TEXT, bio TEXT,
        balance INTEGER DEFAULT 0, xp INTEGER DEFAULT 0, level INTEGER DEFAULT 1,
        role TEXT DEFAULT 'user', perms TEXT DEFAULT '',
        is_banned INTEGER DEFAULT 0, ad_earned INTEGER DEFAULT 0,
        ref_code TEXT, ref_by INTEGER DEFAULT 0, ref_count INTEGER DEFAULT 0, ref_rewarded INTEGER DEFAULT 0,
        signup_ip TEXT, created_at TEXT DEFAULT (datetime('now')), last_seen TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS groups(
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, icon TEXT DEFAULT '📦',
        color TEXT DEFAULT '#6c5ce7', description TEXT, image TEXT, sort INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS products(
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, icon TEXT DEFAULT '🛍️', description TEXT,
        group_id INTEGER DEFAULT 0, price REAL DEFAULT 0, old_price REAL DEFAULT 0, image TEXT,
        tag TEXT DEFAULT 'جديد', owner_id INTEGER DEFAULT 0, status TEXT DEFAULT 'approved',
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS orders(
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, product_id INTEGER,
        status TEXT DEFAULT 'pending', note TEXT, created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS wallets(
        id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, label TEXT, address TEXT, is_active INTEGER DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS topups(
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, method TEXT, amount_usd REAL,
        txid TEXT, receipt TEXT, status TEXT DEFAULT 'pending', note TEXT, created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS withdrawals(
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, method TEXT, address TEXT,
        coins INTEGER, amount_usd REAL, status TEXT DEFAULT 'pending', note TEXT, created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS tasks(
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, url TEXT, reward INTEGER DEFAULT 10,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS task_done(user_id INTEGER, task_id INTEGER, day TEXT, PRIMARY KEY(user_id,task_id,day));
    CREATE TABLE IF NOT EXISTS txns(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, amount INTEGER, type TEXT, note TEXT, created_at TEXT DEFAULT (datetime('now')));
    CREATE TABLE IF NOT EXISTS banners(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, image TEXT, link TEXT, sort INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1);
    CREATE TABLE IF NOT EXISTS posts(
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, body TEXT, image TEXT,
        btn_label TEXT, btn_link TEXT, color TEXT DEFAULT '#e9edff', bg TEXT DEFAULT '#161f3d',
        status TEXT DEFAULT 'approved', created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS friends(follower_id INTEGER, target_id INTEGER, created_at TEXT DEFAULT (datetime('now')), PRIMARY KEY(follower_id,target_id));
    CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY AUTOINCREMENT, from_id INTEGER, to_id INTEGER, body TEXT, created_at TEXT DEFAULT (datetime('now')));
    CREATE TABLE IF NOT EXISTS chat(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, body TEXT, created_at TEXT DEFAULT (datetime('now')));
    CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY AUTOINCREMENT, text TEXT, is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT (datetime('now')));
    CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
    ");
    // migrations لأي تثبيت قديم
    foreach ([['users','xp','INTEGER DEFAULT 0'],['users','level','INTEGER DEFAULT 1'],['users','role',"TEXT DEFAULT 'user'"],
              ['users','perms','TEXT'],['users','ref_code','TEXT'],['users','ref_by','INTEGER DEFAULT 0'],
              ['users','ref_count','INTEGER DEFAULT 0'],['users','ref_rewarded','INTEGER DEFAULT 0'],['users','signup_ip','TEXT'],['users','bio','TEXT'],
              ['products','group_id','INTEGER DEFAULT 0'],['products','owner_id','INTEGER DEFAULT 0'],['products','status',"TEXT DEFAULT 'approved'"],
              ['topups','receipt','TEXT']] as $m) add_col($pdo,$m[0],$m[1],$m[2]);

    $defaults = [
        'coins_per_usd'=>(string)COINS_PER_USD,'min_withdraw'=>(string)MIN_WITHDRAW_USD,'captcha_reward'=>(string)CAPTCHA_REWARD,
        'telegram_bot'=>'@YassotaBot','telegram_info'=>'انضم لبوت تيليجرام واربح نقاطاً يومية.',
        'banner_text'=>'Yassota — تسوّق واربح نقاطاً حقيقية!','seo_desc'=>'Yassota منصة عربية للتسوق وربح المال عبر المهام والكابتشا والإحالات، سحب بالشام كاش وUSDT وبيتكوين.',
        'notice'=>'🎉 مرحباً بك في Yassota! أكمل المهام واربح كوينزات قابلة للسحب.',
        'publish_min_level'=>'2','site_logo'=>'💎',
    ];
    $st = $pdo->prepare("INSERT OR IGNORE INTO settings(k,v) VALUES(?,?)");
    foreach ($defaults as $k=>$v) $st->execute([$k,$v]);

    // بذور أولية (مرة واحدة)
    if ((int)$pdo->query("SELECT COUNT(*) FROM groups")->fetchColumn() === 0) seed($pdo);
}
function seed(PDO $pdo): void {
    $groups = [
        ['برامج','💻','#6c5ce7','أقوى البرامج والأدوات'],
        ['ألعاب','🎮','#f72585','شحن وبطاقات الألعاب'],
        ['اشتراكات','📺','#00b4d8','اشتراكات بريميوم'],
        ['مدفوعات','💳','#19c37d','تحويل وشحن محافظ'],
        ['بطاقات','🎴','#ffd166','بطاقات هدايا رقمية'],
    ];
    $gst = $pdo->prepare("INSERT INTO groups(title,icon,color,description,sort) VALUES(?,?,?,?,?)");
    $gid = [];
    foreach ($groups as $i=>$g){ $gst->execute([$g[0],$g[1],$g[2],$g[3],$i]); $gid[$g[0]]=(int)$pdo->lastInsertId(); }

    $P = [
        ['Adobe Photoshop','🎨','برامج',15000,20000],['Microsoft Office 365','📊','برامج',12000,0],
        ['IDM مفعّل','⬇️','برامج',5000,8000],['Windows 11 Pro','🪟','برامج',9000,0],
        ['PUBG شحن UC','🔫','ألعاب',6000,7500],['Free Fire جواهر','🔥','ألعاب',4000,0],
        ['Fortnite V-Bucks','🛡️','ألعاب',7000,0],['Roblox Robux','🟥','ألعاب',5000,6000],
        ['Netflix Premium','🎬','اشتراكات',8000,10000],['Spotify Premium','🎵','اشتراكات',4000,0],
        ['YouTube Premium','▶️','اشتراكات',5000,0],['Shahid VIP','📡','اشتراكات',6000,7000],
        ['تحويل PayPal','🅿️','مدفوعات',10000,0],['شحن Payoneer','🏦','مدفوعات',11000,0],
        ['Skrill','💸','مدفوعات',9000,0],['Wise تحويل','🌍','مدفوعات',10000,0],
        ['بطاقة iTunes','🍎','بطاقات',7000,0],['بطاقة Google Play','▶️','بطاقات',7000,8000],
        ['Steam Wallet','🎮','بطاقات',8000,0],['بطاقة Amazon','📦','بطاقات',9000,0],
    ];
    $pst = $pdo->prepare("INSERT INTO products(title,icon,description,group_id,price,old_price,tag) VALUES(?,?,?,?,?,?,?)");
    foreach ($P as $p) $pst->execute([$p[0],$p[1],'منتج رقمي يُسلَّم فوراً بعد موافقة الإدارة.',$gid[$p[2]],$p[3],$p[4],$p[4]>0?'خصم':'جديد']);

    $pdo->prepare("INSERT INTO banners(title,image,sort) VALUES(?,?,0)")
        ->execute(['عروض Yassota','']);
    $pdo->prepare("INSERT INTO tasks(title,url,reward) VALUES(?,?,?)")
        ->execute(['زر موقعنا واربح','https://example.com',20]);
}

function setting(string $k, ?string $def=null): ?string { $r=db()->prepare("SELECT v FROM settings WHERE k=?"); $r->execute([$k]); $v=$r->fetchColumn(); return $v===false?$def:$v; }
function set_setting(string $k, string $v): void { db()->prepare("INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=?")->execute([$k,$v,$v]); }

/* ===================== 4) أدوات ===================== */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function coins_to_usd(int $c): float { $r=(int)setting('coins_per_usd',(string)COINS_PER_USD); return $r>0?round($c/$r,4):0.0; }
function usd_to_coins(float $u): int { $r=(int)setting('coins_per_usd',(string)COINS_PER_USD); return (int)round($u*$r); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf(): bool { return isset($_POST['csrf']) && hash_equals($_SESSION['csrf']??'', $_POST['csrf']); }
function client_ip(): string { return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function json_out($d): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function redirect(string $t): void { header("Location: $t"); exit; }

function level_for(int $xp): int { $lvl=1; foreach (LEVELS_XP as $i=>$need) if ($xp>=$need) $lvl=$i+1; return min($lvl,count(LEVELS_XP)); }
function level_progress(int $xp, int $lvl): array {
    $lo = LEVELS_XP[$lvl-1] ?? 0; $hi = LEVELS_XP[$lvl] ?? ($lo+1);
    if ($lvl>=count(LEVELS_XP)) return [100,0];
    $pct = $hi>$lo ? min(100,(int)(($xp-$lo)/($hi-$lo)*100)) : 100;
    return [$pct, max(0,$hi-$xp)];
}
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $r=db()->prepare("SELECT * FROM users WHERE id=?"); $r->execute([$_SESSION['uid']]); $u=$r->fetch();
    if ($u) db()->prepare("UPDATE users SET last_seen=datetime('now') WHERE id=?")->execute([$u['id']]);
    return $u ?: null;
}
function is_owner(?array $u): bool { return $u && strtolower($u['email'])===strtolower(ADMIN_EMAIL); }
function is_admin(?array $u): bool { return $u && (is_owner($u) || $u['role']==='admin'); }
function can(?array $u, string $perm): bool {
    if (!$u) return false; if (is_owner($u)) return true;
    if ($u['role']!=='admin') return false;
    $p = array_filter(array_map('trim', explode(',', (string)$u['perms'])));
    return in_array('all',$p,true) || in_array($perm,$p,true);
}
function add_coins(int $uid, int $amount, string $type, string $note='', int $xp=0): void {
    db()->prepare("UPDATE users SET balance=balance+?, xp=xp+? WHERE id=?")->execute([$amount,$xp,$uid]);
    db()->prepare("INSERT INTO txns(user_id,amount,type,note) VALUES(?,?,?,?)")->execute([$uid,$amount,$type,$note]);
    // تحديث المستوى
    $r=db()->prepare("SELECT xp,level FROM users WHERE id=?"); $r->execute([$uid]); $u=$r->fetch();
    if ($u){ $nl=level_for((int)$u['xp']); if ($nl!=(int)$u['level']) db()->prepare("UPDATE users SET level=? WHERE id=?")->execute([$nl,$uid]); }
}
function save_upload(string $field): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error']!==0) return null;
    $f=$_FILES[$field]; if ($f['size']>5*1024*1024) return null;
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,['jpg','jpeg','png','gif','webp'])) return null;
    $name=bin2hex(random_bytes(8)).'.'.$ext;
    if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR.'/'.$name)) return UPLOAD_URL.'/'.$name;
    return null;
}
function login_user(string $email, string $name, string $avatar=''): array {
    $pdo=db(); $r=$pdo->prepare("SELECT * FROM users WHERE email=?"); $r->execute([$email]); $u=$r->fetch();
    $isOwner = strtolower($email)===strtolower(ADMIN_EMAIL);
    if (!$u) {
        $code = strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $refBy = (int)($_SESSION['ref_by'] ?? 0);
        $ip = client_ip();
        $pdo->prepare("INSERT INTO users(email,name,avatar,role,ref_code,ref_by,signup_ip) VALUES(?,?,?,?,?,?,?)")
            ->execute([$email,$name,$avatar,$isOwner?'owner':'user',$code,$refBy,$ip]);
        $uid=(int)$pdo->lastInsertId();
        // إحالة حقيقية مع حماية الجهاز/الـ IP
        if ($refBy && $refBy!==$uid) {
            $ref=$pdo->prepare("SELECT * FROM users WHERE id=?"); $ref->execute([$refBy]); $ru=$ref->fetch();
            $dup=$pdo->prepare("SELECT COUNT(*) FROM users WHERE signup_ip=? AND id<>?"); $dup->execute([$ip,$uid]);
            $sameIp = $ru && $ru['signup_ip']===$ip;
            if ($ru && !$ru['is_banned'] && !$sameIp && (int)$dup->fetchColumn()===0) {
                add_coins($uid, REF_INVITEE_COINS, 'ref_join', 'مكافأة دعوة', 20);
                $pdo->prepare("UPDATE users SET ref_count=ref_count+1 WHERE id=?")->execute([$refBy]);
                $ref->execute([$refBy]); $ru=$ref->fetch();
                // مكافأة الهدف
                if ((int)$ru['ref_count'] >= REF_TARGET && (int)$ru['ref_rewarded']===0) {
                    add_coins($refBy, usd_to_coins(REF_REWARD_USD), 'ref_reward', 'مكافأة '.REF_TARGET.' إحالات', 100);
                    $pdo->prepare("UPDATE users SET ref_rewarded=1 WHERE id=?")->execute([$refBy]);
                } else {
                    add_coins($refBy, usd_to_coins(REF_REWARD_USD/REF_TARGET), 'ref', 'إحالة جديدة', 30);
                }
            }
            unset($_SESSION['ref_by']);
        }
    } else {
        $uid=(int)$u['id'];
        $role = $isOwner ? 'owner' : $u['role'];
        $pdo->prepare("UPDATE users SET name=?,avatar=?,role=? WHERE id=?")
            ->execute([$name?:$u['name'],$avatar?:$u['avatar'],$role,$uid]);
    }
    $_SESSION['uid']=$uid; return current_user();
}

/* ===================== 5) Router مبكر / Actions ===================== */
$action=$_GET['action']??''; $page=$_GET['page']??'home'; $me=current_user();

// التقاط رمز الإحالة
if (isset($_GET['ref'])) { $c=preg_replace('/[^A-Z0-9]/','',strtoupper($_GET['ref'])); if($c){ $r=db()->prepare("SELECT id FROM users WHERE ref_code=?"); $r->execute([$c]); if($id=$r->fetchColumn()) $_SESSION['ref_by']=(int)$id; } }

/* ---- كابتشا حقيقية مرسومة (GD) ---- */
if ($action==='captcha_img') {
    $code=''; for($i=0;$i<3;$i++)$code.=random_int(0,9);
    $_SESSION['captcha']=$code;
    header('Content-Type: image/png'); header('Cache-Control: no-store');
    $w=160;$hh=60; $img=imagecreatetruecolor($w,$hh);
    $bg=imagecolorallocate($img,18,26,53); imagefilledrectangle($img,0,0,$w,$hh,$bg);
    for($i=0;$i<6;$i++){ $c=imagecolorallocate($img,random_int(40,120),random_int(40,120),random_int(80,160));
        imageline($img,random_int(0,$w),random_int(0,$hh),random_int(0,$w),random_int(0,$hh),$c); }
    for($i=0;$i<3;$i++){
        $col=imagecolorallocate($img,random_int(180,255),random_int(180,255),random_int(120,255));
        $size=random_int(20,28); $x=18+$i*45; $y=random_int(34,46);
        imagettftext_safe($img,$size,random_int(-22,22),$x,$y,$col,$code[$i]);
    }
    for($i=0;$i<150;$i++){ $c=imagecolorallocate($img,random_int(100,200),random_int(100,200),random_int(150,255)); imagesetpixel($img,random_int(0,$w),random_int(0,$hh),$c); }
    imagepng($img); imagedestroy($img); exit;
}
function imagettftext_safe($img,$size,$angle,$x,$y,$col,$char){
    // بدون خط TTF: نستخدم خطوط GD المدمجة مكبّرة مع دوران بسيط
    $tmp=imagecreatetruecolor(30,40); $t=imagecolorallocatealpha($tmp,0,0,0,127);
    imagefill($tmp,0,0,$t); imagesavealpha($tmp,true);
    $white=imagecolorallocate($tmp,255,255,255);
    imagestring($tmp,5,7,10,$char,$white);
    $tmp=imagescale($tmp,30*2,40*2); $rot=imagerotate($tmp,$angle,$t); imagesavealpha($rot,true);
    imagecopy($img,$rot,$x-20,$y-45,0,0,imagesx($rot),imagesy($rot));
    imagedestroy($tmp); imagedestroy($rot);
}

/* ---- Google OAuth / دخول تجريبي ---- */
if ($action==='google_login') {
    if (!GOOGLE_CLIENT_ID) redirect('?login=1');
    $state=bin2hex(random_bytes(8)); $_SESSION['oauth_state']=$state;
    $redirect=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?').'?action=google_callback';
    redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
        'client_id'=>GOOGLE_CLIENT_ID,'redirect_uri'=>$redirect,'response_type'=>'code',
        'scope'=>'openid email profile','state'=>$state,'prompt'=>'select_account']));
}
if ($action==='google_callback') {
    if (($_GET['state']??'')!==($_SESSION['oauth_state']??'x')) redirect('?');
    $redirect=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?').'?action=google_callback';
    $ch=curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query([
        'code'=>$_GET['code']??'','client_id'=>GOOGLE_CLIENT_ID,'client_secret'=>GOOGLE_CLIENT_SECRET,
        'redirect_uri'=>$redirect,'grant_type'=>'authorization_code'])]);
    $tok=json_decode((string)curl_exec($ch),true); curl_close($ch);
    if (!empty($tok['access_token'])) {
        $ch=curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$tok['access_token']]]);
        $info=json_decode((string)curl_exec($ch),true); curl_close($ch);
        if (!empty($info['email'])) login_user($info['email'],$info['name']??'مستخدم',$info['picture']??'');
    }
    redirect('?');
}
if ($action==='demo_login' && $_SERVER['REQUEST_METHOD']==='POST' && check_csrf()) {
    $email=trim(strtolower($_POST['email']??''));
    if (filter_var($email,FILTER_VALIDATE_EMAIL)) login_user($email, ucfirst(explode('@',$email)[0]));
    redirect('?');
}
if ($action==='logout'){ session_destroy(); redirect('?'); }
if ($action==='accept_cookies'){ setcookie('policy_ok','1',time()+31536000,'/'); json_out(['ok'=>true]); }

/* ---- كابتشا: ربح ---- */
if ($action==='captcha_verify' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول أولاً']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $in=trim($_POST['answer']??'');
    if (($_SESSION['captcha']??'')!=='' && $in===(string)$_SESSION['captcha']) {
        unset($_SESSION['captcha']);
        $rw=(int)setting('captcha_reward',(string)CAPTCHA_REWARD);
        add_coins((int)$me['id'],$rw,'captcha','كابتشا',5);
        db()->prepare("UPDATE users SET ad_earned=ad_earned+? WHERE id=?")->execute([$rw,$me['id']]);
        json_out(['ok'=>true,'msg'=>"✅ +$rw كوين!"]);
    }
    json_out(['ok'=>false,'msg'=>'❌ الرمز غير صحيح']);
}
/* ---- مهام ---- */
if ($action==='task_start' && $_SERVER['REQUEST_METHOD']==='POST'){ $_SESSION['task_start'][(int)($_POST['task_id']??0)]=time(); json_out(['ok'=>true,'wait'=>TASK_WAIT_SEC]); }
if ($action==='task_complete' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $tid=(int)($_POST['task_id']??0); $started=(int)($_SESSION['task_start'][$tid]??0);
    if (!$started || (time()-$started)<TASK_WAIT_SEC) json_out(['ok'=>false,'msg'=>'⏳ ابقَ '.TASK_WAIT_SEC.' ثانية']);
    $day=date('Y-m-d'); $c=db()->prepare("SELECT 1 FROM task_done WHERE user_id=? AND task_id=? AND day=?"); $c->execute([$me['id'],$tid,$day]);
    if ($c->fetch()) json_out(['ok'=>false,'msg'=>'✅ أنجزتها اليوم']);
    $t=db()->prepare("SELECT * FROM tasks WHERE id=? AND is_active=1"); $t->execute([$tid]); $task=$t->fetch();
    if (!$task) json_out(['ok'=>false,'msg'=>'غير متاحة']);
    db()->prepare("INSERT INTO task_done(user_id,task_id,day) VALUES(?,?,?)")->execute([$me['id'],$tid,$day]);
    add_coins((int)$me['id'],(int)$task['reward'],'task','مهمة: '.$task['title'],10);
    json_out(['ok'=>true,'msg'=>'✅ +'.$task['reward'].' كوين!']);
}
/* ---- شراء ---- */
if ($action==='buy' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $pid=(int)($_POST['product_id']??0); $p=db()->prepare("SELECT * FROM products WHERE id=? AND is_active=1 AND status='approved'"); $p->execute([$pid]); $prod=$p->fetch();
    if (!$prod) json_out(['ok'=>false,'msg'=>'غير متاح']);
    if ((int)$me['balance']<(int)$prod['price']) json_out(['ok'=>false,'msg'=>'❌ رصيدك غير كافٍ']);
    db()->prepare("INSERT INTO orders(user_id,product_id) VALUES(?,?)")->execute([$me['id'],$pid]);
    json_out(['ok'=>true,'msg'=>'✅ أُرسل الطلب للإدارة']);
}
/* ---- شحن (شام كاش بإيصال) ---- */
if ($action==='topup' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $method=$_POST['method']??'usdt'; $amount=(float)($_POST['amount']??0); $txid=trim($_POST['txid']??'');
    if ($amount<=0) json_out(['ok'=>false,'msg'=>'مبلغ غير صحيح']);
    $receipt=save_upload('receipt');
    if ($method==='sham' && !$receipt) json_out(['ok'=>false,'msg'=>'📷 صورة الإيصال مطلوبة للشام كاش']);
    db()->prepare("INSERT INTO topups(user_id,method,amount_usd,txid,receipt) VALUES(?,?,?,?,?)")->execute([$me['id'],$method,$amount,$txid,$receipt]);
    json_out(['ok'=>true,'msg'=>'✅ أُرسل طلب الشحن للإدارة']);
}
/* ---- سحب ---- */
if ($action==='withdraw' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $coins=(int)$me['balance']; $usd=coins_to_usd($coins); $minw=(float)setting('min_withdraw',(string)MIN_WITHDRAW_USD);
    if ($usd<$minw) json_out(['ok'=>false,'msg'=>"❌ الحد الأدنى \${$minw}"]);
    $method=$_POST['method']??'sham'; $addr=trim($_POST['address']??'');
    if (strlen($addr)<4) json_out(['ok'=>false,'msg'=>'عنوان غير صحيح']);
    db()->prepare("UPDATE users SET balance=0 WHERE id=?")->execute([$me['id']]);
    db()->prepare("INSERT INTO withdrawals(user_id,method,address,coins,amount_usd) VALUES(?,?,?,?,?)")->execute([$me['id'],$method,$addr,$coins,$usd]);
    db()->prepare("INSERT INTO txns(user_id,amount,type,note) VALUES(?,?,?,?)")->execute([$me['id'],-$coins,'withdraw',"سحب \${$usd}"]);
    json_out(['ok'=>true,'msg'=>'✅ أُرسل طلب السحب']);
}
/* ---- نشر منتج من المستخدم ---- */
if ($action==='user_publish' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $minLvl=(int)setting('publish_min_level','2');
    if ((int)$me['level']<$minLvl) json_out(['ok'=>false,'msg'=>"❌ يتطلب المستوى $minLvl للنشر"]);
    $title=trim($_POST['title']??''); if(!$title) json_out(['ok'=>false,'msg'=>'العنوان مطلوب']);
    $img=save_upload('image');
    db()->prepare("INSERT INTO products(title,icon,description,group_id,price,image,owner_id,status,tag) VALUES(?,?,?,?,?,?,?, 'pending','جديد')")
        ->execute([$title,trim($_POST['icon']??'🛍️'),trim($_POST['description']??''),(int)($_POST['group_id']??0),(float)($_POST['price']??0),$img,$me['id']]);
    json_out(['ok'=>true,'msg'=>'✅ أُرسل منتجك للإدارة للمراجعة']);
}
/* ---- منشور من المستخدم ---- */
if ($action==='create_post' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok'=>false,'msg'=>'جلسة غير صالحة']);
    $body=trim($_POST['body']??''); if(!$body) json_out(['ok'=>false,'msg'=>'النص مطلوب']);
    $img=save_upload('image');
    db()->prepare("INSERT INTO posts(user_id,title,body,image,btn_label,btn_link,color,bg,status) VALUES(?,?,?,?,?,?,?,?, 'approved')")
        ->execute([$me['id'],trim($_POST['title']??''),$body,$img,trim($_POST['btn_label']??''),trim($_POST['btn_link']??''),
                   trim($_POST['color']??'#e9edff'),trim($_POST['bg']??'#161f3d')]);
    json_out(['ok'=>true,'msg'=>'✅ نُشر منشورك']);
}
/* ---- متابعة + دردشة خاصة ---- */
if ($action==='follow' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    $tid=(int)($_POST['target']??0); if($tid===(int)$me['id']) json_out(['ok'=>false,'msg'=>'لا يمكنك متابعة نفسك']);
    $ex=db()->prepare("SELECT 1 FROM friends WHERE follower_id=? AND target_id=?"); $ex->execute([$me['id'],$tid]);
    if ($ex->fetch()){ db()->prepare("DELETE FROM friends WHERE follower_id=? AND target_id=?")->execute([$me['id'],$tid]); json_out(['ok'=>true,'following'=>false,'msg'=>'أُلغيت المتابعة']); }
    db()->prepare("INSERT INTO friends(follower_id,target_id) VALUES(?,?)")->execute([$me['id'],$tid]);
    json_out(['ok'=>true,'following'=>true,'msg'=>'✅ تتابع الآن']);
}
if ($action==='dm_send' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    $to=(int)($_POST['to']??0); $body=trim($_POST['body']??''); if(!$body||!$to) json_out(['ok'=>false]);
    db()->prepare("INSERT INTO messages(from_id,to_id,body) VALUES(?,?,?)")->execute([$me['id'],$to,$body]);
    add_coins((int)$me['id'],MSG_COINS,'chat','رسالة خاصة',MSG_XP);
    json_out(['ok'=>true]);
}
if ($action==='dm_fetch' && $me) {
    $to=(int)($_GET['to']??0);
    $r=db()->prepare("SELECT m.*, u.name FROM messages m JOIN users u ON u.id=m.from_id WHERE (from_id=? AND to_id=?) OR (from_id=? AND to_id=?) ORDER BY m.id DESC LIMIT 50");
    $r->execute([$me['id'],$to,$to,$me['id']]);
    json_out(['msgs'=>array_reverse($r->fetchAll()),'me'=>(int)$me['id']]);
}
/* ---- دردشة عامة ---- */
if ($action==='chat_send' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$me) json_out(['ok'=>false,'msg'=>'سجّل الدخول']);
    $body=trim($_POST['body']??''); if(!$body) json_out(['ok'=>false]);
    db()->prepare("INSERT INTO chat(user_id,body) VALUES(?,?)")->execute([$me['id'],$body]);
    add_coins((int)$me['id'],MSG_COINS,'chat','دردشة عامة',MSG_XP);
    json_out(['ok'=>true]);
}
if ($action==='chat_fetch') {
    $r=db()->query("SELECT c.*, u.name, u.level FROM chat c JOIN users u ON u.id=c.user_id ORDER BY c.id DESC LIMIT 40");
    json_out(['msgs'=>array_reverse($r->fetchAll()),'me'=>$me?(int)$me['id']:0]);
}

/* ===================== 6) معالجات الإدارة ===================== */
if (strpos($action,'admin_')===0) {
    $tabPerm = ['admin_product_save'=>'products','admin_product_del'=>'products','admin_product_approve'=>'products',
        'admin_order'=>'orders','admin_topup'=>'topups','admin_withdraw'=>'withdrawals','admin_wallet_save'=>'wallets',
        'admin_wallet_del'=>'wallets','admin_task_save'=>'tasks','admin_task_del'=>'tasks','admin_user'=>'users',
        'admin_settings'=>'settings','admin_group_save'=>'groups','admin_group_del'=>'groups','admin_banner_save'=>'banners',
        'admin_banner_del'=>'banners','admin_post_save'=>'posts','admin_post_del'=>'posts','admin_notice_save'=>'notifications',
        'admin_admin_save'=>'admins','admin_admin_del'=>'admins'][$action] ?? 'dash';
    if (!can($me,$tabPerm)) { http_response_code(403); exit('forbidden'); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && !check_csrf()) { http_response_code(400); exit('bad csrf'); }
    $pdo=db();
    switch ($action) {
        case 'admin_product_save':
            $id=(int)($_POST['id']??0); $img=save_upload('image') ?? trim($_POST['image']??'');
            $f=[trim($_POST['title']??''),trim($_POST['icon']??'🛍️'),trim($_POST['description']??''),(int)($_POST['group_id']??0),
                (float)($_POST['price']??0),(float)($_POST['old_price']??0),$img,trim($_POST['tag']??'جديد'),isset($_POST['is_active'])?1:0];
            if ($id) $pdo->prepare("UPDATE products SET title=?,icon=?,description=?,group_id=?,price=?,old_price=?,image=?,tag=?,is_active=?,status='approved' WHERE id=?")->execute([...$f,$id]);
            else $pdo->prepare("INSERT INTO products(title,icon,description,group_id,price,old_price,image,tag,is_active,status) VALUES(?,?,?,?,?,?,?,?,?, 'approved')")->execute($f);
            redirect('?page=admin&tab=products&ok=1');
        case 'admin_product_approve':
            $pdo->prepare("UPDATE products SET status=? WHERE id=?")->execute([$_POST['do']==='approve'?'approved':'rejected',(int)$_POST['id']]);
            redirect('?page=admin&tab=userproducts');
        case 'admin_product_del': $pdo->prepare("DELETE FROM products WHERE id=?")->execute([(int)$_POST['id']]); redirect('?page=admin&tab=products');
        case 'admin_order':
            $st=$_POST['status']==='approved'?'approved':'rejected'; $oid=(int)$_POST['id'];
            $o=$pdo->prepare("SELECT o.*,p.price,p.title FROM orders o JOIN products p ON p.id=o.product_id WHERE o.id=?"); $o->execute([$oid]); $order=$o->fetch();
            if ($order && $order['status']==='pending'){ if($st==='approved'){ $b=$pdo->prepare("SELECT balance FROM users WHERE id=?");$b->execute([$order['user_id']]);
                if((int)$b->fetchColumn()>=(int)$order['price']) add_coins((int)$order['user_id'],-(int)$order['price'],'purchase','شراء: '.$order['title']); else $st='rejected'; }
                $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$st,$oid]); }
            redirect('?page=admin&tab=orders');
        case 'admin_topup':
            $st=$_POST['status']==='approved'?'approved':'rejected'; $tid=(int)$_POST['id'];
            $t=$pdo->prepare("SELECT * FROM topups WHERE id=?");$t->execute([$tid]);$tp=$t->fetch();
            if($tp&&$tp['status']==='pending'){ if($st==='approved') add_coins((int)$tp['user_id'],usd_to_coins((float)$tp['amount_usd']),'topup','شحن رصيد');
                $pdo->prepare("UPDATE topups SET status=? WHERE id=?")->execute([$st,$tid]); }
            redirect('?page=admin&tab=topups');
        case 'admin_withdraw':
            $st=$_POST['status']==='approved'?'approved':'rejected'; $wid=(int)$_POST['id'];
            $w=$pdo->prepare("SELECT * FROM withdrawals WHERE id=?");$w->execute([$wid]);$wd=$w->fetch();
            if($wd&&$wd['status']==='pending'){ if($st==='rejected') add_coins((int)$wd['user_id'],(int)$wd['coins'],'refund','استرداد سحب');
                $pdo->prepare("UPDATE withdrawals SET status=?,note=? WHERE id=?")->execute([$st,trim($_POST['note']??''),$wid]); }
            redirect('?page=admin&tab=withdrawals');
        case 'admin_wallet_save': $pdo->prepare("INSERT INTO wallets(type,label,address) VALUES(?,?,?)")->execute([trim($_POST['type']),trim($_POST['label']),trim($_POST['address'])]); redirect('?page=admin&tab=wallets');
        case 'admin_wallet_del': $pdo->prepare("DELETE FROM wallets WHERE id=?")->execute([(int)$_POST['id']]); redirect('?page=admin&tab=wallets');
        case 'admin_task_save': $pdo->prepare("INSERT INTO tasks(title,url,reward) VALUES(?,?,?)")->execute([trim($_POST['title']),trim($_POST['url']),(int)$_POST['reward']]); redirect('?page=admin&tab=tasks');
        case 'admin_task_del': $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([(int)$_POST['id']]); redirect('?page=admin&tab=tasks');
        case 'admin_group_save':
            $id=(int)($_POST['id']??0); $img=save_upload('image') ?? trim($_POST['image']??'');
            $f=[trim($_POST['title']),trim($_POST['icon']??'📦'),trim($_POST['color']??'#6c5ce7'),trim($_POST['description']??''),$img,(int)($_POST['sort']??0)];
            if($id) $pdo->prepare("UPDATE groups SET title=?,icon=?,color=?,description=?,image=?,sort=? WHERE id=?")->execute([...$f,$id]);
            else $pdo->prepare("INSERT INTO groups(title,icon,color,description,image,sort) VALUES(?,?,?,?,?,?)")->execute($f);
            redirect('?page=admin&tab=groups');
        case 'admin_group_del': $pdo->prepare("DELETE FROM groups WHERE id=?")->execute([(int)$_POST['id']]); redirect('?page=admin&tab=groups');
        case 'admin_banner_save': $img=save_upload('image') ?? trim($_POST['image']??''); $pdo->prepare("INSERT INTO banners(title,image,link) VALUES(?,?,?)")->execute([trim($_POST['title']??''),$img,trim($_POST['link']??'')]); redirect('?page=admin&tab=banners');
        case 'admin_banner_del': $pdo->prepare("DELETE FROM banners WHERE id=?")->execute([(int)$_POST['id']]); redirect('?page=admin&tab=banners');
        case 'admin_post_save': $img=save_upload('image') ?? trim($_POST['image']??'');
            $pdo->prepare("INSERT INTO posts(user_id,title,body,image,btn_label,btn_link,color,bg) VALUES(0,?,?,?,?,?,?,?)")
                ->execute([trim($_POST['title']??''),trim($_POST['body']??''),$img,trim($_POST['btn_label']??''),trim($_POST['btn_link']??''),trim($_POST['color']??'#e9edff'),trim($_POST['bg']??'#161f3d')]);
            redirect('?page=admin&tab=posts');
        case 'admin_post_del': $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([(int)$_POST['id']]); redirect('?page=admin&tab=posts');
        case 'admin_notice_save': set_setting('notice',trim($_POST['notice']??'')); redirect('?page=admin&tab=notifications');
        case 'admin_user':
            $uid=(int)$_POST['id']; $do=$_POST['do']??'';
            if($do==='ban')$pdo->prepare("UPDATE users SET is_banned=1 WHERE id=?")->execute([$uid]);
            if($do==='unban')$pdo->prepare("UPDATE users SET is_banned=0 WHERE id=?")->execute([$uid]);
            if($do==='addpts')add_coins($uid,(int)$_POST['pts'],'admin','إضافة من الإدارة',(int)$_POST['pts']);
            redirect('?page=admin&tab=users');
        case 'admin_admin_save': // owner فقط
            if(!is_owner($me)){http_response_code(403);exit;}
            $email=trim(strtolower($_POST['email']??'')); $perms=trim($_POST['perms']??'');
            $u=$pdo->prepare("SELECT id FROM users WHERE email=?");$u->execute([$email]);$id=$u->fetchColumn();
            if($id) $pdo->prepare("UPDATE users SET role='admin', perms=? WHERE id=?")->execute([$perms,$id]);
            redirect('?page=admin&tab=admins');
        case 'admin_admin_del':
            if(!is_owner($me)){http_response_code(403);exit;}
            $pdo->prepare("UPDATE users SET role='user', perms='' WHERE id=? AND email<>?")->execute([(int)$_POST['id'],ADMIN_EMAIL]);
            redirect('?page=admin&tab=admins');
        case 'admin_settings':
            foreach(['coins_per_usd','min_withdraw','captcha_reward','telegram_bot','telegram_info','banner_text','seo_desc','publish_min_level','site_logo'] as $k)
                if(isset($_POST[$k])) set_setting($k,trim($_POST[$k]));
            redirect('?page=admin&tab=settings&ok=1');
    }
    exit;
}

/* ===================== 7) بيانات العرض ===================== */
$banners = db()->query("SELECT * FROM banners WHERE is_active=1 ORDER BY sort,id")->fetchAll();
$groups  = db()->query("SELECT * FROM groups WHERE is_active=1 ORDER BY sort,id")->fetchAll();
$tasks   = db()->query("SELECT * FROM tasks WHERE is_active=1 ORDER BY id DESC")->fetchAll();
$wallets = db()->query("SELECT * FROM wallets WHERE is_active=1")->fetchAll();
$posts   = db()->query("SELECT p.*, u.name un FROM posts p LEFT JOIN users u ON u.id=p.user_id WHERE p.status='approved' ORDER BY p.id DESC LIMIT 20")->fetchAll();
$CSRF=csrf_token(); $policy_ok=isset($_COOKIE['policy_ok']);
$logo = setting('site_logo','💎');
$myLevel = $me ? (int)$me['level'] : 1;
$frameColor = LEVEL_FRAMES[max(0,min($myLevel-1,count(LEVEL_FRAMES)-1))];
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(SITE_NAME) ?> | تسوّق واربح كوينزات حقيقية — سحب شام كاش وUSDT وبيتكوين</title>
<meta name="description" content="<?= h(setting('seo_desc')) ?>">
<meta name="keywords" content="ربح المال, شام كاش, USDT, بيتكوين, مهام, كابتشا, نقاط, متجر عربي, برامج, ألعاب, اشتراكات, Yassota">
<meta name="robots" content="index, follow">
<meta property="og:title" content="<?= h(SITE_NAME) ?> — تسوّق واربح"><meta property="og:description" content="<?= h(setting('seo_desc')) ?>"><meta property="og:type" content="website">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'><?= h($logo) ?></text></svg>">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"<?= h(SITE_NAME) ?>","description":"<?= h(setting('seo_desc')) ?>"}</script>
<?php if (MONETAG_ZONE_ID): ?><script src="https://libtl.com/sdk.js" data-zone="<?= h(MONETAG_ZONE_ID) ?>" data-sdk="show_<?= h(MONETAG_ZONE_ID) ?>"></script><?php endif; ?>
<style><?= css() ?></style>
</head><body>

<div id="splash"><div class="logo"><?= h($logo) ?></div><h1><?= h(SITE_NAME) ?></h1><div class="bar"><i></i></div><p style="color:var(--mut);margin-top:12px;font-size:13px">جاري التحميل…</p></div>

<?php
// شريط الإشعارات
$notice = setting('notice');
if ($notice): ?><div class="notice-bar"><span>🔔 <?= h($notice) ?></span></div><?php endif; ?>

<header>
  <button class="menu-btn" onclick="toggleSide()">☰</button>
  <a href="?" class="brand"><span class="mark"><?= h($logo) ?></span><?= h(SITE_NAME) ?></a>
  <div class="spacer"></div>
  <?php if ($me): ?>
    <div class="balance-chip">💰 <?= number_format((int)$me['balance']) ?></div>
    <div class="avatar-wrap" style="--fr:<?= $frameColor ?>" onclick="toggleSide()">
      <?php if ($me['avatar']): ?><img src="<?= h($me['avatar']) ?>" alt=""><?php else: ?><span>👤</span><?php endif; ?>
    </div>
  <?php else: ?>
    <a class="btn sm" href="<?= GOOGLE_CLIENT_ID?'?action=google_login':'#' ?>" <?= GOOGLE_CLIENT_ID?'':'onclick="openModal(\'loginModal\');return false"' ?>>🔐 دخول</a>
  <?php endif; ?>
</header>

<div class="overlay" id="overlay" onclick="toggleSide()"></div>
<aside class="sidebar" id="sidebar">
  <span class="close" onclick="toggleSide()">✕</span>
  <?php if ($me): [$pct,$nx]=level_progress((int)$me['xp'],$myLevel); ?>
    <div class="side-user">
      <div class="avatar-wrap big" style="--fr:<?= $frameColor ?>"><?php if($me['avatar']):?><img src="<?= h($me['avatar']) ?>"><?php else:?><span>👤</span><?php endif;?></div>
      <div style="flex:1">
        <div style="font-weight:800"><?= h($me['name']) ?> <?php if(is_admin($me)):?>👑<?php endif;?></div>
        <div style="color:var(--mut);font-size:11px"><?= h($me['email']) ?></div>
        <div class="lvl-badge" style="--fr:<?= $frameColor ?>">Lvl <?= $myLevel ?> · <?= h(LEVEL_NAMES[$myLevel-1]??'') ?></div>
      </div>
    </div>
    <div class="xpbar"><i style="width:<?= $pct ?>%"></i></div>
    <div style="color:var(--mut);font-size:11px;text-align:center;margin-bottom:10px"><?= number_format((int)$me['xp']) ?> XP<?= $nx>0?" · باقٍ $nx للمستوى التالي":' · أعلى مستوى' ?></div>
  <?php else: ?>
    <div class="side-user"><div class="avatar-wrap"><span>👤</span></div><div>زائر — <a style="color:var(--pri2)" href="<?= GOOGLE_CLIENT_ID?'?action=google_login':'#' ?>" <?= GOOGLE_CLIENT_ID?'':'onclick="openModal(\'loginModal\');toggleSide();return false"' ?>>سجّل الدخول</a></div></div>
  <?php endif; ?>

  <details class="drop" open><summary>🏠 الرئيسية ▾</summary><div class="body">
    <a href="?">🛍️ المتجر والمجموعات</a><a href="#earn" onclick="toggleSide()">🎯 اربح كوينزات</a>
    <a href="#tasks" onclick="toggleSide()">📋 المهام</a><a href="?page=leaderboard">🏆 المتصدرون</a>
  </div></details>
  <details class="drop"><summary>💼 حسابي ▾</summary><div class="body">
    <a href="#" onclick="openModal('walletModal');toggleSide();return false">💳 محفظتي</a>
    <a href="#" onclick="openModal('topupModal');toggleSide();return false">➕ شحن رصيد</a>
    <a href="#" onclick="openModal('withdrawModal');toggleSide();return false">💸 سحب / استبدال</a>
    <a href="#" onclick="openModal('refModal');toggleSide();return false">👥 الإحالات</a>
    <a href="?page=orders">📦 طلباتي</a><a href="?page=publish">📤 انشر منتجك</a>
  </div></details>
  <details class="drop"><summary>💬 المجتمع ▾</summary><div class="body">
    <a href="?page=chat">💬 الدردشة العامة</a><a href="?page=leaderboard">🏆 المتصدرون</a>
    <a href="#" onclick="openModal('postModal');toggleSide();return false">✍️ إنشاء منشور</a>
    <a href="#" onclick="openModal('telegramModal');toggleSide();return false">🤖 بوت تيليجرام</a>
  </div></details>
  <details class="drop"><summary>📄 معلومات ▾</summary><div class="body">
    <a href="?page=privacy">🔒 سياسة الخصوصية</a><a href="?page=terms">📜 شروط الاستخدام</a>
  </div></details>
  <?php if (is_admin($me)): ?>
  <details class="drop"><summary>👑 الإدارة ▾</summary><div class="body">
    <a href="?page=admin">🎛️ لوحة التحكم</a><a href="?page=admin&tab=products">🛍️ المنتجات</a>
    <a href="?page=admin&tab=userproducts">📥 منتجات بانتظار الموافقة</a><a href="?page=admin&tab=users">👥 المستخدمون</a>
  </div></details>
  <?php endif; ?>
  <?php if ($me): ?><a class="btn ghost" style="width:100%;justify-content:center;margin-top:8px" href="?action=logout">🚪 تسجيل خروج</a><?php endif; ?>
</aside>

<?php
if ($page==='privacy') render_privacy();
elseif ($page==='terms') render_terms();
elseif ($page==='orders') render_orders($me);
elseif ($page==='publish') render_publish($me,$groups);
elseif ($page==='leaderboard') render_leaderboard($me);
elseif ($page==='chat') render_chat($me);
elseif ($page==='profile') render_profile($me);
elseif ($page==='group') render_group($me);
elseif ($page==='admin' && is_admin($me)) render_admin($me);
else render_home($banners,$groups,$tasks,$posts,$me);
?>

<nav class="bottomnav">
  <a href="?" class="<?= $page==='home'?'active':'' ?>"><span>🏠</span>الرئيسية</a>
  <a href="#earn"><span>💰</span>اربح</a>
  <a href="?page=leaderboard" class="<?= $page==='leaderboard'?'active':'' ?>"><span>🏆</span>المتصدرون</a>
  <a href="?page=chat" class="<?= $page==='chat'?'active':'' ?>"><span>💬</span>الدردشة</a>
  <?php if (is_admin($me)): ?><a href="?page=admin" class="<?= $page==='admin'?'active':'' ?>"><span>👑</span>الإدارة</a>
  <?php else: ?><a href="#" onclick="openModal('walletModal');return false"><span>💳</span>محفظتي</a><?php endif; ?>
</nav>

<?php render_modals($me,$wallets,$groups,$CSRF,$balUsd ?? ($me?coins_to_usd((int)$me['balance']):0)); ?>

<div id="toast"></div>

<?php if (!$policy_ok): ?>
<div class="modal show" id="policyGate"><div class="box">
  <h3>🔒 الموافقة على الشروط</h3>
  <p style="color:var(--mut);font-size:13px;line-height:1.7">باستخدامك <?= h(SITE_NAME) ?> فأنت توافق على
    <a style="color:var(--pri2)" href="?page=privacy" target="_blank">سياسة الخصوصية</a> و
    <a style="color:var(--pri2)" href="?page=terms" target="_blank">شروط الاستخدام</a>.</p>
  <label style="display:flex;align-items:center;gap:8px;margin:12px 0"><input type="checkbox" id="agreeChk"> أوافق على الشروط وسياسة الخصوصية</label>
  <button class="btn ok" style="width:100%;justify-content:center" onclick="acceptPolicy()">✓ موافق ومتابعة</button>
</div></div>
<?php endif; ?>

<script><?= js($me,$CSRF) ?></script>
</body></html>
<?php
/* ===================== 8) CSS / JS ===================== */
function css(): string { return <<<'CSS'
:root{--bg:#0b1020;--bg2:#121a35;--card:#161f3d;--line:#26315c;--txt:#e9edff;--mut:#9aa6d6;--pri:#6c5ce7;--pri2:#a29bfe;--ok:#19c37d;--warn:#ffb020;--bad:#ff5470;--gold:#ffd166}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,system-ui,sans-serif;background:linear-gradient(160deg,#0b1020,#0e1530);color:var(--txt);min-height:100vh}
a{color:inherit;text-decoration:none}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}
@keyframes load{0%{margin-left:-40%}100%{margin-left:100%}}
@keyframes glow{0%,100%{box-shadow:0 0 0 2px var(--fr),0 0 12px var(--fr)}50%{box-shadow:0 0 0 2px var(--fr),0 0 22px var(--fr)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
.btn{cursor:pointer;border:none;border-radius:12px;padding:10px 16px;font-weight:700;font-size:14px;background:linear-gradient(135deg,var(--pri),var(--pri2));color:#fff;transition:.2s;display:inline-flex;align-items:center;gap:6px}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(108,92,231,.4)}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--txt)}
.btn.ok{background:linear-gradient(135deg,#0fb96b,#19c37d)}.btn.bad{background:linear-gradient(135deg,#e0395a,#ff5470)}
.btn.gold{background:linear-gradient(135deg,#f0a500,#ffd166);color:#3a2c00}.btn.sm{padding:6px 10px;font-size:12px;border-radius:9px}
#splash{position:fixed;inset:0;background:radial-gradient(circle at 50% 40%,#1a2452,#0b1020);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;transition:opacity .6s}
#splash .logo{font-size:64px;animation:pulse 1.4s infinite}
#splash h1{margin-top:14px;font-size:28px;background:linear-gradient(90deg,var(--pri2),var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
#splash .bar{width:200px;height:6px;background:#1d2750;border-radius:99px;overflow:hidden;margin-top:18px}
#splash .bar i{display:block;height:100%;width:40%;background:linear-gradient(90deg,var(--pri),var(--gold));animation:load 1.2s infinite}
.notice-bar{background:linear-gradient(90deg,rgba(108,92,231,.25),rgba(255,209,102,.15));border-bottom:1px solid var(--line);overflow:hidden;white-space:nowrap;font-size:13px;padding:7px 0}
.notice-bar span{display:inline-block;padding-right:100%;animation:marq 18s linear infinite}
@keyframes marq{from{transform:translateX(100%)}to{transform:translateX(-100%)}}
header{position:sticky;top:0;z-index:50;background:rgba(11,16,32,.85);backdrop-filter:blur(10px);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;padding:11px 16px}
.brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:19px}
.brand .mark{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--pri),var(--gold));display:grid;place-items:center;font-size:19px}
.menu-btn{font-size:22px;background:none;border:none;color:var(--txt);cursor:pointer}.spacer{flex:1}
.balance-chip{background:var(--card);border:1px solid var(--line);border-radius:99px;padding:6px 13px;font-weight:800;color:var(--gold);font-size:13px}
.avatar-wrap{width:38px;height:38px;border-radius:50%;overflow:hidden;cursor:pointer;display:grid;place-items:center;background:var(--pri);font-size:18px;border:2px solid var(--fr,var(--pri));animation:glow 2.4s infinite}
.avatar-wrap img{width:100%;height:100%;object-fit:cover}.avatar-wrap.big{width:54px;height:54px;font-size:24px}
.sidebar{position:fixed;top:0;right:-320px;width:300px;height:100%;background:linear-gradient(180deg,var(--bg2),var(--bg));border-left:1px solid var(--line);z-index:100;transition:right .3s;overflow-y:auto;padding:18px}
.sidebar.open{right:0}.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;opacity:0;pointer-events:none;transition:.3s}.overlay.show{opacity:1;pointer-events:auto}
.side-user{display:flex;align-items:center;gap:10px;padding:12px;background:var(--card);border-radius:14px;margin-bottom:10px}
.lvl-badge{display:inline-block;margin-top:4px;font-size:10px;font-weight:800;padding:2px 8px;border-radius:99px;background:var(--fr);color:#06122a}
.xpbar{height:7px;background:#1d2750;border-radius:99px;overflow:hidden;margin-bottom:4px}.xpbar i{display:block;height:100%;background:linear-gradient(90deg,var(--pri),var(--gold))}
.drop{border:1px solid var(--line);border-radius:14px;margin-bottom:10px;overflow:hidden;background:var(--card)}
.drop>summary{list-style:none;cursor:pointer;padding:13px 15px;font-weight:700;display:flex;justify-content:space-between}.drop>summary::-webkit-details-marker{display:none}
.drop[open]>summary{color:var(--pri2)}.drop .body{padding:6px 10px 12px}
.drop .body a{display:flex;align-items:center;gap:9px;padding:10px;border-radius:9px;color:var(--mut);font-weight:600}.drop .body a:hover{background:var(--bg);color:var(--txt)}
.close{float:left;cursor:pointer;color:var(--mut);font-size:22px}
.slider{margin:16px;border-radius:20px;overflow:hidden;position:relative;height:190px}
.slides{display:flex;transition:transform .5s;height:100%}
.slide{min-width:100%;height:100%;display:flex;flex-direction:column;justify-content:center;padding:26px;background:linear-gradient(135deg,rgba(108,92,231,.4),rgba(255,209,102,.2)),radial-gradient(circle at 80% 20%,rgba(162,155,254,.4),transparent);background-size:cover;background-position:center}
.slide h2{font-size:24px;margin-bottom:6px;text-shadow:0 2px 8px #000}.slide p{color:#dfe5ff}
.dots{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);display:flex;gap:6px}
.dots i{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.4);cursor:pointer}.dots i.on{background:var(--gold);width:20px;border-radius:99px}
main{max-width:1100px;margin:0 auto;padding:0 14px 90px;animation:fadeUp .5s}
.sec-title{display:flex;align-items:center;gap:8px;margin:24px 6px 12px;font-size:20px;font-weight:800}
.groups{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
.gcard{border-radius:18px;padding:18px;text-align:center;cursor:pointer;border:1px solid var(--line);background:var(--card);transition:.2s;position:relative;overflow:hidden}
.gcard:hover{transform:translateY(-4px)}.gcard .gi{font-size:38px}.gcard h3{margin-top:8px;font-size:15px}.gcard small{color:var(--mut)}
.chips{display:flex;gap:8px;overflow-x:auto;padding:4px 6px 10px}
.chip{white-space:nowrap;border:1px solid var(--line);background:var(--card);border-radius:99px;padding:7px 14px;font-size:13px;cursor:pointer;font-weight:600}.chip.active{background:var(--pri);border-color:var(--pri)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;position:relative;transition:.2s;animation:fadeUp .5s}
.card:hover{transform:translateY(-4px);border-color:var(--pri)}
.card .imgwrap{height:130px;background:linear-gradient(135deg,#1a2350,#0e1530);display:grid;place-items:center;font-size:42px;overflow:hidden}
.card .imgwrap img{width:100%;height:100%;object-fit:cover}
.card .tag{position:absolute;top:10px;right:10px;background:var(--bad);color:#fff;font-size:11px;font-weight:800;padding:4px 9px;border-radius:99px}.card .tag.new{background:var(--ok)}
.card .body{padding:12px}.card h3{font-size:14px;margin-bottom:4px;display:flex;align-items:center;gap:6px}.card h3 .pi{font-size:16px;flex:none}
.card .cat{color:var(--mut);font-size:12px}.card .desc{color:var(--mut);font-size:12px;margin:6px 0;min-height:30px}
.card .price{display:flex;align-items:center;gap:8px;margin:8px 0}.card .price b{color:var(--gold);font-size:16px}.card .price s{color:var(--mut);font-size:13px}
.empty{text-align:center;color:var(--mut);padding:46px 20px;border:1px dashed var(--line);border-radius:18px}
.panel{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;margin-top:14px}
.captcha-box{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.captcha-box img{border-radius:12px;border:1px solid var(--line)}
.inp{width:100%;background:var(--bg);border:1px solid var(--line);border-radius:11px;padding:11px 13px;color:var(--txt);font-size:15px}
select.inp{appearance:none}label{display:block;font-size:13px;color:var(--mut);margin:10px 0 4px}
.row{display:flex;gap:10px;flex-wrap:wrap}.row>*{flex:1;min-width:150px}
.bottomnav{position:fixed;bottom:0;left:0;right:0;background:rgba(11,16,32,.95);backdrop-filter:blur(10px);border-top:1px solid var(--line);display:flex;justify-content:space-around;padding:8px 4px;z-index:40}
.bottomnav a{display:flex;flex-direction:column;align-items:center;gap:2px;font-size:11px;color:var(--mut);padding:5px 10px}.bottomnav a.active,.bottomnav a:hover{color:var(--pri2)}.bottomnav a span{font-size:20px}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;align-items:center;justify-content:center;z-index:200;padding:16px}.modal.show{display:flex}
.modal .box{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:22px;max-width:460px;width:100%;max-height:90vh;overflow:auto;animation:fadeUp .3s}.modal h3{margin-bottom:12px}
#toast{position:fixed;bottom:80px;right:50%;transform:translateX(50%);background:var(--card);border:1px solid var(--pri);padding:12px 20px;border-radius:12px;z-index:300;opacity:0;transition:.3s;font-weight:700;pointer-events:none;max-width:90%}#toast.show{opacity:1;bottom:90px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.tabs a{padding:9px 14px;border-radius:11px;background:var(--card);border:1px solid var(--line);font-weight:700;font-size:13px}.tabs a.active{background:var(--pri);border-color:var(--pri)}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}th,td{padding:9px;border-bottom:1px solid var(--line);text-align:right}th{color:var(--mut);font-weight:700}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:14px 0}.stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}.stat b{font-size:24px;display:block;color:var(--gold)}.stat span{color:var(--mut);font-size:13px}
.badge{font-size:11px;padding:3px 8px;border-radius:99px;font-weight:700}.badge.p{background:rgba(255,176,32,.2);color:var(--warn)}.badge.a{background:rgba(25,195,125,.2);color:var(--ok)}.badge.r{background:rgba(255,84,112,.2);color:var(--bad)}
.lb-row{display:flex;align-items:center;gap:12px;padding:12px;background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:8px;cursor:pointer;transition:.2s}.lb-row:hover{border-color:var(--pri)}
.lb-rank{font-size:20px;font-weight:900;width:34px;text-align:center}
.chatbox{height:55vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:6px}
.msg{max-width:78%;padding:8px 12px;border-radius:14px;font-size:14px;background:var(--card);border:1px solid var(--line)}.msg.me{align-self:flex-start;background:linear-gradient(135deg,var(--pri),var(--pri2));border:none}.msg .who{font-size:11px;color:var(--gold);font-weight:700;margin-bottom:2px}
.post{border-radius:16px;padding:16px;margin-bottom:12px;border:1px solid var(--line);animation:fadeUp .5s}
.post .ph{font-size:11px;color:var(--mut);margin-bottom:6px}.post img{width:100%;border-radius:12px;margin-top:8px}
@media(max-width:560px){.slide h2{font-size:20px}.slider{height:160px}}
CSS;
}

function js(?array $me, string $csrf): string {
    $logged = $me ? 'true':'false';
    $mid = MONETAG_ZONE_ID ?: '';
    return <<<JS
const LOGGED=$logged;const C='$csrf';
window.addEventListener('load',()=>{setTimeout(()=>{const s=document.getElementById('splash');if(s){s.style.opacity=0;setTimeout(()=>s.remove(),600);}},700);});
function toggleSide(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('show');}
function openModal(id){if(!LOGGED&&['walletModal','topupModal','withdrawModal','refModal','postModal'].includes(id)){openModal('loginModal');return;}const m=document.getElementById(id);if(m)m.classList.add('show');}
function closeModal(id){const m=document.getElementById(id);if(m)m.classList.remove('show');}
function toast(m){const t=document.getElementById('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),3000);}
function acceptPolicy(){if(!document.getElementById('agreeChk').checked){toast('يجب الموافقة أولاً');return;}fetch('?action=accept_cookies').then(()=>document.getElementById('policyGate').remove());}
function ajaxForm(form,action){const fd=new FormData(form);fetch('?action='+action,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{toast(d.msg||'');if(d.ok){form.reset();document.querySelectorAll('.modal.show').forEach(m=>{if(m.id!=='policyGate')m.classList.remove('show')});if(['withdraw','topup','buy','user_publish'].includes(action))setTimeout(()=>location.reload(),1300);}}).catch(()=>toast('خطأ'));return false;}
function buyProduct(id,title,price){if(!LOGGED){openModal('loginModal');return;}document.getElementById('buyContent').innerHTML='<p style="color:var(--mut)">المنتج: <b style="color:var(--txt)">'+title+'</b><br>السعر: <b style="color:var(--gold)">'+price+' كوين</b></p><p style="color:var(--mut);font-size:12px;margin:10px 0">يُرسل الطلب للإدارة ويُخصم الرصيد عند القبول.</p><button class="btn ok" style="width:100%;justify-content:center" onclick="confirmBuy('+id+')">تأكيد الطلب</button>';openModal('buyModal');}
function confirmBuy(id){const fd=new FormData();fd.append('csrf',C);fd.append('product_id',id);fetch('?action=buy',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{closeModal('buyModal');toast(d.msg);});}
function filterCat(cat,el){document.querySelectorAll('.chip').forEach(c=>c.classList.remove('active'));el.classList.add('active');document.querySelectorAll('.card[data-cat]').forEach(c=>{c.style.display=(cat==='all'||c.dataset.cat===cat)?'':'none';});}
function refreshCaptcha(){const i=document.getElementById('capImg');if(i)i.src='?action=captcha_img&t='+Date.now();}
function verifyCaptcha(e){e.preventDefault();if(!LOGGED){openModal('loginModal');return false;}const fd=new FormData();fd.append('csrf',C);fd.append('answer',document.getElementById('capInput').value);fetch('?action=captcha_verify',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{toast(d.msg);document.getElementById('capInput').value='';refreshCaptcha();if(d.ok){if(typeof window['show_$mid']==='function'){try{window['show_$mid']();}catch(e){}}setTimeout(()=>location.reload(),1400);}});return false;}
const tt={};
function startTask(id,url){if(!LOGGED){openModal('loginModal');return;}const fd=new FormData();fd.append('csrf',C);fd.append('task_id',id);fetch('?action=task_start',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{window.open(url,'_blank');let l=d.wait;const b=document.getElementById('task'+id);b.disabled=true;tt[id]=setInterval(()=>{l--;b.textContent='⏳ '+l+'s';if(l<=0){clearInterval(tt[id]);b.disabled=false;b.textContent='✅ استلام';b.onclick=()=>claimTask(id);}},1000);});}
function claimTask(id){const fd=new FormData();fd.append('csrf',C);fd.append('task_id',id);fetch('?action=task_complete',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{toast(d.msg);if(d.ok)setTimeout(()=>location.reload(),1200);});}
let si=0;function slideGo(n){const s=document.querySelector('.slides');if(!s)return;const t=s.children.length;si=(n+t)%t;s.style.transform='translateX('+(si*100)+'%)';document.querySelectorAll('.dots i').forEach((d,i)=>d.classList.toggle('on',i===si));}
setInterval(()=>{if(document.querySelector('.slides'))slideGo(si+1);},5000);
function follow(t,btn){const fd=new FormData();fd.append('csrf',C);fd.append('target',t);fetch('?action=follow',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{toast(d.msg);if(btn)btn.textContent=d.following?'✓ متابَع':'+ متابعة';});}
let dmTo=0;function openDM(id,name){if(!LOGGED){openModal('loginModal');return;}dmTo=id;document.getElementById('dmName').textContent=name;openModal('dmModal');loadDM();}
function loadDM(){if(!dmTo)return;fetch('?action=dm_fetch&to='+dmTo).then(r=>r.json()).then(d=>{const b=document.getElementById('dmBox');b.innerHTML=d.msgs.map(m=>'<div class="msg '+(m.from_id==d.me?'me':'')+'">'+(m.from_id==d.me?'':'<div class=who>'+esc(m.name)+'</div>')+esc(m.body)+'</div>').join('');b.scrollTop=b.scrollHeight;});}
function sendDM(e){e.preventDefault();const inp=document.getElementById('dmInput');const fd=new FormData();fd.append('csrf',C);fd.append('to',dmTo);fd.append('body',inp.value);inp.value='';fetch('?action=dm_send',{method:'POST',body:fd}).then(()=>loadDM());return false;}
function loadChat(){fetch('?action=chat_fetch').then(r=>r.json()).then(d=>{const b=document.getElementById('chatBox');if(!b)return;b.innerHTML=d.msgs.map(m=>'<div class="msg '+(m.user_id==d.me?'me':'')+'"><div class=who>'+esc(m.name)+' · L'+m.level+'</div>'+esc(m.body)+'</div>').join('');b.scrollTop=b.scrollHeight;});}
function sendChat(e){e.preventDefault();const inp=document.getElementById('chatInput');const fd=new FormData();fd.append('csrf',C);fd.append('body',inp.value);inp.value='';fetch('?action=chat_send',{method:'POST',body:fd}).then(()=>loadChat());return false;}
function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
if(document.getElementById('chatBox')){loadChat();setInterval(loadChat,4000);}
setInterval(()=>{if(document.getElementById('dmModal')&&document.getElementById('dmModal').classList.contains('show'))loadDM();},4000);
JS;
}


/* ===================== 9) دوال العرض ===================== */
function status_badge(string $s): string {
    $m=['pending'=>['p','⏳ معلّق'],'approved'=>['a','✅ مقبول'],'rejected'=>['r','❌ مرفوض']];
    [$c,$t]=$m[$s]??['p',$s]; return "<span class='badge $c'>$t</span>";
}
function product_card(array $p): void {
    $hasDisc=(float)$p['old_price']>(float)$p['price'] && (float)$p['old_price']>0;
    $cat=$p['gtitle'] ?? '';
    echo '<div class="card" data-cat="'.h($cat).'">';
    if ($p['tag']) echo '<span class="tag '.($p['tag']==='جديد'?'new':'').'">'.h($p['tag']).'</span>';
    echo '<div class="imgwrap">';
    if ($p['image']) echo '<img loading="lazy" src="'.h($p['image']).'" alt="'.h($p['title']).'">';
    else echo '<span>'.h($p['icon']?:'🛍️').'</span>';
    echo '</div><div class="body">';
    echo '<h3><span class="pi">'.h($p['icon']).'</span> '.h($p['title']).'</h3>';
    echo '<div class="cat">📂 '.h($cat).'</div>';
    echo '<div class="desc">'.h($p['description']).'</div>';
    echo '<div class="price"><b>'.number_format((float)$p['price']).' كوين</b>'.($hasDisc?'<s>'.number_format((float)$p['old_price']).'</s>':'').'</div>';
    echo '<button class="btn" style="width:100%;justify-content:center" onclick="buyProduct('.(int)$p['id'].',\''.h(addslashes($p['title'])).'\','.(int)$p['price'].')">🛒 طلب شراء</button>';
    echo '</div></div>';
}

function render_home(array $banners, array $groups, array $tasks, array $posts, ?array $me): void {
    // Slider
    echo '<div class="slider"><div class="slides">';
    if ($banners) foreach ($banners as $b) {
        $bg=$b['image']?"background-image:linear-gradient(135deg,rgba(11,16,32,.55),rgba(11,16,32,.25)),url('".h($b['image'])."')":'';
        echo '<div class="slide" style="'.$bg.'"><h2>'.h($b['title']?:setting('banner_text')).'</h2><p>'.h(setting('seo_desc')).'</p></div>';
    } else echo '<div class="slide"><h2>🚀 '.h(setting('banner_text')).'</h2><p>'.h(setting('seo_desc')).'</p></div>';
    echo '</div><div class="dots">';
    $cnt=max(1,count($banners)); for($i=0;$i<$cnt;$i++) echo '<i class="'.($i===0?'on':'').'" onclick="slideGo('.$i.')"></i>';
    echo '</div></div>';

    echo '<main>';
    // EARN captcha
    echo '<h2 class="sec-title" id="earn">🎯 اربح كوينزات — كابتشا حقيقية</h2><div class="panel">';
    echo '<p style="color:var(--mut);margin-bottom:12px">اكتب الأرقام الظاهرة في الصورة لتربح كوينزات فوراً (كابتشا مرسومة حقيقية، تموَّل من إعلانات Monetag — 95% للموقع / 5% للمستخدم).</p>';
    echo '<form onsubmit="return verifyCaptcha(event)"><div class="captcha-box"><img id="capImg" src="?action=captcha_img" width="160" height="60" alt="captcha"><button type="button" class="btn ghost sm" onclick="refreshCaptcha()">🔄</button></div>';
    echo '<label>اكتب الأرقام</label><input class="inp" id="capInput" inputmode="numeric" placeholder="مثال: 123" required><button class="btn ok" style="width:100%;justify-content:center;margin-top:12px">تحقّق واربح</button></form></div>';

    // Groups
    echo '<h2 class="sec-title">🗂️ المجموعات</h2><div class="groups">';
    foreach ($groups as $g) {
        $n=db()->prepare("SELECT COUNT(*) FROM products WHERE group_id=? AND is_active=1 AND status='approved'"); $n->execute([$g['id']]);
        echo '<a class="gcard" href="?page=group&id='.(int)$g['id'].'" style="border-color:'.h($g['color']).'"><div class="gi">'.h($g['icon']).'</div><h3>'.h($g['title']).'</h3><small>'.(int)$n->fetchColumn().' عنصر</small></a>';
    }
    echo '</div>';

    // Tasks
    echo '<h2 class="sec-title" id="tasks">📋 المهام اليومية</h2>';
    if ($tasks){ echo '<div class="grid">'; foreach($tasks as $t){
        echo '<div class="card"><div class="body"><h3>🔗 '.h($t['title']).'</h3><div class="cat">زر الرابط '.TASK_WAIT_SEC.'ث ثم استلم</div><div class="price"><b>+'.(int)$t['reward'].'</b> كوين</div>';
        echo '<button class="btn" id="task'.(int)$t['id'].'" style="width:100%;justify-content:center" onclick="startTask('.(int)$t['id'].',\''.h($t['url']).'\')">▶️ بدء</button></div></div>'; }
        echo '</div>'; } else echo '<div class="empty">لا مهام حالياً.</div>';

    // Posts feed
    if ($posts){ echo '<h2 class="sec-title">📣 المنشورات</h2>';
        foreach ($posts as $p){
            echo '<div class="post" style="background:'.h($p['bg']).';color:'.h($p['color']).'">';
            echo '<div class="ph">✍️ '.h($p['un']?:'الإدارة').' · '.h(substr((string)$p['created_at'],0,16)).'</div>';
            if($p['title']) echo '<h3>'.h($p['title']).'</h3>';
            echo '<div style="white-space:pre-wrap">'.h($p['body']).'</div>';
            if($p['image']) echo '<img loading="lazy" src="'.h($p['image']).'">';
            if($p['btn_label']&&$p['btn_link']) echo '<a class="btn" style="margin-top:10px" target="_blank" href="'.h($p['btn_link']).'">'.h($p['btn_label']).'</a>';
            echo '</div>';
        }
    }

    // Newest products
    echo '<h2 class="sec-title" id="products">🛍️ أحدث المنتجات</h2>';
    $rows=db()->query("SELECT p.*, g.title gtitle FROM products p LEFT JOIN groups g ON g.id=p.group_id WHERE p.is_active=1 AND p.status='approved' ORDER BY p.id DESC LIMIT 40")->fetchAll();
    if ($rows){ echo '<div class="grid">'; foreach($rows as $p) product_card($p); echo '</div>'; }
    else echo '<div class="empty">لا منتجات بعد.</div>';
    echo '</main>';
}

function render_group(?array $me): void {
    $gid=(int)($_GET['id']??0);
    $g=db()->prepare("SELECT * FROM groups WHERE id=?"); $g->execute([$gid]); $grp=$g->fetch();
    if (!$grp){ echo '<main><div class="empty">المجموعة غير موجودة.</div></main>'; return; }
    $rows=db()->prepare("SELECT p.*, g.title gtitle FROM products p LEFT JOIN groups g ON g.id=p.group_id WHERE p.group_id=? AND p.is_active=1 AND p.status='approved' ORDER BY p.id DESC");
    $rows->execute([$gid]); $rows=$rows->fetchAll();
    echo '<main><h2 class="sec-title">'.h($grp['icon']).' '.h($grp['title']).'</h2><p style="color:var(--mut);margin:0 6px 10px">'.h($grp['description']).'</p>';
    if ($rows){ echo '<div class="grid">'; foreach($rows as $p) product_card($p); echo '</div>'; }
    else echo '<div class="empty">لا عناصر في هذه المجموعة بعد.</div>';
    echo '</main>';
}

function render_leaderboard(?array $me): void {
    $top=db()->query("SELECT id,name,avatar,xp,level,balance FROM users WHERE is_banned=0 ORDER BY xp DESC, balance DESC LIMIT 50")->fetchAll();
    echo '<main><h2 class="sec-title">🏆 أعلى المتصدرين</h2>';
    $medals=['🥇','🥈','🥉'];
    foreach ($top as $i=>$u){
        $fr=LEVEL_FRAMES[max(0,min((int)$u['level']-1,count(LEVEL_FRAMES)-1))];
        $rank=$medals[$i]??('#'.($i+1));
        echo '<div class="lb-row" onclick="location.href=\'?page=profile&id='.(int)$u['id'].'\'">';
        echo '<div class="lb-rank">'.$rank.'</div>';
        echo '<div class="avatar-wrap" style="--fr:'.$fr.'">'.($u['avatar']?'<img src="'.h($u['avatar']).'">':'<span>👤</span>').'</div>';
        echo '<div style="flex:1"><b>'.h($u['name']).'</b><div style="color:var(--mut);font-size:12px">Lvl '.(int)$u['level'].' · '.h(LEVEL_NAMES[(int)$u['level']-1]??'').' · '.number_format((int)$u['xp']).' XP</div></div>';
        echo '<div style="color:var(--gold);font-weight:800">💰 '.number_format((int)$u['balance']).'</div></div>';
    }
    echo '</main>';
}

function render_profile(?array $me): void {
    $id=(int)($_GET['id']??0);
    $u=db()->prepare("SELECT * FROM users WHERE id=?"); $u->execute([$id]); $u=$u->fetch();
    if (!$u){ echo '<main><div class="empty">المستخدم غير موجود.</div></main>'; return; }
    $fr=LEVEL_FRAMES[max(0,min((int)$u['level']-1,count(LEVEL_FRAMES)-1))];
    [$pct,$nx]=level_progress((int)$u['xp'],(int)$u['level']);
    $followers=db()->prepare("SELECT COUNT(*) FROM friends WHERE target_id=?");$followers->execute([$id]);
    $following=false;
    if ($me){ $f=db()->prepare("SELECT 1 FROM friends WHERE follower_id=? AND target_id=?"); $f->execute([$me['id'],$id]); $following=(bool)$f->fetch(); }
    echo '<main><div class="panel" style="text-align:center">';
    echo '<div class="avatar-wrap big" style="--fr:'.$fr.';width:90px;height:90px;font-size:40px;margin:0 auto 10px">'.($u['avatar']?'<img src="'.h($u['avatar']).'">':'<span>👤</span>').'</div>';
    echo '<h2>'.h($u['name']).' '.(is_owner($u)?'👑':'').'</h2>';
    echo '<div class="lvl-badge" style="--fr:'.$fr.'">Lvl '.(int)$u['level'].' · '.h(LEVEL_NAMES[(int)$u['level']-1]??'').'</div>';
    echo '<div class="xpbar" style="margin:12px 0 4px"><i style="width:'.$pct.'%"></i></div>';
    echo '<div style="color:var(--mut);font-size:12px">'.number_format((int)$u['xp']).' XP · '.(int)$followers->fetchColumn().' متابع · '.(int)$u['ref_count'].' إحالة</div>';
    if ($u['bio']) echo '<p style="color:var(--mut);margin-top:8px">'.h($u['bio']).'</p>';
    if ($me && (int)$me['id']!==$id){
        echo '<div class="row" style="margin-top:14px">';
        echo '<button class="btn" onclick="follow('.$id.',this)">'.($following?'✓ متابَع':'+ متابعة').'</button>';
        echo '<button class="btn ghost" onclick="openDM('.$id.',\''.h(addslashes($u['name'])).'\')">💬 رسالة</button>';
        echo '</div>';
    }
    echo '</div></main>';
}

function render_chat(?array $me): void {
    echo '<main><h2 class="sec-title">💬 الدردشة العامة</h2><p style="color:var(--mut);margin:0 6px 8px">كل رسالة تكسبك +'.MSG_XP.' XP و +'.MSG_COINS.' كوين.</p>';
    echo '<div class="panel" style="padding:10px"><div class="chatbox" id="chatBox"></div>';
    if ($me) echo '<form onsubmit="return sendChat(event)" style="display:flex;gap:8px;margin-top:8px"><input class="inp" id="chatInput" placeholder="اكتب رسالة..." required><button class="btn">إرسال</button></form>';
    else echo '<p style="color:var(--mut);text-align:center;margin-top:8px">سجّل الدخول للمشاركة.</p>';
    echo '</div></main>';
}

function render_publish(?array $me, array $groups): void {
    global $CSRF;
    if (!$me){ echo '<main><div class="empty">سجّل الدخول للنشر.</div></main>'; return; }
    $minLvl=(int)setting('publish_min_level','2');
    echo '<main><h2 class="sec-title">📤 انشر منتجك / تطبيقك</h2>';
    if ((int)$me['level']<$minLvl){ echo '<div class="empty">🔒 يتطلب النشر الوصول للمستوى '.$minLvl.'. مستواك الحالي '.(int)$me['level'].'. اكسب XP عبر المهام والدردشة والكابتشا.</div></main>'; return; }
    echo '<div class="panel"><p style="color:var(--mut);font-size:13px">يظهر منتجك بعد موافقة الإدارة في مجموعته.</p>';
    echo '<form onsubmit="return ajaxForm(this,\'user_publish\')" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$CSRF.'">';
    echo '<div class="row"><div><label>الاسم</label><input class="inp" name="title" required></div><div><label>الأيقونة</label><input class="inp" name="icon" value="🛍️"></div></div>';
    echo '<div class="row"><div><label>المجموعة</label><select class="inp" name="group_id">';
    foreach ($groups as $g) echo '<option value="'.(int)$g['id'].'">'.h($g['icon']).' '.h($g['title']).'</option>';
    echo '</select></div><div><label>السعر (كوين)</label><input class="inp" type="number" name="price" required></div></div>';
    echo '<label>الوصف</label><textarea class="inp" name="description" rows="2"></textarea>';
    echo '<label>صورة المنتج</label><input class="inp" type="file" name="image" accept="image/*">';
    echo '<button class="btn ok" style="width:100%;justify-content:center;margin-top:12px">إرسال للمراجعة</button></form></div>';
    // منتجاتي
    $mine=db()->prepare("SELECT p.*, g.title gt FROM products p LEFT JOIN groups g ON g.id=p.group_id WHERE owner_id=? ORDER BY p.id DESC"); $mine->execute([$me['id']]);
    $rows=$mine->fetchAll();
    if ($rows){ echo '<h2 class="sec-title">منتجاتي</h2><table><tr><th>المنتج</th><th>المجموعة</th><th>السعر</th><th>الحالة</th></tr>';
        foreach($rows as $p) echo '<tr><td>'.h($p['icon']).' '.h($p['title']).'</td><td>'.h($p['gt']).'</td><td>'.number_format((float)$p['price']).'</td><td>'.status_badge($p['status']).'</td></tr>';
        echo '</table>'; }
    echo '</main>';
}

function render_orders(?array $me): void {
    if (!$me){ echo '<main><div class="empty">سجّل الدخول لعرض طلباتك.</div></main>'; return; }
    $o=db()->prepare("SELECT o.*,p.title,p.price,p.icon FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.id DESC");$o->execute([$me['id']]);$orders=$o->fetchAll();
    $w=db()->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY id DESC");$w->execute([$me['id']]);$wd=$w->fetchAll();
    echo '<main><h2 class="sec-title">📦 طلبات الشراء</h2>';
    if($orders)foreach($orders as $od)echo '<div class="panel" style="display:flex;justify-content:space-between;align-items:center"><div><b>'.h($od['icon']).' '.h($od['title']).'</b><br><small style="color:var(--mut)">'.number_format((float)$od['price']).' كوين · '.h($od['created_at']).'</small></div>'.status_badge($od['status']).'</div>';
    else echo '<div class="empty">لا طلبات.</div>';
    echo '<h2 class="sec-title">💸 طلبات السحب</h2>';
    if($wd)foreach($wd as $x)echo '<div class="panel" style="display:flex;justify-content:space-between;align-items:center"><div><b>$'.h($x['amount_usd']).'</b> · '.h($x['method']).'<br><small style="color:var(--mut)">'.h($x['address']).' · '.h($x['created_at']).'</small></div>'.status_badge($x['status']).'</div>';
    else echo '<div class="empty">لا طلبات سحب.</div>';
    echo '</main>';
}

function render_privacy(): void { echo '<main><h2 class="sec-title">🔒 سياسة الخصوصية</h2><div class="panel" style="line-height:1.9;color:var(--mut)"><p>نحترم خصوصيتك. نجمع الحد الأدنى من البيانات (الإيميل والاسم) لإدارة حسابك ورصيدك.</p><p>• لا نشارك بياناتك لأغراض تسويقية.<br>• نستخدم الكوكيز لحفظ الجلسة أسبوعاً.<br>• تُعرض إعلانات Monetag لتغطية المكافآت.<br>• يمكنك طلب حذف حسابك.</p></div></main>'; }
function render_terms(): void { echo '<main><h2 class="sec-title">📜 شروط الاستخدام</h2><div class="panel" style="line-height:1.9;color:var(--mut)"><p>• الكوينزات عملة افتراضية تُكتسب عبر المهام والكابتشا والإحالات والدردشة.<br>• الحد الأدنى للسحب $'.h(setting('min_withdraw')).' بعد موافقة الإدارة.<br>• يُمنع التلاعب والحسابات المكررة من نفس الجهاز؛ ويُحظر المخالف ويُصادر رصيده.<br>• طلبات الشراء/الشحن/السحب والنشر تخضع لمراجعة الإدارة.</p></div></main>'; }

function render_modals(?array $me, array $wallets, array $groups, string $CSRF, float $balUsd): void {
    ?>
    <div class="modal" id="loginModal"><div class="box"><span class="close" onclick="closeModal('loginModal')">✕</span><h3>🔐 تسجيل الدخول</h3>
      <p style="color:var(--mut);font-size:13px;margin-bottom:10px"><?= GOOGLE_CLIENT_ID?'ادخل عبر جوجل.':'وضع تجريبي: أدخل إيميلك (للإدارة استخدم '.h(ADMIN_EMAIL).').' ?></p>
      <?php if (GOOGLE_CLIENT_ID): ?><a class="btn" style="width:100%;justify-content:center" href="?action=google_login">دخول بجوجل</a>
      <?php else: ?><form method="post" action="?action=demo_login"><input type="hidden" name="csrf" value="<?= $CSRF ?>"><input class="inp" type="email" name="email" placeholder="example@gmail.com" required><button class="btn" style="width:100%;justify-content:center;margin-top:12px">دخول</button></form><?php endif; ?>
    </div></div>

    <div class="modal" id="walletModal"><div class="box"><span class="close" onclick="closeModal('walletModal')">✕</span><h3>💳 محفظتي</h3>
      <?php if ($me): ?><div class="panel" style="margin-top:0"><div style="font-size:30px;font-weight:900;color:var(--gold)"><?= number_format((int)$me['balance']) ?> <small style="font-size:14px">كوين</small></div><div style="color:var(--mut)">≈ $<?= $balUsd ?></div></div>
      <div class="row" style="margin-top:12px"><button class="btn ok" onclick="closeModal('walletModal');openModal('topupModal')">➕ شحن</button><button class="btn gold" onclick="closeModal('walletModal');openModal('withdrawModal')">💸 استبدال/سحب</button></div>
      <p style="color:var(--mut);font-size:12px;margin-top:12px">الحد الأدنى للسحب: $<?= h(setting('min_withdraw')) ?></p>
      <?php else: ?><p style="color:var(--mut)">سجّل الدخول.</p><?php endif; ?>
    </div></div>

    <div class="modal" id="topupModal"><div class="box"><span class="close" onclick="closeModal('topupModal')">✕</span><h3>➕ شحن رصيد</h3>
      <div class="panel" style="margin-top:0;padding:12px"><b>📱 الشام كاش</b><div style="font-family:monospace;color:var(--gold);word-break:break-all;font-size:13px;margin-top:4px"><?= h(SHAM_CASH_WALLET) ?></div><small style="color:var(--mut)">حوّل للمحفظة وأرفق صورة الإيصال.</small></div>
      <?php foreach ($wallets as $w): ?><div class="panel" style="padding:12px"><b><?= h($w['label']) ?></b> <span class="badge a"><?= h($w['type']) ?></span><div style="font-family:monospace;color:var(--gold);word-break:break-all;font-size:13px;margin-top:4px"><?= h($w['address']) ?></div></div><?php endforeach; ?>
      <form onsubmit="return ajaxForm(this,'topup')" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $CSRF ?>">
        <label>طريقة الدفع</label><select class="inp" name="method"><option value="sham">الشام كاش</option><option value="usdt">USDT TRC20</option><option value="btc">Bitcoin</option></select>
        <label>المبلغ بالدولار</label><input class="inp" type="number" step="0.01" name="amount" required>
        <label>رقم العملية / TXID</label><input class="inp" name="txid">
        <label>📷 صورة الإيصال (إلزامية للشام كاش)</label><input class="inp" type="file" name="receipt" accept="image/*">
        <button class="btn ok" style="width:100%;justify-content:center;margin-top:12px">إرسال طلب الشحن</button></form>
    </div></div>

    <div class="modal" id="withdrawModal"><div class="box"><span class="close" onclick="closeModal('withdrawModal')">✕</span><h3>💸 سحب / استبدال الكوينزات</h3>
      <?php if ($me): ?><p style="color:var(--mut);font-size:13px">رصيدك: <?= number_format((int)$me['balance']) ?> ≈ $<?= $balUsd ?> · الحد الأدنى $<?= h(setting('min_withdraw')) ?></p>
      <form onsubmit="return ajaxForm(this,'withdraw')"><input type="hidden" name="csrf" value="<?= $CSRF ?>"><label>طريقة الاستلام</label><select class="inp" name="method"><option value="sham">الشام كاش</option><option value="usdt">USDT TRC20</option><option value="btc">Bitcoin</option></select><label>العنوان / رقم الاستلام</label><input class="inp" name="address" required><button class="btn gold" style="width:100%;justify-content:center;margin-top:12px">طلب سحب كامل الرصيد</button></form>
      <?php else: ?><p style="color:var(--mut)">سجّل الدخول.</p><?php endif; ?>
    </div></div>

    <div class="modal" id="refModal"><div class="box"><span class="close" onclick="closeModal('refModal')">✕</span><h3>👥 نظام الإحالات</h3>
      <?php if ($me): $link=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?').'?ref='.h($me['ref_code']); ?>
      <p style="color:var(--mut);font-size:13px">ادعُ <?= REF_TARGET ?> أصدقاء (من أجهزة مختلفة) واحصل على $<?= REF_REWARD_USD ?>! والمدعو يحصل على <?= REF_INVITEE_COINS ?> كوين.</p>
      <div class="panel" style="padding:12px"><small style="color:var(--mut)">رابطك:</small><div style="font-family:monospace;color:var(--gold);word-break:break-all;font-size:12px"><?= $link ?></div></div>
      <div class="stat-grid"><div class="stat"><b><?= (int)$me['ref_count'] ?>/<?= REF_TARGET ?></b><span>إحالاتك</span></div><div class="stat"><b><?= (int)$me['ref_rewarded']?'✅':'⏳' ?></b><span>مكافأة الهدف</span></div></div>
      <button class="btn" style="width:100%;justify-content:center" onclick="navigator.clipboard.writeText('<?= $link ?>');toast('📋 نُسخ الرابط')">نسخ الرابط</button>
      <?php else: ?><p style="color:var(--mut)">سجّل الدخول.</p><?php endif; ?>
    </div></div>

    <div class="modal" id="postModal"><div class="box"><span class="close" onclick="closeModal('postModal')">✕</span><h3>✍️ إنشاء منشور</h3>
      <form onsubmit="return ajaxForm(this,'create_post')" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $CSRF ?>">
        <label>العنوان (اختياري)</label><input class="inp" name="title">
        <label>النص</label><textarea class="inp" name="body" rows="3" required></textarea>
        <div class="row"><div><label>لون النص</label><input class="inp" type="color" name="color" value="#e9edff"></div><div><label>لون الخلفية</label><input class="inp" type="color" name="bg" value="#161f3d"></div></div>
        <div class="row"><div><label>زر (نص)</label><input class="inp" name="btn_label"></div><div><label>رابط الزر</label><input class="inp" name="btn_link"></div></div>
        <label>صورة (اختياري)</label><input class="inp" type="file" name="image" accept="image/*">
        <button class="btn ok" style="width:100%;justify-content:center;margin-top:12px">نشر</button></form>
    </div></div>

    <div class="modal" id="telegramModal"><div class="box"><span class="close" onclick="closeModal('telegramModal')">✕</span><h3>🤖 بوت تيليجرام للربح</h3>
      <p style="color:var(--mut);font-size:14px;line-height:1.7"><?= h(setting('telegram_info')) ?></p>
      <a class="btn" style="width:100%;justify-content:center;margin-top:14px" target="_blank" href="https://t.me/<?= h(ltrim((string)setting('telegram_bot'),'@')) ?>">فتح البوت <?= h(setting('telegram_bot')) ?></a>
    </div></div>

    <div class="modal" id="dmModal"><div class="box"><span class="close" onclick="closeModal('dmModal')">✕</span><h3>💬 محادثة <span id="dmName"></span></h3>
      <div class="chatbox" id="dmBox" style="height:45vh"></div>
      <form onsubmit="return sendDM(event)" style="display:flex;gap:8px;margin-top:8px"><input class="inp" id="dmInput" placeholder="رسالة..." required><button class="btn">إرسال</button></form>
    </div></div>

    <div class="modal" id="buyModal"><div class="box"><span class="close" onclick="closeModal('buyModal')">✕</span><h3>🛒 تأكيد الشراء</h3><div id="buyContent"></div></div></div>
    <?php
}

/* ===================== 10) لوحة الإدارة ===================== */
function render_admin(?array $me): void {
    global $CSRF; $pdo=db(); $tab=$_GET['tab']??'dash';
    $allTabs=['dash'=>'🎛️ الرئيسية','banners'=>'🖼️ البنرات','groups'=>'🗂️ المجموعات','products'=>'🛍️ المنتجات',
        'userproducts'=>'📥 منتجات المستخدمين','orders'=>'📦 الطلبات','topups'=>'➕ الشحن','withdrawals'=>'💸 السحب',
        'wallets'=>'💳 المحافظ','tasks'=>'📋 المهام','posts'=>'📣 المنشورات','notifications'=>'🔔 الإشعارات',
        'users'=>'👥 المستخدمون','admins'=>'🛡️ المشرفون','settings'=>'⚙️ الإعدادات'];
    // عرض التبويبات حسب الصلاحية
    echo '<main><h2 class="sec-title">👑 لوحة الإدارة</h2><div class="tabs">';
    foreach ($allTabs as $k=>$v){ $perm = $k==='dash'?'dash':($k==='userproducts'?'products':$k);
        if (!can($me,$perm) && !($k==='dash')) continue;
        if ($k==='admins' && !is_owner($me)) continue;
        echo "<a class='".($k===$tab?'active':'')."' href='?page=admin&tab=$k'>$v</a>"; }
    echo '</div>';

    $permFor = $tab==='userproducts'?'products':$tab;
    if ($tab!=='dash' && !can($me,$permFor)) { echo '<div class="empty">🔒 لا تملك صلاحية هذا القسم.</div></main>'; return; }
    if ($tab==='admins' && !is_owner($me)) { echo '<div class="empty">🔒 للمالك فقط.</div></main>'; return; }

    switch ($tab) {
    case 'dash':
        $s=[ 'مستخدمون'=>$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),'منتجات'=>$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            'بانتظار الموافقة'=>$pdo->query("SELECT COUNT(*) FROM products WHERE status='pending'")->fetchColumn(),
            'طلبات معلّقة'=>$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'شحن معلّق'=>$pdo->query("SELECT COUNT(*) FROM topups WHERE status='pending'")->fetchColumn(),
            'سحب معلّق'=>$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn() ];
        echo '<div class="stat-grid">'; foreach($s as $l=>$v)echo "<div class='stat'><b>".number_format((int)$v)."</b><span>$l</span></div>"; echo '</div>';
        echo '<div class="panel"><b>أحدث المستخدمين</b><table><tr><th>#</th><th>الاسم</th><th>الإيميل</th><th>المستوى</th><th>الرصيد</th></tr>';
        foreach($pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 10") as $u)echo "<tr><td>{$u['id']}</td><td>".h($u['name'])."</td><td>".h($u['email'])."</td><td>".(int)$u['level']."</td><td>".number_format((int)$u['balance'])."</td></tr>";
        echo '</table></div>'; break;

    case 'products':
        $gs=$pdo->query("SELECT * FROM groups ORDER BY sort,id")->fetchAll();
        echo '<div class="panel"><b>➕ إضافة / تعديل منتج</b><form method="post" action="?action=admin_product_save" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" id="pid">';
        echo '<div class="row"><div><label>الاسم</label><input class="inp" name="title" id="ptitle" required></div><div><label>الأيقونة</label><input class="inp" name="icon" id="picon" value="🛍️"></div></div>';
        echo '<div class="row"><div><label>المجموعة</label><select class="inp" name="group_id" id="pgroup">'; foreach($gs as $g)echo '<option value="'.(int)$g['id'].'">'.h($g['icon']).' '.h($g['title']).'</option>'; echo '</select></div><div><label>الوسم</label><input class="inp" name="tag" id="ptag" value="جديد"></div></div>';
        echo '<div class="row"><div><label>السعر</label><input class="inp" type="number" name="price" id="pprice" required></div><div><label>السعر القديم</label><input class="inp" type="number" name="old_price" id="pold" value="0"></div></div>';
        echo '<label>رابط صورة (أو ارفع ملفاً)</label><input class="inp" name="image" id="pimg" placeholder="https://..."><input class="inp" type="file" name="image" accept="image/*" style="margin-top:6px">';
        echo '<label>الوصف</label><textarea class="inp" name="description" id="pdesc" rows="2"></textarea>';
        echo '<label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" name="is_active" id="pact" checked> نشط</label>';
        echo '<button class="btn ok" style="margin-top:10px">حفظ</button> <button type="button" class="btn ghost" onclick="document.getElementById(\'pid\').value=\'\';document.getElementById(\'ptitle\').value=\'\';document.getElementById(\'pprice\').value=\'\'">جديد</button></form></div>';
        echo '<table><tr><th>#</th><th>المنتج</th><th>السعر</th><th>الحالة</th><th></th></tr>';
        foreach($pdo->query("SELECT * FROM products WHERE status='approved' OR owner_id=0 ORDER BY id DESC") as $p){
            echo '<tr><td>'.$p['id'].'</td><td>'.h($p['icon']).' '.h($p['title']).'</td><td>'.number_format((float)$p['price']).'</td><td>'.($p['is_active']?'🟢':'⏸️').'</td><td>';
            echo '<button class="btn sm" onclick=\'editP('.json_encode($p,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT).')\'>✏️</button> ';
            echo '<form method="post" action="?action=admin_product_del" style="display:inline" onsubmit="return confirm(\'حذف؟\')"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$p['id'].'"><button class="btn sm bad">🗑️</button></form></td></tr>'; }
        echo '</table><script>function editP(p){pid.value=p.id;ptitle.value=p.title;picon.value=p.icon;pgroup.value=p.group_id;ptag.value=p.tag;pprice.value=p.price;pold.value=p.old_price;pimg.value=p.image||"";pdesc.value=p.description||"";pact.checked=p.is_active==1;scrollTo(0,0);}</script>'; break;

    case 'userproducts':
        $rows=$pdo->query("SELECT p.*, u.name un, g.title gt FROM products p JOIN users u ON u.id=p.owner_id LEFT JOIN groups g ON g.id=p.group_id WHERE p.status='pending' ORDER BY p.id DESC")->fetchAll();
        if(!$rows){echo '<div class="empty">لا منتجات بانتظار الموافقة.</div>';break;}
        echo '<table><tr><th>#</th><th>المنتج</th><th>الناشر</th><th>السعر</th><th>إجراء</th></tr>';
        foreach($rows as $p){echo '<tr><td>'.$p['id'].'</td><td>'.h($p['icon']).' '.h($p['title']).' <small style="color:var(--mut)">('.h($p['gt']).')</small></td><td>'.h($p['un']).'</td><td>'.number_format((float)$p['price']).'</td><td>';
            echo '<form method="post" action="?action=admin_product_approve" style="display:inline"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$p['id'].'"><button class="btn sm ok" name="do" value="approve">✅</button> <button class="btn sm bad" name="do" value="reject">❌</button></form></td></tr>';}
        echo '</table>'; break;

    case 'groups':
        echo '<div class="panel"><b>➕ مجموعة جديدة</b><form method="post" action="?action=admin_group_save" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$CSRF.'">';
        echo '<div class="row"><div><label>الاسم</label><input class="inp" name="title" required></div><div><label>الأيقونة</label><input class="inp" name="icon" value="📦"></div><div><label>اللون</label><input class="inp" type="color" name="color" value="#6c5ce7"></div></div>';
        echo '<label>الوصف</label><input class="inp" name="description"><label>صورة (اختياري)</label><input class="inp" type="file" name="image" accept="image/*"><button class="btn ok" style="margin-top:10px">حفظ</button></form></div>';
        echo '<table><tr><th>#</th><th>المجموعة</th><th>اللون</th><th></th></tr>';
        foreach($pdo->query("SELECT * FROM groups ORDER BY sort,id") as $g)echo '<tr><td>'.$g['id'].'</td><td>'.h($g['icon']).' '.h($g['title']).'</td><td><span style="background:'.h($g['color']).';padding:2px 14px;border-radius:6px"></span></td><td><form method="post" action="?action=admin_group_del" style="display:inline" onsubmit="return confirm(\'حذف؟\')"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$g['id'].'"><button class="btn sm bad">🗑️</button></form></td></tr>';
        echo '</table>'; break;

    case 'banners':
        echo '<div class="panel"><b>➕ بنر جديد</b><form method="post" action="?action=admin_banner_save" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$CSRF.'"><label>العنوان</label><input class="inp" name="title"><label>رابط (اختياري)</label><input class="inp" name="link"><label>صورة الخلفية</label><input class="inp" type="file" name="image" accept="image/*"> أو رابط: <input class="inp" name="image"><button class="btn ok" style="margin-top:10px">حفظ</button></form></div>';
        echo '<div class="grid">'; foreach($pdo->query("SELECT * FROM banners ORDER BY sort,id") as $b){echo '<div class="card"><div class="imgwrap">'.($b['image']?'<img src="'.h($b['image']).'">':'<span>🖼️</span>').'</div><div class="body"><b>'.h($b['title']).'</b><form method="post" action="?action=admin_banner_del" style="margin-top:8px"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$b['id'].'"><button class="btn sm bad">🗑️ حذف</button></form></div></div>';} echo '</div>'; break;

    case 'posts':
        echo '<div class="panel"><b>➕ منشور إداري</b><form method="post" action="?action=admin_post_save" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$CSRF.'"><label>العنوان</label><input class="inp" name="title"><label>النص</label><textarea class="inp" name="body" rows="3"></textarea><div class="row"><div><label>لون النص</label><input class="inp" type="color" name="color" value="#e9edff"></div><div><label>الخلفية</label><input class="inp" type="color" name="bg" value="#161f3d"></div></div><div class="row"><div><label>زر</label><input class="inp" name="btn_label"></div><div><label>رابط الزر</label><input class="inp" name="btn_link"></div></div><label>صورة</label><input class="inp" type="file" name="image" accept="image/*"><button class="btn ok" style="margin-top:10px">نشر</button></form></div>';
        echo '<table><tr><th>#</th><th>المنشور</th><th></th></tr>';
        foreach($pdo->query("SELECT * FROM posts ORDER BY id DESC") as $p)echo '<tr><td>'.$p['id'].'</td><td>'.h(mb_substr((string)($p['title']?:$p['body']),0,40)).'</td><td><form method="post" action="?action=admin_post_del" style="display:inline"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$p['id'].'"><button class="btn sm bad">🗑️</button></form></td></tr>';
        echo '</table>'; break;

    case 'notifications':
        echo '<div class="panel"><b>🔔 شريط الإشعارات</b><form method="post" action="?action=admin_notice_save"><input type="hidden" name="csrf" value="'.$CSRF.'"><label>نص الإشعار المتحرك</label><textarea class="inp" name="notice" rows="2">'.h(setting('notice')).'</textarea><button class="btn ok" style="margin-top:10px">حفظ</button></form></div>'; break;

    case 'orders': admin_req($pdo,'admin_order',"SELECT o.*,p.title,p.price,u.name FROM orders o JOIN products p ON p.id=o.product_id JOIN users u ON u.id=o.user_id ORDER BY o.id DESC",fn($r)=>h($r['name']).' → '.h($r['title']).' ('.number_format((float)$r['price']).')'); break;
    case 'topups': admin_req($pdo,'admin_topup',"SELECT t.*,u.name FROM topups t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC",fn($r)=>h($r['name']).' → $'.h($r['amount_usd']).' ('.h($r['method']).')'.($r['receipt']?' <a href="'.h($r['receipt']).'" target="_blank" style="color:var(--pri2)">📷 الإيصال</a>':'').' TX:'.h($r['txid'])); break;
    case 'withdrawals': admin_req($pdo,'admin_withdraw',"SELECT w.*,u.name FROM withdrawals w JOIN users u ON u.id=w.user_id ORDER BY w.id DESC",fn($r)=>h($r['name']).' → $'.h($r['amount_usd']).' ('.h($r['method']).') '.h($r['address'])); break;

    case 'wallets':
        echo '<div class="panel"><b>➕ محفظة استلام</b><form method="post" action="?action=admin_wallet_save"><input type="hidden" name="csrf" value="'.$CSRF.'"><div class="row"><div><label>النوع</label><input class="inp" name="type" placeholder="usdt/sham/btc" required></div><div><label>الاسم</label><input class="inp" name="label" required></div></div><label>العنوان</label><input class="inp" name="address" required><button class="btn ok" style="margin-top:10px">حفظ</button></form></div>';
        echo '<table><tr><th>#</th><th>النوع</th><th>الاسم</th><th>العنوان</th><th></th></tr>';
        foreach($pdo->query("SELECT * FROM wallets ORDER BY id DESC") as $w)echo '<tr><td>'.$w['id'].'</td><td>'.h($w['type']).'</td><td>'.h($w['label']).'</td><td style="font-family:monospace;font-size:12px;word-break:break-all">'.h($w['address']).'</td><td><form method="post" action="?action=admin_wallet_del" style="display:inline"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$w['id'].'"><button class="btn sm bad">🗑️</button></form></td></tr>';
        echo '</table>'; break;

    case 'tasks':
        echo '<div class="panel"><b>➕ مهمة</b><form method="post" action="?action=admin_task_save"><input type="hidden" name="csrf" value="'.$CSRF.'"><label>العنوان</label><input class="inp" name="title" required><div class="row"><div><label>الرابط</label><input class="inp" name="url" required></div><div><label>النقاط</label><input class="inp" type="number" name="reward" value="'.TASK_REWARD.'" required></div></div><button class="btn ok" style="margin-top:10px">حفظ</button></form></div>';
        echo '<table><tr><th>#</th><th>المهمة</th><th>النقاط</th><th></th></tr>';
        foreach($pdo->query("SELECT * FROM tasks ORDER BY id DESC") as $t)echo '<tr><td>'.$t['id'].'</td><td>'.h($t['title']).'</td><td>'.(int)$t['reward'].'</td><td><form method="post" action="?action=admin_task_del" style="display:inline"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$t['id'].'"><button class="btn sm bad">🗑️</button></form></td></tr>';
        echo '</table>'; break;

    case 'users':
        echo '<table><tr><th>#</th><th>الاسم</th><th>الإيميل</th><th>Lvl</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr>';
        foreach($pdo->query("SELECT * FROM users ORDER BY id DESC") as $u){echo '<tr><td>'.$u['id'].'</td><td><a style="color:var(--pri2)" href="?page=profile&id='.$u['id'].'">'.h($u['name']).'</a></td><td>'.h($u['email']).'</td><td>'.(int)$u['level'].'</td><td>'.number_format((int)$u['balance']).'</td><td>'.($u['is_banned']?'<span class="badge r">محظور</span>':'<span class="badge a">نشط</span>').($u['role']!=='user'?' 👑':'').'</td><td><form method="post" action="?action=admin_user" style="display:inline-flex;gap:4px;align-items:center"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$u['id'].'"><input class="inp" style="width:70px;padding:4px" type="number" name="pts" placeholder="نقاط"><button class="btn sm ok" name="do" value="addpts">➕</button>'.($u['is_banned']?'<button class="btn sm" name="do" value="unban">رفع</button>':'<button class="btn sm bad" name="do" value="ban">حظر</button>').'</form></td></tr>';}
        echo '</table>'; break;

    case 'admins':
        echo '<div class="panel"><b>🛡️ إضافة مشرف (للمالك فقط)</b><p style="color:var(--mut);font-size:12px">الصلاحيات مفصولة بفواصل، أو <code>all</code> لكل الصلاحيات. المتاح: products,orders,topups,withdrawals,wallets,tasks,groups,banners,posts,notifications,users,settings</p>';
        echo '<form method="post" action="?action=admin_admin_save"><input type="hidden" name="csrf" value="'.$CSRF.'"><label>إيميل المستخدم (يجب أن يكون مسجلاً)</label><input class="inp" name="email" required><label>الصلاحيات</label><input class="inp" name="perms" placeholder="products,orders,users أو all"><button class="btn ok" style="margin-top:10px">منح صلاحيات</button></form></div>';
        echo '<table><tr><th>#</th><th>الاسم</th><th>الإيميل</th><th>الدور</th><th>الصلاحيات</th><th></th></tr>';
        foreach($pdo->query("SELECT * FROM users WHERE role IN ('admin','owner') ORDER BY id") as $u){echo '<tr><td>'.$u['id'].'</td><td>'.h($u['name']).'</td><td>'.h($u['email']).'</td><td>'.($u['role']==='owner'?'👑 مالك':'🛡️ مشرف').'</td><td style="font-size:12px">'.h($u['role']==='owner'?'الكل':$u['perms']).'</td><td>'.($u['role']==='owner'?'':'<form method="post" action="?action=admin_admin_del" style="display:inline"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$u['id'].'"><button class="btn sm bad">إزالة</button></form>').'</td></tr>';}
        echo '</table>'; break;

    case 'settings':
        $keys=['site_logo'=>'شعار الموقع (إيموجي)','banner_text'=>'نص البنر','seo_desc'=>'وصف SEO','coins_per_usd'=>'كوين لكل 1$','min_withdraw'=>'الحد الأدنى للسحب $','captcha_reward'=>'نقاط الكابتشا','publish_min_level'=>'أدنى مستوى للنشر','telegram_bot'=>'بوت تيليجرام','telegram_info'=>'وصف البوت'];
        echo '<div class="panel"><form method="post" action="?action=admin_settings"><input type="hidden" name="csrf" value="'.$CSRF.'">';
        foreach($keys as $k=>$l)echo '<label>'.$l.'</label><input class="inp" name="'.$k.'" value="'.h(setting($k)).'">';
        echo '<button class="btn ok" style="margin-top:12px">حفظ الإعدادات</button></form></div>'; break;
    }
    echo '</main>';
}

function admin_req(PDO $pdo, string $action, string $sql, callable $fmt): void {
    global $CSRF; $rows=$pdo->query($sql)->fetchAll();
    if(!$rows){echo '<div class="empty">لا طلبات.</div>';return;}
    echo '<table><tr><th>#</th><th>التفاصيل</th><th>الحالة</th><th>إجراء</th></tr>';
    foreach($rows as $r){echo '<tr><td>'.$r['id'].'</td><td>'.$fmt($r).'</td><td>'.status_badge($r['status']).'</td><td>';
        if($r['status']==='pending')echo '<form method="post" action="?action='.$action.'" style="display:inline"><input type="hidden" name="csrf" value="'.$CSRF.'"><input type="hidden" name="id" value="'.$r['id'].'"><button class="btn sm ok" name="status" value="approved">✅</button> <button class="btn sm bad" name="status" value="rejected">❌</button></form>';
        else echo '—'; echo '</td></tr>';}
    echo '</table>';
}
