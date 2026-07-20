<?php
/**
 * ============================================================
 *  Smart Waste Management IoT System v3 — single-file PHP app
 * ------------------------------------------------------------
 *  Sensors per bin (just two physical units):
 *    - Ultrasonic distance sensor  -> fill level %
 *    - DHT22 temperature + humidity sensor -> °C / % RH
 *
 *  Auth:
 *    - Session login + self-service registration
 *    - Two roles: admin / service_team, stored hashed in users.json
 *
 *  Location:
 *    - Real lat/lng per bin, rendered on an OpenStreetMap /
 *      Leaflet map (bins seeded around Meru County, Kenya)
 *
 *  Notifications:
 *    - Online (in-app, polled + browser push) and SMS
 *      (Africa's Talking-style gateway stub; logs to sms_log.json
 *      when no live credentials are configured)
 *
 *  Storage: flat JSON files next to this script.
 * ============================================================
 */
session_start();

// ---------------------------------------------------------------
// CONFIG
// ---------------------------------------------------------------
define('DATA_FILE',  __DIR__ . '/bins_data.json');
define('USERS_FILE', __DIR__ . '/users.json');
define('NOTIF_FILE', __DIR__ . '/notifications.json');
define('SMS_LOG',     __DIR__ . '/sms_log.json');
define('EMAIL_LOG',   __DIR__ . '/email_log.json');
define('ALERT_FROM_EMAIL', 'alerts@wm-os.local');

define('FILL_WARN', 60);   define('FILL_CRIT', 85);
define('TEMP_WARN', 33);   define('TEMP_CRIT', 42);   // °C — early warning vs fire/decomposition risk
define('SENSOR_API_KEY', 'demo-sensor-key-2026');

define('DEPOT_LAT', 0.0470); define('DEPOT_LNG', 37.6499); // Meru Town CBD, Meru County

// Africa's Talking style SMS gateway — leave blank to run in
// simulated/logged-only mode (safe default, no network required).
define('SMS_USERNAME', '');
define('SMS_API_KEY', '');
define('SMS_GATEWAY_URL', 'https://api.africastalking.com/version1/messaging');

$ZONES = [
    'A' => ['name' => 'Meru Town (CBD)',      'color' => '#5FD3E0', 'lat'=>0.0470,  'lng'=>37.6499],
    'B' => ['name' => 'Nkubu',                 'color' => '#5FE0A0', 'lat'=>0.0333,  'lng'=>37.6667],
    'C' => ['name' => 'Maua (Igembe)',         'color' => '#F0B24A', 'lat'=>0.2333,  'lng'=>37.7500],
    'D' => ['name' => 'Timau (Buuri)',         'color' => '#C98CF0', 'lat'=>0.0483,  'lng'=>37.2333],
    'E' => ['name' => 'Kianjai (Tigania West)','color' => '#F06292', 'lat'=>0.1667,  'lng'=>37.6500],
    'F' => ['name' => 'Kanyakine (S. Imenti)', 'color' => '#78C2FF', 'lat'=>-0.0500, 'lng'=>37.5900],
];
function zoneCenter($zone) {
    global $ZONES;
    return $ZONES[$zone] ?? $ZONES['A'];
}

// ---------------------------------------------------------------
// USERS
// ---------------------------------------------------------------
function defaultUsers() {
    return [
        ['username'=>'admin',     'password'=>password_hash('admin123', PASSWORD_DEFAULT),     'role'=>'admin',        'name'=>'System Admin', 'phone'=>'+254700000001', 'email'=>'admin@wm-os.local',    'active'=>true],
        ['username'=>'collector', 'password'=>password_hash('collector123', PASSWORD_DEFAULT), 'role'=>'service_team', 'name'=>'James Mwangi', 'phone'=>'+254700000002', 'email'=>'james@wm-os.local',    'active'=>true],
    ];
}
function normalizeUser($u) {
    if (!isset($u['active'])) $u['active'] = true;
    if (!isset($u['phone'])) $u['phone'] = '';
    if (!isset($u['email'])) $u['email'] = '';
    return $u;
}
function loadUsers() {
    if (!file_exists(USERS_FILE)) saveUsers(defaultUsers());
    $u = json_decode(@file_get_contents(USERS_FILE), true);
    $u = is_array($u) ? $u : defaultUsers();
    return array_map('normalizeUser', $u);
}
function saveUsers($users) { file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT)); }
function currentUser() { return $_SESSION['user'] ?? null; }
function requireRole($roles) {
    $u = currentUser();
    if (!$u || !in_array($u['role'], (array)$roles, true)) {
        http_response_code(403); header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Not authorized']); exit;
    }
}

// ---------------------------------------------------------------
// BIN / STATE STORAGE  (with backward-compatible field migration —
// fixes "undefined array key" errors when loading data files that
// were created by an earlier version of this script)
// ---------------------------------------------------------------
function normalizeBin($b, $fallbackZone = 'A') {
    $zone = $b['zone'] ?? $fallbackZone;
    $zc = zoneCenter($zone);
    $defaults = [
        'zone' => $zone,
        'battery' => 100, 'temp' => 22.0, 'humidity' => 50, 
        
        'rate' => 0.01, 'offline' => false,
        'last_update' => time(), 'last_collected' => null,
        'lat' => $zc['lat'] + (mt_rand(-60,60)/10000),
        'lng' => $zc['lng'] + (mt_rand(-60,60)/10000),
        'fill' => 0,
    ];
    foreach ($defaults as $k => $v) if (!isset($b[$k])) $b[$k] = $v;
    if (!isset($b['id'])) $b['id'] = 'BIN-' . strtoupper(substr(uniqid(),-4));
    return $b;
}

function defaultBins() {
    $now = time();
    $seed = [
        ['id'=>'BIN-A01','zone'=>'A','lat'=>0.0455,'lng'=>37.6470,'fill'=>42,'battery'=>88,'temp'=>24.0,'humidity'=>55,'rate'=>0.010],
        ['id'=>'BIN-A02','zone'=>'A','lat'=>0.0490,'lng'=>37.6530,'fill'=>71,'battery'=>64,'temp'=>23.0,'humidity'=>60,'rate'=>0.014],
        ['id'=>'BIN-A03','zone'=>'A','lat'=>0.0430,'lng'=>37.6510,'fill'=>58,'battery'=>91,'temp'=>22.0,'humidity'=>58, 'rate'=>0.009],
        ['id'=>'BIN-B01','zone'=>'B','lat'=>0.0310,'lng'=>37.6640,'fill'=>35,'battery'=>77,'temp'=>21.0,'humidity'=>65,'rate'=>0.007],
        ['id'=>'BIN-B02','zone'=>'B','lat'=>0.0355,'lng'=>37.6700,'fill'=>66,'battery'=>58,'temp'=>22.0,'humidity'=>70,'rate'=>0.012],
        ['id'=>'BIN-C01','zone'=>'C','lat'=>0.2300,'lng'=>37.7460,'fill'=>91,'battery'=>41,'temp'=>44.0,'humidity'=>38,'rate'=>0.015],
        ['id'=>'BIN-C02','zone'=>'C','lat'=>0.2360,'lng'=>37.7540,'fill'=>52,'battery'=>69,'temp'=>29.0,'humidity'=>42,'rate'=>0.011],
        ['id'=>'BIN-D01','zone'=>'D','lat'=>0.0460,'lng'=>37.2300,'fill'=>29,'battery'=>84,'temp'=>23.0,'humidity'=>50,'rate'=>0.008],
        ['id'=>'BIN-D02','zone'=>'D','lat'=>0.0505,'lng'=>37.2360,'fill'=>63,'battery'=>12,'temp'=>24.0,'humidity'=>72,'rate'=>0.013],
        ['id'=>'BIN-D03','zone'=>'D','lat'=>0.0445,'lng'=>37.2380,'fill'=>80,'battery'=>56,'temp'=>25.0,'humidity'=>68,'rate'=>0.016],
        ['id'=>'BIN-A04','zone'=>'A','lat'=>0.0500,'lng'=>37.6450,'fill'=>25,'battery'=>95,'temp'=>23.0,'humidity'=>52,'rate'=>0.009],
        ['id'=>'BIN-B03','zone'=>'B','lat'=>0.0290,'lng'=>37.6600,'fill'=>48,'battery'=>73,'temp'=>22.0,'humidity'=>62,'rate'=>0.010],
        ['id'=>'BIN-C03','zone'=>'C','lat'=>0.2280,'lng'=>37.7420,'fill'=>37,'battery'=>80,'temp'=>25.0,'humidity'=>45,'rate'=>0.012],
        ['id'=>'BIN-D04','zone'=>'D','lat'=>0.0520,'lng'=>37.2280,'fill'=>54,'battery'=>67,'temp'=>20.0,'humidity'=>60,'rate'=>0.011],
        ['id'=>'BIN-E01','zone'=>'E','lat'=>0.1650,'lng'=>37.6480,'fill'=>62,'battery'=>71,'temp'=>21.0,'humidity'=>64,'rate'=>0.013],
        ['id'=>'BIN-E02','zone'=>'E','lat'=>0.1700,'lng'=>37.6550,'fill'=>18,'battery'=>97,'temp'=>20.0,'humidity'=>59,'rate'=>0.008],
        ['id'=>'BIN-F01','zone'=>'F','lat'=>-0.0480,'lng'=>37.5870,'fill'=>76,'battery'=>49,'temp'=>26.0,'humidity'=>40,'rate'=>0.015],
        ['id'=>'BIN-F02','zone'=>'F','lat'=>-0.0530,'lng'=>37.5950,'fill'=>33,'battery'=>85,'temp'=>24.0,'humidity'=>48,'rate'=>0.010],
    ];
    foreach ($seed as &$b) {
        $b['offline']=false; $b['last_update']=$now; $b['last_collected']=null;
    }
    return $seed;
}

