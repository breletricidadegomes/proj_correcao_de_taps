<?php
/**
 * Regulação COI (Modelo 2) — regras de negócio e permissões.
 *
 * Registros de ajuste de regulação de tensão (OLTC de trafo de SE, BRT e
 * banco de capacitores): solicitação → proposta (com simulação) → pareceres
 * → aprovação → execução (manobra ADMS / nota SIGA) → verificação.
 * Também aceita o registro retroativo de ajustes já feitos em campo.
 *
 * As regras ficam AQUI, no servidor; a tela só esconde o que não pode.
 * Registros nunca são excluídos: para tirar de circulação, cancela-se.
 * Compatível com PHP 5.5 a 8.x.
 */
class Regulacao
{
    const MASTER_UID = 'master';

    /** @var BancoJson */
    private $db;
    private $cfg;
    /** @var Rede */
    private $rede;
    /** @var Publicado */
    private $pub;

    public static $PERFIS = array(
        'engenheiro'  => 'Engenheiro',
        'supervisor'  => 'Supervisor',
        'controlador' => 'Controlador (COI)',
        'outra_area'  => 'Outra área',
    );

    public static $STATUS = array(
        'solicitada' => 'Solicitada',
        'em_analise' => 'Em análise',
        'devolvida'  => 'Devolvida para ajuste',
        'aprovada'   => 'Aprovada',
        'reprovada'  => 'Reprovada',
        'executada'  => 'Executada',
        'verificada' => 'Verificada',
        'cancelada'  => 'Cancelada',
    );

    public static $MOTIVOS = array(
        'SUBTENSAO'           => 'Correção de subtensão',
        'SOBRETENSAO'         => 'Correção de sobretensão',
        'PATAMAR_CARGA'       => 'Ajuste de patamar de carga',
        'GERACAO_DISTRIBUIDA' => 'Influência de geração distribuída',
        'RECLAMACAO'          => 'Reclamação de tensão',
        'RECOMPOSICAO'        => 'Recomposição / transferência de carga',
        'PERDAS_REATIVO'      => 'Perdas / controle de reativo',
        'MANUTENCAO'          => 'Manutenção',
        'OUTRO'               => 'Outro',
    );

    private static $ACOES = array(
        'acesso.login' => 'Login', 'acesso.falha' => 'Login recusado', 'acesso.logout' => 'Saída',
        'registro.solicitado' => 'Solicitação aberta', 'registro.proposto' => 'Proposta enviada',
        'registro.parecer' => 'Parecer técnico', 'registro.aprovado' => 'Proposta aprovada',
        'registro.reprovado' => 'Proposta reprovada', 'registro.devolvido' => 'Proposta devolvida',
        'registro.executado' => 'Execução confirmada', 'registro.verificado' => 'Verificação registrada',
        'registro.retroativo' => 'Ajuste retroativo registrado', 'registro.cancelado' => 'Registro cancelado',
        'usuario.criado' => 'Usuário criado', 'usuario.alterado' => 'Usuário alterado', 'usuario.senha' => 'Senha alterada',
    );

    public function __construct(BancoJson $db, array $cfg)
    {
        $this->db = $db;
        $this->cfg = $cfg;
        $this->rede = new Rede($cfg);
        $this->pub = new Publicado($db->dir(), !empty($cfg['modo_exemplo']));
        $self = $this;
        $db->aoGerarCsv(function (BancoJson $db, $arquivo) use ($self) {
            $self->gerarCsvRegistros($db, $arquivo);
        });
        $db->definirDiasBackup(isset($cfg['backup_dias']) ? $cfg['backup_dias'] : 30);
    }

    public function rede()
    {
        return $this->rede;
    }

    public function publicado()
    {
        return $this->pub;
    }

    // ================================================================== permissões

    public static function ehAdm($me)
    {
        return is_array($me) && (!empty($me['master']) || !empty($me['adm']));
    }

    private static function perfilEm($me, array $perfis)
    {
        if (!is_array($me) || empty($me['uid'])) {
            return false;
        }
        if (!empty($me['master'])) {
            return true;
        }
        return isset($me['perfil']) && in_array($me['perfil'], $perfis, true);
    }

    public static function podeSimular($me)
    {
        return self::perfilEm($me, array('engenheiro', 'supervisor'));
    }

    public static function podePropor($me)
    {
        return self::perfilEm($me, array('engenheiro', 'supervisor'));
    }

    public static function podeParecer($me)
    {
        return self::perfilEm($me, array('engenheiro', 'supervisor', 'outra_area'));
    }

    public static function podeAprovar($me)
    {
        return self::perfilEm($me, array('supervisor'));
    }

    public static function podeExecutar($me)
    {
        return self::perfilEm($me, array('controlador', 'engenheiro', 'supervisor'));
    }

    public static function podeVerificar($me)
    {
        return self::perfilEm($me, array('engenheiro', 'supervisor'));
    }

    public static function permissoes($me)
    {
        $logado = is_array($me) && !empty($me['uid']);
        return array(
            'solicitar'  => $logado,
            'simular'    => self::podeSimular($me),
            'propor'     => self::podePropor($me),
            'parecer'    => self::podeParecer($me),
            'aprovar'    => self::podeAprovar($me),
            'executar'   => self::podeExecutar($me),
            'verificar'  => self::podeVerificar($me),
            'retroativo' => self::podePropor($me),
            'logs'       => self::ehAdm($me) || self::perfilEm($me, array('supervisor')),
            'usuarios'   => self::ehAdm($me),
        );
    }

    private static function exigir($cond, $msg)
    {
        if (!$cond) {
            throw new ErroApi($msg, 403);
        }
    }

    // ================================================================== usuários e login

    /** ADMs master do config (mesma senha), perfil supervisor com acesso total. */
    public function masters()
    {
        $m = isset($this->cfg['master']) && is_array($this->cfg['master']) ? $this->cfg['master'] : array();
        if (empty($m['senha_hash']) || empty($m['usuarios']) || !is_array($m['usuarios'])) {
            return array();
        }
        $nomes = isset($m['nomes']) && is_array($m['nomes']) ? $m['nomes'] : array();
        $o = array();
        foreach ($m['usuarios'] as $l) {
            $login = self::limparLogin($l);
            if ($login === '') {
                continue;
            }
            $uid = $o ? 'master_' . $login : self::MASTER_UID;
            $nome = isset($nomes[$l]) ? $nomes[$l] : 'ADM ' . strtoupper($login);
            $o[$uid] = array('nome' => $nome, 'usuario' => $login, 'senha' => $m['senha_hash'], 'perfil' => 'supervisor',
                'area' => 'COI', 'adm' => true, 'ativo' => true, 'master' => true, 'criadoEm' => 0);
        }
        return $o;
    }

    public static function ehMasterUid($uid)
    {
        return is_string($uid) && ($uid === self::MASTER_UID || strpos($uid, 'master_') === 0);
    }

    public static function publico($id, array $u)
    {
        unset($u['senha']);
        $u['uid'] = $id;
        $u['adm'] = !empty($u['adm']) || !empty($u['master']);
        $u['ativo'] = !empty($u['ativo']);
        $u['perfilNome'] = isset(self::$PERFIS[$u['perfil']]) ? self::$PERFIS[$u['perfil']] : $u['perfil'];
        return $u;
    }

    public static function quem(array $me)
    {
        return array('uid' => $me['uid'], 'nome' => $me['nome'], 'usuario' => $me['usuario'],
            'perfil' => $me['perfil'], 'area' => isset($me['area']) ? $me['area'] : '');
    }

    private function usuariosTodos()
    {
        $db = $this->db;
        $us = $db->leitura(function () use ($db) {
            return $db->ler('usuarios', array());
        });
        return $us + $this->masters();
    }

    public function perfil($uid)
    {
        if (!$uid) {
            return null;
        }
        $us = $this->usuariosTodos();
        if (!isset($us[$uid]) || empty($us[$uid]['ativo'])) {
            return null;
        }
        return self::publico($uid, $us[$uid]);
    }

