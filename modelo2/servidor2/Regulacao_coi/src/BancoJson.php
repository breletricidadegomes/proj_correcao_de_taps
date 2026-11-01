<?php
/**
 * Banco de dados em arquivos JSON.
 *
 * - Um único arquivo de trava (.trava) serializa as gravações: leitura usa
 *   LOCK_SH e gravação usa LOCK_EX, então dois controladores salvando ao
 *   mesmo tempo nunca corrompem um arquivo nem perdem a alteração do outro.
 * - Cada gravação vai primeiro para um .tmp e depois é renomeada por cima do
 *   original: se o servidor cair no meio, fica o arquivo antigo, inteiro.
 * - O meta.json guarda um número de versão que sobe a cada transação. É ele
 *   que permite ao navegador perguntar "mudou algo desde a versão X?" sem
 *   baixar tudo de novo.
 *
 * Compatível com PHP 5.5 a 8.x. Mesmo banco do Comunica COI, adaptado
 * para os registros de ajuste de regulação de tensão.
 */
class BancoJson
{
    private $dir;
    private $trava = null;
    private $meta = null;
    private $versaoTx = 0;
    private $emTransacao = false;

    // Espelhos em paralelo (CSV, log, backup diário)
    private $logsPendentes = array();
    private $csvSujo = false;
    private $geradorCsv = null;
    private $diasBackup = 30;

