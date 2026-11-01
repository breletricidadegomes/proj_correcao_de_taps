-- =============================================================================
--  Ferramenta de Mudança de Tap - Esquema PostgreSQL (9.1+)
--  Uso:  createdb regulacao_tap
--        psql -d regulacao_tap -f sql/schema_postgresql.sql
-- =============================================================================

-- Usuário de aplicação com privilégios mínimos (ajuste a senha!)
-- CREATE ROLE app_tap LOGIN PASSWORD 'TROQUE_ESTA_SENHA';
-- GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_tap;
-- GRANT INSERT ON historico_manobras_tap TO app_tap;
-- GRANT UPDATE (tap_atual) ON equipamentos_regulacao TO app_tap;
-- GRANT USAGE ON SEQUENCE historico_manobras_tap_id_seq TO app_tap;

CREATE TABLE alimentadores (
  id                 SERIAL PRIMARY KEY,
  codigo             VARCHAR(20)  NOT NULL UNIQUE,
  nome               VARCHAR(120) NOT NULL,
  subestacao         VARCHAR(80)  NOT NULL,
  tensao_nominal_kv  NUMERIC(6,3) NOT NULL,
  ativo              SMALLINT     NOT NULL DEFAULT 1
);

-- no_codigo: TRAFO_SE -> barra MT da SE (nó raiz) | REGULADOR -> nó a jusante
CREATE TABLE equipamentos_regulacao (
  id                    SERIAL PRIMARY KEY,
  alimentador_id        INTEGER      NOT NULL REFERENCES alimentadores (id),
  tipo                  VARCHAR(10)  NOT NULL CHECK (tipo IN ('TRAFO_SE','REGULADOR')),
  codigo                VARCHAR(30)  NOT NULL,
  descricao             VARCHAR(150),
  no_codigo             VARCHAR(30)  NOT NULL,
  sn_kva                NUMERIC(10,1) NOT NULL,
  vk_percent            NUMERIC(6,3) NOT NULL DEFAULT 0,
  vkr_percent           NUMERIC(6,3) NOT NULL DEFAULT 0,
  tensao_primaria_kv    NUMERIC(7,3) NOT NULL,
  tensao_secundaria_kv  NUMERIC(7,3) NOT NULL,
  tap_min               SMALLINT     NOT NULL,
  tap_max               SMALLINT     NOT NULL,
  tap_neutro            SMALLINT     NOT NULL DEFAULT 0,
  tap_passo_percent     NUMERIC(6,4) NOT NULL,
  tap_atual             SMALLINT     NOT NULL DEFAULT 0,
  ativo                 SMALLINT     NOT NULL DEFAULT 1
);
CREATE INDEX idx_equip_alimentador ON equipamentos_regulacao (alimentador_id);

-- Dados de trecho referem-se ao trecho nó PAI -> nó. Raiz: pai NULL.
CREATE TABLE nos_rede (
  id                SERIAL PRIMARY KEY,
  alimentador_id    INTEGER      NOT NULL REFERENCES alimentadores (id),
  codigo            VARCHAR(30)  NOT NULL,
  nome              VARCHAR(120) NOT NULL,
  no_pai_codigo     VARCHAR(30),
  r_ohm_km          NUMERIC(8,4) NOT NULL DEFAULT 0,
  x_ohm_km          NUMERIC(8,4) NOT NULL DEFAULT 0,
  comprimento_km    NUMERIC(8,3) NOT NULL DEFAULT 0,
  ampacidade_a      NUMERIC(7,1) NOT NULL DEFAULT 0,
  carga_kw          NUMERIC(10,1) NOT NULL DEFAULT 0,
  carga_kvar        NUMERIC(10,1) NOT NULL DEFAULT 0,
  gd_kw             NUMERIC(10,1) NOT NULL DEFAULT 0,
  num_consumidores  INTEGER      NOT NULL DEFAULT 0,
  ordem             INTEGER      NOT NULL DEFAULT 0,
  CONSTRAINT uk_no_alimentador_codigo UNIQUE (alimentador_id, codigo)
);

CREATE TABLE historico_manobras_tap (
  id                BIGSERIAL PRIMARY KEY,
  equipamento_id    INTEGER      NOT NULL REFERENCES equipamentos_regulacao (id),
  alimentador_id    INTEGER      NOT NULL REFERENCES alimentadores (id),
  data_hora         TIMESTAMP    NOT NULL,
  operador          VARCHAR(80)  NOT NULL,
  tap_anterior      SMALLINT     NOT NULL,
  tap_novo          SMALLINT     NOT NULL,
  v_fonte_pu        NUMERIC(6,4) NOT NULL,
  fator_carga       NUMERIC(5,3) NOT NULL,
  fator_gd          NUMERIC(5,3) NOT NULL,
  v_min_antes_pu    NUMERIC(6,4),
  v_min_depois_pu   NUMERIC(6,4),
  v_max_antes_pu    NUMERIC(6,4),
  v_max_depois_pu   NUMERIC(6,4),
  drp_antes         NUMERIC(6,2),
  drp_depois        NUMERIC(6,2),
  drc_antes         NUMERIC(6,2),
  drc_depois        NUMERIC(6,2),
  perdas_antes_kw   NUMERIC(10,1),
  perdas_depois_kw  NUMERIC(10,1),
  motivo            VARCHAR(30)  NOT NULL,
  observacao        VARCHAR(500),
  ip_origem         VARCHAR(45)
);
CREATE INDEX idx_hist_alim_data ON historico_manobras_tap (alimentador_id, data_hora);
CREATE INDEX idx_hist_equip ON historico_manobras_tap (equipamento_id);

