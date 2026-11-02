# Regulação de Tensão · Mudança de Tap (PHP 5.5+)

Ferramenta web para centros de operação (COI/COD) e engenharia de distribuição:
**simula, otimiza e registra mudanças de tap** em comutadores sob carga (OLTC) de
transformadores de subestação e em reguladores de tensão de linha de alimentadores MT,
com indicadores de conformidade de tensão do PRODIST Módulo 8.

Também responde duas perguntas de planejamento ligadas à geração distribuída:
**quanta GD cada ponto da rede suporta** (capacidade de hospedagem) e **como a tensão e o
comutador se comportam ao longo de um dia** de carga e sol variáveis.

Porte da versão Python/Flask + pandapower para PHP puro, sem dependências de servidor
além de PDO.

---

## 1. Requisitos

| Item | Mínimo |
|---|---|
| PHP | **5.5** (testado também em 8.3) |
| Extensões | `pdo`, `pdo_mysql` **ou** `pdo_pgsql`, `json`, `session`, `openssl` (token CSRF) |
| Banco | MySQL 5.5+ / MariaDB 5.5+ **ou** PostgreSQL 9.1+ |
| Servidor | Apache 2.2/2.4 (com `.htaccess`), Nginx ou IIS |
| Navegador | Chrome/Edge/Firefox atuais (Bootstrap 5.3 não suporta IE11) |

Nenhuma internet é necessária: Bootstrap 5.3.3 e Chart.js 4.4.1 estão em `assets/vendor/`.

## 2. Estrutura

```
index.php                     Painel principal (dashboard + formulário de simulação)
backend/
  processar_tap.php           Endpoint JSON (simular, otimizar, registrar, histórico,
                              hospedagem, diaria)
  bootstrap.php               Configuração, sessão, CSRF, fábrica de repositório
  lib/
    Complexo.php              Aritmética complexa
    FluxoCargaRadial.php      Fluxo de carga (varredura inversa/direta) — substitui pandapower
    ProdistTensao.php         Faixas adequada/precária/crítica (PRODIST Mód. 8)
    SimuladorTap.php          Monta o modelo, simula, calcula indicadores, otimiza tap
    AnaliseHospedagem.php     Capacidade de hospedagem de GD por nó e mapa do alimentador
    CurvasTipicas.php         Curvas diárias de carga (4 perfis) e de irradiância (3 céus)
    SimulacaoDiaria.php       Série de 24 h: tap fixo × relé automático × tap ótimo
    RepositorioInterface.php  Contrato de acesso a dados
    RepositorioPDO.php        MySQL/PostgreSQL (prepared statements)
    RepositorioDemo.php       Modo demonstração (sem banco; histórico na sessão)
    DadosDemo.php             Rede de exemplo (mesmos dados do seed SQL)
assets/js/app.js              Frontend: fetch/AJAX, gráficos, tabelas, modal de registro
assets/css/style.css          Tema COI escuro/claro de alta visibilidade
assets/vendor/                Bootstrap e Chart.js locais (licença MIT)
config/config.php             Configuração (banco, modo demo, parâmetros de cálculo)
sql/schema_mysql.sql          Esquema + dados de exemplo (MySQL)
sql/schema_postgresql.sql     Esquema + dados de exemplo (PostgreSQL)
tests/executar_testes.php     Testes do motor (php tests/executar_testes.php)
```

## 3. Implantação

### 3.1 Avaliação rápida (sem banco)
1. Copie a pasta para o servidor web (ex.: `/var/www/html/tap`).
2. Mantenha `'modo_demo' => true` em `config/config.php`.
3. Acesse `http://servidor/tap/`.

Teste local sem Apache: `php -S 127.0.0.1:8080` dentro da pasta.

### 3.2 Produção com MySQL
```bash
mysql -u root -p < sql/schema_mysql.sql
```
Crie o usuário de aplicação (comandos comentados no início do script — privilégios
mínimos: SELECT geral, INSERT no histórico e UPDATE apenas em `tap_atual`).
Copie `config/config.local.example.php` para **`config/config.local.php`** e preencha
`'modo_demo' => false`, `'driver' => 'mysql'` e as credenciais. Esse arquivo está no
`.gitignore`: a senha do banco nunca vai para o repositório (o `config.php` versionado
fica sem senha).

