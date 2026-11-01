<?php
/**
 * Conferência do servidor: abra depois de publicar.
 * Mostra só "ok / falta", sem dados do banco.
 */
require __DIR__ . '/src/inicio.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$itens = array();
function item(&$itens, $nome, $ok, $detalhe, $obrigatorio = true)
{
    $itens[] = array('nome' => $nome, 'ok' => (bool) $ok, 'detalhe' => $detalhe, 'obrigatorio' => $obrigatorio);
}

item($itens, 'PHP 5.5 ou superior', version_compare(PHP_VERSION, '5.5.0', '>='), 'Versão instalada: ' . PHP_VERSION);
item($itens, 'Extensão json', function_exists('json_encode'), 'No Debian/Ubuntu com PHP 5.5 ela vem separada: apt-get install php5-json');
item($itens, 'Extensão session', function_exists('session_start'), 'Necessária para o login.');
item($itens, 'password_hash (senhas)', function_exists('password_hash'), 'Nativo do PHP 5.5+.');
item($itens, 'flock (trava de arquivo)', function_exists('flock'), 'Evita que duas gravações simultâneas corrompam o banco.');
item($itens, 'Fuso horário', date_default_timezone_get() !== '', 'Atual: ' . date_default_timezone_get() . ' · ' . date('d/m/Y H:i:s'));
item($itens, 'openssl (opcional)', function_exists('openssl_random_pseudo_bytes'), 'Gera tokens mais fortes. Sem ele o sistema usa mt_rand.', false);
item($itens, 'mbstring (opcional)', function_exists('mb_substr'), 'Corta textos longos respeitando acentos.', false);

$dir = $config['dir_dados'];
$ok = false;
$msg = '';
try {
    $db = new BancoJson($dir);
    $ok = $db->gravavel();
    if ($ok) {
        $teste = $db->dir() . '/.teste_escrita';
        $ok = @file_put_contents($teste, 'ok') !== false;
        @unlink($teste);
        $fp = @fopen($db->dir() . '/.trava', 'c');
        $ok = $ok && $fp && flock($fp, LOCK_EX);
        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    $msg = $ok ? 'Gravação e trava funcionando.' : 'Dê permissão de escrita (modificar) para a conta do PHP nesta pasta e nas subpastas eventos/, csv/, backup/, sessoes/ e publicado/.';
} catch (Exception $e) {
    $msg = $e->getMessage();
}
item($itens, 'Pasta do banco: ' . str_replace('\\', '/', $dir), $ok, $msg);
$unc = strpos(str_replace('\\', '/', $dir), '//') === 0 || strpos(str_replace('\\', '/', __DIR__), '//') === 0;
if ($unc) {
    item($itens, 'Pasta de rede (UNC)', $ok, $ok
        ? 'O sistema está num compartilhamento de rede e a conta do PHP consegue gravar e travar arquivos nele.'
        : 'No IIS, o pool de aplicativos precisa rodar com uma conta de domínio que tenha Modificar em \\\\servportaloper\\nucleo\\Regulacao_coi (a identidade padrão do pool não enxerga a rede).');
}
item($itens, 'Tamanho do inteiro do PHP', true, (PHP_INT_SIZE * 8) . ' bits' . (PHP_INT_SIZE === 4 ? ' · PHP de 32 bits (comum no PHP 5.x para Windows). Esta versão grava as datas sem depender disso; registros antigos com "1970" são corrigidos sozinhos.' : ''), false);
try {
    $pub = new Publicado($config['dir_dados'], !empty($config['modo_exemplo']));
    $man = $pub->manifest();
    if ($pub->disponivel()) {
        $idade = isset($man['gerado_em']) ? (time() - strtotime($man['gerado_em'])) / 3600 : 999;
        $limite = isset($config['publicacao_antiga_horas']) ? (float) $config['publicacao_antiga_horas'] : 36;
        item($itens, 'Publicação do servidor 1 (data/publicado)', $idade <= $limite,
            'Gerada em ' . (isset($man['gerado_em']) ? $man['gerado_em'] : '?') . ' · ' . count($pub->alimentadores()) . ' alimentadores · '
            . count($pub->equipamentos()) . ' equipamentos' . ($idade > $limite ? ' · ATENÇÃO: publicação antiga, confira o coletor do servidor 1.' : ''), false);
    } else {
        item($itens, 'Publicação do servidor 1 (data/publicado)', false, !empty($config['modo_exemplo'])
            ? 'Ainda não há publicação: o sistema usa a rede de exemplo. Rode o coletor do servidor 1 (iniciar_coletor.bat --demo --uma-vez para testar a cópia).'
            : 'Sem publicação e com modo_exemplo desligado: a tela fica sem alimentadores. Rode o coletor do servidor 1.', false);
    }
} catch (Exception $e) {
    item($itens, 'Publicação do servidor 1 (data/publicado)', false, $e->getMessage(), false);
}
$rede = new Rede($config);
$ldapCfg = isset($config['ldap']) && is_array($config['ldap']) ? $config['ldap'] : array();
if ($rede->ldapAtivo()) {
    item($itens, 'Extensão LDAP (login pela rede)', Rede::extensaoLdap(), Rede::extensaoLdap()
        ? 'Ativa.' : 'O login de rede está ligado, mas falta a extensão: no php.ini, tire o ";" de extension=php_ldap.dll (ou ldap) e reinicie o IIS.');
    $uri = preg_split('/\s+/', trim((string) $ldapCfg['servidor']));
    $pu = @parse_url($uri[0]);
    $host = isset($pu['host']) ? $pu['host'] : $uri[0];
    $porta = isset($pu['port']) ? (int) $pu['port'] : (isset($pu['scheme']) && strtolower($pu['scheme']) === 'ldaps' ? 636 : 389);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $porta, $errno, $errstr, 4);
    if ($fp) {
        fclose($fp);
    }
    item($itens, 'Servidor de rede (AD) alcançável', (bool) $fp, $fp
        ? 'O servidor do PHP alcança o controlador de domínio configurado (porta ' . $porta . ').'
        : 'Não foi possível abrir a porta ' . $porta . ' do servidor configurado em ldap → servidor (' . $errstr . '). Verifique o nome/IP e o firewall.', true);
} else {
    item($itens, 'Login pela rede (LDAP/AD)', true, 'Desligado (opcional). Extensão LDAP ' . (Rede::extensaoLdap() ? 'disponível' : 'não instalada') . ' neste PHP.', false);
}
item($itens, 'Entrada com o usuário do Windows (SSO)', true, $rede->ssoAtivo()
    ? 'Ligada. Confirme no IIS que só o sso.php tem a Autenticação do Windows (sem a Anônima).'
    : 'Desligada (opcional).', false);
