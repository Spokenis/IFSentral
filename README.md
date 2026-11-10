# Testes do IFSCentral

Branch só com os testes usados no TCC (não contém o código da plataforma).

- `testes-e2e/`: bateria E2E das duas edições (completa e Lite), com o ambiente Docker isolado e os resultados (`resultado-*.json`).
- `testes-carga/`: geradores de carga por HTTP, MQTT e leitura de gráficos, preparação do cenário (`preparar_cenario.py`) e os resultados de cada rodada em `resultados/`:
  - `2026-11-08_pc-local/`: carga de escrita e leitura, antes e depois de cada correção;
  - `2026-11-09_longa-duracao/`: 10 h contínuas (720 mil leituras, 0 perdas);
  - `2026-11-10_seguranca/`: controle de acesso, CSRF, força bruta, arquivos expostos, testssl e OWASP ZAP;
  - `2026-11-10_qualidade/`: acessibilidade (axe), Lighthouse, celular e Firefox.
- `firmware-teste/`: firmwares de exemplo (ESP32, ESP8266, Arduino WiFiNINA) e de estresse para enviar dados à plataforma. Wi-Fi e chaves ficam como espaços para preencher.
- `testes-usabilidade/`: roteiro do teste com usuários, questionário SUS e cálculo da nota.

O código está nas branches `profissional-ifscsentral` (edição completa) e `lite-ifscsentral` (IFSCentral Lite).
