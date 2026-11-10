# Teste de longa duração — 09/11/2026, 01h28 → 11h29 (10 h), PC local (HDD)

Pilha isolada `ifs_carga` (versão nova, já atualizada a partir de 95777ae com o dump real).
Carga: 200 dispositivos a cada 10 s (100 HTTP + 100 MQTT QoS 1) = 20 leituras/s,
e 5 leituras/s (70% gráficos 24 h/7 dias com 10 séries, 30% API) ao mesmo tempo.

## Integridade
- HTTP: 360.000 gravadas; 359.398 confirmadas; **0 perdidas, 0 duplicadas**
  (602 respostas não chegaram dentro de 15 s durante a pausa das 10h53, mas foram gravadas).
- MQTT: 360.000 confirmadas, 360.000 gravadas; **0 perdidas, 0 duplicadas**.
- Total no banco: 720.610 leituras; diretório do MySQL: 615 MB.

## Desempenho
- HTTP: mediana 4,4 ms em todas as horas; p95 por hora entre 8 e 35 ms; p99 entre 136 e 410 ms.
- MQTT (PUBACK): p95 0,3 ms.
- Leitura: 180.000 requisições, 179.997 com 200 e 3 timeouts (60 s) na pausa das 10h53.
  Mediana 2,9 ms; p95 64,6 ms; p99 89,5 ms. O p95 sobe com o volume na janela do gráfico
  (14,7 ms na 1ª hora → 83 ms na 10ª), porque com 20 leituras/s a janela de 24 h só fica
  cheia após um dia.
- Nenhum reinício de contêiner; atraso dos agregados sempre 0; conexões MySQL entre 36 e 41.
- Memória: banco 495 → ~810 MB (estável a partir de ~7 h, buffer pool de 1 GB); web 28 → 50–62 MB (estável).
- Backup das 03h00: sem efeito visível.

## Ocorrências
1. **10h53–10h58: pausa de ~1 min.** O host entrou em pressão de memória
   (`systemd-journald: Under memory pressure`) enquanto o flatpak/PackageKit atualizava o
   sistema. Os contêineres pararam juntos (até o amostrador atrasou 6 min), as conexões
   MySQL foram a 120 e tudo voltou sozinho em seguida, sem perda. É uma causa externa (o PC
   de mesa), não da plataforma.
2. **Log do worker sem rotação:** 100 MB em 10 h. Corrigido em `src/mqtt/mqtt_subscriber.php`
   (rotação ao passar de 10 MB, guarda 3 arquivos).
