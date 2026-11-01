<?php
/**
 * Configuração do Regulação COI — Modelo 2 (PHP 5.5+ / banco em JSON)
 *
 * Em produção, NÃO edite este arquivo: copie config.local.example.php para
 * config.local.php e sobrescreva ali só o que muda no servidor (pasta de
 * dados, masters, LDAP). O config.local.php tem prioridade e sobrevive às
 * atualizações do código.
 */

$config = array(

    // Mostra a mensagem real dos erros nas respostas da API. Deixe false no servidor.
    'debug' => false,

    'fuso_horario' => 'America/Maceio',

    // ---------------------------------------------------------------------
    // Banco de dados em JSON (mesmo método do Comunica COI)
    // ---------------------------------------------------------------------
    // Precisa de ESCRITA para a conta do PHP. A subpasta data/publicado é
    // escrita pelo coletor do servidor 1 (cadastro, rede e estudos).
    // Use barra normal (/) mesmo no Windows.
    'dir_dados' => __DIR__ . '/data',

    // Sem publicação do servidor 1, usa a rede de exemplo (aparece a faixa
    // "Dados de exemplo"). Desligue em produção depois da 1ª publicação.
    'modo_exemplo' => true,

    // Publicação mais velha que isto (horas) gera aviso na tela.
    'publicacao_antiga_horas' => 36,

    // ---------------------------------------------------------------------
    // Sessão e "tempo real"
    // ---------------------------------------------------------------------
    'nome_sessao' => 'RCOI_SESSAO',
    'sessao_minutos' => 720,          // plantão de 12 h
    'intervalo_sync_ms' => 5000,      // o navegador pergunta "mudou algo?" a cada 5 s

    'backup_dias' => 30,

    // Leitura sem login: quem não entrou vê o painel e os registros (sem ações).
    'leitura_publica' => true,

    // ---------------------------------------------------------------------
    // Cálculo (motor de fluxo de carga radial)
    // ---------------------------------------------------------------------
    'calculo' => array(
        'potencia_base_mva' => 10.0,
        'tolerancia_pu'     => 1e-6,
        'max_iteracoes'     => 60,
    ),

    // ---------------------------------------------------------------------
    // Login pela rede (Active Directory) — opcional, igual ao Comunica COI
    // ---------------------------------------------------------------------
    'ldap' => array(
        'ativo' => false,
        'servidor' => '',
        'dominio' => '',
        'dominio_netbios' => '',
        'base_dn' => '',
        'formato_bind' => '',
        'starttls' => false,
        'ignorar_certificado' => false,
        'timeout' => 5,
        'masters_pela_rede' => true,
    ),
    'sso' => array(
        'ativo' => false,
        'dominios' => array(),
    ),

    // ---------------------------------------------------------------------
    // ADMs master (fixos). Configure SÓ no config.local.php (não versionado):
    //   'master' => array(
    //       'usuarios'   => array('U0000000', 'U0000001'),
    //       'senha_hash' => '<php -r "echo password_hash(\'Senha\', PASSWORD_DEFAULT);">',
    //   ),
    // Sem master, o primeiro acesso pede para criar o administrador.
    // ---------------------------------------------------------------------
    'master' => array(
        'usuarios' => array(),
        'nomes' => array(),
        'senha_hash' => '',
    ),
);

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
