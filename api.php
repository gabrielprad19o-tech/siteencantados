<?php
// Encantados v3 - API (PHP 7.4+ com pdo_sqlite). game.db é criado/migrado automaticamente.
session_start();
header('Content-Type: application/json; charset=utf-8');
$db = new PDO('sqlite:' . __DIR__ . '/game.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_TIMEOUT, 8);
try { $db->exec('PRAGMA busy_timeout=8000'); $db->exec('PRAGMA journal_mode=WAL'); } catch (Exception $e) {}
// Migrações rodam só uma vez (antes rodavam a cada requisição e travavam o banco => jogador aparecia offline)
$MIG = (int)$db->query('PRAGMA user_version')->fetchColumn() < 6;
if ($MIG) {
$db->exec("CREATE TABLE IF NOT EXISTS players(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE COLLATE NOCASE, pass TEXT, avatar TEXT, title TEXT, trophies INTEGER DEFAULT 0, gold INTEGER DEFAULT 200, wins INTEGER DEFAULT 0, losses INTEGER DEFAULT 0, clan_id INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS clans(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE COLLATE NOCASE, descr TEXT, leader_id INTEGER)");
foreach (["gems INTEGER DEFAULT 20", "owned TEXT", "chests TEXT", "best INTEGER DEFAULT 0", "streak INTEGER DEFAULT 0", "since INTEGER", "seen INTEGER DEFAULT 0"] as $c) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) {} }
}