    /** Primeiro acesso: sem masters e sem usuários, cria o primeiro supervisor ADM. */
    public function precisaInstalar()
    {
        if ($this->masters()) {
            return false;
        }
        $db = $this->db;
        return $db->leitura(function () use ($db) {
            return count($db->ler('usuarios', array())) === 0;
        });
    }

    public function instalar($nome, $usuario, $senha)
    {
        if (!$this->precisaInstalar()) {
            throw new ErroApi('O sistema já tem administrador.', 409);
        }
        $nome = self::texto($nome, 80);
        $login = self::limparLogin($usuario);
        self::validarSenha($senha);
        if ($nome === '' || $login === '') {
            throw new ErroApi('Informe nome e usuário.');
        }
        $uid = BancoJson::novoId('u');
        $u = array('nome' => $nome, 'usuario' => $login, 'senha' => Senha::gerar($senha), 'perfil' => 'supervisor',
            'area' => 'COI', 'adm' => true, 'ativo' => true, 'criadoEm' => BancoJson::agoraMs());
        $p = self::publico($uid, $u);
        $this->db->transacao(function (BancoJson $db) use ($uid, $u, $p) {
            $us = $db->ler('usuarios', array());
            $us[$uid] = $u;
            $db->gravar('usuarios', $us);
            $meta = &$db->meta();
            $meta['vUsuarios'] = $db->versaoAtual();
            $db->log(Regulacao::entradaLog($p, 'usuario.criado', 'Primeiro administrador: ' . $u['usuario']));
        });
        return $p;
    }

    public function login($usuario, $senha)
    {
        $bruto = trim((string) $usuario);
        $senha = (string) $senha;
        $sep = Rede::separar($bruto);
        $login = self::limparLogin(strpos($bruto, '\\') !== false || strpos($bruto, '@') !== false ? $sep[0] : $bruto);
        if ($senha === '' || $login === '') {
            usleep(300000);
            throw new ErroApi('Informe usuário e senha.', 401);
        }

        foreach ($this->masters() as $muid => $m) {
            if ($login !== $m['usuario']) {
                continue;
            }
            $p = self::publico($muid, $m);
            $ok = Senha::confere($senha, $m['senha']);
            if (!$ok && $this->rede->ldapAtivo() && $this->rede->mastersPelaRede()) {
                $r = $this->rede->autenticar($bruto, $senha);
                $ok = $r && in_array($m['usuario'], $r['logins'], true);
            }
            if ($ok) {
                $this->logAvulso($p, 'acesso.login', 'Login do ADM master ' . strtoupper($m['usuario']));
                return $p;
            }
            usleep(700000);
            $this->logAvulso(null, 'acesso.falha', 'Senha incorreta para o ADM master ' . strtoupper($m['usuario']));
            throw new ErroApi('Usuário ou senha incorretos.', 401);
        }

        $db = $this->db;
        $us = $db->leitura(function () use ($db) {
            return $db->ler('usuarios', array());
        });
        foreach ($us as $id => $u) {
            if ($u['usuario'] === $login && !empty($u['senha']) && Senha::confere($senha, $u['senha'])) {
                $this->exigirAtivo($id, $u);
                $p = self::publico($id, $u);
                $this->logAvulso($p, 'acesso.login', 'Login de ' . $p['usuario']);
                return $p;
            }
        }

        if ($this->rede->ldapAtivo()) {
            $r = $this->rede->autenticar($bruto, $senha);
            if ($r) {
                $achado = $this->acharPorLogins($r['logins']);
                if (!$achado) {
                    $this->logAvulso(null, 'acesso.falha', 'Usuário de rede "' . $r['conta'] . '" sem cadastro');
                    throw new ErroApi('Sua senha de rede foi conferida, mas o usuário "' . $r['conta'] . '" não tem cadastro no Regulação COI. Peça a um administrador para cadastrar você.', 403);
                }
                list($id, $u) = $achado;
                $this->exigirAtivo($id, $u);
                $p = self::publico($id, $u);
                $this->logAvulso($p, 'acesso.login', 'Login de ' . $p['usuario'] . ' com a senha de rede');
                return $p;
            }
        }
        usleep(700000);
        $this->logAvulso(null, 'acesso.falha', 'Login "' . $login . '" com usuário ou senha incorretos');
        throw new ErroApi('Usuário ou senha incorretos.', 401);
    }

    public function loginWindows($login)
    {
        $login = self::limparLogin($login);
        if ($login === '') {
            throw new ErroApi('O servidor não informou o usuário do Windows. Verifique a Autenticação do Windows do sso.php no IIS.', 401);
        }
        $achado = $this->acharPorLogins(array($login));
        if (!$achado) {
            $this->logAvulso(null, 'acesso.falha', 'Usuário do Windows "' . $login . '" sem cadastro');
            throw new ErroApi('Você entrou no Windows como "' . $login . '", mas esse usuário não tem cadastro no Regulação COI.', 403);
        }
        list($id, $u) = $achado;
        $this->exigirAtivo($id, $u);
        $p = self::publico($id, $u);
        $this->logAvulso($p, 'acesso.login', 'Login de ' . $p['usuario'] . ' pelo usuário do Windows');
        return $p;
    }

    private function acharPorLogins(array $logins)
    {
        $us = $this->usuariosTodos();
        foreach ($logins as $l) {
            foreach ($us as $id => $u) {
                if ($u['usuario'] === $l) {
                    return array($id, $u);
                }
            }
        }
        return null;
    }

    private function exigirAtivo($id, array $u)
    {
        if (empty($u['ativo'])) {
            $this->logAvulso(self::publico($id, $u), 'acesso.falha', 'Usuário desativado tentou entrar');
            throw new ErroApi('Este usuário está desativado. Procure um administrador.', 403);
        }
    }

    public function trocarSenha(array $me, $atual, $nova)
    {
        if (self::ehMasterUid($me['uid'])) {
            throw new ErroApi('A senha do ADM master fica no config.local.php.', 403);
        }
        self::validarSenha($nova);
        $uid = $me['uid'];
        $this->db->transacao(function (BancoJson $db) use ($uid, $atual, $nova, $me) {
            $us = $db->ler('usuarios', array());
            if (!isset($us[$uid])) {
                throw new ErroApi('Usuário não encontrado.', 404);
            }
            if (!empty($us[$uid]['senha']) && !Senha::confere($atual, $us[$uid]['senha'])) {
                throw new ErroApi('A senha atual não confere.', 403);
            }
            $us[$uid]['senha'] = Senha::gerar($nova);
            $db->gravar('usuarios', $us);
            $db->log(Regulacao::entradaLog($me, 'usuario.senha', 'Trocou a própria senha'));
        });
    }

    public function listarUsuarios(array $me)
    {
        self::exigir(self::ehAdm($me), 'Apenas administradores veem os usuários.');
        $o = array();
        foreach ($this->usuariosTodos() as $id => $u) {
            $o[] = self::publico($id, $u);
        }
        return $o;
    }

