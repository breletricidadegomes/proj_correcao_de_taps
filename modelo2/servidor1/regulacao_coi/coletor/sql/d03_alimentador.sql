-- d03_alimentador.sql
-- Uma linha por circuito MT (BDGD CTMT)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_alimentador,   -- CTMT.COD_ID
    NULL AS nome_alimentador,
    NULL AS cod_se,
    NULL AS cod_trafo,   -- CTMT.UNI_TR_AT
    NULL AS tensao_nominal_kv,   -- decodificar TEN_NOM
    NULL AS pac_inicial   -- CTMT.PAC_INI
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
