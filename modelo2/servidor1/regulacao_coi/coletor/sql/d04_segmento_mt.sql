-- d04_segmento_mt.sql
-- Uma linha por segmento MT (BDGD SSDMT + SEGCON)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_segmento,
    NULL AS cod_alimentador,   -- SSDMT.CTMT
    NULL AS pac_1,
    NULL AS pac_2,
    NULL AS comprimento_m,   -- SSDMT.COMP
    NULL AS cod_condutor,   -- SSDMT.TIP_CND
    NULL AS r1_ohm_km,   -- SEGCON.R1
    NULL AS x1_ohm_km,   -- SEGCON.X1
    NULL AS ampacidade_a,   -- SEGCON.CNOM
    NULL AS fases
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