function loadState() {
    if (!file_exists(DATA_FILE)) {
        $state = ['bins'=>defaultBins(), 'collections_today'=>0, 'history'=>[]];
        saveState($state);
        return $state;
    }
    $state = json_decode(@file_get_contents(DATA_FILE), true);
    if (!is_array($state) || !isset($state['bins'])) $state = ['bins'=>defaultBins(),'collections_today'=>0,'history'=>[]];
    // migrate every bin so old data files never throw undefined-key warnings
    $state['bins'] = array_map(fn($b) => normalizeBin($b), $state['bins']);
    if (!isset($state['collections_today'])) $state['collections_today'] = 0;
    if (!isset($state['history']) || !is_array($state['history'])) $state['history'] = [];
    return $state;
}
function saveState($state) {
    $fp = fopen(DATA_FILE, 'c+');
    if ($fp) { flock($fp, LOCK_EX); ftruncate($fp,0); fwrite($fp, json_encode($state, JSON_PRETTY_PRINT)); fflush($fp); flock($fp, LOCK_UN); fclose($fp); }
}

function statusOf($bin) {
    if (!empty($bin['offline'])) return 'offline';
    if ($bin['fill'] >= FILL_CRIT) return 'crit';
    if ($bin['fill'] >= FILL_WARN) return 'warn';
    return 'ok';
}

function tempStatus($temp) {
    if ($temp >= TEMP_CRIT) return 'crit';
    if ($temp >= TEMP_WARN) return 'warn';
    return 'ok';
}

// ---------------------------------------------------------------
// NOTIFICATIONS + SMS GATEWAY
// ---------------------------------------------------------------
function loadNotifs() {
    if (!file_exists(NOTIF_FILE)) file_put_contents(NOTIF_FILE, json_encode([]));
    $n = json_decode(@file_get_contents(NOTIF_FILE), true);
    return is_array($n) ? $n : [];
}
function saveNotifs($n) { file_put_contents(NOTIF_FILE, json_encode(array_slice($n,0,60), JSON_PRETTY_PRINT)); }

function sendEmail($to, $subject, $message) {
    $entry = ['to'=>$to, 'subject'=>$subject, 'message'=>$message, 'time'=>date('Y-m-d H:i:s'), 'status'=>'skipped (no email on file)'];
    if ($to !== '') {
        try {
            $headers = "From: WM/OS Alerts <" . ALERT_FROM_EMAIL . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            // Uses PHP's built-in mail() — works out of the box if the host has
            // sendmail/SMTP configured. Swap this block for PHPMailer or an
            // API-based provider (SendGrid, Mailgun, SES...) for production use.
            $sent = @mail($to, $subject, $message, $headers);
            $entry['status'] = $sent ? 'sent' : 'failed (server has no mail transport configured)';
        } catch (Throwable $e) { $entry['status'] = 'error: ' . $e->getMessage(); }
    }
    $log = file_exists(EMAIL_LOG) ? json_decode(@file_get_contents(EMAIL_LOG), true) : [];
    if (!is_array($log)) $log = [];
    array_unshift($log, $entry);
    file_put_contents(EMAIL_LOG, json_encode(array_slice($log,0,60), JSON_PRETTY_PRINT));
    return $entry['status'];
}

function sendSMS($phone, $message) {
    $entry = ['phone'=>$phone, 'message'=>$message, 'time'=>date('Y-m-d H:i:s'), 'status'=>'simulated'];
    if (SMS_USERNAME !== '' && SMS_API_KEY !== '') {
        try {
            $opts = ['http' => [
                'method'  => 'POST',
                'header'  => "apiKey: " . SMS_API_KEY . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['username'=>SMS_USERNAME, 'to'=>$phone, 'message'=>$message]),
                'timeout' => 5,
            ]];
            $result = @file_get_contents(SMS_GATEWAY_URL, false, stream_context_create($opts));
            $entry['status'] = $result !== false ? 'sent' : 'failed';
        } catch (Throwable $e) { $entry['status'] = 'error: ' . $e->getMessage(); }
    }
    $log = file_exists(SMS_LOG) ? json_decode(@file_get_contents(SMS_LOG), true) : [];
    if (!is_array($log)) $log = [];
    array_unshift($log, $entry);
    file_put_contents(SMS_LOG, json_encode(array_slice($log,0,60), JSON_PRETTY_PRINT));
    return $entry['status'];
}

function notifyCollectors(&$notifs, $level, $msg, $binId=null, $forceAllChannels=false) {
    $users = loadUsers();
    // Critical events — and anything explicitly flagged (temperature
    // changes) — page every active, registered account immediately on
    // every channel we have contact info for (phone and/or email);
    // routine warnings stay in-app only to avoid alert fatigue.
    $channels = ($level === 'crit' || $forceAllChannels) ? ['sms','email','online'] : ['online'];
    $subject = 'WM/OS Alert — Collection Needed';
    $smsPrefix = '[WM/OS]';
    foreach ($users as $u) {
        if (empty($u['active'])) continue;
        if (in_array('sms', $channels)   && !empty($u['phone'])) sendSMS($u['phone'], "$smsPrefix $msg");
        if (in_array('email', $channels) && !empty($u['email'])) sendEmail($u['email'], $subject, $msg);
    }
    array_unshift($notifs, [
        'id'=>uniqid('n_'), 'level'=>$level, 'msg'=>$msg, 'bin_id'=>$binId,
        'channels'=>$channels, 'time'=>date('H:i:s'), 'read'=>false,
    ]);
}

