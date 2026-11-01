-- d02_transformador_potencia.sql
-- Uma linha por trafo de SE (BDGD UNTRAT/EQTRAT + placa)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_trafo,
    NULL AS cod_se,
    NULL AS tensao_primaria_kv,
    NULL AS tensao_secundaria_kv,
    NULL AS potencia_nominal_mva,
    NULL AS potencia_maxima_mva,
    NULL AS impedancia_percent,
    NULL AS perdas_cobre_kw,
    NULL AS possui_oltc,   -- 'S'/'N'
    NULL AS tap_min,
    NULL AS tap_max,
    NULL AS tap_neutro,
    NULL AS passo_percent,
    NULL AS cod_barra_secundaria
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