### 3.3 Produção com PostgreSQL
```bash
createdb regulacao_tap
psql -d regulacao_tap -f sql/schema_postgresql.sql
```
Em `config/config.local.php`: `'driver' => 'pgsql'`, `'porta' => 5432`.

### 3.4 Servidor web
- **Apache**: os `.htaccess` em `config/`, `sql/` e `backend/lib/` bloqueiam acesso direto
  (requer `AllowOverride All` ou equivalente).
- **Nginx**: adicione ao bloco `server`:
  ```nginx
  location ~ ^/(config|sql|tests|backend/lib)/ { deny all; }
  location ~ \.(md|sql)$ { deny all; }
  ```
- **IIS**: negue as mesmas pastas em *Request Filtering → Hidden Segments*.
- Recomendado: mover `config/` para fora da raiz pública e ajustar o caminho em
  `backend/bootstrap.php`.

### 3.5 Autenticação do operador
Se o servidor autentica via AD/LDAP (Kerberos, Basic, `mod_auth_*`), o usuário em
`$_SERVER['REMOTE_USER']` é gravado automaticamente como operador da manobra
(o campo fica somente leitura).

**Fora do modo demo o registro de manobra exige esse usuário**: se o servidor não
autenticar (`REMOTE_USER` vazio), `registrar` responde 403. Isso impede que alguém que
alcance o servidor grave manobras com um nome inventado. Para testes isolados, defina
`'exigir_remote_user' => false` no `config.local.php`. No IIS: *Autenticação do Windows*
ligada e *Anônima* desligada; no Apache: `mod_auth_kerb`/`mod_authnz_ldap` com `Require valid-user`.

## 4. Modelo de cálculo

- **Fluxo de carga**: varredura inversa/direta (backward/forward sweep), equivalente
  monofásico de sequência positiva, cargas de potência constante, p.u. com
  S<sub>base</sub> = 10 MVA e V<sub>base</sub> = tensão nominal do alimentador.
- **Ramo com regulação**: `V_pai → [relação ideal a] → [Z] → V_filho`, com
  `a = 1 + (tap − tap_neutro) × passo% / 100`.
  - Trafo SE: Z de curto-circuito (`vk%`, `vkr%`) referida à MT.
  - Regulador de linha: ideal (impedância própria desprezada), seguido do trecho.
- **Convenção de tap**: posição **positiva eleva** a tensão do lado regulado.
- **Validação**: no caso CTR-01 em carga pesada, tensões idênticas ao pandapower
  (diferença máx. 0,00003 p.u.; perdas 165,4 kW em ambos). Com o OLTC fora do neutro,
  diferenças ≤ 0,002 p.u. por convenção de referência da impedância ao lado do tap.

### Otimização de tap
Varre todas as posições do equipamento selecionado e ordena por:
0. barra da SE dentro da faixa adequada (restrição dura: ela alimenta os demais
   alimentadores, mesmo sem UCs cadastradas) →
1. menos UCs em faixa crítica → 2. menos UCs em faixa precária →
3. menor desvio |1 − Vmín| + |1 − Vmáx| → 4. menos degraus a partir do tap atual →
5. menores perdas.

### Capacidade de hospedagem de GD (aba "Hospedagem GD")
Quantos kW de GD podem ser ligados num nó antes da primeira violação. Um cenário é
viável quando todos os nós estão em faixa adequada, nenhum trecho passa de 100% da
ampacidade e o trafo da SE não passa de 100% da potência nominal.

- **Com o tap atual**: comutadores parados.
- **Ajustando o tap**: para cada valor testado, procura uma posição do equipamento
  selecionado que torne o cenário viável, começando pelas mais próximas da atual.
  Informa o tap necessário.
- **Busca**: duplicação a partir de 250 kW até o primeiro valor inviável, depois
  bisseção até 10 kW (configurável em `config.php`, bloco `hospedagem`).
- **Fluxo reverso**: menor GD que inverte o fluxo no primeiro trecho do caminho SE → nó
  (o alimentador passa a exportar para a barra).
- **Mapa**: a mesma análise para todos os nós, com o fator limitante de cada um.

Use o patamar **Leve** com a GD existente a 100%: é o pior caso para sobretensão.
A busca supõe que, a partir de um cenário viável, mais GD não corrige uma violação
causada por GD, o que vale para sobretensão e sobrecarga.

