-- d05_chave_mt.sql
-- Uma linha por chave MT (BDGD UNSEMT)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_chave,
    NULL AS cod_alimentador,
    NULL AS pac_1,
    NULL AS pac_2,
    NULL AS estado_normal,   -- 'NA' ou 'NF'
    NULL AS telecomandada   -- 'S'/'N'
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