    public function salvarUsuario(array $me, $id, array $d)
    {
        self::exigir(self::ehAdm($me), 'Apenas administradores alteram usuários.');
        if ($id !== '' && self::ehMasterUid($id)) {
            throw new ErroApi('O ADM master é fixo no config e não pode ser alterado pela tela.', 403);
        }
        $nome = self::texto(isset($d['nome']) ? $d['nome'] : '', 80);
        $login = self::limparLogin(isset($d['usuario']) ? $d['usuario'] : '');
        $perfil = isset($d['perfil']) ? (string) $d['perfil'] : '';
        $area = self::texto(isset($d['area']) ? $d['area'] : '', 60);
        $senha = isset($d['senha']) ? (string) $d['senha'] : '';
        if ($nome === '' || $login === '') {
            throw new ErroApi('Informe nome e login (o mesmo da rede, ex.: u1205817).');
        }
        if (!isset(self::$PERFIS[$perfil])) {
            throw new ErroApi('Perfil inválido.');
        }
        if ($senha !== '') {
            self::validarSenha($senha);
        }
        foreach ($this->masters() as $m) {
            if ($m['usuario'] === $login) {
                throw new ErroApi('Este login é de um ADM master.', 409);
            }
        }
        $novo = $id === '';
        $uid = $novo ? BancoJson::novoId('u') : self::validarId($id);
        $this->db->transacao(function (BancoJson $db) use ($me, $novo, $uid, $nome, $login, $perfil, $area, $senha, $d) {
            $us = $db->ler('usuarios', array());
            foreach ($us as $k => $u) {
                if ($k !== $uid && $u['usuario'] === $login) {
                    throw new ErroApi('Já existe usuário com o login ' . $login . '.', 409);
                }
            }
            if (!$novo && !isset($us[$uid])) {
                throw new ErroApi('Usuário não encontrado.', 404);
            }
            $u = $novo ? array('criadoEm' => BancoJson::agoraMs(), 'senha' => '') : $us[$uid];
            $u['nome'] = $nome;
            $u['usuario'] = $login;
            $u['perfil'] = $perfil;
            $u['area'] = $area;
            $u['adm'] = !empty($d['adm']);
            $u['ativo'] = !array_key_exists('ativo', $d) || !empty($d['ativo']);
            if ($senha !== '') {
                $u['senha'] = Senha::gerar($senha);
            }
            if ($uid === $me['uid'] && (!$u['adm'] || !$u['ativo'])) {
                throw new ErroApi('Você não pode tirar o próprio acesso de administrador.', 409);
            }
            $us[$uid] = $u;
            $db->gravar('usuarios', $us);
            $meta = &$db->meta();
            $meta['vUsuarios'] = $db->versaoAtual();
            $db->log(Regulacao::entradaLog($me, $novo ? 'usuario.criado' : 'usuario.alterado',
                ($novo ? 'Criou ' : 'Alterou ') . $login . ' (' . Regulacao::$PERFIS[$perfil] . ($u['adm'] ? ', ADM' : '') . ($u['ativo'] ? '' : ', inativo') . ')'));
        });
        return $uid;
    }

    // ================================================================== equipamentos e ajustes vigentes

    /**
     * Equipamentos com o ajuste vigente: vale o mais recente entre o que o
     * servidor 1 publicou (ADMS/cadastro) e o último registro executado aqui.
     */
    public function equipamentos()
    {
        $db = $this->db;
        $vig = $db->leitura(function () use ($db) {
            return $db->ler('ajustes_vigentes', array());
        });
        $o = array();
        foreach ($this->pub->equipamentos() as $eq) {
            // Chave = código do equipamento (estável entre a rede de exemplo e as
            // publicações do servidor 1; o id numérico pode mudar).
            $id = (string) $eq['codigo'];
            $eq['origem_ajuste'] = 'publicado';
            $tsPub = isset($eq['atualizado_em']) ? strtotime($eq['atualizado_em']) * 1000.0 : 0.0;
            if (isset($vig[$id]) && (float) $vig[$id]['em'] >= $tsPub) {
                foreach (array('tap_atual', 'estagios_ligados', 'modo', 'v_ref_pu', 'banda_pu', 'temporizacao_s') as $k) {
                    if (array_key_exists($k, $vig[$id])) {
                        $eq[$k] = $vig[$id][$k];
                    }
                }
                $eq['origem_ajuste'] = 'registro';
                $eq['registro_vigente'] = $vig[$id]['protocolo'];
                $eq['atualizado_em'] = date('Y-m-d H:i:s', (int) floor((float) $vig[$id]['em'] / 1000));
            }
            $eq['alimentadores'] = Publicado::alimentadoresDo($eq);
            $o[] = $eq;
        }
        return $o;
    }

    public function equipamento($id)
    {
        foreach ($this->equipamentos() as $e) {
            if ((int) $e['id'] === (int) $id) {
                return $e;
            }
        }
        return null;
    }

    /** Equipamentos de um alimentador, com o trafo da SE posicionado na barra raiz dele */
    public function equipamentosDoAlimentador($alimId, array $nos)
    {
        $raiz = '';
        foreach ($nos as $n) {
            if ($n['no_pai_codigo'] === null || $n['no_pai_codigo'] === '') {
                $raiz = $n['codigo'];
            }
        }
        $o = array();
        foreach ($this->equipamentos() as $e) {
            if (!in_array((int) $alimId, $e['alimentadores'], true)) {
                continue;
            }
            if ($e['tipo'] === 'TRAFO_SE') {
                $e['no_codigo'] = $raiz;
            }
            $o[] = $e;
        }
        return $o;
    }

    // ================================================================== simulação de propostas

    /**
     * Simula a situação vigente e a proposta em todos os alimentadores
     * afetados (um OLTC de SE afeta todos os alimentadores da barra).
     *
     * $ajustes: [ {equipamento_id, tap?, modo? ('AUTO'|'MANUAL'), v_ref_pu?, banda_pu?, estagios?} ]
     * $cenario: {v_fonte_pu, fator_carga, fator_gd}
     */
    public function simularProposta($alimId, array $ajustes, array $cenario)
    {
        $alimId = (int) $alimId;
        if ($this->pub->alimentador($alimId) === null) {
            throw new ErroApi('Alimentador não encontrado.', 404);
        }
        $cen = self::cenarioValido($cenario);
        $aj = $this->validarAjustes($ajustes);

        $afetados = array($alimId);
        foreach ($aj as $a) {
            foreach ($a['_eq']['alimentadores'] as $x) {
                if (!in_array($x, $afetados, true)) {
                    $afetados[] = $x;
                }
            }
        }

        $sim = new SimuladorTap($this->cfg['calculo']);
        $saida = array();
        foreach ($afetados as $aid) {
            $al = $this->pub->alimentador($aid);
            $nos = $this->pub->nos($aid);
            if (!$nos) {
                continue;
            }
            $eqs = $this->equipamentosDoAlimentador($aid, $nos);
            $antes = $sim->simularComControle($al, $nos, $eqs, self::parametros($eqs, array(), $cen));
            $depois = $sim->simularComControle($al, $nos, $eqs, self::parametros($eqs, $aj, $cen));
            $saida[] = array(
                'alimentador_id' => $aid,
                'alimentador'    => $al['codigo'],
                'nome'           => $al['nome'],
                'principal'      => $aid === $alimId,
                'antes'          => self::resumoSimulacao($antes, $eqs),
                'depois'         => self::resumoSimulacao($depois, $eqs),
                'limites_pu'     => $depois['limites_pu'],
            );
        }
        return array('cenario' => $cen, 'alimentadores' => $saida);
    }

    /** Monta os parâmetros do motor a partir dos ajustes (vigentes + propostos) */
    private static function parametros(array $eqs, array $ajustes, array $cen)
    {
        $p = array('v_fonte_pu' => $cen['v_fonte_pu'], 'fator_carga' => $cen['fator_carga'],
            'fator_gd' => $cen['fator_gd'], 'taps' => array(), 'bc' => array(), 'controle' => array());
        $porId = array();
        foreach ($ajustes as $a) {
            $porId[(int) $a['equipamento_id']] = $a;
        }
        foreach ($eqs as $e) {
            $id = (int) $e['id'];
            $a = isset($porId[$id]) ? $porId[$id] : null;
            if ($e['tipo'] === 'BC') {
                if ($a !== null && isset($a['estagios'])) {
                    $p['bc'][$id] = (int) $a['estagios'];
                }
                continue;
            }
            $modo = $a !== null && isset($a['modo']) ? $a['modo'] : (isset($e['modo']) ? $e['modo'] : 'MANUAL');
            if ($a !== null && isset($a['tap'])) {
                $p['taps'][$id] = (int) $a['tap'];
            }
            if ($modo === 'AUTO') {
                $p['controle'][$id] = array(
                    'v_ref' => $a !== null && isset($a['v_ref_pu']) ? (float) $a['v_ref_pu'] : (float) $e['v_ref_pu'],
                    'banda' => $a !== null && isset($a['banda_pu']) ? (float) $a['banda_pu'] : (float) $e['banda_pu'],
                );
            }
        }
        return $p;
    }