const RANKED_MIN = 4000;
// Arenas ranqueadas: [troféus mínimos, nome]
const RANKED = [[4000, 'Liga do Cocar'], [6000, 'Liga da Pedra Pintada'], [9000, 'Liga da Lua Cheia'], [13000, 'Liga do Trovão'], [18000, 'Panteão dos Encantados']];
const EMOTES = ['e:😂', 'e:😭', 'e:😡', 'e:👍', 'e:😱', 'e:🤔', 'e:😎', 'e:🥳'];
const AVATARS = ['🧝', '🧙', '🦊', '🐺', '🌿', '🔥', '🧜‍♀️', '🪶', '🐆', '🦜', '🐍', '🦉', '🐊', '🦇', '🐗', '🐬', '🌙', '☀️', '⚡', '👹', '🧚', '🧛', '🧞', '🐉'];
const TITLES = ['Guardião da Mata', 'Curandeira do Sertão', 'Mestre das Águas', 'Caçador de Assombrações', 'Pajé das Estrelas', 'Domador de Boiúnas', 'Cria do Saci', 'Benzedeira de Respeito', 'Rei do Folclore', 'Rainha da Lua Cheia', 'Contador de Causos', 'Andarilho da Caatinga', 'Senhor dos Trovões', 'Feiticeiro do Mangue', 'Lenda Viva', 'Terror das Torres'];
// raridade: 0 comum, 1 raro, 2 épico, 3 lendário
const RARITY = ['saci'=>0,'negrinho'=>0,'uirapuru'=>0,'matinta'=>0,'caipora'=>0,'curupira'=>0,'iara'=>0,'cuia'=>0,'pisadeira'=>0,'anhanga'=>1,'mula'=>1,'boto'=>1,'boi'=>1,'capelobo'=>1,'corpo'=>1,'curacanga'=>1,'tutu'=>2,'ipupiara'=>2,'lobis'=>2,'cuca'=>2,'yacu'=>2,'jurupari'=>2,'boitata'=>3,'boiuna'=>3,'maeouro'=>3,'onca'=>3,'mapin'=>3,'tupa'=>4,'jaci'=>4,'guaraci'=>4,'romao'=>0,'ahoaho'=>0,'papafigo'=>1,'bradador'=>1,'alamoa'=>2,'quibungo'=>2,'labatut'=>3,'norato'=>3,'paimato'=>3,'ceuci'=>4,'flechas'=>0,'bolafogo'=>1,'veneno'=>1,'cura'=>1,'geada'=>2,'tornado'=>2,'raio'=>3,'oca'=>1,'totem'=>1,'ninho'=>2,'tapera'=>2];
const CARD_AR = ['saci'=>0,'negrinho'=>0,'uirapuru'=>0,'matinta'=>0,'caipora'=>0,'curupira'=>0,'iara'=>0,'cuia'=>1,'pisadeira'=>1,'anhanga'=>1,'mula'=>0,'boto'=>1,'boi'=>0,'capelobo'=>2,'corpo'=>3,'curacanga'=>4,'tutu'=>2,'ipupiara'=>5,'lobis'=>2,'cuca'=>3,'yacu'=>4,'jurupari'=>5,'boitata'=>5,'boiuna'=>6,'maeouro'=>6,'onca'=>7,'mapin'=>7,'tupa'=>8,'jaci'=>8,'guaraci'=>9,'romao'=>10,'ahoaho'=>10,'papafigo'=>11,'bradador'=>12,'alamoa'=>13,'quibungo'=>14,'labatut'=>15,'norato'=>16,'paimato'=>17,'ceuci'=>19,'flechas'=>0,'bolafogo'=>0,'veneno'=>1,'cura'=>2,'geada'=>3,'tornado'=>4,'raio'=>5,'oca'=>1,'totem'=>2,'ninho'=>3,'tapera'=>6]; // arena mínima de cada carta
const ARENA_T = [0,150,300,500,800,1200,1700,2300,3000,4000,5000,6200,7500,9000,10500,12000,14000,16000,18500,21000];
if ($MIG) {
$db->exec("CREATE TABLE IF NOT EXISTS clan_msgs(id INTEGER PRIMARY KEY AUTOINCREMENT, clan_id INTEGER, player_id INTEGER, name TEXT, avatar TEXT, text TEXT, t INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS matches(id INTEGER PRIMARY KEY AUTOINCREMENT, host INTEGER, guest INTEGER, status TEXT, created INTEGER, ht INTEGER DEFAULT 0, gt INTEGER DEFAULT 0, hseen INTEGER, gseen INTEGER, result TEXT, invite INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS friends(a INTEGER, b INTEGER, status TEXT, PRIMARY KEY(a,b))");
foreach (["clan_msgs" => "img TEXT", "matches" => "mode TEXT"] as $tb => $c) { try { $db->exec("ALTER TABLE $tb ADD COLUMN $c"); } catch (Exception $e) {} }
$db->exec("CREATE TABLE IF NOT EXISTS moves(id INTEGER PRIMARY KEY AUTOINCREMENT, match_id INTEGER, side INTEGER, tick INTEGER, card TEXT, x REAL, y REAL)");
try { $db->exec("CREATE INDEX IF NOT EXISTS moves_m ON moves(match_id,id)"); } catch (Exception $e) {}
$db->exec("PRAGMA user_version=6");
}
const ONLINE = 90; // segundos sem sinal até contar como offline
const PVP_TICKS = 5400; // 120 s + 60 s de morte súbita a 30 ticks/s
function arenaOf($t) { $a = 0; foreach (ARENA_T as $i => $x) { if ($t >= $x) $a = $i; } return $a; }
const START = ['saci','negrinho','caipora','curupira','iara','uirapuru','mula','boi','flechas'];
const PRICE = [300, 800, 2000, 5000, 12000];   // gemas (caro de propósito)
const DUP = [15, 40, 100, 300, 800];         // ouro por carta repetida
// Loja de moedas (ouro): baús, gemas e carta surpresa
const GOLD_SHOP = ['madeira'=>150, 'prata'=>450, 'ouro'=>1200, 'lendario'=>3000, 'gems10'=>500, 'gems60'=>2500, 'carta'=>900];
const CHESTS = ['madeira'=>[1,[70,25,5,0,0],2], 'prata'=>[2,[48,35,14,3,0],4], 'ouro'=>[3,[28,40,24,7,1],8], 'lendario'=>[4,[10,30,35,20,5],15]]; // cartas, pesos de raridade, máx. gemas

