-- d10_ajuste_controle.sql
-- RECOMENDADO. Ajustes do relé de OLTC/BRT (ADMS ou planilha). Pode ficar vazio.
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_equipamento,
    NULL AS modo,   -- 'AUTO'/'MANUAL'
    NULL AS tensao_referencia_pu,   -- ou converta da base 120 V
    NULL AS banda_percent,
    NULL AS temporizacao_s,
    NULL AS ldc_r_v,
    NULL AS ldc_x_v,
    NULL AS data_ultimo_ajuste
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