    private static function resumoSimulacao(array $s, array $eqs)
    {
        $i = $s['indicadores'];
        $perfil = array();
        foreach ($s['nos'] as $n) {
            $perfil[] = array('codigo' => $n['codigo'], 'nome' => $n['nome'], 'pai' => $n['pai'],
                'distancia_km' => $n['distancia_km'], 'v_pu' => $n['v_pu'], 'classe' => $n['classe'],
                'sentido' => $n['sentido'], 'consumidores' => $n['consumidores']);
        }
        $taps = array();
        foreach ($eqs as $e) {
            if ($e['tipo'] !== 'BC' && isset($s['taps'][(int) $e['id']])) {
                $taps[] = array('id' => (int) $e['id'], 'codigo' => $e['codigo'], 'tap' => $s['taps'][(int) $e['id']]);
            }
        }
        return array(
            'v_min_pu' => $i['v_min_pu'], 'no_v_min' => $i['no_v_min'],
            'v_max_pu' => $i['v_max_pu'], 'no_v_max' => $i['no_v_max'],
            'v_barra_se_pu' => $i['v_barra_se_pu'], 'queda_max_percent' => $i['queda_max_percent'],
            'perdas_kw' => $i['perdas_kw'], 'drp_estimado' => $i['drp_estimado'], 'drc_estimado' => $i['drc_estimado'],
            'ucs_total' => $i['ucs_total'], 'ucs_precaria' => $i['ucs_precaria'], 'ucs_critica' => $i['ucs_critica'],
            'nos_fora' => $i['nos_precarios'] + $i['nos_criticos'], 'fluxo_reverso' => $i['fluxo_reverso'],
            'barra_se_fora' => $i['barra_se_fora'],
            'carregamento_trafo' => $s['trafo'] !== null ? $s['trafo']['carregamento_percent'] : null,
            'taps' => $taps, 'bcs' => $s['bcs'], 'perfil' => $perfil,
        );
    }

    private static function cenarioValido(array $c)
    {
        $n = function ($k, $min, $max, $padrao) use ($c) {
            if (!isset($c[$k]) || $c[$k] === '' || $c[$k] === null) {
                return $padrao;
            }
            if (!is_numeric($c[$k]) || $c[$k] < $min || $c[$k] > $max) {
                throw new ErroApi('Cenário inválido: ' . $k . ' fora de [' . $min . '; ' . $max . '].');
            }
            return (float) $c[$k];
        };
        return array(
            'v_fonte_pu'  => $n('v_fonte_pu', 0.85, 1.15, 1.0),
            'fator_carga' => $n('fator_carga', 0.0, 2.0, 1.0),
            'fator_gd'    => $n('fator_gd', 0.0, 1.5, 0.0),
        );
    }

    /** Valida os ajustes contra o cadastro (faixas de tap, estágios, V ref, banda) */
    private function validarAjustes(array $ajustes)
    {
        if (count($ajustes) > 20) {
            throw new ErroApi('Proposta com equipamentos demais.');
        }
        $o = array();
        $vistos = array();
        foreach ($ajustes as $a) {
            if (!is_array($a) || !isset($a['equipamento_id'])) {
                throw new ErroApi('Ajuste sem equipamento.');
            }
            $eq = $this->equipamento($a['equipamento_id']);
            if ($eq === null) {
                throw new ErroApi('Equipamento ' . (int) $a['equipamento_id'] . ' não encontrado.', 404);
            }
            if (isset($vistos[(int) $eq['id']])) {
                throw new ErroApi('O equipamento ' . $eq['codigo'] . ' aparece duas vezes na proposta.');
            }
            $vistos[(int) $eq['id']] = true;
            $v = array('equipamento_id' => (int) $eq['id'], '_eq' => $eq);
            if ($eq['tipo'] === 'BC') {
                if (!isset($a['estagios']) || filter_var($a['estagios'], FILTER_VALIDATE_INT) === false
                    || (int) $a['estagios'] < 0 || (int) $a['estagios'] > (int) $eq['n_estagios']) {
                    throw new ErroApi($eq['codigo'] . ': estágios ligados deve ficar entre 0 e ' . (int) $eq['n_estagios'] . '.');
                }
                $v['estagios'] = (int) $a['estagios'];
            } else {
                $modo = isset($a['modo']) ? strtoupper((string) $a['modo']) : (isset($eq['modo']) ? $eq['modo'] : 'MANUAL');
                if ($modo !== 'AUTO' && $modo !== 'MANUAL') {
                    throw new ErroApi($eq['codigo'] . ': modo inválido.');
                }
                $v['modo'] = $modo;
                if (isset($a['tap']) && $a['tap'] !== '' && $a['tap'] !== null) {
                    if (filter_var($a['tap'], FILTER_VALIDATE_INT) === false
                        || (int) $a['tap'] < (int) $eq['tap_min'] || (int) $a['tap'] > (int) $eq['tap_max']) {
                        throw new ErroApi($eq['codigo'] . ': tap fora da faixa (' . $eq['tap_min'] . ' a ' . $eq['tap_max'] . ').');
                    }
                    $v['tap'] = (int) $a['tap'];
                } elseif ($modo === 'MANUAL') {
                    throw new ErroApi($eq['codigo'] . ': em modo manual, informe o tap.');
                }
                if ($modo === 'AUTO') {
                    $vr = isset($a['v_ref_pu']) ? $a['v_ref_pu'] : (isset($eq['v_ref_pu']) ? $eq['v_ref_pu'] : null);
                    $bd = isset($a['banda_pu']) ? $a['banda_pu'] : (isset($eq['banda_pu']) ? $eq['banda_pu'] : null);
                    if (!is_numeric($vr) || $vr < 0.95 || $vr > 1.05) {
                        throw new ErroApi($eq['codigo'] . ': V ref deve ficar entre 0,95 e 1,05 p.u.');
                    }
                    if (!is_numeric($bd) || $bd < 0.005 || $bd > 0.05) {
                        throw new ErroApi($eq['codigo'] . ': banda deve ficar entre 0,5% e 5%.');
                    }
                    $v['v_ref_pu'] = round((float) $vr, 4);
                    $v['banda_pu'] = round((float) $bd, 4);
                }
                if (isset($a['temporizacao_s']) && $a['temporizacao_s'] !== '' && $a['temporizacao_s'] !== null) {
                    if (!is_numeric($a['temporizacao_s']) || $a['temporizacao_s'] < 0 || $a['temporizacao_s'] > 600) {
                        throw new ErroApi($eq['codigo'] . ': temporização deve ficar entre 0 e 600 s.');
                    }
                    $v['temporizacao_s'] = (int) $a['temporizacao_s'];
                }
            }
            $o[] = $v;
        }
        return $o;
    }

    /** "De → para" de cada ajuste, para guardar no registro */
    private static function deParaAjustes(array $aj)
    {
        $o = array();
        foreach ($aj as $a) {
            $eq = $a['_eq'];
            if ($eq['tipo'] === 'BC') {
                $de = array('estagios' => (int) $eq['estagios_ligados']);
                $para = array('estagios' => $a['estagios']);
            } else {
                $de = array('modo' => isset($eq['modo']) ? $eq['modo'] : 'MANUAL', 'tap' => (int) $eq['tap_atual']);
                $para = array('modo' => $a['modo']);
                if (isset($a['tap'])) {
                    $para['tap'] = $a['tap'];
                }
                foreach (array('v_ref_pu', 'banda_pu', 'temporizacao_s') as $k) {
                    if (isset($eq[$k])) {
                        $de[$k] = $eq[$k];
                    }
                    if (isset($a[$k])) {
                        $para[$k] = $a[$k];
                    }
                }
            }
            $o[] = array('equipamento_id' => (int) $eq['id'], 'codigo' => $eq['codigo'], 'tipo' => $eq['tipo'],
                'de' => $de, 'para' => $para);
        }
        return $o;
    }

    // ================================================================== registros: fluxo

    public function solicitar(array $me, array $d)
    {
        self::exigir(!empty($me['uid']), 'Entre no sistema para abrir uma solicitação.');
        $campos = $this->camposBasicos($d, true);
        return $this->novoRegistro($me, 'SOLICITACAO', 'solicitada', $campos, array(), null, 'registro.solicitado',
            'Solicitação aberta: ' . $campos['titulo']);
    }

