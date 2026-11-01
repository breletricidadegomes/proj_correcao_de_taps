-- f02_posicao_tap.sql
-- Última posição de tap por equipamento (ADMS). Basta a mais recente de cada um.
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS data_hora,
    NULL AS cod_equipamento,
    NULL AS posicao_tap,
    NULL AS modo
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