// ---------------------------------------------------------------
// SENSOR SIMULATION (time-based)
// ---------------------------------------------------------------
function simulate(&$state, &$notifs) {
    $now = time();
    foreach ($state['bins'] as &$bin) {
        if (!empty($bin['offline'])) continue;
        $elapsed = max(0, $now - $bin['last_update']);
        if ($elapsed <= 0) continue;
        $wasStatus = statusOf($bin);
        $wasTempStatus = tempStatus($bin['temp']);

        // --- Ultrasonic: fill level ---
        $noise = mt_rand(-20,40)/1000;
        $bin['fill'] = min(100, $bin['fill'] + ($bin['rate']+$noise) * $elapsed);

        // --- DHT22: temperature & humidity ---
        $tDrift = (mt_rand(-30,30)/100) + ($bin['zone']==='C' ? 0.15 : 0);
        $bin['temp'] = max(15, $bin['temp'] + $tDrift * min($elapsed,20)/10);
        $hDrift = mt_rand(-60,60)/100;
        $bin['humidity'] = max(10, min(100, $bin['humidity'] + $hDrift * min($elapsed,20)/10));

        // --- Battery ---
        $bin['battery'] = max(0, $bin['battery'] - 0.0025*$elapsed);
        if ($bin['battery'] <= 0.5 && mt_rand(1,100) <= 5) {
            $bin['offline'] = true;
            notifyCollectors($notifs, 'warn', "{$bin['id']} went offline — battery depleted.", $bin['id']);
        }

        $newStatus = statusOf($bin);
        if ($newStatus === 'crit' && $wasStatus !== 'crit' && $bin['fill'] >= FILL_CRIT) {
            notifyCollectors($notifs,'crit',"{$bin['id']} is full (".round($bin['fill'])."%) — dispatch collection.",$bin['id']);
        }

        // Temperature: notify automatically the moment it crosses into a
        // new band, in either direction — no dice roll, so a real reading
        // always reaches everyone registered.
        $newTempStatus = tempStatus($bin['temp']);
        if ($newTempStatus !== $wasTempStatus) {
            if ($newTempStatus === 'crit') {
                notifyCollectors($notifs,'crit',"{$bin['id']} temperature spike to ".round($bin['temp'],1)."°C — possible fire/decomposition risk, dispatch response.",$bin['id'],true);
            } elseif ($newTempStatus === 'warn' && $wasTempStatus === 'ok') {
                notifyCollectors($notifs,'warn',"{$bin['id']} temperature rising (".round($bin['temp'],1)."°C) — monitor closely.",$bin['id'],true);
            } elseif ($newTempStatus === 'ok' && $wasTempStatus !== 'ok') {
                notifyCollectors($notifs,'warn',"{$bin['id']} temperature back to normal (".round($bin['temp'],1)."°C).",$bin['id'],true);
            }
        }

        if ($bin['battery'] < 15 && $bin['battery'] > 0 && mt_rand(1,100) <= 2) {
            notifyCollectors($notifs,'warn',"{$bin['id']} battery low (".round($bin['battery'])."%).",$bin['id']);
        }

        $bin['last_update'] = $now;
    }
    unset($bin);

    $lastPoint = end($state['history']);
    if (!$lastPoint || ($now - $lastPoint['t']) >= 8) {
        $n = count($state['bins']);
        $avgFill = $n ? array_sum(array_column($state['bins'],'fill'))/$n : 0;
        $state['history'][] = ['t'=>$now,'label'=>date('H:i:s',$now),'avg'=>round($avgFill,1)];
        if (count($state['history']) > 24) array_shift($state['history']);
    }
}

// ---------------------------------------------------------------
// ROUTE OPTIMIZER — greedy nearest-neighbor over real coordinates
// ---------------------------------------------------------------
function haversineKm($a, $b) {
    $R = 6371;
    $dLat = deg2rad($b['lat'] - $a['lat']);
    $dLng = deg2rad($b['lng'] - $a['lng']);
    $h = sin($dLat/2)**2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng/2)**2;
    return $R * 2 * atan2(sqrt($h), sqrt(1-$h));
}
function computeRoute($bins) {
    $depot = ['lat'=>DEPOT_LAT, 'lng'=>DEPOT_LNG];
    $needing = array_values(array_filter($bins, fn($b) => empty($b['offline']) && $b['fill']>=70));
    if (empty($needing)) return ['stops'=>[], 'distance'=>0];
    $route = []; $current = $depot; $remaining = $needing; $total = 0;
    while (!empty($remaining)) {
        usort($remaining, fn($a,$b)=> haversineKm($current,$a) <=> haversineKm($current,$b));
        $next = array_shift($remaining);
        $total += haversineKm($current, $next);
        $route[] = $next; $current = $next;
    }
    $total += haversineKm($current, $depot);
    return ['stops'=>$route, 'distance'=>round($total,2)];
}

// ---------------------------------------------------------------
// AUTH — login / registration / logout (PRG pattern)
// ---------------------------------------------------------------
$loginError = null; $registerError = null; $showRegister = false;