    /**
     * Proposta de ajuste. Se $registroId vier, completa uma solicitação
     * (status solicitada ou devolvida); senão cria um registro novo.
     */
    public function propor(array $me, $registroId, array $d)
    {
        self::exigir(self::podePropor($me), 'Apenas engenheiro ou supervisor criam propostas.');
        $ajustesBrutos = isset($d['ajustes']) && is_array($d['ajustes']) ? $d['ajustes'] : array();
        if (!$ajustesBrutos) {
            throw new ErroApi('Inclua pelo menos um equipamento com o ajuste proposto.');
        }
        $campos = $this->camposBasicos($d, $registroId === '');
        $alimId = isset($d['alimentador_id']) ? (int) $d['alimentador_id'] : 0;
        $aj = $this->validarAjustes($ajustesBrutos);
        $sim = $this->simularProposta($alimId, $ajustesBrutos, isset($d['cenario']) && is_array($d['cenario']) ? $d['cenario'] : array());
        $deParaAj = self::deParaAjustes($aj);
        $resumoSim = array('cenario' => $sim['cenario'], 'alimentadores' => array());
        foreach ($sim['alimentadores'] as $s) {
            unset($s['antes']['perfil'], $s['depois']['perfil']);
            $resumoSim['alimentadores'][] = $s;
        }

        if ($registroId === '') {
            return $this->novoRegistro($me, 'PROPOSTA', 'em_analise', $campos, $deParaAj, $resumoSim, 'registro.proposto',
                'Proposta enviada: ' . $campos['titulo']);
        }
        $id = self::validarId($registroId);
        return $this->alterarRegistro($me, $id, function (array $r) use ($campos, $deParaAj, $resumoSim) {
            if (!in_array($r['status'], array('solicitada', 'devolvida'), true)) {
                throw new ErroApi('Só é possível propor ajustes para registros solicitados ou devolvidos.', 409);
            }
            foreach ($campos as $k => $v) {
                if ($v !== '' && $v !== null) {
                    $r[$k] = $v;
                }
            }
            $r['ajustes'] = $deParaAj;
            $r['simulacao'] = $resumoSim;
            $r['status'] = 'em_analise';
            $r['equipamentos'] = array();
            foreach ($deParaAj as $a) {
                $r['equipamentos'][] = $a['codigo'];
            }
            return array($r, 'registro.proposto', 'Proposta enviada', null);
        });
    }

    public function parecer(array $me, $id, array $d)
    {
        self::exigir(self::podeParecer($me), 'Seu perfil não emite parecer técnico.');
        $texto = self::texto(isset($d['texto']) ? $d['texto'] : '', 2000);
        $conc = isset($d['conclusao']) ? (string) $d['conclusao'] : '';
        if ($texto === '') {
            throw new ErroApi('Escreva o parecer.');
        }
        if (!in_array($conc, array('favoravel', 'ressalva', 'desfavoravel'), true)) {
            throw new ErroApi('Escolha a conclusão do parecer.');
        }
        $nomes = array('favoravel' => 'favorável', 'ressalva' => 'favorável com ressalva', 'desfavoravel' => 'desfavorável');
        return $this->alterarRegistro($me, self::validarId($id), function (array $r) use ($me, $texto, $conc, $nomes) {
            if (!in_array($r['status'], array('solicitada', 'em_analise', 'devolvida', 'aprovada'), true)) {
                throw new ErroApi('Este registro não recebe mais pareceres.', 409);
            }
            $r['pareceres'][] = array('por' => Regulacao::quem($me), 'conclusao' => $conc, 'texto' => $texto, 'em' => BancoJson::agoraMs());
            return array($r, 'registro.parecer', 'Parecer ' . $nomes[$conc], $texto);
        });
    }

    public function decidir(array $me, $id, $decisao, $comentario)
    {
        self::exigir(self::podeAprovar($me), 'Apenas o supervisor aprova ou reprova propostas.');
        if (!in_array($decisao, array('aprovar', 'reprovar', 'devolver'), true)) {
            throw new ErroApi('Decisão inválida.');
        }
        $comentario = self::texto($comentario, 1000);
        if ($decisao !== 'aprovar' && $comentario === '') {
            throw new ErroApi('Explique o motivo ao reprovar ou devolver.');
        }
        $map = array('aprovar' => array('aprovada', 'registro.aprovado', 'Proposta aprovada'),
            'reprovar' => array('reprovada', 'registro.reprovado', 'Proposta reprovada'),
            'devolver' => array('devolvida', 'registro.devolvido', 'Proposta devolvida para ajuste'));
        return $this->alterarRegistro($me, self::validarId($id), function (array $r) use ($me, $decisao, $comentario, $map) {
            if ($r['status'] !== 'em_analise') {
                throw new ErroApi('Só propostas em análise podem ser decididas.', 409);
            }
            $r['status'] = $map[$decisao][0];
            $r['aprovacao'] = array('por' => Regulacao::quem($me), 'decisao' => $decisao, 'comentario' => $comentario, 'em' => BancoJson::agoraMs());
            return array($r, $map[$decisao][1], $map[$decisao][2], $comentario);
        });
    }

    public function executar(array $me, $id, array $d)
    {
        self::exigir(self::podeExecutar($me), 'Seu perfil não confirma execução.');
        $ref = self::referencias($d);
        if ($ref['manobra_adms'] === '' && $ref['nota_siga'] === '') {
            throw new ErroApi('Informe o nº da manobra no ADMS ou o nº da nota no SIGA.');
        }
        $quando = self::dataHoraExecucao(isset($d['executado_em']) ? $d['executado_em'] : '');
        $obs = self::texto(isset($d['observacao']) ? $d['observacao'] : '', 1000);
        $self = $this;
        return $this->alterarRegistro($me, self::validarId($id), function (array $r) use ($me, $ref, $quando, $obs) {
            if ($r['status'] !== 'aprovada') {
                throw new ErroApi('Só propostas aprovadas podem ser executadas.', 409);
            }
            if ($quando < $r['criadoEm'] - 60000) {
                throw new ErroApi('A execução não pode ser anterior à abertura do registro.');
            }
            $r['status'] = 'executada';
            $r['referencias'] = array_merge($r['referencias'], array_filter($ref, 'strlen'));
            $r['execucao'] = array('por' => Regulacao::quem($me), 'executado_em' => $quando, 'observacao' => $obs,
                'origem' => $ref['manobra_adms'] !== '' ? 'ADMS' : 'SIGA', 'em' => BancoJson::agoraMs());
            $txt = 'Execução confirmada' . ($ref['manobra_adms'] !== '' ? ' · manobra ADMS ' . $ref['manobra_adms'] : '')
                . ($ref['nota_siga'] !== '' ? ' · nota SIGA ' . $ref['nota_siga'] : '');
            return array($r, 'registro.executado', $txt, $obs, $quando);
        });
    }

    public function verificar(array $me, $id, array $d)
    {
        self::exigir(self::podeVerificar($me), 'Apenas engenheiro ou supervisor registram a verificação.');
        $res = isset($d['resultado']) ? (string) $d['resultado'] : '';
        if (!in_array($res, array('conforme', 'parcial', 'nao_conforme'), true)) {
            throw new ErroApi('Escolha o resultado da verificação.');
        }
        $num = function ($k) use ($d) {
            if (!isset($d[$k]) || $d[$k] === '' || $d[$k] === null) {
                return null;
            }
            if (!is_numeric($d[$k]) || $d[$k] < 0.7 || $d[$k] > 1.3) {
                throw new ErroApi('Tensão medida fora da faixa esperada (0,7 a 1,3 p.u.).');
            }
            return round((float) $d[$k], 4);
        };
        $vmin = $num('v_min_medido_pu');
        $vmax = $num('v_max_medido_pu');
        $obs = self::texto(isset($d['observacao']) ? $d['observacao'] : '', 1000);
        $nomes = array('conforme' => 'conforme o previsto', 'parcial' => 'parcialmente conforme', 'nao_conforme' => 'não conforme');
        return $this->alterarRegistro($me, self::validarId($id), function (array $r) use ($me, $res, $vmin, $vmax, $obs, $nomes) {
            if ($r['status'] !== 'executada') {
                throw new ErroApi('Só registros executados podem ser verificados.', 409);
            }
            $r['status'] = 'verificada';
            $r['verificacao'] = array('por' => Regulacao::quem($me), 'resultado' => $res, 'v_min_medido_pu' => $vmin,
                'v_max_medido_pu' => $vmax, 'observacao' => $obs, 'em' => BancoJson::agoraMs());
            return array($r, 'registro.verificado', 'Verificação: ' . $nomes[$res], $obs);
        });
    }