### Simulação de 24 horas (aba "Curva 24 h")
96 intervalos de 15 min. Carga e GD seguem curvas típicas normalizadas
(`CurvasTipicas.php`; substitua pelas curvas da distribuidora, por exemplo CRVCRG da BDGD).
Três estratégias para o equipamento selecionado:

| Estratégia | Comportamento |
|---|---|
| Tap fixo | Comutador parado na posição atual |
| Relé automático | Mede a tensão no nó de instalação e comuta um degrau por vez até voltar a V<sub>ref</sub> ± banda/2 (como um relé 90) |
| Tap ótimo | Melhor posição a cada intervalo pelo critério da otimização; limite teórico para comparação |

Indicadores diários: comutações (desgaste do comutador), % de intervalos fora da
faixa, DRP/DRC diários estimados (média no dia da fração de UCs em faixa
precária/crítica, análogo temporal ao indicador do PRODIST), V máx/mín com horário,
perdas em kWh e horas com fluxo reverso.

O relé só enxerga a própria barra: com GD distante do equipamento, o fim do
alimentador pode passar de 1,05 p.u. sem que ele perceba. A simulação do CTR-02 com
céu parcialmente nublado mostra esse efeito.

## 5. Indicadores

| Indicador | Cálculo nesta ferramenta |
|---|---|
| Classificação do nó | PRODIST Mód. 8 — MT: adequada 0,93–1,05; precária 0,90–0,93; crítica < 0,90 ou > 1,05 |
| DRP estimado | % de UCs em nós com tensão precária (retrato instantâneo) |
| DRC / ICC estimado | % de UCs em nós com tensão crítica |
| Queda máxima | (V<sub>barra SE</sub> − V<sub>nó</sub>) / V<sub>barra SE</sub> |
| Perdas técnicas | Σ I²R nos trechos + trafo SE |
| Fluxo reverso | P < 0 em algum trecho ou no trafo SE |

> DRP/DRC regulatórios são apurados com 1008 leituras de 10 min; os valores aqui são
> estimativas instantâneas para **comparar cenários de tap**, não substituem a medição.
> DEC/FEC são indicadores de **continuidade** e não variam com a posição de tap — por isso
> não são calculados pelo simulador.

## 6. Cadastro da rede

- `nos_rede`: um registro por nó. Os campos de trecho (`r_ohm_km`, `x_ohm_km`,
  `comprimento_km`, `ampacidade_a`) descrevem o trecho **entre o nó e seu pai**.
  O nó raiz (barra MT da SE) tem `no_pai_codigo = NULL`.
- Carga dos demais alimentadores da SE: lance como carga equivalente na barra raiz
  (o OLTC afeta todos os alimentadores da barra).
- `equipamentos_regulacao.no_codigo`: para `TRAFO_SE`, o código da barra raiz; para
  `REGULADOR`, o código do nó **imediatamente a jusante** do regulador.
- A topologia é validada a cada simulação (raiz única, sem órfãos, sem malhas).

## 7. Segurança

- PDO com prepared statements e `ATTR_EMULATE_PREPARES = false` (anti SQL Injection).
- Token CSRF em todas as requisições AJAX (`X-CSRF-Token`), comparação em tempo constante.
- Validação de faixa em todos os parâmetros; motivo da manobra por lista fechada.
- Indicadores antes/depois **recalculados no servidor** no momento do registro, com o
  equipamento travado (`SELECT … FOR UPDATE`) para evitar registros concorrentes.
- Saída escapada no HTML (`htmlspecialchars`) e no JS (`esc()`); cookies de sessão `HttpOnly`.
- `display_errors` desligado; detalhes técnicos só com `'debug' => true`.

## 8. Limitações conhecidas

- Modelo equilibrado (sequência positiva); desequilíbrio entre fases não é avaliado.
- Rede apenas MT (nós BT exigiriam trafos de distribuição como ramos adicionais).
- Bancos de capacitores e compensação de queda na linha (LDC) não são modelados; o relé
  automático da simulação de 24 h controla apenas a tensão no ponto de instalação, sem
  temporização entre comutações.
- A simulação de 24 h aplica a mesma curva de carga a todos os nós, inclusive à carga
  equivalente da barra da SE.

## 9. Testes

```bash
php tests/executar_testes.php
```

20 verificações sem dependências externas (roda em PHP 5.5): resultado do fluxo de
carga contra o pandapower, efeito do tap, capacidade de hospedagem (viável no limite,
inviável 20 kW acima) e coerência entre as estratégias de 24 h.