if (isset($_POST['do_login'])) {
    $users = loadUsers();
    $uname = trim($_POST['username'] ?? ''); $pass = $_POST['password'] ?? '';
    $found = null;
    foreach ($users as $u) if ($u['username'] === $uname) { $found = $u; break; }
    if ($found && password_verify($pass, $found['password'])) {
        if (empty($found['active'])) {
            $loginError = 'This account has been deactivated. Contact an admin.';
        } else {
            $_SESSION['user'] = ['username'=>$found['username'], 'role'=>$found['role'], 'name'=>$found['name']];
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
    } else {
        $loginError = 'Invalid username or password. New here? Create an account below.';
        $showRegister = true;
    }
}

if (isset($_POST['do_register'])) {
    $showRegister = true;
    $name = trim($_POST['reg_name'] ?? '');
    $uname = trim($_POST['reg_username'] ?? '');
    $pass = $_POST['reg_password'] ?? '';
    $confirm = $_POST['reg_confirm'] ?? '';
    $phone = trim($_POST['reg_phone'] ?? '');
    $email = trim($_POST['reg_email'] ?? '');
    if ($name==='' || $uname==='' || $pass==='') {
        $registerError = 'Please fill in name, username and password.';
    } elseif ($pass !== $confirm) {
        $registerError = 'Passwords do not match.';
    } elseif (strlen($pass) < 6) {
        $registerError = 'Password must be at least 6 characters.';
    } else {
        $users = loadUsers();
        $exists = false;
        foreach ($users as $u) if ($u['username'] === $uname) { $exists = true; break; }
        if ($exists) {
            $registerError = 'That username is already taken.';
        } else {
            $newUser = ['username'=>$uname, 'password'=>password_hash($pass, PASSWORD_DEFAULT), 'role'=>'service_team', 'name'=>$name, 'phone'=>$phone, 'email'=>$email, 'active'=>true];
            $users[] = $newUser; saveUsers($users);
            $_SESSION['user'] = ['username'=>$uname, 'role'=>'service_team', 'name'=>$name];
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
    }
}

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }

// ---------------------------------------------------------------
// API ROUTER
// ---------------------------------------------------------------
$action = $_GET['action'] ?? null;
if ($action) {
    header('Content-Type: application/json');
    if ($action !== 'ingest' && !currentUser()) {
        http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit;
    }

    $state = loadState();
    $notifs = loadNotifs();
    simulate($state, $notifs);

    switch ($action) {

        case 'state':
            $bins = $state['bins'];
            foreach ($bins as &$b) $b['status'] = statusOf($b);
            unset($b);
            $route = computeRoute($state['bins']);
            saveState($state); saveNotifs($notifs);
            $meFull = currentUser();
            foreach (loadUsers() as $u) if ($u['username'] === $meFull['username']) { $meFull['phone'] = $u['phone'] ?? ''; $meFull['email'] = $u['email'] ?? ''; break; }
            echo json_encode([
                'bins'=>$bins, 'notifications'=>$notifs, 'history'=>$state['history'],
                'collections_today'=>$state['collections_today'], 'route'=>$route,
                'depot'=>['lat'=>DEPOT_LAT,'lng'=>DEPOT_LNG],
                'me'=>$meFull,
                'thresholds'=>['fill_warn'=>FILL_WARN,'fill_crit'=>FILL_CRIT,'temp_warn'=>TEMP_WARN,'temp_crit'=>TEMP_CRIT],
            ]);
            exit;

        case 'collect':
            requireRole(['admin','service_team']);
            $id = $_POST['id'] ?? '';
            foreach ($state['bins'] as &$b) {
                if ($b['id'] === $id) {
                    $b['fill']=mt_rand(2,8);
                    $b['last_collected']=date('Y-m-d H:i:s'); $b['last_update']=time();
                    $state['collections_today']++;
                }
            }
            unset($b);
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true]); exit;

        case 'dispatch_route':
            requireRole('admin');
            $route = computeRoute($state['bins']);
            $ids = array_map(fn($s)=>$s['id'], $route['stops']);
            foreach ($state['bins'] as &$b) {
                if (in_array($b['id'],$ids,true)) {
                    $b['fill']=mt_rand(2,8);
                    $b['last_collected']=date('Y-m-d H:i:s'); $b['last_update']=time();
                    $state['collections_today']++;
                }
            }
            unset($b);
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true,'collected'=>$ids]); exit;

        case 'mark_read':
            $id = $_POST['id'] ?? '';
            foreach ($notifs as &$n) if ($n['id']===$id) $n['read']=true;
            unset($n);
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true]); exit;

        case 'toggle_offline':
            requireRole('admin');
            $id = $_POST['id'] ?? '';
            foreach ($state['bins'] as &$b) if ($b['id']===$id) { $b['offline']=empty($b['offline']); $b['last_update']=time(); }
            unset($b);
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true]); exit;

        case 'add_bin':
            requireRole('admin');
            $id = trim($_POST['id'] ?? ''); $zone = $_POST['zone'] ?? 'A';
            if ($id==='') { echo json_encode(['ok'=>false,'error'=>'Bin ID required']); exit; }
            foreach ($state['bins'] as $b) if ($b['id']===$id) { echo json_encode(['ok'=>false,'error'=>'ID exists']); exit; }
            $zc = zoneCenter($zone);
            $state['bins'][] = normalizeBin([
                'id'=>$id, 'zone'=>$zone,
                'lat'=>$zc['lat'] + (mt_rand(-60,60)/10000), 'lng'=>$zc['lng'] + (mt_rand(-60,60)/10000),
                'fill'=>0, 'battery'=>100, 'temp'=>22.0, 'humidity'=>50,
                'rate'=>mt_rand(6,16)/1000,
            ], $zone);
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true]); exit;

        case 'delete_bin':
            requireRole('admin');
            $id = $_POST['id'] ?? '';
            $before = count($state['bins']);
            $state['bins'] = array_values(array_filter($state['bins'], fn($b)=>$b['id']!==$id));
            if (count($state['bins']) === $before) { echo json_encode(['ok'=>false,'error'=>'Bin not found']); exit; }
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true]); exit;

        case 'add_user':
            requireRole('admin');
            $uname = trim($_POST['username'] ?? ''); $pass = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'service_team'; $name = trim($_POST['name'] ?? $uname);
            $phone = trim($_POST['phone'] ?? ''); $email = trim($_POST['email'] ?? '');
            if ($uname==='' || $pass==='') { echo json_encode(['ok'=>false,'error'=>'Username & password required']); exit; }
            $users = loadUsers();
            foreach ($users as $u) if ($u['username']===$uname) { echo json_encode(['ok'=>false,'error'=>'Username exists']); exit; }
            $users[] = ['username'=>$uname,'password'=>password_hash($pass,PASSWORD_DEFAULT),'role'=>$role,'name'=>$name,'phone'=>$phone,'email'=>$email,'active'=>true];
            saveUsers($users);
            echo json_encode(['ok'=>true]); exit;

        case 'update_profile':
            // self-service: any logged-in user can keep their own contact info current
            $me = currentUser();
            $phone = trim($_POST['phone'] ?? ''); $email = trim($_POST['email'] ?? '');
            $users = loadUsers(); $found = false;
            foreach ($users as &$u) if ($u['username']===$me['username']) { $u['phone']=$phone; $u['email']=$email; $found = true; }
            unset($u);
            if (!$found) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }
            saveUsers($users);
            echo json_encode(['ok'=>true]); exit;

        case 'toggle_user_active':
            requireRole('admin');
            $uname = $_POST['username'] ?? '';
            $me = currentUser();
            if ($uname === $me['username']) { echo json_encode(['ok'=>false,'error'=>"You can't deactivate your own account."]); exit; }
            $users = loadUsers(); $found = false;
            foreach ($users as &$u) if ($u['username']===$uname) { $u['active'] = empty($u['active']); $found = true; }
            unset($u);
            if (!$found) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }
            saveUsers($users);
            echo json_encode(['ok'=>true]); exit;

        case 'set_user_role':
            requireRole('admin');
            $uname = $_POST['username'] ?? ''; $role = $_POST['role'] ?? '';
            $me = currentUser();
            if ($uname === $me['username']) { echo json_encode(['ok'=>false,'error'=>"You can't change your own role."]); exit; }
            if (!in_array($role, ['admin','service_team'], true)) { echo json_encode(['ok'=>false,'error'=>'Invalid role']); exit; }
            $users = loadUsers(); $found = false;
            foreach ($users as &$u) if ($u['username']===$uname) { $u['role'] = $role; $found = true; }
            unset($u);
            if (!$found) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }
            saveUsers($users);
            echo json_encode(['ok'=>true]); exit;

        case 'delete_user':
            requireRole('admin');
            $uname = $_POST['username'] ?? '';
            $me = currentUser();
            if ($uname === $me['username']) { echo json_encode(['ok'=>false,'error'=>"You can't delete your own account."]); exit; }
            $users = loadUsers();
            $admins = array_filter($users, fn($u)=>$u['role']==='admin' && !empty($u['active']));
            $target = null; foreach ($users as $u) if ($u['username']===$uname) { $target = $u; break; }
            if ($target && $target['role']==='admin' && count($admins) <= 1) {
                echo json_encode(['ok'=>false,'error'=>'Cannot delete the last active admin.']); exit;
            }
            $before = count($users);
            $users = array_values(array_filter($users, fn($u)=>$u['username']!==$uname));
            if (count($users) === $before) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }
            saveUsers($users);
            echo json_encode(['ok'=>true]); exit;

        case 'list_users':
            requireRole('admin');
            $users = array_map(fn($u)=>['username'=>$u['username'],'role'=>$u['role'],'name'=>$u['name'],'phone'=>$u['phone'] ?? '','email'=>$u['email'] ?? '','active'=>!empty($u['active'])], loadUsers());
            echo json_encode(['ok'=>true,'users'=>$users]); exit;

        case 'ingest':
            // Real hardware posts here — just the two physical sensors:
            // ultrasonic (fill %) and DHT22 (temp/humidity). Weight is
            // derived server-side from fill % against the bin's capacity.
            // POST ?action=ingest {"api_key":"...","id":"BIN-A01","fill":73,"temp":24.1,"humidity":58,"battery":81}
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!is_array($payload)) $payload = $_POST;
            if (($payload['api_key'] ?? '') !== SENSOR_API_KEY) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Invalid API key']); exit; }
            $id = $payload['id'] ?? ''; $found = false;
            foreach ($state['bins'] as &$b) {
                if ($b['id']===$id) {
                    $found = true;
                    $wasTempStatus = tempStatus($b['temp']);
                    foreach (['fill','battery','humidity'] as $f) if (isset($payload[$f])) $b[$f] = max(0,min(100,(float)$payload[$f]));
                    if (isset($payload['temp'])) $b['temp'] = (float)$payload['temp'];
                    $b['offline']=false; $b['last_update']=time();
                    if ($b['fill'] >= FILL_CRIT) notifyCollectors($notifs,'crit',"{$id} is full (".round($b['fill'])."%) via live sensor push — dispatch collection.",$id);
                    $newTempStatus = tempStatus($b['temp']);
                    if ($newTempStatus !== $wasTempStatus) {
                        if ($newTempStatus === 'crit') notifyCollectors($notifs,'crit',"{$id} temperature spike to ".round($b['temp'],1)."°C via live sensor push — possible fire risk.",$id,true);
                        elseif ($newTempStatus === 'warn' && $wasTempStatus === 'ok') notifyCollectors($notifs,'warn',"{$id} temperature rising (".round($b['temp'],1)."°C).",$id,true);
                    }
                }
            }
            unset($b);
            if (!$found) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Unknown bin']); exit; }
            saveState($state); saveNotifs($notifs);
            echo json_encode(['ok'=>true]); exit;

        default:
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
    }
}

