<?php
/**
 * Login com o usuário de rede da empresa (Active Directory via LDAP) e
 * entrada automática com o usuário do Windows (SSO: NTLM/Kerberos do IIS).
 *
 * Os dois são opcionais e ficam desligados até serem configurados em
 * config.php / config.local.php (chaves 'ldap' e 'sso'). O cadastro em
 * Usuários continua mandando: a rede só confirma QUEM é a pessoa; o que ela
 * pode fazer (engenheiro, supervisor, controlador, outra área) vem do cadastro do Regulação COI.
 *
 * Compatível com PHP 5.5 a 8.x. Precisa da extensão php_ldap só para o LDAP;
 * o SSO não usa LDAP (quem confere a identidade é o IIS).
 */
class Rede
{
    private $ldap;
    private $sso;

    public function __construct(array $config)
    {
        $l = isset($config['ldap']) && is_array($config['ldap']) ? $config['ldap'] : array();
        $s = isset($config['sso']) && is_array($config['sso']) ? $config['sso'] : array();
        $this->ldap = array_merge(array(
            'ativo' => false,
            'servidor' => '',            // ldap://dc1.empresa.local (vários separados por espaço) ou ldaps://...
            'dominio' => '',             // sufixo UPN: empresa.local  → u1205817@empresa.local
            'dominio_netbios' => '',     // EMPRESA → aceita digitar EMPRESA\u1205817
            'formato_bind' => '',        // opcional, ex.: uid={usuario},ou=people,dc=empresa,dc=local
            'base_dn' => '',             // opcional; vazio = DC=empresa,DC=local (a partir do domínio)
            'starttls' => false,
            'ignorar_certificado' => false,
            'timeout' => 5,
            'masters_pela_rede' => true, // masters também podem entrar com a senha de rede
        ), $l);
        $this->sso = array_merge(array(
            'ativo' => false,
            'dominios' => array(),       // NETBIOS aceitos (ex.: array('EMPRESA')); vazio = qualquer
        ), $s);
    }

    public static function extensaoLdap()
    {
        return function_exists('ldap_connect');
    }

    public function ldapAtivo()
    {
        return !empty($this->ldap['ativo']) && trim((string) $this->ldap['servidor']) !== '';
    }

    public function ssoAtivo()
    {
        return !empty($this->sso['ativo']);
    }

    public function mastersPelaRede()
    {
        return !empty($this->ldap['masters_pela_rede']);
    }

    /** O que a tela precisa saber (sem nomes de servidor). */
    public function estado()
    {
        return array('ldap' => $this->ldapAtivo(), 'sso' => $this->ssoAtivo(),
            'dominio' => $this->ldapAtivo() ? strtoupper((string) $this->ldap['dominio_netbios']) : '');
    }

    /**
     * Separa o que a pessoa digitou: "EMPRESA\u1205817", "u1205817@empresa.local"
     * ou só "u1205817". Devolve array(conta, dominio).
     */
    public static function separar($bruto)
    {
        $bruto = trim((string) $bruto);
        $dom = '';
        if (strpos($bruto, '\\') !== false) {
            $p = explode('\\', $bruto, 2);
            $dom = $p[0];
            $bruto = $p[1];
        } elseif (strpos($bruto, '@') !== false) {
            $p = explode('@', $bruto, 2);
            $bruto = $p[0];
            $dom = $p[1];
        }
        return array(trim($bruto), trim($dom));
    }

    /** Escapa um valor para filtro LDAP (RFC 4515). O ldap_escape só existe no PHP 5.6+. */
    public static function escapar($v)
    {
        return str_replace(array('\\', '*', '(', ')', "\0"), array('\\5c', '\\2a', '\\28', '\\29', '\\00'), (string) $v);
    }

