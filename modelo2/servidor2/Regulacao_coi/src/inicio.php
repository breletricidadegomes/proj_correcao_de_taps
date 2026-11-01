<?php
/**
 * Carrega configuração e classes. Incluído por index.php, api.php, sso.php e diagnostico.php.
 */
require_once __DIR__ . '/Apoio.php';
require_once __DIR__ . '/BancoJson.php';
require_once __DIR__ . '/Rede.php';
require_once __DIR__ . '/motor/Complexo.php';
require_once __DIR__ . '/motor/FluxoCargaRadial.php';
require_once __DIR__ . '/motor/ProdistTensao.php';
require_once __DIR__ . '/motor/SimuladorTap.php';
require_once __DIR__ . '/motor/CurvasTipicas.php';
require_once __DIR__ . '/motor/SimulacaoDiaria.php';
require_once __DIR__ . '/motor/AnaliseHospedagem.php';
require_once __DIR__ . '/DadosExemplo.php';
require_once __DIR__ . '/Publicado.php';
require_once __DIR__ . '/Regulacao.php';

$config = require dirname(__DIR__) . '/config.php';

ini_set('display_errors', empty($config['debug']) ? '0' : '1');
error_reporting(empty($config['debug']) ? E_ALL & ~E_NOTICE & ~E_DEPRECATED : E_ALL);
// 14 dígitos: horários em milissegundos saem inteiros no JSON (PHP 32 bits).
ini_set('precision', '14');

if (!empty($config['fuso_horario'])) {
    date_default_timezone_set($config['fuso_horario']);
}