    public function cancelar(array $me, $id, $motivo)
    {
        self::exigir(!empty($me['uid']), 'Entre no sistema para cancelar.');
        $motivo = self::texto($motivo, 1000);
        if ($motivo === '') {
            throw new ErroApi('Informe o motivo do cancelamento.');
        }
        return $this->alterarRegistro($me, self::validarId($id), function (array $r) use ($me, $motivo) {
            if (in_array($r['status'], array('executada', 'verificada', 'cancelada', 'reprovada'), true)) {
                throw new ErroApi('Este registro não pode mais ser cancelado.', 409);
            }
            $autor = isset($r['criadoPor']['uid']) && $r['criadoPor']['uid'] === $me['uid'];
            if (!$autor && !Regulacao::podeAprovar($me) && !Regulacao::ehAdm($me)) {
                throw new ErroApi('Só quem abriu o registro ou um supervisor pode cancelar.', 403);
            }
            $r['status'] = 'cancelada';
            $r['cancelamento'] = array('por' => Regulacao::quem($me), 'motivo' => $motivo, 'em' => BancoJson::agoraMs());
            return array($r, 'registro.cancelado', 'Registro cancelado', $motivo);
        });
    }

    /**
     * Ajuste já executado (ex.: correção feita em campo e anotada só no SIGA).
     * Entra direto como executado e atualiza o ajuste vigente se for o mais recente.
     */
    public function retroativo(array $me, array $d)
    {
        self::exigir(self::podePropor($me), 'Apenas engenheiro ou supervisor registram ajustes retroativos.');
        $ajustesBrutos = isset($d['ajustes']) && is_array($d['ajustes']) ? $d['ajustes'] : array();
        if (!$ajustesBrutos) {
            throw new ErroApi('Informe o equipamento e o ajuste que foi feito.');
        }
        $ref = self::referencias($d);
        if ($ref['manobra_adms'] === '' && $ref['nota_siga'] === '') {
            throw new ErroApi('Informe o nº da nota no SIGA ou da manobra no ADMS.');
        }
        $quando = self::dataHoraExecucao(isset($d['executado_em']) ? $d['executado_em'] : '');
        $campos = $this->camposBasicos($d, true);
        $aj = $this->validarAjustes($ajustesBrutos);
        $deParaAj = self::deParaAjustes($aj);
        $obs = self::texto(isset($d['observacao']) ? $d['observacao'] : '', 1000);
        $extra = array('referencias' => $ref, 'execucao' => array('por' => self::quem($me), 'executado_em' => $quando,
            'observacao' => $obs, 'origem' => $ref['nota_siga'] !== '' ? 'SIGA' : 'ADMS', 'em' => BancoJson::agoraMs(), 'retroativo' => true));
        return $this->novoRegistro($me, 'RETROATIVO', 'executada', $campos, $deParaAj, null, 'registro.retroativo',
            'Ajuste retroativo registrado' . ($ref['nota_siga'] !== '' ? ' · nota SIGA ' . $ref['nota_siga'] : ''), $extra, $quando);
    }

    // ================================================================== registros: gravação

    private function camposBasicos(array $d, $exigirTitulo)
    {
        $titulo = self::texto(isset($d['titulo']) ? $d['titulo'] : '', 140);
        $motivo = isset($d['motivo']) ? (string) $d['motivo'] : '';
        $alimId = isset($d['alimentador_id']) ? (int) $d['alimentador_id'] : 0;
        if ($exigirTitulo && $titulo === '') {
            throw new ErroApi('Dê um título curto ao registro.');
        }
        if ($exigirTitulo && !isset(self::$MOTIVOS[$motivo])) {
            throw new ErroApi('Escolha o motivo.');
        }
        if ($motivo !== '' && !isset(self::$MOTIVOS[$motivo])) {
            throw new ErroApi('Motivo inválido.');
        }
        $al = $alimId ? $this->pub->alimentador($alimId) : null;
        if ($exigirTitulo && $al === null) {
            throw new ErroApi('Escolha o alimentador.');
        }
        $ref = self::referencias($d);
        return array(
            'titulo' => $titulo,
            'motivo' => $motivo,
            'descricao' => self::texto(isset($d['descricao']) ? $d['descricao'] : '', 3000),
            'alimentador_id' => $al ? (int) $al['id'] : null,
            'alimentador' => $al ? $al['codigo'] : '',
            'referencias_iniciais' => $ref,
        );
    }

    private static function referencias(array $d)
    {
        $limpa = function ($k) use ($d) {
            $v = isset($d[$k]) ? strtoupper(Regulacao::texto($d[$k], 40)) : '';
            return preg_replace('/[^A-Z0-9 ._\/-]/', '', $v);
        };
        return array('nota_siga' => $limpa('nota_siga'), 'manobra_adms' => $limpa('manobra_adms'),
            'incidente' => $limpa('incidente'), 'protocolo_reclamacao' => $limpa('protocolo_reclamacao'));
    }

    /** Data/hora real da execução: AAAA-MM-DDTHH:MM (do campo datetime-local) ou agora */
    private static function dataHoraExecucao($s)
    {
        $s = trim((string) $s);
        if ($s === '') {
            return BancoJson::agoraMs();
        }
        $t = strtotime(str_replace('T', ' ', $s));
        if ($t === false) {
            throw new ErroApi('Data/hora da execução inválida.');
        }
        if ($t > time() + 300) {
            throw new ErroApi('A data/hora da execução não pode estar no futuro.');
        }
        return $t * 1000.0;
    }

    private function novoRegistro(array $me, $tipo, $status, array $campos, array $ajustes, $simulacao, $acao, $descricao,
                                  array $extra = array(), $execEm = null)
    {
        $id = BancoJson::novoId('r');
        $agora = BancoJson::agoraMs();
        $ano = date('Y');
        $self = $this;
        $resultado = null;
        $this->db->transacao(function (BancoJson $db) use ($me, $tipo, $status, $campos, $ajustes, $simulacao, $acao,
                                                         $descricao, $extra, $execEm, $id, $agora, $ano, &$resultado) {
            $meta = &$db->meta();
            if (!isset($meta['contador'][$ano])) {
                $meta['contador'][$ano] = 0;
            }
            $meta['contador'][$ano]++;
            $prot = 'AJ-' . $ano . '-' . str_pad((string) $meta['contador'][$ano], 5, '0', STR_PAD_LEFT);
            $ref = $campos['referencias_iniciais'];
            unset($campos['referencias_iniciais']);
            $eqs = array();
            foreach ($ajustes as $a) {
                $eqs[] = $a['codigo'];
            }
            $r = array_merge(array(
                'id' => $id, 'protocolo' => $prot, 'tipo' => $tipo, 'status' => $status,
                'ajustes' => $ajustes, 'equipamentos' => $eqs, 'simulacao' => $simulacao,
                'referencias' => array_filter($ref, 'strlen'), 'pareceres' => array(),
                'aprovacao' => null, 'execucao' => null, 'verificacao' => null, 'cancelamento' => null,
                'criadoPor' => Regulacao::quem($me), 'criadoEm' => $agora, 'atualizadoEm' => $agora,
                '_v' => $db->versaoAtual(),
            ), $campos);
            if ($extra) {
                if (isset($extra['referencias'])) {
                    $r['referencias'] = array_filter(array_merge($r['referencias'], $extra['referencias']), 'strlen');
                    unset($extra['referencias']);
                }
                $r = array_merge($r, $extra);
            }
            $regs = $db->ler('registros', array());
            $regs[$id] = $r;
            $db->gravar('registros', $regs);
            $db->gravar('eventos/' . $id, array(array('em' => $agora, 'acao' => $acao, 'texto' => $descricao,
                'detalhe' => $r['descricao'], 'por' => Regulacao::quem($me))));
            if ($status === 'executada') {
                Regulacao::aplicarVigentes($db, $r, $execEm !== null ? $execEm : $agora);
            }
            $db->log(Regulacao::entradaLog($me, $acao, $descricao, $r));
            $resultado = $r;
        });
        return $resultado;
    }

