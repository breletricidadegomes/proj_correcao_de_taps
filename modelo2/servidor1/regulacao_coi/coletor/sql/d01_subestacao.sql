-- d01_subestacao.sql
-- Uma linha por subestação (BDGD SUB + cadastro)
-- Contrato com o coletor: mantenha EXATAMENTE os nomes das colunas (aliases).
-- Troque cada NULL pela coluna da sua base e <<ESQUEMA.TABELA>> pela origem real.
-- Coluna sem dado: deixe NULL (o coletor usa valor típico e avisa no log).
SELECT
    NULL AS cod_se,
    NULL AS nome_se,
    NULL AS tipo_se,   -- 'DISTRIBUICAO' (AT/MT) ou 'SUBTRANSMISSAO' (AT/AT)
    NULL AS tensoes_kv,   -- ex.: '69/13.8'
    NULL AS regional,
    NULL AS municipio
FROM <<ESQUEMA.TABELA>>
WHERE 1 = 0   -- remova esta linha quando a consulta estiver pronta
