<?php
/**
 * Regulação COI (Modelo 2) — tela única. A aplicação roda no navegador e
 * conversa com api.php; este arquivo só entrega a página e as configurações dela.
 */
require __DIR__ . '/src/inicio.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$cfgJs = BancoJson::json(array(
    'api' => 'api.php',
    'intervalo' => max(2000, (int) $config['intervalo_sync_ms']),
    'publicacaoAntigaHoras' => isset($config['publicacao_antiga_horas']) ? (float) $config['publicacao_antiga_horas'] : 36,
));

$html = file_get_contents(__DIR__ . '/views/regulacao.html');
echo str_replace('/*RCOI_CFG*/', 'window.RCOI_CFG=' . $cfgJs . ';', $html);
