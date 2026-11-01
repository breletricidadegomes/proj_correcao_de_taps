-- d07_banco_capacitor.sql
-- Uma linha por banco de capacitores (BDGD UNCRMT + cadastro)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_bc,
    NULL AS local,   -- 'SE' ou 'REDE'
    NULL AS cod_se,
    NULL AS cod_alimentador,
    NULL AS pac,
    NULL AS tensao_nominal_kv,
    NULL AS kvar_total,
    NULL AS n_estagios,
    NULL AS tipo_controle,
    NULL AS telecomandado   -- 'S'/'N'
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