    public function __construct($dir)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
        $this->prepararPastas();
    }

    public function dir()
    {
        return $this->dir;
    }

    // ------------------------------------------------------------------ pastas

    private function prepararPastas()
    {
        foreach (array('', '/eventos', '/sessoes', '/csv', '/backup', '/publicado') as $sub) {
            $p = $this->dir . $sub;
            if (!is_dir($p)) {
                @mkdir($p, 0775, true);
            }
        }
        // Bloqueia acesso direto pela web caso a pasta fique dentro do site.
        $ht = $this->dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Apache 2.4\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        $wc = $this->dir . '/web.config';
        if (!is_file($wc)) {
            @file_put_contents($wc, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n");
        }
        $ix = $this->dir . '/index.html';
        if (!is_file($ix)) {
            @file_put_contents($ix, '');
        }
    }

    public function gravavel()
    {
        return is_dir($this->dir) && is_writable($this->dir)
            && is_writable($this->dir . '/eventos')
            && is_writable($this->dir . '/csv') && is_writable($this->dir . '/backup');
    }

    // ------------------------------------------------------------------ trava

    private function travar($modo)
    {
        if ($this->trava !== null) {
            return;
        }
        $arq = $this->dir . '/.trava';
        $fp = @fopen($arq, 'c');
        if (!$fp) {
            throw new ErroApi('Não foi possível abrir o banco. Confira a permissão de escrita na pasta de dados.', 500);
        }
        if (!flock($fp, $modo)) {
            fclose($fp);
            throw new ErroApi('O banco está ocupado. Tente de novo.', 503);
        }
        $this->trava = $fp;
    }

    private function destravar()
    {
        if ($this->trava !== null) {
            flock($this->trava, LOCK_UN);
            fclose($this->trava);
            $this->trava = null;
        }
    }

    /**
     * Executa $fn com trava exclusiva. Tudo o que $fn gravar entra na mesma
     * versão. Se $fn lançar exceção, nada do meta é gravado.
     */
    public function transacao($fn)
    {
        $this->travar(LOCK_EX);
        $this->emTransacao = true;
        try {
            $this->meta = $this->ler('meta', $this->metaPadrao());
            $this->versaoTx = (int) $this->meta['versao'] + 1;
            $this->logsPendentes = array();
            $this->csvSujo = false;
            $r = call_user_func($fn, $this);
            $this->meta['versao'] = $this->versaoTx;
            $this->meta['atualizadoEm'] = self::agoraMs();
            $this->gravar('meta', $this->meta);
            // Ainda com a trava: os espelhos ficam sempre iguais ao JSON.
            $this->espelhar();
            return $r;
        } finally {
            $this->logsPendentes = array();
            $this->concluir();
        }
    }

    // ================================================================== espelhos

    /** Função que reescreve o registros.csv: function (BancoJson $db, $arquivo). */
    public function aoGerarCsv($fn)
    {
        $this->geradorCsv = $fn;
    }

    public function definirDiasBackup($dias)
    {
        $this->diasBackup = max(1, (int) $dias);
    }

    /** Registra uma linha de log, gravada junto com a transação em curso. */
    public function log(array $entrada)
    {
        $this->logsPendentes[] = $entrada;
    }

    /** Reescreve logs.jsonl e logs.csv inteiros (usado só pelo reparo de datas). */
    public function reescreverLogs(array $entradas)
    {
        if (!$this->emTransacao) {
            throw new ErroApi('Reescrita de log fora de transação.', 500);
        }
        $jsonl = '';
        $csv = Csv::BOM . Csv::linha(self::$COLUNAS_LOG);
        foreach ($entradas as $e) {
            $jsonl .= self::json($e) . "\n";
            $csv .= Csv::linha(array(
                date('d/m/Y H:i:s', (int) floor($e['em'] / 1000)), $e['acaoNome'], $e['descricao'], $e['protocolo'],
                $e['registroId'], $e['equipamento'], $e['alimentador'], $e['usuarioNome'], $e['usuarioLogin'],
                $e['perfil'], $e['ip'],
            ));
        }
        file_put_contents($this->arquivoLogJson() . '.tmp', $jsonl);
        @unlink($this->arquivoLogJson());
        rename($this->arquivoLogJson() . '.tmp', $this->arquivoLogJson());
        file_put_contents($this->arquivoCsv('logs') . '.tmp', $csv);
        @unlink($this->arquivoCsv('logs'));
        rename($this->arquivoCsv('logs') . '.tmp', $this->arquivoCsv('logs'));
    }

    /** Grava log fora de transação (login, falha de login...). Não muda a versão. */
    public function logAvulso(array $entrada)
    {
        $this->travar(LOCK_EX);
        try {
            $this->logsPendentes = array($entrada);
            $this->gravarLogs();
        } finally {
            $this->logsPendentes = array();
            $this->destravar();
        }
    }

    private function espelhar()
    {
        try {
            $this->gravarLogs();
        } catch (Exception $e) {
            error_log('[regulacao-coi] falha ao gravar log: ' . $e->getMessage());
        }
        if ($this->csvSujo || !is_file($this->arquivoCsv('registros'))) {
            try {
                $this->regerarCsvRegistros();
            } catch (Exception $e) {
                error_log('[regulacao-coi] falha ao gravar registros.csv: ' . $e->getMessage());
            }
        }
        try {
            $this->backupDiario();
        } catch (Exception $e) {
            error_log('[regulacao-coi] falha no backup diário: ' . $e->getMessage());
        }
    }

    public function arquivoCsv($nome)
    {
        return $this->dir . '/csv/' . ($nome === 'logs' ? 'logs' : 'registros') . '.csv';
    }

    public function arquivoLogJson()
    {
        return $this->dir . '/logs.jsonl';
    }

    public static $COLUNAS_LOG = array('Data/hora', 'Ação', 'Descrição', 'Protocolo', 'ID registro',
        'Equipamento', 'Alimentador', 'Feito por', 'Login', 'Perfil', 'IP');

    private function gravarLogs()
    {
        if (!$this->logsPendentes) {
            return;
        }
        $jsonl = '';
        $csv = '';
        foreach ($this->logsPendentes as $e) {
            $jsonl .= self::json($e) . "\n";
            $csv .= Csv::linha(array(
                date('d/m/Y H:i:s', (int) floor($e['em'] / 1000)), $e['acaoNome'], $e['descricao'], $e['protocolo'],
                $e['registroId'], $e['equipamento'], $e['alimentador'], $e['usuarioNome'], $e['usuarioLogin'],
                $e['perfil'], $e['ip'],
            ));
        }
        if (@file_put_contents($this->arquivoLogJson(), $jsonl, FILE_APPEND) === false) {
            throw new ErroApi('Não foi possível gravar logs.jsonl.', 500);
        }
        $arq = $this->arquivoCsv('logs');
        if (!is_file($arq) || filesize($arq) === 0) {
            $csv = Csv::BOM . Csv::linha(self::$COLUNAS_LOG) . $csv;
        }
        if (@file_put_contents($arq, $csv, FILE_APPEND) === false) {
            throw new ErroApi('Não foi possível gravar logs.csv.', 500);
        }
        $this->logsPendentes = array();
    }

    /** Reescreve o CSV completo dos registros (arquivo temporário + rename). */
    public function regerarCsvRegistros()
    {
        if (!$this->geradorCsv) {
            return;
        }
        $arq = $this->arquivoCsv('registros');
        $tmp = $arq . '.' . getmypid() . '.tmp';
        call_user_func($this->geradorCsv, $this, $tmp);
        if (!@rename($tmp, $arq)) {
            @unlink($arq);
            if (!@rename($tmp, $arq)) {
                @unlink($tmp);
                throw new ErroApi('Não foi possível gravar registros.csv.', 500);
            }
        }
        $this->csvSujo = false;
    }

    /**
     * Uma vez por dia, na primeira gravação, copia o banco inteiro para
     * backup/AAAA-MM-DD/ (JSON + CSV). Guarda os últimos N dias.
     */
    private function backupDiario()
    {
        $hoje = date('Y-m-d');
        $pasta = $this->dir . '/backup/' . $hoje;
        if (is_dir($pasta)) {
            return;
        }
        if (!@mkdir($pasta, 0775, true)) {
            return;
        }
        foreach (array('meta', 'usuarios', 'registros', 'ajustes_vigentes') as $n) {
            $f = $this->dir . '/' . $n . '.json';
            if (is_file($f)) {
                @copy($f, $pasta . '/' . $n . '.json');
            }
        }
        $ev = array();
        foreach (glob($this->dir . '/eventos/*.json') ?: array() as $f) {
            $ev[basename($f, '.json')] = (object) $this->ler('eventos/' . basename($f, '.json'), array());
        }
        @file_put_contents($pasta . '/eventos.json', self::json((object) $ev, true));
        foreach (array('registros', 'logs') as $n) {
            if (is_file($this->arquivoCsv($n))) {
                @copy($this->arquivoCsv($n), $pasta . '/' . $n . '.csv');
            }
        }
        // limpeza
        $limite = date('Y-m-d', time() - $this->diasBackup * 86400);
        foreach (glob($this->dir . '/backup/*', GLOB_ONLYDIR) ?: array() as $d) {
            $nome = basename($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nome) && $nome < $limite) {
                foreach (glob($d . '/*') ?: array() as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }

    public function backupsDisponiveis()
    {
        $o = array();
        foreach (glob($this->dir . '/backup/*', GLOB_ONLYDIR) ?: array() as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', basename($d))) {
                $o[] = basename($d);
            }
        }
        rsort($o);
        return $o;
    }


    public function __destruct()
    {
        $this->destravar();
    }

    /** Executa $fn com trava compartilhada (só leitura). */
    public function leitura($fn)
    {
        $jaTinha = $this->trava !== null;
        if (!$jaTinha) {
            $this->travar(LOCK_SH);
        }
        try {
            return call_user_func($fn, $this);
        } finally {
            if (!$jaTinha) {
                $this->destravar();
            }
        }
    }

    /** Encerra a transação e libera a trava. */
    private function concluir()
    {
        $this->emTransacao = false;
        $this->meta = null;
        $this->destravar();
    }

    /** Versão que será gravada pela transação em curso. */
    public function versaoAtual()
    {
        return $this->versaoTx;
    }

    /** meta.json da transação em curso (para contador de protocolos etc.). */
    public function &meta()
    {
        if (!$this->emTransacao) {
            throw new ErroApi('meta() fora de transação.', 500);
        }
        return $this->meta;
    }

    public function metaPadrao()
    {
        return array(
            'versao' => 0,
            'vUsuarios' => 0,
            'vVigentes' => 0,
            'contador' => array(),
            'criadoEm' => self::agoraMs(),
        );
    }

    // ------------------------------------------------------------------ arquivos

    private function caminho($nome)
    {
        if (!preg_match('#^[a-z0-9_]+(/[A-Za-z0-9_-]+)?$#', $nome)) {
            throw new ErroApi('Nome de arquivo inválido.', 400);
        }
        return $this->dir . '/' . $nome . '.json';
    }

    public function existe($nome)
    {
        return is_file($this->caminho($nome));
    }

    public function ler($nome, $padrao = array())
    {
        $arq = $this->caminho($nome);
        if (!is_file($arq)) {
            return $padrao;
        }
        $txt = @file_get_contents($arq);
        if ($txt === false || $txt === '') {
            return $padrao;
        }
        // Remove BOM, caso alguém tenha editado o arquivo no Bloco de Notas.
        if (substr($txt, 0, 3) === "\xEF\xBB\xBF") {
            $txt = (string) substr($txt, 3);
        }
        $dados = json_decode($txt, true);
        if (!is_array($dados)) {
            throw new ErroApi('O arquivo ' . basename($arq) . ' está corrompido (' . json_last_error_msg() . '). Restaure o backup.', 500);
        }
        return $dados;
    }

    public function gravar($nome, $dados)
    {
        if (!$this->emTransacao) {
            throw new ErroApi('Gravação fora de transação.', 500);
        }
        $arq = $this->caminho($nome);
        $json = self::json($dados, true);
        $tmp = $arq . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new ErroApi('Não foi possível gravar no banco. Confira a permissão de escrita em ' . $this->dir . '.', 500);
        }
        if ($nome === 'registros' || $nome === 'usuarios') {
            $this->csvSujo = true;
        }
        if (!@rename($tmp, $arq)) {
            // Windows antigo não renomeia por cima de arquivo existente.
            @unlink($arq);
            if (!@rename($tmp, $arq)) {
                @unlink($tmp);
                throw new ErroApi('Não foi possível gravar ' . basename($arq) . '.', 500);
            }
        }
    }

    // ------------------------------------------------------------------ utilidades

    public static function json($dados, $bonito = false)
    {
        $op = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($bonito) {
            $op |= JSON_PRETTY_PRINT;
        }
        $s = json_encode($dados, $op);
        if ($s === false) {
            // Texto com bytes inválidos em UTF-8 (colado de outro sistema).
            $s = json_encode(self::limparUtf8($dados), $op);
        }
        if ($s === false) {
            throw new ErroApi('Falha ao converter dados para JSON: ' . json_last_error_msg(), 500);
        }
        return $s;
    }

    public static function limparUtf8($v)
    {
        if (is_array($v)) {
            $o = array();
            foreach ($v as $k => $x) {
                $o[$k] = self::limparUtf8($x);
            }
            return $o;
        }
        if (is_string($v) && !preg_match('//u', $v)) {
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
            }
            $c = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $v);
            return $c === false ? '' : $c;
        }
        return $v;
    }

    /** Milissegundos desde 1970, como o Date.now() do navegador. */
    public static function agoraMs()
    {
        // Sem (int): no PHP de 32 bits (PHP 5.x no Windows, mesmo o "x64") o
        // inteiro vai só até 2.147.483.647, e o horário em milissegundos
        // (1,7 trilhão) "dava a volta", gravando datas de janeiro de 1970.
        // Como float, o valor é exato até o ano 287.000.
        return round(microtime(true) * 1000);
    }

    /** O valor errado que a versão anterior gravava neste servidor (para o reparo). */
    public static function agoraMsAntigo()
    {
        return (int) round(microtime(true) * 1000);
    }

    /** Identificador curto, só [a-z0-9], seguro para nome de arquivo. */
    public static function novoId($prefixo)
    {
        $r = '';
        if (function_exists('openssl_random_pseudo_bytes')) {
            $r = bin2hex(openssl_random_pseudo_bytes(4));
        } else {
            $r = dechex(mt_rand(0, 0x7fffffff));
        }
        return $prefixo . base_convert((string) self::agoraMs(), 10, 36) . substr($r, 0, 6);
    }
}

if (!function_exists('json_last_error_msg')) {
    function json_last_error_msg()
    {
        return 'erro ' . json_last_error();
    }
}

/** CSV no formato que o Excel brasileiro abre direto: UTF-8 com BOM, ";" e CRLF. */
class Csv
{
    const BOM = "\xEF\xBB\xBF";

    public static function linha(array $campos)
    {
        $o = array();
        foreach ($campos as $c) {
            if (is_bool($c)) {
                $c = $c ? 'Sim' : 'Não';
            }
            $c = (string) $c;
            // Evita que o Excel execute fórmula vinda de um campo digitado.
            if ($c !== '' && strpos('=+-@', $c[0]) !== false && !is_numeric($c)) {
                $c = "'" . $c;
            }
            $o[] = '"' . str_replace('"', '""', str_replace(array("\r\n", "\r"), "\n", $c)) . '"';
        }
        return implode(';', $o) . "\r\n";
    }
}