item($itens, 'Usuário do processo PHP', true, function_exists('get_current_user') ? get_current_user() . (getenv('USERNAME') ? ' · ' . getenv('USERDOMAIN') . '\\' . getenv('USERNAME') : '') : '—', false);

$todosOk = true;
foreach ($itens as $i) {
    if ($i['obrigatorio'] && !$i['ok']) {
        $todosOk = false;
    }
}
$h = function ($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico · Regulação COI</title><link rel="icon" href="assets/favicon.png">
<style>
:root{--bg:#EDF1F0;--s:#fff;--ink:#12201F;--ink2:#4B5D5B;--line:#D6DFDD;--ok:#25724A;--oks:#DDF0E4;--crit:#C1272D;--crits:#FBE3E3;--warn:#9A6400;--warns:#FCEFD3;--top:#0E2B31}
@media (prefers-color-scheme:dark){:root{--bg:#0B1414;--s:#121E1E;--ink:#E2ECEA;--ink2:#A3B5B2;--line:#26383A;--ok:#5CCB8C;--oks:#14301F;--crit:#FF7070;--crits:#3A1719;--warn:#F0B44C;--warns:#352812;--top:#071315}}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 system-ui,'Segoe UI',sans-serif}
header{background:var(--top);color:#E7F0EE;padding:12px 16px;display:flex;gap:10px;align-items:center}header img{width:40px;height:40px}
main{max-width:820px;margin:0 auto;padding:20px 16px}
.box{background:var(--s);border:1px solid var(--line);border-radius:10px;overflow:hidden}
.row{display:flex;gap:12px;padding:12px 14px;border-top:1px solid var(--line);align-items:flex-start}.row:first-child{border-top:0}
.tag{flex:none;font-weight:700;font-size:12px;padding:2px 8px;border-radius:999px;min-width:52px;text-align:center}
.ok{background:var(--oks);color:var(--ok)}.no{background:var(--crits);color:var(--crit)}.opt{background:var(--warns);color:var(--warn)}
small{color:var(--ink2);display:block}.res{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-weight:600}
a{color:inherit}
</style></head><body>
<header><img src="assets/coi.png" alt="" onerror="this.style.display='none'"><b>Regulação COI · Diagnóstico do servidor</b></header>
<main>
<div class="res <?php echo $todosOk ? 'ok' : 'no'; ?>"><?php echo $todosOk ? 'Servidor pronto. Abra o sistema em <a href="index.php">index.php</a>.' : 'Há itens obrigatórios faltando. Corrija e recarregue esta página.'; ?></div>
<div class="box">
<?php foreach ($itens as $i) : ?>
<div class="row"><span class="tag <?php echo $i['ok'] ? 'ok' : ($i['obrigatorio'] ? 'no' : 'opt'); ?>"><?php echo $i['ok'] ? 'OK' : ($i['obrigatorio'] ? 'FALTA' : 'OPCIONAL'); ?></span>
<div><b><?php echo $h($i['nome']); ?></b><small><?php echo $h($i['detalhe']); ?></small></div></div>
<?php endforeach; ?>
</div>
</main></body></html>
