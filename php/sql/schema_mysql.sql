-- =============================================================================
--  Ferramenta de Mudança de Tap - Esquema MySQL (5.5+ / MariaDB 5.5+)
--  Uso:  mysql -u root -p < sql/schema_mysql.sql
-- =============================================================================
CREATE DATABASE IF NOT EXISTS regulacao_tap
  DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE regulacao_tap;

-- Usuário de aplicação com privilégios mínimos (ajuste a senha!)
-- CREATE USER 'app_tap'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
-- GRANT SELECT ON regulacao_tap.* TO 'app_tap'@'localhost';
-- GRANT INSERT ON regulacao_tap.historico_manobras_tap TO 'app_tap'@'localhost';
-- GRANT UPDATE (tap_atual) ON regulacao_tap.equipamentos_regulacao TO 'app_tap'@'localhost';

-- -----------------------------------------------------------------------------
-- Alimentadores (unidade de simulação)
-- -----------------------------------------------------------------------------
CREATE TABLE alimentadores (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo             VARCHAR(20)  NOT NULL,
  nome               VARCHAR(120) NOT NULL,
  subestacao         VARCHAR(80)  NOT NULL,
  tensao_nominal_kv  DECIMAL(6,3) NOT NULL,
  ativo              TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_alimentador_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- -----------------------------------------------------------------------------
-- Equipamentos de regulação: OLTC do trafo da SE e reguladores de linha
--   no_codigo: TRAFO_SE  -> código da barra MT da SE (nó raiz)
--              REGULADOR -> código do nó imediatamente a jusante do regulador
-- -----------------------------------------------------------------------------
CREATE TABLE equipamentos_regulacao (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alimentador_id        INT UNSIGNED NOT NULL,
  tipo                  ENUM('TRAFO_SE','REGULADOR') NOT NULL,
  codigo                VARCHAR(30)  NOT NULL,
  descricao             VARCHAR(150) NULL,
  no_codigo             VARCHAR(30)  NOT NULL,
  sn_kva                DECIMAL(10,1) NOT NULL,
  vk_percent            DECIMAL(6,3) NOT NULL DEFAULT 0,   -- impedância de curto Z%
  vkr_percent           DECIMAL(6,3) NOT NULL DEFAULT 0,   -- parcela resistiva R%
  tensao_primaria_kv    DECIMAL(7,3) NOT NULL,
  tensao_secundaria_kv  DECIMAL(7,3) NOT NULL,
  tap_min               SMALLINT     NOT NULL,
  tap_max               SMALLINT     NOT NULL,
  tap_neutro            SMALLINT     NOT NULL DEFAULT 0,
  tap_passo_percent     DECIMAL(6,4) NOT NULL,
  tap_atual             SMALLINT     NOT NULL DEFAULT 0,
  ativo                 TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_equip_alimentador (alimentador_id),
  CONSTRAINT fk_equip_alimentador FOREIGN KEY (alimentador_id) REFERENCES alimentadores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- -----------------------------------------------------------------------------
-- Nós da rede. Dados de trecho (r, x, comprimento, ampacidade) referem-se ao
-- trecho que liga o nó ao seu nó PAI. O nó raiz (barra da SE) tem pai NULL.
-- Cargas da barra da SE podem representar os demais alimentadores (equivalente).
-- -----------------------------------------------------------------------------
CREATE TABLE nos_rede (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alimentador_id    INT UNSIGNED NOT NULL,
  codigo            VARCHAR(30)  NOT NULL,
  nome              VARCHAR(120) NOT NULL,
  no_pai_codigo     VARCHAR(30)  NULL,
  r_ohm_km          DECIMAL(8,4) NOT NULL DEFAULT 0,
  x_ohm_km          DECIMAL(8,4) NOT NULL DEFAULT 0,
  comprimento_km    DECIMAL(8,3) NOT NULL DEFAULT 0,
  ampacidade_a      DECIMAL(7,1) NOT NULL DEFAULT 0,
  carga_kw          DECIMAL(10,1) NOT NULL DEFAULT 0,
  carga_kvar        DECIMAL(10,1) NOT NULL DEFAULT 0,
  gd_kw             DECIMAL(10,1) NOT NULL DEFAULT 0,
  num_consumidores  INT UNSIGNED NOT NULL DEFAULT 0,
  ordem             INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_no_alimentador_codigo (alimentador_id, codigo),
  CONSTRAINT fk_no_alimentador FOREIGN KEY (alimentador_id) REFERENCES alimentadores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- -----------------------------------------------------------------------------
-- Histórico de manobras de tap (trilha de auditoria)
-- -----------------------------------------------------------------------------
CREATE TABLE historico_manobras_tap (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipamento_id    INT UNSIGNED NOT NULL,
  alimentador_id    INT UNSIGNED NOT NULL,
  data_hora         DATETIME     NOT NULL,
  operador          VARCHAR(80)  NOT NULL,
  tap_anterior      SMALLINT     NOT NULL,
  tap_novo          SMALLINT     NOT NULL,
  v_fonte_pu        DECIMAL(6,4) NOT NULL,
  fator_carga       DECIMAL(5,3) NOT NULL,
  fator_gd          DECIMAL(5,3) NOT NULL,
  v_min_antes_pu    DECIMAL(6,4) NULL,
  v_min_depois_pu   DECIMAL(6,4) NULL,
  v_max_antes_pu    DECIMAL(6,4) NULL,
  v_max_depois_pu   DECIMAL(6,4) NULL,
  drp_antes         DECIMAL(6,2) NULL,
  drp_depois        DECIMAL(6,2) NULL,
  drc_antes         DECIMAL(6,2) NULL,
  drc_depois        DECIMAL(6,2) NULL,
  perdas_antes_kw   DECIMAL(10,1) NULL,
  perdas_depois_kw  DECIMAL(10,1) NULL,
  motivo            VARCHAR(30)  NOT NULL,
  observacao        VARCHAR(500) NULL,
  ip_origem         VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  KEY idx_hist_alim_data (alimentador_id, data_hora),
  KEY idx_hist_equip (equipamento_id),
  CONSTRAINT fk_hist_equip FOREIGN KEY (equipamento_id) REFERENCES equipamentos_regulacao (id),
  CONSTRAINT fk_hist_alim  FOREIGN KEY (alimentador_id) REFERENCES alimentadores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

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