function out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m) { http_response_code(400); out(['error' => $m]); }
function q($sql, $p = []) { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }
function J($s, $d) { $v = json_decode($s ?? '', true); return is_array($v) ? $v : $d; }
function sv($id, $f, $v) { q("UPDATE players SET $f=? WHERE id=?", [json_encode(array_values($v)), $id]); }
function rr($w) { $r = mt_rand(1, array_sum($w)); foreach ($w as $i => $x) { if (($r -= $x) <= 0) return $i; } return 0; }
function me() {
    if (empty($_SESSION['uid'])) return null;
    $u = q("SELECT p.id,p.name,p.avatar,p.title,p.trophies,p.gold,p.gems,p.wins,p.losses,p.best,p.streak,p.clan_id,p.owned,p.chests,c.name AS clan
            FROM players p LEFT JOIN clans c ON c.id=p.clan_id WHERE p.id=?", [$_SESSION['uid']])->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    try { q("UPDATE players SET seen=? WHERE id=? AND COALESCE(seen,0)<?", [time(), $u['id'], time() - 5]); } catch (Exception $e) {}
    $u['freq'] = (int)q("SELECT COUNT(*) FROM friends WHERE b=? AND status='pending'", [$u['id']])->fetchColumn();
    $u['owned'] = J($u['owned'], START); $u['chests'] = J($u['chests'], []); $u['gems'] = (int)$u['gems']; $u['streak'] = (int)$u['streak']; $u['best'] = max((int)$u['best'], (int)$u['trophies']);
    return $u;
}
// Sorteia uma carta ainda não obtida (da arena do jogador). Comuns saem mais.
function grantCard($u) {
    $ua = arenaOf($u['trophies']); $pool = [];
    foreach (RARITY as $k => $r) if (CARD_AR[$k] <= $ua && !in_array($k, $u['owned'])) $pool[$k] = [60, 30, 12, 4, 1][$r];
    if (!$pool) return null;
    $x = mt_rand(1, array_sum($pool));
    foreach ($pool as $k => $w) { if (($x -= $w) <= 0) { $own = $u['owned']; $own[] = $k; q("UPDATE players SET owned=? WHERE id=?", [json_encode($own), $u['id']]); return $k; } }
    return null;
}
function fid($name) {
    $s = trim((string)($name ?? '')); if ($s === '') return false;
    if (preg_match('/^#?\s*(\d+)$/', $s, $m)) { $i = q("SELECT id FROM players WHERE id=?", [(int)$m[1]])->fetchColumn(); if ($i) return $i; }
    $i = q("SELECT id FROM players WHERE name=? COLLATE NOCASE", [$s])->fetchColumn(); if ($i) return $i;
    $l = q("SELECT id FROM players WHERE LOWER(TRIM(name))=LOWER(?) OR name LIKE ? ORDER BY LENGTH(name) LIMIT 2", [$s, $s . '%'])->fetchAll(PDO::FETCH_COLUMN);
    return count($l) === 1 ? $l[0] : (count($l) ? $l[0] : false);
}
function need() { $u = me(); if (!$u) fail('Faça login primeiro.'); return $u; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? '';

switch ($a) {
    case 'register':
        $n = trim($in['name'] ?? ''); $p = $in['pass'] ?? '';
        if (!preg_match('/^[\p{L}\p{N}_ ]{3,16}$/u', $n)) fail('Nome: 3 a 16 letras ou números.');
        if (strlen($p) < 4) fail('Senha: mínimo de 4 caracteres.');
        $av = in_array($in['avatar'] ?? '', AVATARS) ? $in['avatar'] : AVATARS[0];
        $ti = in_array($in['title'] ?? '', TITLES) ? $in['title'] : TITLES[0];
        if (q("SELECT 1 FROM players WHERE name=?", [$n])->fetch()) fail('Esse nome já está em uso.');
        q("INSERT INTO players(name,pass,avatar,title,owned,chests,since) VALUES(?,?,?,?,?,'[]',?)", [$n, password_hash($p, PASSWORD_DEFAULT), $av, $ti, json_encode(START), time() * 1000]);
        $_SESSION['uid'] = $db->lastInsertId();
        out(['user' => me()]);

    case 'login':
        $r = q("SELECT id,pass FROM players WHERE name=?", [trim($in['name'] ?? '')])->fetch(PDO::FETCH_ASSOC);
        if (!$r || !password_verify($in['pass'] ?? '', $r['pass'])) fail('Nome ou senha incorretos.');
        $_SESSION['uid'] = $r['id'];
        out(['user' => me()]);

    case 'logout': session_destroy(); out(['ok' => 1]);
    case 'me': out(['user' => me()]);
    case 'ping': // batimento: mantém o jogador online mesmo dentro da batalha
        $u = me(); out(['ok' => $u ? 1 : 0, 'freq' => $u ? $u['freq'] : 0]);
    case 'gold_buy':
        $u = need(); $it = $in['item'] ?? '';
        if (!isset(GOLD_SHOP[$it])) fail('Item inexistente.');
        $p = GOLD_SHOP[$it];
        if ($u['gold'] < $p) fail('Moedas insuficientes.');
        $got = null;
        if (isset(CHESTS[$it])) {
            if (count($u['chests']) >= 4) fail('Seus 4 espaços de baú estão cheios. Abra um antes.');
            $ch = $u['chests']; $ch[] = $it; sv($u['id'], 'chests', $ch); $got = 'Baú de ' . $it;
        } elseif ($it === 'gems10' || $it === 'gems60') {
            q("UPDATE players SET gems=gems+? WHERE id=?", [$it === 'gems10' ? 10 : 60, $u['id']]); $got = ($it === 'gems10' ? 10 : 60) . ' 💎';
        } else {
            $got = grantCard($u); if (!$got) fail('Você já tem todas as cartas da sua arena!');
        }
        q("UPDATE players SET gold=gold-? WHERE id=?", [$p, $u['id']]);
        out(['user' => me(), 'got' => $got, 'card' => isset(RARITY[$got]) ? $got : null]);

    case 'ranking':
        out(['list' => q("SELECT p.name,p.avatar,p.title,p.trophies,p.wins,c.name AS clan FROM players p
            LEFT JOIN clans c ON c.id=p.clan_id ORDER BY p.trophies DESC, p.wins DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC)]);

    case 'player':
        $p = q("SELECT p.name,p.avatar,p.title,p.trophies,p.best,p.wins,p.losses,p.owned,p.since,c.name AS clan, p.id FROM players p LEFT JOIN clans c ON c.id=p.clan_id WHERE p.id=?", [fid($in['name'] ?? '')])->fetch(PDO::FETCH_ASSOC);
        if (!$p) fail('Jogador não encontrado.');
        $p['cards'] = count(J($p['owned'], START)); unset($p['owned']); $p['best'] = max((int)$p['best'], (int)$p['trophies']);
        out(['p' => $p]);

    case 'profile':
        $u = need();
        if (in_array($in['avatar'] ?? '', AVATARS)) q("UPDATE players SET avatar=? WHERE id=?", [$in['avatar'], $u['id']]);
        if (in_array($in['title'] ?? '', TITLES)) q("UPDATE players SET title=? WHERE id=?", [$in['title'], $u['id']]);
        out(['user' => me()]);

    case 'clans':
        out(['list' => q("SELECT c.id,c.name,c.descr,COUNT(p.id) AS members,COALESCE(SUM(p.trophies),0) AS trophies
            FROM clans c LEFT JOIN players p ON p.clan_id=c.id GROUP BY c.id ORDER BY trophies DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC)]);

    case 'clan_info':
        $u = need();
        if (!$u['clan_id']) out(['clan' => null]);
        $c = q("SELECT id,name,descr,leader_id FROM clans WHERE id=?", [$u['clan_id']])->fetch(PDO::FETCH_ASSOC);
        $c['members'] = q("SELECT id,name,avatar,trophies FROM players WHERE clan_id=? ORDER BY trophies DESC", [$u['clan_id']])->fetchAll(PDO::FETCH_ASSOC);
        out(['clan' => $c]);

    case 'clan_create':
        $u = need();
        if ($u['clan_id']) fail('Saia do clã atual antes de criar outro.');
        if ($u['gold'] < 100) fail('Criar um clã custa 100 de ouro.');
        $n = trim($in['name'] ?? ''); $d = mb_substr(trim($in['descr'] ?? ''), 0, 80);
        if (!preg_match('/^[\p{L}\p{N}_ ]{3,20}$/u', $n)) fail('Nome do clã: 3 a 20 letras ou números.');
        if (q("SELECT 1 FROM clans WHERE name=?", [$n])->fetch()) fail('Já existe um clã com esse nome.');
        q("INSERT INTO clans(name,descr,leader_id) VALUES(?,?,?)", [$n, $d, $u['id']]);
        q("UPDATE players SET clan_id=?, gold=gold-100 WHERE id=?", [$db->lastInsertId(), $u['id']]);
        out(['user' => me()]);

    case 'clan_join':
        $u = need();
        if ($u['clan_id']) fail('Você já está em um clã.');
        $id = (int)($in['id'] ?? 0);
        if (!q("SELECT 1 FROM clans WHERE id=?", [$id])->fetch()) fail('Clã não encontrado.');
        if (q("SELECT COUNT(*) FROM players WHERE clan_id=?", [$id])->fetchColumn() >= 30) fail('Clã lotado (30 membros).');
        q("UPDATE players SET clan_id=? WHERE id=?", [$id, $u['id']]);
        out(['user' => me()]);

    case 'clan_leave':
        $u = need();
        if (!$u['clan_id']) fail('Você não está em um clã.');
        q("UPDATE players SET clan_id=NULL WHERE id=?", [$u['id']]);
        $next = q("SELECT id FROM players WHERE clan_id=? ORDER BY trophies DESC LIMIT 1", [$u['clan_id']])->fetchColumn();
        if ($next) q("UPDATE clans SET leader_id=? WHERE id=? AND leader_id=?", [$next, $u['clan_id'], $u['id']]);
        else q("DELETE FROM clans WHERE id=?", [$u['clan_id']]);
        out(['user' => me()]);

    case 'battle_start':
        $u = need();
        $m = ($in['mode'] ?? '') === 'ranked' ? 'ranked' : 'casual';
        $d = max(0, min(2, (int)($in['diff'] ?? 1)));
        $L = max(0, min(count(RANKED) - 1, (int)($in['league'] ?? 0)));
        if ($m === 'ranked') { if ($u['trophies'] < RANKED[$L][0]) fail(RANKED[$L][1] . ' abre com ' . RANKED[$L][0] . ' troféus.'); $d = 2; }
        $_SESSION['b'] = ['mode' => $m, 'diff' => $d, 't' => time(), 'league' => $L];
        out(['ok' => 1]);

    case 'battle_end':
        $u = need();
        $b = $_SESSION['b'] ?? null; unset($_SESSION['b']);
        if (!$b || time() - $b['t'] < 15) fail('Batalha inválida.');
        $r = $in['r'] ?? ''; $rk = $b['mode'] === 'ranked'; $d = $b['diff']; $L = (int)($b['league'] ?? 0); $w = $r === 'win';
        $win = $rk ? 45 + 5 * $L : [20, 30, 40][$d]; $los = $rk ? 25 : [10, 15, 20][$d];
        $dt = $w ? $win : ($r === 'loss' ? -$los : 0);
        $g = $w ? ($rk ? 60 + 25 * $L : 30 + 15 * $d) : 8;
        $gm = $w ? ($rk ? 3 + $L : [1, 2, 3][$d]) : 0;
        $streak = $w ? $u['streak'] + 1 : 0; $sb = ($w && $streak >= 3) ? 10 * min($streak, 6) : 0; $g += $sb;
        $chest = null;
        if ($w && mt_rand(1, 100) <= ($rk ? 100 : [50, 70, 90][$d]) && count($u['chests']) < 4) {
            $x = mt_rand(1, 100);
            if ($rk) $chest = $x <= 6 + 4 * $L ? 'lendario' : ($x <= 30 + 5 * $L ? 'ouro' : ($x <= 75 ? 'prata' : 'madeira'));
            else $chest = $d === 2 ? ($x <= 4 && arenaOf($u['trophies']) >= 10 ? 'lendario' : ($x <= 18 ? 'ouro' : ($x <= 60 ? 'prata' : 'madeira'))) : ($d === 1 && $x <= 30 ? 'prata' : 'madeira');
            $ch = $u['chests']; $ch[] = $chest; sv($u['id'], 'chests', $ch);
        }
        // Carta nova: chance por vitória + garantida a cada 5 vitórias
        $card = null; $ms = $w && ($u['wins'] + 1) % 5 === 0;
        if ($w && ($ms || mt_rand(1, 100) <= ($rk ? 45 : [15, 25, 35][$d]))) { $card = grantCard($u); if (!$card) $g += 100; }
        $nt = max(0, $u['trophies'] + $dt);
        q("UPDATE players SET trophies=?, gold=gold+?, gems=gems+?, wins=wins+?, losses=losses+?, streak=?, best=MAX(COALESCE(best,0),?) WHERE id=?",
          [$nt, $g, $gm, $w ? 1 : 0, $r === 'loss' ? 1 : 0, $streak, $nt, $u['id']]);
        out(['user' => me(), 'delta' => $nt - $u['trophies'], 'gold' => $g, 'gems' => $gm, 'chest' => $chest, 'streak' => $streak, 'sb' => $sb, 'card' => $card, 'milestone' => $ms]);

    case 'chest_open':
        $u = need(); $i = (int)($in['i'] ?? -1); $ch = $u['chests'];
        if (!isset($ch[$i])) fail('Baú não encontrado.');
        $t = $ch[$i]; array_splice($ch, $i, 1);
        [$n, $w, $gmax] = CHESTS[$t];
        $own = $u['owned']; $got = []; $gold = 10 * $n;
        for ($k = 0; $k < $n; $k++) {
            $rar = rr($w); $ua = arenaOf($u['trophies']);
            do { $pool = array_keys(array_filter(RARITY, fn($q, $k) => $q === $rar && CARD_AR[$k] <= $ua, ARRAY_FILTER_USE_BOTH)); } while (!$pool && $rar-- > 0);
            $id = $pool[array_rand($pool)];
            if (in_array($id, $own)) { $gold += DUP[$rar]; $got[] = ['id' => $id, 'new' => false, 'gold' => DUP[$rar]]; }
            else { $own[] = $id; $got[] = ['id' => $id, 'new' => true]; }
        }
        $gems = mt_rand(0, $gmax);
        q("UPDATE players SET owned=?, chests=?, gold=gold+?, gems=gems+? WHERE id=?", [json_encode($own), json_encode(array_values($ch)), $gold, $gems, $u['id']]);
        out(['user' => me(), 'got' => $got, 'gems' => $gems, 'gold' => $gold]);

    case 'shop_buy':
        $u = need(); $id = $in['id'] ?? '';
        if (!isset(RARITY[$id])) fail('Carta inexistente.');
        if (in_array($id, $u['owned'])) fail('Você já tem essa carta.');
        if (CARD_AR[$id] > arenaOf($u['trophies'])) fail('Essa lenda ainda não apareceu na sua arena.');
        $p = PRICE[RARITY[$id]];
        if ($u['gems'] < $p) fail('Gemas insuficientes.');
        $own = $u['owned']; $own[] = $id;
        q("UPDATE players SET owned=?, gems=gems-? WHERE id=?", [json_encode($own), $p, $u['id']]);
        out(['user' => me()]);

    case 'clan_chat':
        $u = need(); if (!$u['clan_id']) fail('Você não está em um clã.');
        $l = q("SELECT id,name,avatar,text,img,t FROM clan_msgs WHERE clan_id=? AND id>? ORDER BY id DESC LIMIT 50", [$u['clan_id'], (int)($in['since'] ?? 0)])->fetchAll(PDO::FETCH_ASSOC);
        out(['list' => array_reverse($l)]);

    case 'clan_chat_send':
        $u = need(); if (!$u['clan_id']) fail('Você não está em um clã.');
        $t = trim(mb_substr(preg_replace('/\s+/u', ' ', $in['text'] ?? ''), 0, 200));
        $img = $in['img'] ?? null;
        if ($img !== null && $img !== '') {
            if (!is_string($img) || strlen($img) > 400000 || !preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $img)) fail('Foto inválida ou muito grande.');
        } else $img = null;
        if ($t === '' && !$img) fail('Mensagem vazia.');
        $last = q("SELECT t FROM clan_msgs WHERE player_id=? ORDER BY id DESC LIMIT 1", [$u['id']])->fetchColumn();
        if ($last && microtime(true) * 1000 - $last < 1500) fail('Calma! Espere um pouquinho para mandar outra.');
        q("INSERT INTO clan_msgs(clan_id,player_id,name,avatar,text,img,t) VALUES(?,?,?,?,?,?,?)", [$u['clan_id'], $u['id'], $u['name'], $u['avatar'], $t, $img, (int)(microtime(true) * 1000)]);
        q("DELETE FROM clan_msgs WHERE clan_id=? AND id NOT IN (SELECT id FROM clan_msgs WHERE clan_id=? ORDER BY id DESC LIMIT 200)", [$u['clan_id'], $u['clan_id']]);
        out(['ok' => 1]);

    // ---------- Jogador contra jogador (ao vivo, lockstep por polling) ----------
    case 'pvp_queue':
        $u = need(); $now = time();
        q("UPDATE matches SET status='gone' WHERE status='wait' AND hseen<?", [$now - 15]);
        q("DELETE FROM matches WHERE status='wait' AND host=?", [$u['id']]);
        $inv = trim($in['invite'] ?? ''); $mode = ($in['mode'] ?? '') === 'duo' ? 'duo' : 'pvp';
        if ($mode === 'duo' && $inv === '' && empty($in['accept'])) fail('Escolha um amigo para jogar em dupla.');
        if ($inv !== '') {
            $t = q("SELECT id,name,seen FROM players WHERE name=?", [$inv])->fetch(PDO::FETCH_ASSOC);
            if (!$t || $t['id'] == $u['id']) fail('Jogador não encontrado.');
            q("UPDATE matches SET status='gone' WHERE status='wait' AND host=? AND invite=?", [$u['id'], $t['id']]);
            q("INSERT INTO matches(host,status,created,hseen,invite,mode) VALUES(?,'wait',?,?,?,?)", [$u['id'], $now, $now, $t['id'], $mode]);
            out(['m' => (int)$db->lastInsertId(), 'side' => 1, 'status' => 'wait', 'mode' => $mode, 'online' => (int)$t['seen'] >= $now - ONLINE]);
        }
        $acc = (int)($in['accept'] ?? 0);
        $db->beginTransaction();
        $m = $acc ? q("SELECT id,host,mode FROM matches WHERE id=? AND status='wait' AND invite=?", [$acc, $u['id']])->fetch(PDO::FETCH_ASSOC)
                  : q("SELECT id,host,mode FROM matches WHERE status='wait' AND invite IS NULL AND host<>? ORDER BY id LIMIT 1", [$u['id']])->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            q("UPDATE matches SET guest=?, status='live', gseen=?, hseen=? WHERE id=?", [$u['id'], $now, $now, $m['id']]);
            $db->commit();
            out(['m' => (int)$m['id'], 'side' => -1, 'status' => 'live', 'mode' => $m['mode'] ?: 'pvp']);
        }
        $db->commit();
        if ($acc) fail('Esse convite expirou.');
        q("INSERT INTO matches(host,status,created,hseen) VALUES(?,'wait',?,?)", [$u['id'], $now, $now]);
        out(['m' => (int)$db->lastInsertId(), 'side' => 1, 'status' => 'wait']);

    case 'pvp_invites':
        $u = need();
        out(['list' => q("SELECT m.id,m.mode,p.name,p.avatar,p.trophies FROM matches m JOIN players p ON p.id=m.host WHERE m.status='wait' AND m.invite=? AND m.hseen>=?", [$u['id'], time() - 15])->fetchAll(PDO::FETCH_ASSOC)]);

    case 'pvp_decline':
        $u = need();
        q("UPDATE matches SET status='declined' WHERE id=? AND invite=? AND status='wait'", [(int)($in['m'] ?? 0), $u['id']]);
        out(['ok' => 1]);

    case 'pvp_cancel':
        $u = need();
        q("UPDATE matches SET status='gone' WHERE id=? AND host=? AND status='wait'", [(int)($in['m'] ?? 0), $u['id']]);
        out(['ok' => 1]);

    case 'pvp_sync':
        $u = need(); $now = time();
        $m = q("SELECT * FROM matches WHERE id=?", [(int)($in['m'] ?? 0)])->fetch(PDO::FETCH_ASSOC);
        if (!$m || ($m['host'] != $u['id'] && $m['guest'] != $u['id'])) fail('Partida não encontrada.');
        $side = $m['host'] == $u['id'] ? 1 : -1; $me = $side == 1 ? 'h' : 'g'; $op = $side == 1 ? 'g' : 'h';
        if ($m['status'] === 'wait') { q("UPDATE matches SET hseen=? WHERE id=?", [$now, $m['id']]); out(['status' => 'wait']); }
        if ($m['status'] === 'live') {
            $tick = min(PVP_TICKS + 200, max((int)$m[$me . 't'], (int)($in['tick'] ?? 0)));
            $db->beginTransaction();
            foreach (array_slice($in['cmds'] ?? [], 0, 20) as $c) {
                $id = $c['card'] ?? ''; $ct = (int)($c['tick'] ?? 0);
                if ((!isset(RARITY[$id]) && !in_array($id, EMOTES)) || $ct <= (int)$m[$me . 't']) continue;
                q("INSERT INTO moves(match_id,side,tick,card,x,y) VALUES(?,?,?,?,?,?)", [$m['id'], $side, $ct, $id, (float)$c['x'], (float)$c['y']]);
            }
            q("UPDATE matches SET {$me}t=?, {$me}seen=? WHERE id=?", [$tick, $now, $m['id']]);
            $db->commit();
            if ($now - (int)$m[$op . 'seen'] > 20) { // oponente sumiu
                q("UPDATE matches SET status='done', result=? WHERE id=? AND status='live'", [$m['mode'] === 'duo' ? 'draw' : ($side == 1 ? 'host' : 'guest'), $m['id']]);
                $m = q("SELECT * FROM matches WHERE id=?", [$m['id']])->fetch(PDO::FETCH_ASSOC);
            }
        }
        $ot = (int)q("SELECT {$op}t FROM matches WHERE id=?", [$m['id']])->fetchColumn(); // lê o tick antes dos lances
        $mv = q("SELECT id,tick,card,x,y FROM moves WHERE match_id=? AND side=? AND id>? ORDER BY id", [$m['id'], -$side, (int)($in['since'] ?? 0)])->fetchAll(PDO::FETCH_ASSOC);
        $st = q("SELECT status,result FROM matches WHERE id=?", [$m['id']])->fetch(PDO::FETCH_ASSOC);
        $opp = q("SELECT name,avatar,trophies FROM players WHERE id=?", [$side == 1 ? $m['guest'] : $m['host']])->fetch(PDO::FETCH_ASSOC);
        out(['status' => $st['status'], 'result' => $st['result'], 'otick' => $ot, 'moves' => $mv, 'opp' => $opp, 'side' => $side, 'mode' => $m['mode'] ?: 'pvp']);

    case 'pvp_end':
        $u = need();
        $m = q("SELECT * FROM matches WHERE id=?", [(int)($in['m'] ?? 0)])->fetch(PDO::FETCH_ASSOC);
        if (!$m || ($m['host'] != $u['id'] && $m['guest'] != $u['id']) || !$m['guest']) fail('Partida não encontrada.');
        $side = $m['host'] == $u['id'] ? 1 : -1; $me = $side == 1 ? 'host' : 'guest';
        $r = $in['r'] ?? ''; $duo = $m['mode'] === 'duo';
        if ($r === 'quit') $r = $duo ? 'loss' : ($side == 1 ? 'guest' : 'host');
        if (!in_array($r, $duo ? ['win', 'loss', 'draw'] : ['host', 'guest', 'draw'])) fail('Resultado inválido.');
        if ($m['status'] === 'live') { q("UPDATE matches SET status='done', result=? WHERE id=? AND status='live'", [$r, $m['id']]); }
        $m = q("SELECT * FROM matches WHERE id=?", [$m['id']])->fetch(PDO::FETCH_ASSOC);
        $paid = 'paid_' . $m['id'] . '_' . $u['id'];
        $res = $duo ? $m['result'] : ($m['result'] === 'draw' ? 'draw' : ($m['result'] === $me ? 'win' : 'loss'));
        if (!empty($_SESSION[$paid]) || q("SELECT 1 FROM moves WHERE match_id=? AND side=0 AND card=?", [$m['id'], (string)$u['id']])->fetch())
            out(['user' => me(), 'res' => $res, 'delta' => 0, 'gold' => 0]);
        q("INSERT INTO moves(match_id,side,tick,card) VALUES(?,0,0,?)", [$m['id'], (string)$u['id']]); // marca recompensa paga
        $_SESSION[$paid] = 1;
        $dt = $res === 'win' ? ($duo ? 30 : 35) : ($res === 'loss' ? ($duo ? -10 : -20) : 0); $g = $res === 'win' ? ($duo ? 45 : 50) : 10; $gm = $res === 'win' ? 2 : 0;
        $card = null; if ($res === 'win' && mt_rand(1, 100) <= 30) $card = grantCard($u);
        $nt = max(0, $u['trophies'] + $dt);
        q("UPDATE players SET trophies=?, gold=gold+?, gems=gems+?, wins=wins+?, losses=losses+?, best=MAX(COALESCE(best,0),?) WHERE id=?",
          [$nt, $g, $gm, $res === 'win' ? 1 : 0, $res === 'loss' ? 1 : 0, $nt, $u['id']]);
        out(['user' => me(), 'res' => $res, 'delta' => $nt - $u['trophies'], 'gold' => $g, 'gems' => $gm, 'card' => $card]);

    // ---------- Amigos ----------
    case 'friend_add':
        $u = need(); $t = fid($in['name'] ?? '');
        if (!$t || $t == $u['id']) fail('Jogador não encontrado.');
        $ex = q("SELECT a,b,status FROM friends WHERE (a=? AND b=?) OR (a=? AND b=?)", [$u['id'], $t, $t, $u['id']])->fetch(PDO::FETCH_ASSOC);
        if ($ex && $ex['status'] === 'ok') fail('Vocês já são amigos.');
        if ($ex && $ex['a'] == $t) { q("UPDATE friends SET status='ok' WHERE a=? AND b=?", [$t, $u['id']]); out(['ok' => 1, 'msg' => 'Agora vocês são amigos! 🎉']); }
        if ($ex) fail('Pedido já enviado. Aguarde a resposta.');
        if (q("SELECT COUNT(*) FROM friends WHERE (a=? OR b=?) AND status='ok'", [$u['id'], $u['id']])->fetchColumn() >= 100) fail('Limite de 100 amigos.');
        q("INSERT INTO friends(a,b,status) VALUES(?,?,'pending')", [$u['id'], $t]);
        out(['ok' => 1, 'msg' => 'Pedido de amizade enviado!']);

    case 'friend_accept':
        $u = need(); $t = fid($in['name'] ?? '');
        q("UPDATE friends SET status='ok' WHERE a=? AND b=? AND status='pending'", [$t, $u['id']]);
        out(['ok' => 1]);

    case 'friend_remove':
        $u = need(); $t = fid($in['name'] ?? '');
        q("DELETE FROM friends WHERE (a=? AND b=?) OR (a=? AND b=?)", [$u['id'], $t, $t, $u['id']]);
        out(['ok' => 1]);

    case 'friend_list':
        $u = need(); $on = time() - ONLINE;
        $f = q("SELECT p.id,p.name,p.avatar,p.title,p.trophies,(p.seen>=?) AS online FROM friends f JOIN players p ON p.id=(CASE WHEN f.a=? THEN f.b ELSE f.a END)
                WHERE (f.a=? OR f.b=?) AND f.status='ok' ORDER BY online DESC, p.trophies DESC", [$on, $u['id'], $u['id'], $u['id']])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($f as &$x) $x['online'] = (bool)$x['online']; unset($x);
        $in_ = q("SELECT p.id,p.name,p.avatar,p.trophies FROM friends f JOIN players p ON p.id=f.a WHERE f.b=? AND f.status='pending'", [$u['id']])->fetchAll(PDO::FETCH_ASSOC);
        $out_ = q("SELECT p.name,p.avatar FROM friends f JOIN players p ON p.id=f.b WHERE f.a=? AND f.status='pending'", [$u['id']])->fetchAll(PDO::FETCH_ASSOC);
        out(['friends' => $f, 'incoming' => $in_, 'outgoing' => $out_]);

    default: fail('Ação desconhecida.');
}
