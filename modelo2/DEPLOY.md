# Implantação · Regulação COI (Modelo 2, etapa 1)

Ordem recomendada: **servidor 2 em modo exemplo → coletor em `--demo` → consultas reais →
desligar o modo exemplo**.

---

## 1. Servidor 2 (PHP 5.5, IIS)

1. Copie `servidor2/Regulacao_coi/` para `\\servportaloper\nucleo\Regulacao_coi`, junto com
   o emblema (`assets/coi.png`, `coi-64.png`, `favicon.png`), que segue no pacote interno.
2. Copie `config.local.example.php` para **`config.local.php`** e ajuste:
   - `dir_dados`: `//servportaloper/nucleo/Regulacao_coi/data` (barra normal);
   - `master`: os mesmos ADMs master e o mesmo `senha_hash` do Comunica COI / Escala COI;
   - `ldap` (senha de rede) e/ou `sso` (usuário do Windows pelo `sso.php`, com
     *Autenticação do Windows* ligada **só** nesse arquivo no IIS).
3. Permissões: a conta do pool do IIS precisa de **leitura e escrita** em `data\`.
4. Abra `diagnostico.php`: versão do PHP, extensões (json, mbstring, ldap opcional), escrita
   em `data\`, idade da última publicação. Tudo verde antes de seguir.
5. Abra `index.php`. No primeiro acesso aparece **Instalar**: cria o primeiro supervisor
   administrador (ou entre direto com um master). Cadastre os usuários em *Usuários*.

Enquanto `modo_exemplo` estiver `true` e não houver publicação, o sistema usa a rede de
exemplo (SE CTR, 2 alimentadores, RT-0142, BC-0310, BC-0721), boa para treinamento.

`web.config` esconde `data/`, `src/`, `views/`, `tests/` e os arquivos de configuração.
Confira abrindo `.../Regulacao_coi/data/meta.json` no navegador: deve dar **404**.

## 2. Servidor 1 (Windows 10, Python 3.12)

1. Copie `servidor1/regulacao_coi/` para `C:\servportaloperd\regulacao_coi\`.
2. Em `coletor\`, copie `config.ini.exemplo` para **`config.ini`** e preencha
   `[snowflake]` (SSO pelo navegador, ou par de chaves `.p8` para rodar sem ninguém logado)
   e confira `[servidor2] pasta_dados`.
3. A conta do Windows que roda o coletor precisa de **escrita** em
   `\\servportaloper\nucleo\Regulacao_coi\data\publicado` e **leitura** no restante de `data\`.
4. Teste sem Snowflake:
   ```bat
   iniciar_coletor.bat --demo --uma-vez
   ```
   Cria `.venv`, instala dependências, publica a rede fictícia em `data\publicado` e copia os
   registros para `..\espelho\`. Veja `..\saida\status_coletor.json`.

### Contrato das consultas (`coletor\sql\*.sql`)

Cada arquivo traz as colunas com os **nomes exatos** que o coletor espera. Troque os `NULL`
pelas colunas reais, `<<ESQUEMA.TABELA>>` pela origem e apague `WHERE 1 = 0`.

| Arquivo | Conteúdo | Obrigatório |
|---|---|:-:|
| d01_subestacao | SEs (código, nome, tensões) | ✔ |
| d02_transformador_potencia | trafos de SE com OLTC (faixa, passo, neutro, Vref, banda) | ✔ |
| d03_alimentador | alimentadores, PAC inicial, trafo de SE de origem | ✔ |
| d04_segmento_mt | segmentos MT com R1/X1/ampacidade (SSDMT + SEGCON) | ✔ |
| d05_chave_mt | chaves MT e estado normal NA/NF (UNSEMT) | ✔ |
| d06_regulador_brt | reguladores de linha (UNREMT) | ✔ |
| d07_banco_capacitor | bancos de capacitores (kvar, estágios, PAC) | ✔ |
| d08_carga_por_trafo | energia mensal / UCs por ponto (UCBT/UCMT agregadas) | ✔ |
| d09_gd_por_ponto | potência de GD por ponto | |
| d10_ajuste_controle | Vref/banda atuais do relé 90 | |
| f02_posicao_tap | última posição de tap medida (SCADA) | |
| f03_estado_bc | estágios ligados de cada BC (SCADA) | |

Rode `iniciar_coletor.bat --uma-vez --publicar` e confira os avisos em `status_coletor.json`
(PAC fora da árvore, malhas por chave NF indevida, colunas vazias).

### Tarefa agendada

Agendador de Tarefas → *Criar tarefa*: “Executar estando o usuário conectado ou não”,
disparo **Ao iniciar** + repetição diária, ação
`C:\servportaloperd\regulacao_coi\coletor\.venv\Scripts\python.exe coletor.py`, *Iniciar em*
`C:\servportaloperd\regulacao_coi\coletor`. Para rodar sem navegador use o par de chaves.

O coletor espelha os registros a cada `intervalo` (300 s) e republica cadastro/rede a cada
`publicar_a_cada_horas` (24 h). O `manifest.json` é gravado **por último**: o PHP só
considera uma publicação completa quando ele existe.

## 3. Virar a chave

Com a primeira publicação real conferida, em `config.local.php`:

```php
'modo_exemplo' => false,
```

`diagnostico.php` avisa se a publicação passar de `publicacao_antiga_horas` (36 h).

## 4. Backup e auditoria

- Backup diário automático em `data\backup\` (30 dias).
- `data\csv\registros.csv` e `logs.csv` abrem direto no Excel; o espelho no servidor 1 pode
  alimentar MySQL/Power BI.
- Registros nunca são apagados; correções viram novos eventos na trilha.
