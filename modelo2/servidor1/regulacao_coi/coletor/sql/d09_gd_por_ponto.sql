-- d09_gd_por_ponto.sql
-- Uma linha por ponto MT com GD (BDGD UGBT/UGMT + cadastro de acesso)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS pac_mt,
    NULL AS cod_alimentador,
    NULL AS potencia_instalada_kw,
    NULL AS n_unidades,
    NULL AS fonte,
    NULL AS situacao   -- 'CONECTADA' ou 'APROVADA'
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
