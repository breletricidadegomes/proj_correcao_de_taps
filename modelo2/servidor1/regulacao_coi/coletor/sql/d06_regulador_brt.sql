-- d06_regulador_brt.sql
-- Uma linha por banco de reguladores (BDGD UNREMT/EQRE + cadastro)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_brt,
    NULL AS cod_alimentador,
    NULL AS pac_fonte,   -- UNREMT.PAC_1 (lado da SE)
    NULL AS pac_carga,   -- UNREMT.PAC_2
    NULL AS potencia_unidade_kva,
    NULL AS n_unidades,
    NULL AS ligacao,
    NULL AS faixa_percent,   -- ex.: 10
    NULL AS n_degraus,   -- ex.: 32
    NULL AS telecomandado,   -- 'S'/'N'
    NULL AS modo_fluxo_reverso
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
