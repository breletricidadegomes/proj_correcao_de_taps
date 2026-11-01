<?php
/**
 * =============================================================================
 *  CONFIGURAÇÃO GERAL - Ferramenta de Mudança de Tap (PHP 5.5+)
 * =============================================================================
 *  Edite este arquivo na implantação. Em produção, mantenha a pasta /config
 *  FORA da raiz pública do servidor web ou proteja-a via .htaccess (incluso).
 *
 *  Compatível com: PHP 5.5, 5.6, 7.x e 8.x
 *  Extensões necessárias: pdo, pdo_mysql OU pdo_pgsql, json, session
 * =============================================================================
 */

// Impede acesso direto a este arquivo pelo navegador
if (!defined('APP_TAP')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Acesso negado.');
}

// Fuso horário dos registros de manobra
date_default_timezone_set('America/Sao_Paulo');

return array(

    // -------------------------------------------------------------------------
    // MODO DEMONSTRAÇÃO
    //   true  -> usa a rede de exemplo embutida (backend/lib/DadosDemo.php) e
    //            grava o histórico apenas na sessão PHP. Não precisa de banco.
    //   false -> usa o banco configurado abaixo (produção).
    // -------------------------------------------------------------------------
    'modo_demo' => true,

    // -------------------------------------------------------------------------
    // BANCO DE DADOS (PDO)
    //   driver: 'mysql' ou 'pgsql'
    // -------------------------------------------------------------------------
    'db' => array(
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'porta'    => 3306,          // MySQL: 3306 | PostgreSQL: 5432
        'banco'    => 'regulacao_tap',
        'usuario'  => 'app_tap',
        'senha'    => 'TROQUE_ESTA_SENHA',
        'charset'  => 'utf8',        // MySQL 5.5.3+ aceita 'utf8mb4'
    ),

    // -------------------------------------------------------------------------
    // PARÂMETROS DE CÁLCULO
    // -------------------------------------------------------------------------
    'calculo' => array(
        'potencia_base_mva'  => 10.0,   // Base de potência do sistema p.u.
        'tolerancia_pu'      => 1e-6,   // Critério de convergência (p.u.)
        'max_iteracoes'      => 60,     // Limite da varredura direta/inversa
        // Limites de indicadores PRODIST Módulo 8 (valores de referência)
        'drp_limite_percent' => 3.0,
        'drc_limite_percent' => 0.5,
    ),

    // -------------------------------------------------------------------------
    // CAPACIDADE DE HOSPEDAGEM DE GD
    // -------------------------------------------------------------------------
    'hospedagem' => array(
        'resolucao_kw'     => 10,     // precisão da resposta (kW)
        'passo_inicial_kw' => 250,    // primeiro valor testado; depois dobra
        'max_kw'           => 30000,  // teto da busca
        'limite_nos_mapa'  => 60,     // mapa completo só até esta quantidade de nós
    ),

    // -------------------------------------------------------------------------
    // SEGURANÇA
    // -------------------------------------------------------------------------
    // Exibir mensagens técnicas de erro no JSON (apenas em homologação!)
    'debug' => false,

    // Nome de usuário: se o servidor web autentica via AD/LDAP (Basic/Kerberos),
    // $_SERVER['REMOTE_USER'] é usado automaticamente como operador da manobra.
    'usar_remote_user' => true,
);