    /**
     * Altera um registro dentro de uma transação. $fn recebe o registro e
     * devolve array(registro, acao, texto, detalhe[, executadoEm]).
     */
    private function alterarRegistro(array $me, $id, $fn)
    {
        $resultado = null;
        $this->db->transacao(function (BancoJson $db) use ($me, $id, $fn, &$resultado) {
            $regs = $db->ler('registros', array());
            if (!isset($regs[$id])) {
                throw new ErroApi('Registro não encontrado.', 404);
            }
            $antes = $regs[$id];
            $ret = call_user_func($fn, $antes);
            $r = $ret[0];
            $r['atualizadoEm'] = BancoJson::agoraMs();
            $r['_v'] = $db->versaoAtual();
            $regs[$id] = $r;
            $db->gravar('registros', $regs);
            $ev = $db->ler('eventos/' . $id, array());
            $ev[] = array('em' => $r['atualizadoEm'], 'acao' => $ret[1], 'texto' => $ret[2],
                'detalhe' => isset($ret[3]) ? $ret[3] : null, 'por' => Regulacao::quem($me));
            $db->gravar('eventos/' . $id, $ev);
            if ($r['status'] === 'executada' && $antes['status'] !== 'executada') {
                Regulacao::aplicarVigentes($db, $r, isset($ret[4]) ? $ret[4] : $r['atualizadoEm']);
            }
            $db->log(Regulacao::entradaLog($me, $ret[1], $ret[2] . ($ret[3] ? ': ' . $ret[3] : ''), $r));
            $resultado = $r;
        });
        return $resultado;
    }

    /** Atualiza ajustes_vigentes (chave = código do equipamento) com o "para" de cada ajuste, se mais recente */
    public static function aplicarVigentes(BancoJson $db, array $r, $em)
    {
        $vig = $db->ler('ajustes_vigentes', array());
        foreach ($r['ajustes'] as $a) {
            $id = (string) $a['codigo'];
            if (isset($vig[$id]) && (float) $vig[$id]['em'] > (float) $em) {
                continue; // já existe ajuste mais novo
            }
            $novo = isset($vig[$id]) ? $vig[$id] : array();
            $p = $a['para'];
            if (isset($p['estagios'])) {
                $novo['estagios_ligados'] = $p['estagios'];
            }
            if (isset($p['tap'])) {
                $novo['tap_atual'] = $p['tap'];
            }
            foreach (array('modo', 'v_ref_pu', 'banda_pu', 'temporizacao_s') as $k) {
                if (isset($p[$k])) {
                    $novo[$k] = $p[$k];
                }
            }
            $novo['em'] = (float) $em;
            $novo['protocolo'] = $r['protocolo'];
            $novo['registro_id'] = $r['id'];
            $vig[$id] = $novo;
        }
        $db->gravar('ajustes_vigentes', $vig);
        $meta = &$db->meta();
        $meta['vVigentes'] = $db->versaoAtual();
    }

    // ================================================================== leitura

    /** O que mudou desde a versão $v (mesmo esquema de sincronização do Comunica COI) */
    public function sincronizar($me, $v)
    {
        $v = max(0, (int) $v);
        $db = $this->db;
        $adm = self::ehAdm($me);
        $masters = $this->masters();
        $uid = is_array($me) && isset($me['uid']) ? $me['uid'] : '';
        $r = $db->leitura(function () use ($db, $v, $adm, $masters, $uid) {
            $meta = $db->ler('meta', $db->metaPadrao());
            $atual = (int) $meta['versao'];
            if ($v > 0 && $v === $atual) {
                return array('mudou' => false, 'v' => $atual);
            }
            $completo = $v === 0 || $v > $atual;
            $o = array('mudou' => true, 'v' => $atual, 'completo' => $completo);
            $regs = $db->ler('registros', array());
            $saida = array();
            foreach ($regs as $id => $reg) {
                if ($completo || (int) $reg['_v'] > $v) {
                    $saida[$id] = $reg;
                }
            }
            $o['registros'] = (object) $saida;
            if ($completo || (int) $meta['vUsuarios'] > $v) {
                $pub = array();
                foreach ($db->ler('usuarios', array()) + $masters as $id => $u) {
                    $p = Regulacao::publico($id, $u);
                    if (!$adm && $id !== $uid) {
                        $p = array('nome' => $p['nome'], 'perfil' => $p['perfil'], 'perfilNome' => $p['perfilNome'], 'ativo' => $p['ativo']);
                    }
                    $pub[$id] = $p;
                }
                $o['usuarios'] = (object) $pub;
            }
            $o['vigentesMudou'] = $completo || (int) $meta['vVigentes'] > $v;
            return $o;
        });
        if (!empty($r['vigentesMudou'])) {
            $r['equipamentos'] = $this->equipamentos();
        }
        unset($r['vigentesMudou']);
        return $r;
    }

    /** Dados de referência para a tela (cadastro publicado, listas, permissões) */
    public function referencia($me)
    {
        $alims = array();
        foreach ($this->pub->alimentadores() as $a) {
            $alims[] = $a;
        }
        return array(
            'alimentadores' => $alims,
            'subestacoes' => $this->pub->subestacoes(),
            'equipamentos' => $this->equipamentos(),
            'manifest' => $this->pub->manifest(),
            'exemplo' => $this->pub->usandoExemplo(),
            'perfis' => self::$PERFIS,
            'status' => self::$STATUS,
            'motivos' => self::$MOTIVOS,
            'permissoes' => self::permissoes($me),
        );
    }

    public function redeAlimentador($alimId)
    {
        $al = $this->pub->alimentador($alimId);
        if ($al === null) {
            throw new ErroApi('Alimentador não encontrado.', 404);
        }
        $nos = $this->pub->nos($alimId);
        return array('alimentador' => $al, 'equipamentos' => $this->equipamentosDoAlimentador($alimId, $nos), 'total_nos' => count($nos));
    }

    public function eventos($id)
    {
        $id = self::validarId($id);
        $db = $this->db;
        return $db->leitura(function () use ($db, $id) {
            return $db->ler('eventos/' . $id, array());
        });
    }

    // ================================================================== log e CSV

    public static function entradaLog($me, $acao, $descricao, array $r = array())
    {
        return array(
            'id' => BancoJson::novoId('l'),
            'em' => BancoJson::agoraMs(),
            'acao' => $acao,
            'acaoNome' => isset(self::$ACOES[$acao]) ? self::$ACOES[$acao] : $acao,
            'descricao' => self::texto($descricao, 2000),
            'protocolo' => isset($r['protocolo']) ? $r['protocolo'] : '',
            'registroId' => isset($r['id']) ? $r['id'] : '',
            'equipamento' => isset($r['equipamentos']) ? implode(', ', $r['equipamentos']) : '',
            'alimentador' => isset($r['alimentador']) ? $r['alimentador'] : '',
            'usuarioNome' => $me && isset($me['nome']) ? $me['nome'] : '',
            'usuarioLogin' => $me && isset($me['usuario']) ? $me['usuario'] : '',
            'uid' => $me && isset($me['uid']) ? $me['uid'] : '',
            'perfil' => $me && isset($me['perfil']) && isset(self::$PERFIS[$me['perfil']]) ? self::$PERFIS[$me['perfil']] : '',
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        );
    }

    public function logAvulso($me, $acao, $descricao)
    {
        try {
            $this->db->logAvulso(self::entradaLog($me, $acao, $descricao));
        } catch (Exception $e) {
            error_log('[regulacao-coi] log: ' . $e->getMessage());
        }
    }

