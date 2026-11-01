# Modelo 2 · Regulação COI — Etapa 1: gestão de ajustes

Simulador e registrador corporativo de ajustes de regulação de tensão: OLTC dos
transformadores de SE (69/138 kV), reguladores de tensão (BRT) e bancos de capacitores (BC).
Substitui o registro de correções de tap em observações livres de notas do SIGA por um fluxo
com proposta simulada, aprovação, execução com referência (manobra ADMS / nota SIGA) e
trilha de auditoria.

> O **Modelo 1** é o simulador Flask/pandapower da raiz do repositório e a versão
> [`php/`](../php/) de apoio à decisão. O Modelo 2 reaproveita o motor de cálculo do `php/`.

## Arquitetura em dois servidores

```
 Snowflake (BDGD + SCADA)
        │  SQL (sql/*.sql)
        ▼
 SERVIDOR 1 · Windows 10 · Python 3.12          SERVIDOR 2 · PHP 5.5 · IIS
 C:\servportaloperd\regulacao_coi\              \\servportaloper\nucleo\Regulacao_coi\
 ┌──────────────────────────────┐   cópia de    ┌───────────────────────────────────┐
 │ coletor.py                   │   arquivos    │ index.php / api.php (usuários)    │
 │  · monta a rede radial       │ ────────────► │ data/publicado/  (só leitura)     │
 │    reduzida por alimentador  │ data/publicado│   manifest.json  (gravado por      │
 │  · publica cadastro + rede   │               │   último = publicação completa)   │
 │  · espelha os registros      │ ◄──────────── │ data/registros.json  (PHP grava)  │
 │    (espelho/ → MySQL, BI)    │   espelho/    │ data/ajustes_vigentes.json        │
 └──────────────────────────────┘               │ data/csv/registros.csv, logs.csv  │
                                                └───────────────────────────────────┘
```

- A rede entre os servidores só permite **cópia de arquivos**: o servidor 1 escreve em
  `data\publicado` do compartilhamento do servidor 2 e lê de volta os registros.
- O PHP nunca acessa o Snowflake. Os usuários só falam com o servidor 2.
- Registros, usuários e logs ficam em JSON no servidor 2 (método do Comunica COI:
  `flock` em `.trava`, gravação atômica tmp+rename, `meta.json` com versão, `logs.jsonl`,
  CSV com BOM e `;`, backup diário). Nada é apagado; cancelamento é um status.
- O ajuste vigente de cada equipamento é guardado pelo **código** do equipamento, que é
  estável entre publicações (os ids numéricos podem mudar).

## Perfis e fluxo

| Perfil | Simula | Propõe | Parecer | Aprova | Registra execução |
|---|:-:|:-:|:-:|:-:|:-:|
| Engenheiro | ✔ | ✔ | ✔ | | ✔ |
| Supervisor | ✔ | ✔ | ✔ | ✔ | ✔ |
| Controlador | | | | | ✔ |
| Outra área | | solicita | ✔ | | |

```
solicitada ─► em_analise ─► aprovada ─► executada ─► verificada
                 │  ▲           │
                 ▼  │           └─► (cancelada)
              devolvida      reprovada
```

Execução exige manobra ADMS **ou** nota SIGA. Ajustes já feitos fora do sistema entram
como **retroativos** (status `executada`, origem SIGA) e só passam a valer se forem mais
recentes que o ajuste vigente.

## Pastas

| Pasta | Vai para |
|---|---|
| [`servidor2/Regulacao_coi/`](servidor2/Regulacao_coi/) | `\\servportaloper\nucleo\Regulacao_coi` (site PHP) |
| [`servidor1/regulacao_coi/coletor/`](servidor1/regulacao_coi/coletor/) | `C:\servportaloperd\regulacao_coi\coletor` |

Instalação passo a passo: [DEPLOY.md](DEPLOY.md).

## Testes

```bash
php modelo2/servidor2/Regulacao_coi/tests/executar_testes.php   # 48 testes (PHP 5.5+)
python -m pytest -q modelo2/servidor1                           # redução de rede
python modelo2/servidor1/regulacao_coi/coletor/coletor.py --demo --uma-vez   # publicação de exemplo
```

## Fora do repositório (público)

`config.local.php`, `config.ini`, a pasta `data/` e o emblema corporativo (`assets/coi.png`,
`coi-64.png`, `favicon.png`) não são versionados; seguem no pacote interno. Sem o emblema a
interface funciona normalmente, apenas sem a imagem.

## Próximas etapas

2. Trafos MT/BT e otimização assistida por alimentador · 3. Subtransmissão (malha/anel) ·
4. Integração de leitura com ADMS/SIGA para conferência automática da execução.
