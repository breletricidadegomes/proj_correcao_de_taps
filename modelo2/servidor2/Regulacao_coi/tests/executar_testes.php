<?php
/**
 * Testes do Regulação COI (Modelo 2), sem dependências — rodam no PHP 5.5.
 *   php tests/executar_testes.php
 * Usam uma pasta de dados temporária; não tocam no data/ do sistema.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Execute pela linha de comando.');
}
require dirname(__DIR__) . '/src/inicio.php';

$total = 0;
$falhas = 0;
function ok($cond, $desc)
{
    global $total, $falhas;
    $total++;
    echo ($cond ? '  ok    ' : '  FALHA ') . $desc . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function recusa($fn, $desc, $status = null)
{
    try {
        $fn();
        ok(false, $desc . ' (deveria recusar)');
    } catch (ErroApi $e) {
        ok($status === null || $e->status === $status, $desc . ' → ' . $e->getMessage());
    }
}

$dir = sys_get_temp_dir() . '/rcoi_teste_' . getmypid();
$cfg = $config;
$cfg['dir_dados'] = $dir;
$cfg['modo_exemplo'] = true;
$cfg['master'] = array('usuarios' => array(), 'senha_hash' => '');
$db = new BancoJson($dir);
$app = new Regulacao($db, $cfg);

echo "Instalação e usuários\n";
ok($app->precisaInstalar(), 'banco vazio pede o primeiro administrador');
$adm = $app->instalar('Ana Supervisora', 'U1000001', 'senha-forte-1');
ok($adm['perfil'] === 'supervisor' && $adm['adm'], 'primeiro usuário é supervisor ADM');
ok(!$app->precisaInstalar(), 'depois disso não pede mais instalação');
$uEng = $app->salvarUsuario($adm, '', array('nome' => 'Edu Engenheiro', 'usuario' => 'U1000002', 'perfil' => 'engenheiro', 'senha' => 'senha-forte-2'));
$uCtl = $app->salvarUsuario($adm, '', array('nome' => 'Caio Controlador', 'usuario' => 'U1000003', 'perfil' => 'controlador', 'senha' => 'senha-forte-3'));
$uOut = $app->salvarUsuario($adm, '', array('nome' => 'Olga Planejamento', 'usuario' => 'U1000004', 'perfil' => 'outra_area', 'area' => 'Planejamento', 'senha' => 'senha-forte-4'));
$eng = $app->login('U1000002', 'senha-forte-2');
$ctl = $app->login('u1000003', 'senha-forte-3');
$out = $app->login('U1000004', 'senha-forte-4');
ok($eng['perfil'] === 'engenheiro' && $ctl['perfil'] === 'controlador', 'login com senha própria (maiúsculas ou minúsculas)');
recusa(function () use ($app) { $app->login('U1000002', 'errada-123'); }, 'senha errada', 401);
recusa(function () use ($app, $eng) { $app->listarUsuarios($eng); }, 'engenheiro não gerencia usuários', 403);

echo "Equipamentos e simulação\n";
$eqs = $app->equipamentos();
ok(count($eqs) === 4, '4 equipamentos de exemplo (OLTC, BRT, 2 BC)');
$oltc = $app->equipamento(1);
ok($oltc['alimentadores'] === array(1, 2), 'OLTC da SE atende os dois alimentadores');
$cen = array('v_fonte_pu' => 1.0, 'fator_carga' => 1.0, 'fator_gd' => 0);
$s = $app->simularProposta(1, array(array('equipamento_id' => 3, 'estagios' => 2)), $cen);
ok(count($s['alimentadores']) === 1, 'BC de linha afeta só o próprio alimentador');
$a = $s['alimentadores'][0];
ok($a['depois']['v_min_pu'] > $a['antes']['v_min_pu'], 'ligar o BC-0310 (2 × 600 kvar) eleva a tensão mínima');
ok($a['depois']['bcs'][0]['kvar_efetivo'] < 1200 && $a['depois']['bcs'][0]['kvar_efetivo'] > 1000, 'kvar efetivo do BC cai com V² (abaixo de 1,0 p.u.)');
$s2 = $app->simularProposta(1, array(array('equipamento_id' => 1, 'modo' => 'MANUAL', 'tap' => 3)), $cen);
ok(count($s2['alimentadores']) === 2, 'mudar o OLTC simula os dois alimentadores da barra');
$s3 = $app->simularProposta(1, array(array('equipamento_id' => 2, 'modo' => 'AUTO', 'v_ref_pu' => 1.02, 'banda_pu' => 0.02)), $cen);
$p05 = null;
foreach ($s3['alimentadores'][0]['depois']['perfil'] as $n) {
    if ($n['codigo'] === 'P05') {
        $p05 = $n['v_pu'];
    }
}
ok($p05 !== null && abs($p05 - 1.02) <= 0.0101, 'BRT em automático leva o nó regulado a V ref ± banda/2 (P05 = ' . $p05 . ')');
recusa(function () use ($app, $cen) { $app->simularProposta(1, array(array('equipamento_id' => 2, 'modo' => 'MANUAL', 'tap' => 40)), $cen); }, 'tap fora da faixa');
recusa(function () use ($app, $cen) { $app->simularProposta(1, array(array('equipamento_id' => 3, 'estagios' => 5)), $cen); }, 'estágios acima do banco');

echo "Fluxo completo de um ajuste\n";
$sol = $app->solicitar($out, array('titulo' => 'Reclamações de baixa tensão na Vila São José', 'motivo' => 'RECLAMACAO',
    'alimentador_id' => 1, 'descricao' => '3 reclamações na semana', 'protocolo_reclamacao' => 'OUV-123'));
ok($sol['status'] === 'solicitada' && preg_match('/^AJ-\d{4}-00001$/', $sol['protocolo']), 'outra área abre solicitação (' . $sol['protocolo'] . ')');
recusa(function () use ($app, $ctl, $sol) { $app->propor($ctl, $sol['id'], array('alimentador_id' => 1, 'ajustes' => array(array('equipamento_id' => 3, 'estagios' => 2)))); }, 'controlador não cria proposta', 403);
$pro = $app->propor($eng, $sol['id'], array('alimentador_id' => 1, 'cenario' => $cen,
    'ajustes' => array(array('equipamento_id' => 3, 'estagios' => 2), array('equipamento_id' => 2, 'modo' => 'AUTO', 'v_ref_pu' => 1.02, 'banda_pu' => 0.02))));
ok($pro['status'] === 'em_analise' && count($pro['ajustes']) === 2, 'engenheiro transforma a solicitação em proposta com 2 equipamentos');
ok(!empty($pro['simulacao']['alimentadores'][0]['depois']['v_min_pu']), 'proposta guarda o retrato da simulação');
$app->parecer($out, $sol['id'], array('texto' => 'Sem impacto no planejamento.', 'conclusao' => 'favoravel'));
recusa(function () use ($app, $eng, $sol) { $app->decidir($eng, $sol['id'], 'aprovar', ''); }, 'engenheiro não aprova', 403);
recusa(function () use ($app, $ctl, $sol) { $app->executar($ctl, $sol['id'], array('manobra_adms' => 'M1')); }, 'não executa antes de aprovar', 409);
$apr = $app->decidir($adm, $sol['id'], 'aprovar', 'Ok');
ok($apr['status'] === 'aprovada', 'supervisor aprova');
recusa(function () use ($app, $ctl, $sol) { $app->executar($ctl, $sol['id'], array()); }, 'execução exige nº da manobra ADMS ou da nota SIGA');
recusa(function () use ($app, $ctl, $sol) { $app->executar($ctl, $sol['id'], array('manobra_adms' => 'MAN-1', 'executado_em' => date('Y-m-d\TH:i', time() + 86400))); }, 'execução no futuro');
$exe = $app->executar($ctl, $sol['id'], array('manobra_adms' => 'man-2026-778', 'observacao' => 'Telecomando OK'));
ok($exe['status'] === 'executada' && $exe['referencias']['manobra_adms'] === 'MAN-2026-778', 'controlador confirma execução com a manobra do ADMS');
$bc = $app->equipamento(3);
$rt = $app->equipamento(2);
ok($bc['estagios_ligados'] === 2 && $bc['origem_ajuste'] === 'registro' && $bc['registro_vigente'] === $sol['protocolo'], 'ajuste vigente do BC atualizado pelo registro');
ok(abs($rt['v_ref_pu'] - 1.02) < 1e-9 && $rt['modo'] === 'AUTO', 'V ref do BRT atualizada');
$vig = $db->leitura(function () use ($db) { return $db->ler('ajustes_vigentes', array()); });
ok(isset($vig['BC-0310']) && isset($vig['RT-0142']), 'ajuste vigente guardado pelo código do equipamento (estável entre publicações)');
$ver = $app->verificar($eng, $sol['id'], array('resultado' => 'conforme', 'v_min_medido_pu' => '0.951', 'observacao' => 'Medição ADMS ok'));
ok($ver['status'] === 'verificada', 'engenheiro registra a verificação e fecha o registro');
recusa(function () use ($app, $adm, $sol) { $app->cancelar($adm, $sol['id'], 'teste'); }, 'registro verificado não pode ser cancelado', 409);
$ev = $app->eventos($sol['id']);
ok(count($ev) === 6, 'trilha com 6 eventos (solicitação, proposta, parecer, aprovação, execução, verificação)');

echo "Devolução, cancelamento e retroativo\n";
$p2 = $app->propor($eng, '', array('titulo' => 'Baixar OLTC no patamar leve', 'motivo' => 'GERACAO_DISTRIBUIDA', 'alimentador_id' => 2,
    'cenario' => array('fator_carga' => 0.4, 'fator_gd' => 1.0), 'ajustes' => array(array('equipamento_id' => 1, 'modo' => 'MANUAL', 'tap' => -2))));
ok($p2['status'] === 'em_analise' && count($p2['simulacao']['alimentadores']) === 2, 'proposta nova no OLTC simula os dois alimentadores');
recusa(function () use ($app, $adm, $p2) { $app->decidir($adm, $p2['id'], 'devolver', ''); }, 'devolver exige motivo');
$dev = $app->decidir($adm, $p2['id'], 'devolver', 'Avaliar também o BC-0721');
ok($dev['status'] === 'devolvida', 'supervisor devolve');
$p2b = $app->propor($eng, $p2['id'], array('alimentador_id' => 2, 'cenario' => array('fator_carga' => 0.4, 'fator_gd' => 1.0),
    'ajustes' => array(array('equipamento_id' => 1, 'modo' => 'MANUAL', 'tap' => -1), array('equipamento_id' => 4, 'estagios' => 0))));
ok($p2b['status'] === 'em_analise' && count($p2b['ajustes']) === 2, 'engenheiro reapresenta a proposta devolvida');
recusa(function () use ($app, $out, $p2) { $app->cancelar($out, $p2['id'], 'não quero'); }, 'outra área não cancela registro alheio', 403);
$can = $app->cancelar($eng, $p2['id'], 'Substituída por manobra de emergência');
ok($can['status'] === 'cancelada', 'autor cancela com motivo');
$ret = $app->retroativo($eng, array('titulo' => 'Tap do RT-0142 ajustado em campo', 'motivo' => 'SUBTENSAO', 'alimentador_id' => 1,
    'nota_siga' => '300012345', 'executado_em' => date('Y-m-d\TH:i', time() - 3600),
    'ajustes' => array(array('equipamento_id' => 2, 'modo' => 'MANUAL', 'tap' => 4))));
ok($ret['status'] === 'executada' && $ret['execucao']['origem'] === 'SIGA', 'registro retroativo entra executado com origem SIGA');
$rt2 = $app->equipamento(2);
ok($rt2['modo'] === 'AUTO' && $rt2['registro_vigente'] === $sol['protocolo'], 'retroativo mais antigo que o último ajuste não substitui o vigente');
$ret2 = $app->retroativo($eng, array('titulo' => 'Tap do RT-0142 fixado em campo hoje', 'motivo' => 'SUBTENSAO', 'alimentador_id' => 1,
    'nota_siga' => '300012399', 'ajustes' => array(array('equipamento_id' => 2, 'modo' => 'MANUAL', 'tap' => 4))));
$rt3 = $app->equipamento(2);
ok($rt3['tap_atual'] === 4 && $rt3['modo'] === 'MANUAL' && $rt3['registro_vigente'] === $ret2['protocolo'], 'retroativo mais recente passa a ser o ajuste vigente');

echo "Sincronização, log e CSV\n";
$sync = $app->sincronizar($eng, 0);
ok(count((array) $sync['registros']) === 4 && !empty($sync['equipamentos']), 'sync completo traz 4 registros e equipamentos');
$v = $sync['v'];
ok($app->sincronizar($eng, $v) === array('mudou' => false, 'v' => $v), 'sync sem mudança responde só a versão');
$pub = $app->sincronizar(null, 0);
$u = (array) $pub['usuarios'];
$algum = reset($u);
ok(!isset($algum['usuario']), 'sem login, a lista de usuários vem sem os logins');
$logs = $app->lerLogs($adm, array(), 0, 500);
ok($logs['total'] >= 15, 'log com ' . $logs['total'] . ' ações');
recusa(function () use ($app, $eng) { $app->lerLogs($eng, array(), 0, 10); }, 'engenheiro não vê logs', 403);
$csv = file_get_contents($app->caminhoCsv('registros'));
ok(substr($csv, 0, 3) === "\xEF\xBB\xBF" && substr_count($csv, "\r\n") === 5, 'registros.csv para o Excel (BOM, 4 linhas + cabeçalho)');
ok(strpos($csv, 'BC-0310: 0 estágio(s) → 2 estágio(s)') !== false, 'CSV traz o "de → para" dos ajustes');
ok(is_dir($dir . '/backup/' . date('Y-m-d')), 'backup diário criado');

// limpeza
$apagar = function ($d) use (&$apagar) {
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
        if (basename($f) === '.' || basename($f) === '..') {
            continue;
        }
        is_dir($f) ? $apagar($f) : @unlink($f);
    }
    @rmdir($d);
};
$apagar($dir);

echo "\n" . ($total - $falhas) . "/$total testes passaram\n";
exit($falhas > 0 ? 1 : 0);