    public function lerLogs(array $me, array $f, $offset, $limite)
    {
        $p = self::permissoes($me);
        self::exigir($p['logs'], 'Apenas supervisores e administradores veem os logs.');
        $arq = $this->db->arquivoLogJson();
        $de = !empty($f['de']) ? strtotime($f['de'] . ' 00:00:00') * 1000.0 : null;
        $ate = !empty($f['ate']) ? strtotime($f['ate'] . ' 23:59:59') * 1000.0 + 999 : null;
        $tipo = isset($f['tipo']) ? (string) $f['tipo'] : '';
        $q = isset($f['q']) ? self::normalizar($f['q']) : '';
        $todas = array();
        if (is_file($arq)) {
            $fp = fopen($arq, 'r');
            if ($fp) {
                flock($fp, LOCK_SH);
                while (($l = fgets($fp)) !== false) {
                    $e = json_decode($l, true);
                    if (!is_array($e)) {
                        continue;
                    }
                    if (($de !== null && $e['em'] < $de) || ($ate !== null && $e['em'] > $ate)) {
                        continue;
                    }
                    if ($tipo !== '' && strpos($e['acao'], $tipo) !== 0) {
                        continue;
                    }
                    if ($q !== '' && strpos(self::normalizar(implode(' ', array($e['acaoNome'], $e['descricao'], $e['protocolo'],
                        $e['equipamento'], $e['alimentador'], $e['usuarioNome'], $e['usuarioLogin'], $e['ip']))), $q) === false) {
                        continue;
                    }
                    $todas[] = $e;
                }
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
        $total = count($todas);
        $todas = array_reverse($todas);
        return array('total' => $total, 'logs' => array_slice($todas, max(0, (int) $offset), max(1, min(1000, (int) $limite))));
    }

    public static $COLUNAS_REGISTROS = array('ID', 'Protocolo', 'Tipo', 'Status', 'Título', 'Motivo', 'Alimentador',
        'Equipamentos', 'Ajustes (de → para)', 'Aberto em', 'Aberto por', 'Perfil', 'Pareceres', 'Decisão', 'Decidido por',
        'Decidido em', 'Executado em', 'Confirmado por', 'Origem execução', 'Manobra ADMS', 'Nota SIGA', 'Incidente',
        'Verificação', 'V mín medida (p.u.)', 'V máx medida (p.u.)', 'V mín prevista antes', 'V mín prevista depois',
        'V máx prevista antes', 'V máx prevista depois', 'Descrição', 'Última atualização');

    /** Escreve registros.csv (Excel brasileiro: UTF-8 com BOM, ";"). Chamado com a trava do banco. */
    public function gerarCsvRegistros(BancoJson $db, $arquivo)
    {
        $fp = fopen($arquivo, 'w');
        if (!$fp) {
            throw new ErroApi('Não foi possível gravar registros.csv.', 500);
        }
        fwrite($fp, Csv::BOM . Csv::linha(self::$COLUNAS_REGISTROS));
        $dt = function ($ms) {
            return $ms ? date('d/m/Y H:i', (int) floor((float) $ms / 1000)) : '';
        };
        foreach ($db->ler('registros', array()) as $r) {
            $aj = array();
            foreach ($r['ajustes'] as $a) {
                $aj[] = $a['codigo'] . ': ' . self::textoDePara($a['de']) . ' → ' . self::textoDePara($a['para']);
            }
            $sim = null;
            if (!empty($r['simulacao']['alimentadores'])) {
                foreach ($r['simulacao']['alimentadores'] as $s) {
                    if (!empty($s['principal'])) {
                        $sim = $s;
                    }
                }
            }
            $ap = $r['aprovacao'];
            $ex = $r['execucao'];
            $ve = $r['verificacao'];
            $ref = $r['referencias'];
            fwrite($fp, Csv::linha(array(
                $r['id'], $r['protocolo'], $r['tipo'], self::$STATUS[$r['status']], $r['titulo'],
                isset(self::$MOTIVOS[$r['motivo']]) ? self::$MOTIVOS[$r['motivo']] : $r['motivo'], $r['alimentador'],
                implode(', ', $r['equipamentos']), implode(' | ', $aj), $dt($r['criadoEm']), $r['criadoPor']['nome'],
                isset(self::$PERFIS[$r['criadoPor']['perfil']]) ? self::$PERFIS[$r['criadoPor']['perfil']] : '',
                count($r['pareceres']), $ap ? $ap['decisao'] : '', $ap ? $ap['por']['nome'] : '', $ap ? $dt($ap['em']) : '',
                $ex ? $dt($ex['executado_em']) : '', $ex ? $ex['por']['nome'] : '', $ex ? $ex['origem'] : '',
                isset($ref['manobra_adms']) ? $ref['manobra_adms'] : '', isset($ref['nota_siga']) ? $ref['nota_siga'] : '',
                isset($ref['incidente']) ? $ref['incidente'] : '', $ve ? $ve['resultado'] : '',
                $ve ? str_replace('.', ',', (string) $ve['v_min_medido_pu']) : '', $ve ? str_replace('.', ',', (string) $ve['v_max_medido_pu']) : '',
                $sim ? str_replace('.', ',', (string) $sim['antes']['v_min_pu']) : '', $sim ? str_replace('.', ',', (string) $sim['depois']['v_min_pu']) : '',
                $sim ? str_replace('.', ',', (string) $sim['antes']['v_max_pu']) : '', $sim ? str_replace('.', ',', (string) $sim['depois']['v_max_pu']) : '',
                $r['descricao'], $dt($r['atualizadoEm']),
            )));
        }
        fclose($fp);
    }

    public static function textoDePara(array $x)
    {
        $o = array();
        if (isset($x['modo'])) {
            $o[] = $x['modo'] === 'AUTO' ? 'auto' : 'manual';
        }
        if (isset($x['tap'])) {
            $o[] = 'tap ' . ($x['tap'] > 0 ? '+' : '') . $x['tap'];
        }
        if (isset($x['v_ref_pu'])) {
            $o[] = 'Vref ' . str_replace('.', ',', (string) $x['v_ref_pu']);
        }
        if (isset($x['banda_pu'])) {
            $o[] = 'banda ' . str_replace('.', ',', (string) round($x['banda_pu'] * 100, 2)) . '%';
        }
        if (isset($x['temporizacao_s'])) {
            $o[] = $x['temporizacao_s'] . ' s';
        }
        if (isset($x['estagios'])) {
            $o[] = $x['estagios'] . ' estágio(s)';
        }
        return implode(', ', $o);
    }

    public function caminhoCsv($nome)
    {
        $nome = $nome === 'logs' ? 'logs' : 'registros';
        $arq = $this->db->arquivoCsv($nome);
        if ($nome === 'registros' && !is_file($arq)) {
            $self = $this;
            $this->db->leitura(function (BancoJson $db) use ($self) {
                $db->regerarCsvRegistros();
            });
        }
        if (!is_file($arq)) {
            throw new ErroApi('Ainda não há dados para exportar.', 404);
        }
        return $arq;
    }

    // ================================================================== utilidades

    public static function texto($s, $max)
    {
        if (is_bool($s) || is_array($s) || $s === null) {
            return '';
        }
        $s = trim(str_replace(array("\r\n", "\r"), "\n", (string) $s));
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        return (string) substr($s, 0, $max * 2);
    }

    public static function limparLogin($s)
    {
        $s = strtolower(trim((string) $s));
        return (string) substr(preg_replace('/[^a-z0-9._-]/', '', $s), 0, 40);
    }

    public static function normalizar($s)
    {
        $s = strtolower(trim((string) $s));
        $de = array('á', 'à', 'â', 'ã', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ü', 'ç', 'Á', 'À', 'Â', 'Ã', 'É', 'Ê', 'Í', 'Ó', 'Ô', 'Õ', 'Ú', 'Ç');
        $pa = array('a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'u', 'c', 'a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c');
        return str_replace($de, $pa, $s);
    }

    public static function validarId($id)
    {
        if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,40}$/i', $id)) {
            throw new ErroApi('Identificador inválido.', 400);
        }
        return $id;
    }

    public static function validarSenha($s)
    {
        if (!is_string($s) || strlen($s) < 8) {
            throw new ErroApi('A senha precisa ter pelo menos 8 caracteres.');
        }
        if (strlen($s) > 200) {
            throw new ErroApi('Senha longa demais.');
        }
    }
}
