<?php
/**
 * Classes de apoio: erro da API, senhas e sessão.
 * Mesmo método do Comunica COI e da Escala COI (servidor PHP 5.5 do COI).
 * Compatível com PHP 5.5 a 8.x.
 */

class ErroApi extends Exception
{
    public $status;

    public function __construct($mensagem, $status = 400)
    {
        parent::__construct($mensagem);
        $this->status = (int) $status;
    }
}

/**
 * Senhas com password_hash (bcrypt), nativo desde o PHP 5.5.
 * Nunca guardamos a senha em texto.
 */
class Senha
{
    public static function gerar($senha)
    {
        if (function_exists('password_hash')) {
            return password_hash($senha, PASSWORD_DEFAULT);
        }
        // Reserva para PHP sem password_hash (não deveria acontecer no 5.5+).
        $sal = '$6$rounds=50000$' . substr(str_replace('+', '.', base64_encode(self::bytes(12))), 0, 16) . '$';
        return crypt($senha, $sal);
    }

    public static function confere($senha, $hash)
    {
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        if (function_exists('password_verify')) {
            return password_verify((string) $senha, $hash);
        }
        return self::iguais(crypt((string) $senha, $hash), $hash);
    }

    public static function precisaRefazer($hash)
    {
        return function_exists('password_needs_rehash') && password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /** Comparação em tempo constante (hash_equals só existe no 5.6+). */
    public static function iguais($a, $b)
    {
        $a = (string) $a;
        $b = (string) $b;
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $r = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $r |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $r === 0;
    }

    public static function bytes($n)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $b = openssl_random_pseudo_bytes($n);
            if ($b !== false && strlen($b) === $n) {
                return $b;
            }
        }
        $b = '';
        for ($i = 0; $i < $n; $i++) {
            $b .= chr(mt_rand(0, 255));
        }
        return $b;
    }
}

/**
 * Sessão PHP gravada na própria pasta de dados (data/sessoes), o que evita o
 * problema clássico do IIS/servidor antigo sem permissão na pasta padrão.
 */
class Sessao
{
    private static $minutos = 720;

    public static function iniciar(array $cfg, $dirDados)
    {
        self::$minutos = max(5, (int) $cfg['sessao_minutos']);
        $dir = rtrim($dirDados, '/\\') . '/sessoes';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.gc_maxlifetime', (string) (self::$minutos * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_only_cookies', '1');
        if (version_compare(PHP_VERSION, '5.5.2', '>=')) {
            ini_set('session.use_strict_mode', '1'); // phpcs:ignore PHPCompatibility.IniDirectives.NewIniDirectives
        }
        session_name($cfg['nome_sessao']);

        $caminho = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/') . '/';
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params(0, $caminho, '', $https, true);
        session_start();

        // Expira por inatividade.
        $agora = time();
        if (isset($_SESSION['ultimo']) && $agora - $_SESSION['ultimo'] > self::$minutos * 60) {
            $_SESSION = array();
            session_regenerate_id(true);
            $_SESSION['expirou'] = true;
        }
        $_SESSION['ultimo'] = $agora;

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(Senha::bytes(16));
        }
    }

    public static function csrf()
    {
        return isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    }

    public static function conferirCsrf()
    {
        $h = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        if ($h === '' || !Senha::iguais($h, self::csrf())) {
            throw new ErroApi('Sessão inválida. Recarregue a página (F5).', 419);
        }
    }

    public static function uid()
    {
        return isset($_SESSION['uid']) ? $_SESSION['uid'] : null;
    }

    public static function entrar($uid)
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        unset($_SESSION['expirou']);
    }

    public static function sair()
    {
        $csrf = self::csrf();
        $_SESSION = array();
        session_regenerate_id(true);
        $_SESSION['csrf'] = $csrf;
    }

    /** Libera o arquivo de sessão: as próximas requisições não esperam por esta. */
    public static function liberar()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