// ---------------------------------------------------------------
// PAGE RENDER
// ---------------------------------------------------------------
$me = currentUser();
if ($me) {
    $state = loadState(); $notifs = loadNotifs();
    simulate($state, $notifs);
    saveState($state); saveNotifs($notifs);
}
$selfUrl = htmlspecialchars($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>WM/OS — Smart Waste IoT Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');
:root{
  --bg:#1B1917; --bg-2:#221F1C; --panel:#242019; --panel-2:#2B261F; --border:rgba(240,230,210,0.10);
  --text:#F1EADC; --text-dim:#A79C8A;
  --ok:#8CA377; --warn:#D6963E; --crit:#BD4E30; --offline:#6E675B;
  --accent:#C97A3D; --accent-soft:#C97A3D33;
  --font-d:'Fraunces',serif; --font-b:'Manrope',sans-serif; --font-m:'IBM Plex Mono',monospace;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{
  font-family:var(--font-b); color:var(--text); font-size:13px; min-height:100vh; position:relative; overflow-x:hidden;
  background:
    radial-gradient(ellipse 900px 600px at 15% -5%, rgba(201,122,61,0.07), transparent 60%),
    radial-gradient(ellipse 900px 700px at 105% 105%, rgba(140,163,119,0.06), transparent 60%),
    var(--bg);
}
::-webkit-scrollbar{width:8px;height:8px;} ::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}

/* ---------- background: quiet paper grain + faint contour lines (map/geo motif) ---------- */
.bg-texture{ position:fixed; inset:0; z-index:0; pointer-events:none; }
.bg-texture::before{
  content:''; position:absolute; inset:0; opacity:0.035; mix-blend-mode:overlay;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}
.bg-texture::after{
  content:''; position:absolute; inset:0; opacity:0.05;
  background-image: repeating-radial-gradient(circle at 12% 8%, transparent 0, transparent 90px, rgba(201,122,61,0.5) 91px, transparent 93px),
                     repeating-radial-gradient(circle at 92% 96%, transparent 0, transparent 120px, rgba(140,163,119,0.4) 121px, transparent 124px);
}

.wrap{ position:relative; z-index:1; max-width:1400px; margin:0 auto; padding:22px 24px 40px; }

/* ---------- login / register screen ---------- */
.login-screen{ min-height:100vh; display:flex; align-items:center; justify-content:center; position:relative; z-index:1; }
.login-card{ width:400px; background:var(--panel); border:1px solid var(--border); border-radius:4px; padding:34px;
  border-top:3px solid var(--accent); box-shadow:0 24px 60px rgba(0,0,0,0.45); }
.login-card .mark{ width:46px;height:46px;border-radius:4px; background:var(--accent);
  display:flex;align-items:center;justify-content:center; font-family:var(--font-d); font-weight:700; color:#1B1917; font-size:19px; margin-bottom:16px;}
.login-card h1{ font-family:var(--font-d); font-weight:600; font-size:23px; margin-bottom:4px; letter-spacing:0.2px; }
.login-card .sub{ color:var(--text-dim); font-size:12px; margin-bottom:22px; font-family:var(--font-m); }
.field{ margin-bottom:13px; }
.field label{ display:block; font-size:10.5px; color:var(--text-dim); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.7px; }
.field input, .field select{ width:100%; background:var(--bg-2); border:1px solid var(--border); color:var(--text);
  border-radius:4px; padding:10px 12px; font-size:13px; font-family:var(--font-b); }
.field input:focus, .field select:focus{ outline:none; border-color:var(--accent); }
.login-error{ background:rgba(189,78,48,0.14); border:1px solid rgba(189,78,48,0.4); color:#E8A08E;
  border-radius:4px; padding:9px 11px; font-size:12px; margin-bottom:14px; }
.btn{ background:var(--accent); border:none; color:#1B1917; font-weight:700;
  padding:11px 14px; border-radius:4px; cursor:pointer; width:100%; font-size:13px; font-family:var(--font-b); letter-spacing:0.2px; }
.btn:hover{ background:#DA8A48; } .btn:disabled{ opacity:0.4; cursor:not-allowed; background:var(--panel-2); color:var(--text-dim); }
.demo-creds{ margin-top:18px; padding-top:15px; border-top:1px dashed var(--border); font-family:var(--font-m); font-size:10.5px; color:var(--text-dim); line-height:1.7; }
.demo-creds b{ color:var(--text); }
.switch-link{ text-align:center; margin-top:16px; font-size:12px; color:var(--text-dim); }
.switch-link a{ color:var(--accent); text-decoration:none; cursor:pointer; }
.switch-link a:hover{ text-decoration:underline; }
#registerBox{ display:none; }

/* ---------- dashboard ---------- */
header{ display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;
  padding-bottom:18px; margin-bottom:20px; border-bottom:1px solid var(--border); }
.brand{ display:flex; align-items:center; gap:13px; }
.brand .mark{ width:40px;height:40px;border-radius:4px; background:var(--accent);
  display:flex;align-items:center;justify-content:center; font-family:var(--font-d); font-weight:700; color:#1B1917; font-size:15px; }
.brand h1{ font-family:var(--font-d); font-weight:600; font-size:20px; letter-spacing:0.2px; }
.brand .sub{ color:var(--text-dim); font-size:11px; font-family:var(--font-m); margin-top:1px; }
.who{ display:flex; align-items:center; gap:12px; }
.who .role-badge{ font-family:var(--font-m); font-size:10.5px; padding:4px 11px; border-radius:3px; border:1px solid var(--border); }
.who .role-badge.admin{ color:#D6963E; border-color:rgba(214,150,62,0.4); }
.who .role-badge.service_team{ color:#8CA377; border-color:rgba(140,163,119,0.4); }
.who a{ color:var(--text-dim); text-decoration:none; font-size:12px; border:1px solid var(--border); padding:7px 13px; border-radius:4px; }
.who a:hover{ color:var(--text); border-color:var(--accent); }

.stats{ display:flex; gap:10px; flex-wrap:wrap; }
.stat{ background:var(--panel); border:1px solid var(--border); border-left:3px solid var(--border); padding:8px 15px; min-width:94px; }
.stat .l{ font-size:9.5px; color:var(--text-dim); text-transform:uppercase; letter-spacing:0.6px;}
.stat .v{ font-family:var(--font-m); font-size:19px; font-weight:600; }
.stat.crit{ border-left-color:var(--crit); } .stat.crit .v{color:var(--crit);}
.stat.warn{ border-left-color:var(--warn); } .stat.warn .v{color:var(--warn);}
.stat.ok{ border-left-color:var(--ok); } .stat.ok .v{color:var(--ok);}

.grid{ display:grid; grid-template-columns:1.5fr 1fr; gap:18px; }
@media(max-width:980px){ .grid{ grid-template-columns:1fr; } }
.panel{ background:var(--panel); border:1px solid var(--border); border-top:2px solid var(--accent-soft); border-radius:4px; padding:18px; margin-bottom:18px; }
.panel h2{ font-family:var(--font-d); font-weight:600; font-size:14px; letter-spacing:0.2px; color:var(--text);
  display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.panel h2 .count{ background:var(--panel-2); border:1px solid var(--border); border-radius:3px; padding:2px 9px; font-family:var(--font-m); color:var(--text-dim); font-size:10.5px; font-weight:400;}

#cityMap{ height:340px; border-radius:4px; border:1px solid var(--border); }
.leaflet-tile-pane{ filter:invert(94%) hue-rotate(15deg) brightness(0.9) contrast(88%) saturate(70%); }
.leaflet-popup-content-wrapper, .leaflet-popup-tip{ background:#242019; color:var(--text); border-radius:3px; }
.bin-label{ background:var(--panel-2); border:1px solid var(--border); color:var(--text); font-family:var(--font-m);
  font-size:9.5px; padding:1px 6px; border-radius:3px; box-shadow:none; }
.bin-label::before{ display:none; }
.leaflet-popup-content{ font-family:var(--font-m); font-size:11.5px; }
.leaflet-container{ background:#1B1917; }

.bin-list{ display:flex; flex-direction:column; gap:9px; max-height:440px; overflow-y:auto; padding-right:4px; }
.bin-card{ background:var(--panel-2); border:1px solid var(--border); border-radius:4px; padding:12px 14px; }
.bin-card-top{ display:flex; align-items:center; gap:10px; margin-bottom:9px; }
.dot{ width:8px;height:8px;border-radius:50%; flex-shrink:0; }
.bin-name{ font-weight:600; font-size:12.5px; flex:1; font-family:var(--font-m); letter-spacing:0.2px; }
.zone-tag{ font-family:var(--font-m); font-size:9px; color:var(--text-dim); background:var(--bg-2); border:1px solid var(--border); border-radius:3px; padding:1px 5px;}
.sensor-row{ display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-bottom:9px; }
.sensor{ background:var(--bg-2); border:1px solid var(--border); border-radius:3px; padding:6px 4px; text-align:center; }
.sensor .sv{ font-family:var(--font-m); font-weight:600; font-size:11.5px; }
.sensor .sl{ font-size:7.5px; color:var(--text-dim); text-transform:uppercase; margin-top:1px; letter-spacing:0.3px;}
.bin-actions{ display:flex; gap:7px; align-items:center; }
.bin-fill-bar{ flex:1; height:5px; border-radius:3px; background:var(--bg-2); overflow:hidden; }
.bin-fill-bar .fill{ height:100%; border-radius:3px; }
.mini-btn{ background:var(--bg-2); border:1px solid var(--border); color:var(--text-dim); border-radius:3px; padding:5px 10px; font-size:10.5px; cursor:pointer; font-family:var(--font-b); }
.mini-btn:hover{ color:var(--text); border-color:var(--accent); }
.mini-btn.danger:hover{ border-color:var(--crit); color:#E8A08E; }

.notif-feed{ display:flex; flex-direction:column; gap:8px; max-height:250px; overflow-y:auto; }
.notif{ display:flex; gap:10px; padding:10px 12px; border-radius:3px; border-left:3px solid var(--crit); background:var(--panel-2); font-size:12px; position:relative; cursor:pointer;}
.notif.warn{ border-left-color:var(--warn); } .notif.ok{ border-left-color:var(--ok); }
.notif.unread::after{ content:''; position:absolute; top:11px; right:10px; width:6px; height:6px; border-radius:50%; background:var(--accent); }
.notif .badge{ font-family:var(--font-m); font-size:9px; font-weight:600; letter-spacing:0.4px; padding:1px 6px; border-radius:3px; flex-shrink:0; height:18px; display:flex; align-items:center; }
.notif.crit .badge{ background:rgba(189,78,48,0.18); color:var(--crit); }
.notif.warn .badge{ background:rgba(214,150,62,0.18); color:var(--warn); }
.notif.ok .badge{ background:rgba(140,163,119,0.18); color:var(--ok); }
.notif .t{ color:var(--text-dim); font-family:var(--font-m); font-size:10px; margin-top:4px; }
.notif .chan{ display:inline-block; font-size:8.5px; font-family:var(--font-m); padding:1px 5px; border-radius:3px; border:1px solid var(--border); color:var(--text-dim); margin-right:4px; }

.route-item{ display:flex; align-items:center; gap:9px; padding:8px 0; border-bottom:1px dashed var(--border); font-size:12px; }
.route-idx{ width:19px;height:19px;border-radius:3px; background:var(--panel-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-family:var(--font-m); font-size:10px; }
.route-dist{ margin-left:auto; color:var(--text-dim); font-family:var(--font-m); font-size:11px; }

.addbin-form{ display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.addbin-form input, .addbin-form select{ background:var(--bg-2); border:1px solid var(--border); color:var(--text); border-radius:3px; padding:8px 10px; font-size:12px; font-family:var(--font-b); }
.addbin-form input{ flex:1; }

.legend{ display:flex; gap:15px; margin-top:12px; flex-wrap:wrap; }
.legend span{ display:flex; align-items:center; gap:6px; font-size:11px; color:var(--text-dim); }
.legend i{ width:8px;height:8px;border-radius:50%; display:inline-block; }
.bars{ display:flex; align-items:flex-end; gap:5px; height:80px; margin-top:8px; }
.bars .bar{ flex:1; background:var(--accent); opacity:0.85; border-radius:2px 2px 0 0; min-height:2px; }
.tiny{ color:var(--text-dim); font-size:11px; font-family:var(--font-m); }
.user-row{ display:flex; align-items:center; gap:8px; padding:10px 0; border-bottom:1px dashed var(--border); font-size:12px; flex-wrap:wrap; }
.user-row .role-pill{ margin-left:auto; font-family:var(--font-m); font-size:9.5px; color:var(--text-dim); }
</style>
</head>
<body>
<div class="bg-texture"></div>

<?php if (!$me): ?>
<!-- ================= LOGIN / REGISTER SCREEN ================= -->
<div class="login-screen">
  <div class="login-card">
    <div class="mark">WM</div>
    <h1>Waste Management OS</h1>
    <div class="sub">Sign in to the sensor network console</div>

    <div id="loginBox" style="<?php echo $showRegister ? 'display:none;' : ''; ?>">
      <?php if ($loginError): ?><div class="login-error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
      <form method="POST" action="<?php echo $selfUrl; ?>">
        <input type="hidden" name="do_login" value="1">
        <div class="field"><label>Username</label><input type="text" name="username" required autofocus></div>
        <div class="field"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn" type="submit">Sign In</button>
      </form>
      <div class="switch-link">No account yet? <a onclick="toggleAuth()">Create one</a></div>
      <div class="demo-creds">
        <b>Demo — Admin:</b> admin / admin123<br>
        <b>Demo — Service Team:</b> collector / collector123
      </div>
    </div>

    <div id="registerBox" style="<?php echo $showRegister ? 'display:block;' : ''; ?>">
      <?php if ($registerError): ?><div class="login-error"><?php echo htmlspecialchars($registerError); ?></div><?php endif; ?>
      <form method="POST" action="<?php echo $selfUrl; ?>">
        <input type="hidden" name="do_register" value="1">
        <div class="field"><label>Full name</label><input type="text" name="reg_name" required></div>
        <div class="field"><label>Username</label><input type="text" name="reg_username" required></div>
        <div class="field"><label>Phone (for SMS alerts)</label><input type="text" name="reg_phone" placeholder="+2547..."></div>
        <div class="field"><label>Email (for email alerts)</label><input type="email" name="reg_email" placeholder="you@example.com"></div>
        <div class="field"><label>Password</label><input type="password" name="reg_password" required></div>
        <div class="field"><label>Confirm password</label><input type="password" name="reg_confirm" required></div>
        <button class="btn" type="submit">Create Account</button>
      </form>
      <div class="switch-link">Already have an account? <a onclick="toggleAuth()">Sign in</a></div>
      <div class="demo-creds">New accounts are provisioned as <b>Service Team</b> collectors. Ask an admin to upgrade a role if needed.</div>
    </div>
  </div>
</div>
<script>
function toggleAuth(){
  const l = document.getElementById('loginBox'), r = document.getElementById('registerBox');
  const showingLogin = l.style.display !== 'none';
  l.style.display = showingLogin ? 'none' : 'block';
  r.style.display = showingLogin ? 'block' : 'none';
}
</script>

<?php else: ?>
<!-- ================= DASHBOARD ================= -->
<div class="wrap">
  <header>
    <div class="brand">
      <div class="mark">WM</div>
      <div><h1>Waste Management OS</h1><div class="sub">Ultrasonic · DHT22 — Meru County Network</div></div>
    </div>
    <div class="stats">
      <div class="stat ok"><div class="l">Online</div><div class="v" id="stOnline">--</div></div>
      <div class="stat warn"><div class="l">Needs Pickup</div><div class="v" id="stWarn">--</div></div>
      <div class="stat crit"><div class="l">Critical</div><div class="v" id="stCrit">--</div></div>
      <div class="stat"><div class="l">Collected Today</div><div class="v" id="stCollected">--</div></div>
    </div>
    <div class="who">
      <span class="role-badge <?php echo $me['role']; ?>"><?php echo htmlspecialchars($me['name']); ?> · <?php echo $me['role']==='admin'?'Admin':'Service Team'; ?></span>
      <a href="?logout=1">Log out</a>
    </div>
  </header>

  <div class="grid">
    <div>
      <div class="panel">
        <h2>Geographic Sensor Map <span class="count" id="mapCount">0</span></h2>
        <div id="cityMap"></div>
        <div class="legend">
          <span><i style="background:var(--ok)"></i>Normal</span>
          <span><i style="background:var(--warn)"></i>Needs pickup</span>
          <span><i style="background:var(--crit)"></i>Critical</span>
          <span><i style="background:var(--offline)"></i>Offline</span>
        </div>
      </div>
      <div class="panel">
        <h2>Fleet Sensor Feed <span class="count" id="listCount">0</span></h2>
        <div class="bin-list" id="binList"></div>
        <?php if ($me['role']==='admin'): ?>
        <div class="addbin-form">
          <input type="text" id="newBinId" placeholder="New sensor ID e.g. BIN-E01">
          <select id="newBinZone"><option value="A">Zone A — Meru Town</option><option value="B">Zone B — Nkubu</option><option value="C">Zone C — Maua</option><option value="D">Zone D — Timau</option><option value="E">Zone E — Kianjai</option><option value="F">Zone F — Kanyakine</option></select>
          <button class="mini-btn" id="addBinBtn">+ Commission</button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="panel">
        <h2>Collector Notifications <span class="count" id="notifCount">0</span></h2>
        <div class="tiny" style="margin-bottom:9px;">Critical events page the service team via SMS + in-app alert.</div>
        <div class="notif-feed" id="notifFeed"></div>
      </div>
      <div class="panel">
        <h2>Route Optimizer <span class="count" id="routeCount">0</span></h2>
        <div class="tiny">Nearest-neighbor from depot — real-world distance.</div>
        <div id="routeList" style="margin-top:8px;"></div>
        <?php if ($me['role']==='admin'): ?>
        <button class="btn" id="dispatchBtn" disabled>Dispatch Truck &amp; Collect Route</button>
        <?php else: ?>
        <div class="tiny" style="margin-top:10px;">Only an admin can dispatch the route. Use "Collect" on individual bins below as you service them.</div>
        <?php endif; ?>
      </div>
      <div class="panel">
        <h2>Avg Fill Trend</h2>
        <div class="bars" id="trendBars"></div>
      </div>
      <div class="panel">
        <h2>My Notification Contact</h2>
        <div class="tiny" style="margin-bottom:9px;">Critical alerts page you here automatically, in real time.</div>
        <div class="addbin-form" style="flex-wrap:wrap;">
          <input type="text" id="myPhone" placeholder="Phone +2547..." style="flex:1 1 100%;">
          <input type="email" id="myEmail" placeholder="Email address" style="flex:1 1 100%;margin-top:6px;">
          <button class="mini-btn" id="saveProfileBtn" style="width:100%;margin-top:6px;">Save Contact Info</button>
        </div>
      </div>
      <?php if ($me['role']==='admin'): ?>
      <div class="panel">
        <h2>Team Accounts <span class="count" id="userCount">0</span></h2>
        <div id="userList"></div>
        <div class="addbin-form">
          <input type="text" id="newUserName" placeholder="Full name" style="flex:1 1 100%;">
          <input type="text" id="newUsername" placeholder="Username">
          <input type="password" id="newPassword" placeholder="Password">
          <select id="newRole"><option value="service_team">Service Team</option><option value="admin">Admin</option></select>
          <input type="text" id="newPhone" placeholder="+2547..." style="flex:1 1 100%;">
          <input type="email" id="newEmail" placeholder="Email address" style="flex:1 1 100%;">
          <button class="mini-btn" id="addUserBtn" style="width:100%;">+ Add Account</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
<?php if ($me): ?>
const SELF = "<?php echo $selfUrl; ?>";
const IS_ADMIN = <?php echo $me['role']==='admin' ? 'true' : 'false'; ?>;
const DEPOT = { lat: <?php echo DEPOT_LAT; ?>, lng: <?php echo DEPOT_LNG; ?> };
let notifiedIds = new Set();

if ('Notification' in window && Notification.permission === 'default') { Notification.requestPermission(); }

function statusColor(s){ return {ok:"#8CA377",warn:"#D6963E",crit:"#BD4E30",offline:"#6E675B"}[s]; }

async function api(action, params, method){
  method = method || "GET";
  if (method === "GET") {
    const q = new URLSearchParams(params||{}).toString();
    const r = await fetch(`${SELF}?action=${action}${q?`&${q}`:""}`);
    return r.json();
  }
  const body = new URLSearchParams(params||{});
  const r = await fetch(`${SELF}?action=${action}`, {method:"POST", body});
  return r.json();
}

/* ---------------- Leaflet geographic map ---------------- */
const map = L.map('cityMap', { zoomControl:true, attributionControl:false }).setView([DEPOT.lat, DEPOT.lng], 10);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:18 }).addTo(map);
L.marker([DEPOT.lat, DEPOT.lng], {
  icon: L.divIcon({ className:'', html:'<div style="width:16px;height:16px;background:#C97A3D;border:2px solid #1B1917;border-radius:3px;"></div>' })
}).addTo(map).bindTooltip('Collection Depot — Meru Town');
let binMarkers = {};
let mapFitted = false;

function renderMap(bins){
  bins.forEach(b=>{
    const color = statusColor(b.status);
    const popupHtml = `<b>${b.id}</b><br>Fill: ${Math.round(b.fill)}%<br>Temp: ${b.temp.toFixed(1)}°C · Humidity: ${Math.round(b.humidity)}%`;
    if (binMarkers[b.id]) {
      binMarkers[b.id].setLatLng([b.lat, b.lng]);
      binMarkers[b.id].setStyle({ color: color, fillColor: color });
      binMarkers[b.id].setPopupContent(popupHtml);
    } else {
      const m = L.circleMarker([b.lat, b.lng], { radius:8, color, fillColor:color, fillOpacity:0.85, weight:2 }).addTo(map);
      m.bindPopup(popupHtml);
      m.bindTooltip(b.id, { permanent:true, direction:'top', offset:[0,-9], className:'bin-label' });
      binMarkers[b.id] = m;
    }
  });
  document.getElementById('mapCount').textContent = bins.length;
  if (!mapFitted && bins.length) {
    const pts = bins.map(b=>[b.lat,b.lng]).concat([[DEPOT.lat, DEPOT.lng]]);
    map.fitBounds(L.latLngBounds(pts), { padding:[36,36] });
    mapFitted = true;
  }
}

function renderList(bins){
  const list = document.getElementById('binList');
  const sorted = [...bins].sort((a,b)=>b.fill-a.fill);
  list.innerHTML = sorted.map(b=>{
    const color = statusColor(b.status);
    const adminBtns = IS_ADMIN ? `<button class="mini-btn danger" onclick="toggleOffline('${b.id}')">${b.offline?'Bring online':'Take offline'}</button><button class="mini-btn danger" onclick="deleteBin('${b.id}')">Delete</button>` : '';
    return `<div class="bin-card">
      <div class="bin-card-top">
        <span class="dot" style="background:${color}"></span>
        <span class="bin-name">${b.id} <span class="zone-tag">${b.zone}</span></span>
        <span class="tiny">batt ${Math.round(b.battery)}%</span>
      </div>
      <div class="sensor-row">
        <div class="sensor" title="Ultrasonic distance sensor"><div class="sv" style="color:${color}">${Math.round(b.fill)}%</div><div class="sl">Fill</div></div>
        <div class="sensor" title="DHT22 temperature"><div class="sv">${b.temp.toFixed(1)}°</div><div class="sl">Temp</div></div>
        <div class="sensor" title="DHT22 humidity"><div class="sv">${Math.round(b.humidity)}%</div><div class="sl">Humidity</div></div>
      </div>
      <div class="bin-actions">
        <div class="bin-fill-bar"><div class="fill" style="width:${Math.min(b.fill,100)}%;background:${color}"></div></div>
        <button class="mini-btn" onclick="collectBin('${b.id}')">Collect</button>
        ${adminBtns}
      </div>
    </div>`;
  }).join("");
  document.getElementById('listCount').textContent = bins.length;
}

function renderNotifs(notifs){
  const el = document.getElementById('notifFeed');
  el.innerHTML = notifs.length ? notifs.slice(0,14).map(n=>`
    <div class="notif ${n.level} ${n.read?'':'unread'}" onclick="markRead('${n.id}')">
      <div class="badge">${n.level.toUpperCase()}</div>
      <div style="flex:1;">
        <strong style="display:block;font-size:12px;">${n.msg}</strong>
        <span class="t">${n.time}</span>
        ${n.channels.map(c=>`<span class="chan">${c==='sms'?'SMS':c==='email'?'EMAIL':'ONLINE'}</span>`).join('')}
      </div>
    </div>`).join("") : `<div class="tiny" style="text-align:center;padding:16px 0;">No notifications yet — monitoring...</div>`;
  document.getElementById('notifCount').textContent = notifs.filter(n=>!n.read).length;

  notifs.filter(n=>n.level==='crit' && !notifiedIds.has(n.id)).forEach(n=>{
    notifiedIds.add(n.id);
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('Waste Management Alert', { body: n.msg });
    }
  });
}

function renderRoute(route){
  const el = document.getElementById('routeList');
  const btn = document.getElementById('dispatchBtn');
  if (!route.stops.length){
    el.innerHTML = `<div class="tiny" style="padding:8px 0;">No bins currently need collection.</div>`;
    if (btn) btn.disabled = true;
  } else {
    el.innerHTML = route.stops.map((b,i)=>`
      <div class="route-item">
        <div class="route-idx">${i+1}</div>
        <div>${b.id} <span class="tiny">Zone ${b.zone}</span></div>
        <div class="route-dist">${Math.round(b.fill)}% full</div>
      </div>`).join("") + `<div class="tiny" style="margin-top:8px;">Total distance: ${route.distance} km</div>`;
    if (btn) btn.disabled = false;
  }
  document.getElementById('routeCount').textContent = route.stops.length;
}

function renderTrend(history){
  document.getElementById('trendBars').innerHTML = history.map(h=>`<div class="bar" style="height:${Math.max(2,h.avg)}%" title="${h.label}: ${h.avg}%"></div>`).join("");
}

function renderStats(bins, collectedToday){
  const online = bins.filter(b=>b.status!=='offline').length;
  const warn = bins.filter(b=>b.status==='warn').length;
  const crit = bins.filter(b=>b.status==='crit').length;
  document.getElementById('stOnline').textContent = online + '/' + bins.length;
  document.getElementById('stWarn').textContent = warn;
  document.getElementById('stCrit').textContent = crit;
  document.getElementById('stCollected').textContent = collectedToday;
}

let profileLoaded = false;
async function refresh(){
  const data = await api('state');
  renderMap(data.bins); renderList(data.bins); renderNotifs(data.notifications);
  renderRoute(data.route); renderTrend(data.history); renderStats(data.bins, data.collections_today);
  if (!profileLoaded && data.me) {
    document.getElementById('myPhone').value = data.me.phone || '';
    document.getElementById('myEmail').value = data.me.email || '';
    profileLoaded = true;
  }
}

document.getElementById('saveProfileBtn').addEventListener('click', async ()=>{
  const phone = document.getElementById('myPhone').value.trim();
  const email = document.getElementById('myEmail').value.trim();
  const res = await api('update_profile', {phone, email}, 'POST');
  const btn = document.getElementById('saveProfileBtn');
  btn.textContent = res.ok ? 'Saved ✓' : 'Failed to save';
  setTimeout(()=>{ btn.textContent = 'Save Contact Info'; }, 1800);
});

async function collectBin(id){ await api('collect', {id}, 'POST'); refresh(); }
async function toggleOffline(id){ await api('toggle_offline', {id}, 'POST'); refresh(); }
async function deleteBin(id){ if(!confirm(`Remove ${id} from the network? This cannot be undone.`)) return; await api('delete_bin', {id}, 'POST'); refresh(); }
async function markRead(id){ await api('mark_read', {id}, 'POST'); refresh(); }
window.collectBin = collectBin; window.toggleOffline = toggleOffline; window.deleteBin = deleteBin; window.markRead = markRead;

const dispatchBtn = document.getElementById('dispatchBtn');
if (dispatchBtn) dispatchBtn.addEventListener('click', async ()=>{ await api('dispatch_route', {}, 'POST'); refresh(); });

const addBinBtn = document.getElementById('addBinBtn');
if (addBinBtn) addBinBtn.addEventListener('click', async ()=>{
  const id = document.getElementById('newBinId').value.trim();
  const zone = document.getElementById('newBinZone').value;
  if (!id) return;
  const res = await api('add_bin', {id, zone}, 'POST');
  if (res.ok){ document.getElementById('newBinId').value=''; refresh(); } else alert(res.error || 'Could not add bin');
});

if (IS_ADMIN){
  async function refreshUsers(){
    const res = await api('list_users');
    if (!res.ok) return;
    document.getElementById('userCount').textContent = res.users.length;
    document.getElementById('userList').innerHTML = res.users.map(u=>`
      <div class="user-row">
        <span class="dot" style="background:${u.active?'#4CD98A':'#5A6670'}"></span>
        <span>${u.name}</span><span class="tiny">@${u.username}</span>
        <span class="tiny" style="flex:1 1 100%;">${u.phone||'no phone'} · ${u.email||'no email'}</span>
        <select class="mini-btn" style="padding:4px 6px;" onchange="setUserRole('${u.username}', this.value)">
          <option value="service_team" ${u.role==='service_team'?'selected':''}>Service Team</option>
          <option value="admin" ${u.role==='admin'?'selected':''}>Admin</option>
        </select>
        <button class="mini-btn" onclick="toggleUserActive('${u.username}')">${u.active?'Deactivate':'Activate'}</button>
        <button class="mini-btn danger" onclick="deleteUser('${u.username}')">Delete</button>
      </div>`).join("");
  }
  window.toggleUserActive = async function(username){
    const res = await api('toggle_user_active', {username}, 'POST');
    if (!res.ok) alert(res.error || 'Could not update account'); refreshUsers();
  };
  window.setUserRole = async function(username, role){
    const res = await api('set_user_role', {username, role}, 'POST');
    if (!res.ok) alert(res.error || 'Could not update role'); refreshUsers();
  };
  window.deleteUser = async function(username){
    if (!confirm(`Remove account "${username}"? This cannot be undone.`)) { refreshUsers(); return; }
    const res = await api('delete_user', {username}, 'POST');
    if (!res.ok) alert(res.error || 'Could not delete account'); refreshUsers();
  };
  document.getElementById('addUserBtn').addEventListener('click', async ()=>{
    const name = document.getElementById('newUserName').value.trim();
    const username = document.getElementById('newUsername').value.trim();
    const password = document.getElementById('newPassword').value;
    const role = document.getElementById('newRole').value;
    const phone = document.getElementById('newPhone').value.trim();
    const email = document.getElementById('newEmail').value.trim();
    if (!username || !password) return;
    const res = await api('add_user', {name, username, password, role, phone, email}, 'POST');
    if (res.ok){ ['newUserName','newUsername','newPassword','newPhone','newEmail'].forEach(i=>document.getElementById(i).value=''); refreshUsers(); }
    else alert(res.error || 'Could not add account');
  });
  refreshUsers();
}


refresh();
setInterval(refresh, 3000);
<?php endif; ?>
</script>
</body>
</html>