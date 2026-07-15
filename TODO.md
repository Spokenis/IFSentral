# TODO - Logs MQTT no admin-dashboard (exibição C)

- [ ] Criar endpoint API `src/api/obter_logs_mqtt.php`:
  - [ ] Proteger com sessão/admin (via `src/auth/auth_check.php`)
  - [ ] Ler `logs/mqtt_subscriber.log`
  - [ ] Implementar paginação por linhas ou leitura por “últimos N bytes/linhas”
  - [ ] Retornar JSON com dados e contagem
- [ ] Atualizar `src/pages/admin-dashboard.php`:
  - [ ] Substituir a seção “Logs do MQTT (Em breve)” por UI real
  - [ ] Adicionar fetch AJAX para `/src/api/obter_logs_mqtt.php` e renderizar logs em tabela/pre/scroll
  - [ ] Implementar botão/scroll “carregar mais” (opcional) com offset/limit
- [ ] Testar:
  - [ ] Login como Admin e abrir `admin-dashboard.php`
  - [ ] Verificar carregamento inicial + paginação
- [ ] (Opcional) Ajustar formato de log no worker se a exibição ficar ruim
  - [ ] Verificar `src/mqtt/mqtt_subscriber.php` e `logs/mqtt_subscriber.log`
