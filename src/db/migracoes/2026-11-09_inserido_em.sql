-- Momento em que cada leitura chegou ao servidor (independe do instante da
-- medição, created_at). Com o envio de leituras atrasadas (campo "timestamp"
-- da API, usado pelo firmware que guarda leituras enquanto está sem rede), o
-- limite de requisições não pode contar pelo created_at: um dispositivo
-- escaparia dele mandando horários antigos. O RateLimiter passa a contar por aqui.
--
-- As leituras que já existiam ficam com inserido_em NULO (o limitador as
-- ignora). Com um DEFAULT direto no ADD COLUMN, todas recebiam o horário da
-- migração e, no minuto seguinte, cada dispositivo parecia ter enviado o
-- histórico inteiro "agora": o limitador recusava tudo (teste de atualização
-- de 09/11/2026: 307 envios recusados). O valor automático só vale daqui para
-- frente (segundo comando; MODIFY e não ALTER COLUMN ... SET DEFAULT, que o
-- MySQL 8 recusa para current_timestamp()).

ALTER TABLE `device_payloads`
  ADD COLUMN `inserido_em` timestamp NULL DEFAULT NULL COMMENT 'Quando a leitura chegou ao servidor (o limite de requisições conta por aqui; NULL = leitura anterior a 09/11/2026)',
  ADD KEY `idx_device_inserido` (`device_id`,`inserido_em`);

ALTER TABLE `device_payloads`
  MODIFY COLUMN `inserido_em` timestamp NULL DEFAULT current_timestamp() COMMENT 'Quando a leitura chegou ao servidor (o limite de requisições conta por aqui; NULL = leitura anterior a 09/11/2026)';