-- ---------------------------------------------------------------
-- DADOS DE EXEMPLO (mesma rede do modo demonstração)
-- ---------------------------------------------------------------
INSERT INTO alimentadores (id, codigo, nome, subestacao, tensao_nominal_kv, ativo) VALUES
  (1, 'CTR-01', 'Alimentador CTR-01 - Distrito Industrial', 'SE Centro 69/13,8 kV', 13.8, 1),
  (2, 'CTR-02', 'Alimentador CTR-02 - Zona Rural / Usina FV', 'SE Centro 69/13,8 kV', 13.8, 1);

INSERT INTO equipamentos_regulacao (id, alimentador_id, tipo, codigo, descricao, no_codigo, sn_kva, vk_percent, vkr_percent, tensao_primaria_kv, tensao_secundaria_kv, tap_min, tap_max, tap_neutro, tap_passo_percent, tap_atual, ativo) VALUES
  (1, 1, 'TRAFO_SE', 'TR-01 SE CTR', 'Trafo 69/13,8 kV 26,6 MVA - OLTC ±8 x 1,25%', 'BSE-CTR1', 26600, 10, 0.5, 69, 13.8, -8, 8, 0, 1.25, 0, 1),
  (2, 1, 'REGULADOR', 'RT-0142', 'Banco de reguladores 3x 167 kVA - 32 degraus ±10%', 'P05', 501, 0, 0, 13.8, 13.8, -16, 16, 0, 0.625, 0, 1),
  (3, 2, 'TRAFO_SE', 'TR-01 SE CTR', 'Trafo 69/13,8 kV 26,6 MVA - OLTC ±8 x 1,25%', 'BSE-CTR2', 26600, 10, 0.5, 69, 13.8, -8, 8, 0, 1.25, 0, 1);

INSERT INTO nos_rede (alimentador_id, codigo, nome, no_pai_codigo, r_ohm_km, x_ohm_km, comprimento_km, ampacidade_a, carga_kw, carga_kvar, gd_kw, num_consumidores, ordem) VALUES
  (1, 'BSE-CTR1', 'Barra 13,8 kV SE Centro (eqv. demais alim.)', NULL, 0, 0, 0, 0, 7000, 2800, 0, 0, 0),
  (1, 'P01', 'Saída SE - Av. Brasil', 'BSE-CTR1', 0.19, 0.39, 1.5, 530, 350, 150, 0, 620, 1),
  (1, 'P02', 'Rótula Distrito Industrial', 'P01', 0.19, 0.39, 2.5, 530, 600, 280, 0, 310, 2),
  (1, 'P03', 'Frigorífico (cliente A4)', 'P02', 0.19, 0.39, 2, 530, 1000, 480, 0, 1, 3),
  (1, 'P04', 'Bairro Jardim América', 'P03', 0.19, 0.39, 3, 530, 500, 210, 0, 1450, 4),
  (1, 'P05', 'Jusante RT-0142', 'P04', 0.368, 0.42, 2.5, 340, 380, 160, 0, 980, 5),
  (1, 'P06', 'Vila São José', 'P05', 0.368, 0.42, 3, 340, 340, 140, 0, 1120, 6),
  (1, 'P07', 'Fim de tronco - Granjas', 'P06', 0.696, 0.45, 4, 230, 220, 95, 0, 540, 7),
  (1, 'R01', 'Ramal Cerâmica Santa Rita', 'P03', 0.696, 0.45, 3.5, 230, 400, 190, 0, 2, 8),
  (1, 'R02', 'Ramal Loteamento Novo Horizonte', 'R01', 0.696, 0.45, 2.5, 230, 260, 110, 0, 860, 9),
  (2, 'BSE-CTR2', 'Barra 13,8 kV SE Centro (eqv. demais alim.)', NULL, 0, 0, 0, 0, 9000, 3600, 0, 0, 0),
  (2, 'Q01', 'Saída SE - Rod. MG-050', 'BSE-CTR2', 0.19, 0.39, 3, 530, 400, 170, 0, 380, 1),
  (2, 'Q02', 'Comunidade Ponte Alta', 'Q01', 0.368, 0.42, 4, 340, 350, 150, 0, 720, 2),
  (2, 'Q03', 'UFV Sol do Cerrado (GD 3 MW)', 'Q02', 0.368, 0.42, 6, 340, 50, 20, 4000, 1, 3),
  (2, 'Q04', 'Assentamento Boa Vista', 'Q03', 0.696, 0.45, 4, 230, 250, 110, 0, 610, 4),
  (2, 'Q05', 'Fim de linha - Irrigantes', 'Q04', 0.696, 0.45, 3, 230, 300, 140, 0, 95, 5);

-- Ajusta as sequências após inserir IDs explícitos
SELECT setval('alimentadores_id_seq', (SELECT MAX(id) FROM alimentadores));
SELECT setval('equipamentos_regulacao_id_seq', (SELECT MAX(id) FROM equipamentos_regulacao));