    /** DC=empresa,DC=local a partir de "empresa.local". */
    private function baseDn()
    {
        if (trim((string) $this->ldap['base_dn']) !== '') {
            return trim((string) $this->ldap['base_dn']);
        }
        $d = trim((string) $this->ldap['dominio']);
        if ($d === '') {
            return '';
        }
        $p = array();
        foreach (explode('.', $d) as $x) {
            if ($x !== '') {
                $p[] = 'DC=' . $x;
            }
        }
        return implode(',', $p);
    }

    /**
     * Confere usuário e senha no AD. Devolve:
     *   array('logins' => [...possíveis logins do Regulação COI...], 'nome' => '...', 'conta' => '...')
     * ou false se usuário/senha de rede não conferem.
     * Lança ErroApi (503) se o servidor de rede não responde e (403) para
     * conta bloqueada/expirada, com mensagem clara.
     */
    public function autenticar($bruto, $senha)
    {
        if (!$this->ldapAtivo()) {
            return false;
        }
        if (!self::extensaoLdap()) {
            throw new ErroApi('Login de rede ligado, mas a extensão LDAP do PHP não está ativa no servidor (php_ldap).', 503);
        }
        // Senha vazia faria um "bind anônimo", que o AD aceita: nunca tente.
        if ((string) $senha === '') {
            return false;
        }
        list($conta, $dom) = self::separar($bruto);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $conta) || ($dom !== '' && !preg_match('/^[A-Za-z0-9.-]{1,100}$/', $dom))) {
            return false;
        }

        $cfgDom = trim((string) $this->ldap['dominio']);
        $netbios = trim((string) $this->ldap['dominio_netbios']);
        if (trim((string) $this->ldap['formato_bind']) !== '') {
            $dn = str_replace('{usuario}', $conta, (string) $this->ldap['formato_bind']);
        } elseif ($dom !== '' && strpos($dom, '.') === false) {
            $dn = $dom . '\\' . $conta;                     // EMPRESA\conta
        } elseif ($dom !== '' && $cfgDom !== '' && strtolower($dom) !== strtolower($cfgDom)) {
            $dn = $conta . '@' . $dom;                      // UPN/e-mail com outro sufixo: o AD aceita se for o UPN
        } elseif ($cfgDom !== '') {
            $dn = $conta . '@' . $cfgDom;
        } elseif ($netbios !== '') {
            $dn = $netbios . '\\' . $conta;
        } else {
            $dn = $conta;
        }

        if (!empty($this->ldap['ignorar_certificado'])) {
            putenv('LDAPTLS_REQCERT=never');
        }
        $c = @ldap_connect(trim((string) $this->ldap['servidor']));
        if (!$c) {
            throw new ErroApi('Endereço do servidor de rede inválido (config: ldap → servidor).', 503);
        }
        @ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($c, LDAP_OPT_REFERRALS, 0);
        $t = max(1, (int) $this->ldap['timeout']);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            @ldap_set_option($c, LDAP_OPT_NETWORK_TIMEOUT, $t);
        }
        if (defined('LDAP_OPT_TIMELIMIT')) {
            @ldap_set_option($c, LDAP_OPT_TIMELIMIT, $t);
        }
        if (!empty($this->ldap['starttls']) && !@ldap_start_tls($c)) {
            $e = ldap_errno($c);
            @ldap_unbind($c);
            throw new ErroApi('Não foi possível iniciar a conexão segura (STARTTLS) com o servidor de rede. Código ' . $e . '.', 503);
        }

        if (!@ldap_bind($c, $dn, (string) $senha)) {
            $e = ldap_errno($c);
            $diag = '';
            @ldap_get_option($c, 0x0032, $diag); // mensagem de diagnóstico (no AD traz "data 52e", "data 775"...)
            @ldap_unbind($c);
            if (in_array($e, array(-1, 81, 85, 91), true)) {
                throw new ErroApi('Não foi possível falar com o servidor de rede da empresa. Tente de novo em instantes ou entre com a senha do Regulação COI.', 503);
            }
            if ($e === 8 || $e === 13) {
                // strongAuthRequired / confidentialityRequired: o AD recusa senha em ldap:// sem criptografia.
                throw new ErroApi('O servidor de rede exige conexão segura. Configure ldap → servidor com ldaps://...:636 ou ligue ldap → starttls.', 503);
            }
            $diag = strtolower((string) $diag);
            $avisos = array(
                'data 532' => 'Sua senha de rede expirou. Troque a senha no Windows e tente de novo.',
                'data 773' => 'Sua senha de rede precisa ser trocada. Troque no Windows e tente de novo.',
                'data 775' => 'Sua conta de rede está bloqueada. Procure o suporte de TI.',
                'data 533' => 'Sua conta de rede está desativada. Procure o suporte de TI.',
                'data 701' => 'Sua conta de rede expirou. Procure o suporte de TI.',
            );
            foreach ($avisos as $k => $msg) {
                if (strpos($diag, $k) !== false) {
                    throw new ErroApi($msg, 403);
                }
            }
            return false; // 49 = usuário ou senha inválidos
        }

        // Autenticado. Busca os dados da conta para casar com o cadastro (login, e-mail, nome).
        $logins = array($conta);
        $nome = '';
        $base = $this->baseDn();
        if ($base !== '') {
            $e = self::escapar($conta);
            $filtro = '(|(sAMAccountName=' . $e . ')(uid=' . $e . ')(userPrincipalName=' . self::escapar($cfgDom !== '' ? $conta . '@' . $cfgDom : $conta) . '))';
            $attrs = array('samaccountname', 'userprincipalname', 'mail', 'displayname', 'cn', 'uid');
            $r = @ldap_search($c, $base, $filtro, $attrs, 0, 2);
            if ($r) {
                $ent = @ldap_get_entries($c, $r);
                if ($ent && isset($ent['count']) && $ent['count'] == 1) {
                    $x = $ent[0];
                    $v = function ($k) use ($x) {
                        return isset($x[$k][0]) ? (string) $x[$k][0] : '';
                    };
                    foreach (array('samaccountname', 'uid') as $k) {
                        if ($v($k) !== '') {
                            $logins[] = $v($k);
                        }
                    }
                    foreach (array('userprincipalname', 'mail') as $k) {
                        if ($v($k) !== '') {
                            $p = explode('@', $v($k), 2);
                            $logins[] = $p[0];
                        }
                    }
                    $nome = $v('displayname') !== '' ? $v('displayname') : $v('cn');
                }
            }
        }
        @ldap_unbind($c);
        $limpos = array();
        foreach ($logins as $l) {
            $l = Regulacao::limparLogin($l);
            if ($l !== '' && !in_array($l, $limpos, true)) {
                $limpos[] = $l;
            }
        }
        return array('logins' => $limpos, 'nome' => $nome, 'conta' => Regulacao::limparLogin($conta));
    }

    /**
     * Usuário do Windows informado pelo IIS (autenticação do Windows ligada
     * só no sso.php). Só lê variáveis do servidor, nunca cabeçalhos HTTP do
     * navegador (que poderiam ser forjados). Devolve o login ou ''.
     */
    public function usuarioWindows(array $server)
    {
        if (!$this->ssoAtivo()) {
            return '';
        }
        $bruto = '';
        foreach (array('LOGON_USER', 'AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER') as $k) {
            if (!empty($server[$k]) && is_string($server[$k])) {
                $bruto = $server[$k];
                break;
            }
        }
        if ($bruto === '') {
            return '';
        }
        list($conta, $dom) = self::separar($bruto);
        $aceitos = array();
        foreach ((array) $this->sso['dominios'] as $d) {
            $aceitos[] = strtoupper(trim((string) $d));
        }
        if ($aceitos) {
            // Kerberos pode trazer conta@EMPRESA.LOCAL: compara pelo primeiro pedaço também.
            $p = explode('.', strtoupper($dom), 2);
            if (!in_array(strtoupper($dom), $aceitos, true) && !in_array($p[0], $aceitos, true)) {
                return '';
            }
        }
        return Regulacao::limparLogin($conta);
    }
}
