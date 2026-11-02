<?php
/**
 * =============================================================================
 *  BOOTSTRAP - carregado por index.php e backend/processar_tap.php
 * =============================================================================
 *  - Carrega configuração e classes (sem Composer, para servidores legados)
 *  - Inicia sessão com cookies endurecidos
 *  - Funções utilitárias: CSRF, escape HTML, fábrica de repositório
 * =============================================================================
 */

if (version_compare(PHP_VERSION, '5.5.0', '<')) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Esta aplicação requer PHP 5.5 ou superior. Versão atual: ' . PHP_VERSION);
}

define('APP_TAP', true);
define('APP_RAIZ', dirname(__DIR__));

$GLOBALS['APP_CONFIG'] = require APP_RAIZ . '/config/config.php';

// Senhas e ajustes deste servidor ficam em config.local.php, que NÃO vai para o git
// (modelo: config/config.local.example.php). Ele sobrescreve só o que definir.
if (is_file(APP_RAIZ . '/config/config.local.php')) {
    $GLOBALS['APP_CONFIG'] = array_replace_recursive(
        $GLOBALS['APP_CONFIG'],
        require APP_RAIZ . '/config/config.local.php'
    );
}

// Carregamento explícito das classes (ordem respeita dependências)
$libDir = __DIR__ . '/lib/';
require_once $libDir . 'Complexo.php';
require_once $libDir . 'FluxoCargaRadial.php';
require_once $libDir . 'ProdistTensao.php';
require_once $libDir . 'SimuladorTap.php';
require_once $libDir . 'AnaliseHospedagem.php';
require_once $libDir . 'CurvasTipicas.php';
require_once $libDir . 'SimulacaoDiaria.php';
require_once $libDir . 'RepositorioInterface.php';
require_once $libDir . 'RepositorioPDO.php';
require_once $libDir . 'RepositorioDemo.php';
require_once $libDir . 'DadosDemo.php';

// Não exibir erros na saída (quebraria o JSON); registrar no log do servidor
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---- Sessão com cookie seguro ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1'); // phpcs:ignore PHPCompatibility -- ignorado em < 5.5.2
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(0, '/', '', $https, true); // httponly = true
    session_name('TAPSESS');
    session_start();
}

/** Configuração carregada */
function app_config($chave = null)
{
    $cfg = $GLOBALS['APP_CONFIG'];
    return $chave === null ? $cfg : $cfg[$chave];
}

/** Escape para saída HTML */
function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Gera bytes aleatórios seguros (random_bytes só existe no PHP 7+) */
function bytes_aleatorios($n)
{
    if (function_exists('random_bytes')) {
        return random_bytes($n); // phpcs:ignore PHPCompatibility -- protegido por function_exists
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $forte = false;
        $b = openssl_random_pseudo_bytes($n, $forte);
        if ($b !== false && $forte) {
            return $b;
        }
    }
    if (function_exists('mcrypt_create_iv')) {
        return mcrypt_create_iv($n, MCRYPT_DEV_URANDOM); // phpcs:ignore PHPCompatibility -- fallback PHP 5.x
    }
    throw new RuntimeException('Nenhuma fonte de aleatoriedade segura disponível (habilite OpenSSL).');
}

/** Token CSRF da sessão */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(bytes_aleatorios(32));
    }
    return $_SESSION['csrf_token'];
}

/** Comparação em tempo constante (hash_equals só existe a partir do PHP 5.6) */
function comparar_seguro($a, $b)
{
    if (function_exists('hash_equals')) {
        return hash_equals((string) $a, (string) $b); // phpcs:ignore PHPCompatibility -- PHP 5.6+
    }
    $a = (string) $a;
    $b = (string) $b;
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

/** Fábrica do repositório conforme o modo configurado */
function criar_repositorio()
{
    if (app_config('modo_demo')) {
        return new RepositorioDemo();
    }
    return new RepositorioPDO(app_config('db'));
}

/** Cabeçalhos de segurança comuns */
function cabecalhos_seguranca()
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}
