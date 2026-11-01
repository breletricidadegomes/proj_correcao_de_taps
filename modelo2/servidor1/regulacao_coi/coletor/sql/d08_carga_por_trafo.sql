-- d08_carga_por_trafo.sql
-- Uma linha por trafo de distribuição ou UC de MT (agregação de UCBT/UCMT)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_trafo_dist,
    NULL AS cod_alimentador,
    NULL AS pac_mt,
    NULL AS potencia_kva,
    NULL AS n_ucs,
    NULL AS energia_media_mensal_kwh,   -- média de ENE_01..ENE_12
    NULL AS demanda_contratada_kw,   -- só UC de MT
    NULL AS classe_predominante,
    NULL AS tipologia_curva
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
