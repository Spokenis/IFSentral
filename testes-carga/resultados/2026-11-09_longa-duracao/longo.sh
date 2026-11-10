#!/bin/bash
# Teste de longa duração na pilha isolada (versão nova, já atualizada).
#   longo.sh HORAS
S=/tmp/claude-1000/-home-denis--rea-de-trabalho-TCC/6b104e01-2d11-4413-b49a-78a97813f5d9/scratchpad; C=$S/carga; PY=$S/venv-carga/bin/python
TC="/home/denis/Área de trabalho/TCC/testes-carga"; H=${1:-10}; L=$C/longo; mkdir -p $L
m(){ docker exec ifs_carga_db mysql -uroot -prootpassword -N -B ifsentral_bd -e "$1" 2>/dev/null; }

echo "[$(date +%T)] preparando: 20 projetos x 10 dispositivos"
m "DELETE FROM device_rate_limits"
$PY "$TC/preparar_cenario.py" --base https://127.0.0.1:38443 --email carga@teste.dev --senha 'Carga@2026' --projetos 20 --dispositivos 10 \
    --mqtt-host 127.0.0.1 --mqtt-port 31883 --saida-http $L/todos.http.json --saida-mqtt $L/todos.mqtt.json >/dev/null 2>&1
# metade dos dispositivos por HTTP, metade por MQTT
$PY - $L <<'PY'
import json, sys
L = sys.argv[1]
h = json.load(open(f'{L}/todos.http.json')); q = json.load(open(f'{L}/todos.mqtt.json'))
json.dump({'base_url': h['base_url'], 'targets': h['targets'][:100]}, open(f'{L}/http.json', 'w'))
json.dump({'broker': q['broker'], 'targets': q['targets'][100:]}, open(f'{L}/mqtt.json', 'w'))
PY
# 40 gráficos (24 h e 7 dias, 10 séries cada) para a carga de leitura
$PY - $L <<'PY'
import json, sys, requests, urllib3
urllib3.disable_warnings()
L = sys.argv[1]; B = 'https://127.0.0.1:38443'
s = requests.Session(); s.verify = False; s.headers['X-Requested-With'] = 'XMLHttpRequest'
s.post(f'{B}/api/login', json={'email': 'carga@teste.dev', 'password': 'Carga@2026'})
h = {'X-CSRF-Token': s.get(f'{B}/api/obter-csrf-token').json()['csrf_token']}
q = json.load(open(f'{L}/todos.mqtt.json'))['targets']; t = json.load(open(f'{L}/todos.http.json'))['targets']
por = {}
for a in q: por.setdefault(a['project_id'], []).append(a['device_id'])
g = []
for pid, devs in sorted(por.items()):
    for nome, var, faixa in [('Temperatura 24h', 'temperatura', '24h'), ('Umidade 7d', 'umidade', '7d')]:
        r = s.post(f'{B}/api/salvar-grafico-avancado', headers=h, json={'project_id': pid, 'name': nome, 'chart_type': 'line', 'time_range': faixa,
               'datasets': [{'device_id': d, 'variable_name': var, 'alias': f'S{i}', 'color': '#1B7D3D'} for i, d in enumerate(devs)]})
        j = r.json(); g.append(int(j.get('chart_id') or j.get('id') or j.get('insertedId')))
json.dump({'base_url': B, 'login': {'email': 'carga@teste.dev', 'senha': 'Carga@2026'}, 'graficos': g,
           'dispositivos': [{'device_id': a['device_id'], 'api_key': a['api_key']} for a in t], 'mix': {'grafico': 70, 'api': 30}}, open(f'{L}/leitura.json', 'w'))
PY
sleep 15
echo "[$(date +%T)] início: $H h, 200 dispositivos a cada 10 s (100 HTTP + 100 MQTT) + 5 leituras/s"
ID0=$(m "SELECT MAX(id) FROM device_payloads"); echo $ID0 > $L/id_inicio.txt; date +%s > $L/inicio.txt
$PY $C/longo_gerador.py --http $L/http.json --mqtt $L/mqtt.json --horas $H --intervalo 10 --rotulo longo --saida $L/envio.json > $L/envio.log 2>&1 &
GE=$!
$PY "$TC/ifscentral_leitura_loadtest.py" --config $L/leitura.json --duration $(echo "$H*3600" | bc) --rate 5 --concurrency 5 --timeout 60 --out $L > $L/leitura.log 2>&1 &
GL=$!

# Amostras a cada 5 min: recursos de cada contêiner, banco, disco, fila do broker, atraso dos agregados
echo "hora,conteiner,cpu_pct,mem_mb" > $L/recursos.csv
echo "hora,leituras,tamanho_banco_mb,disco_livre_gb,worker_reinicios,web_reinicios,fila_broker,agregados_atraso,conexoes_mysql,log_worker_mb" > $L/estado.csv
while kill -0 $GE 2>/dev/null; do
  h=$(date +%FT%T)
  docker stats --no-stream --format "{{.Name}} {{.CPUPerc}} {{.MemUsage}}" ifs_carga_web ifs_carga_web_2 ifs_carga_worker ifs_carga_db ifs_carga_mqtt ifs_carga_proxy 2>/dev/null \
    | awk -v h=$h '{gsub("%","",$2); u=$3; v=u+0; if(u ~ /GiB/) v*=1024; if(u ~ /KiB/) v/=1024; printf "%s,%s,%s,%.1f\n", h, $1, $2, v}' >> $L/recursos.csv
  n=$(m "SELECT COUNT(*) FROM device_payloads")
  tb=$(m "SELECT ROUND(SUM(data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema='ifsentral_bd'")
  df=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
  wr=$(docker inspect -f '{{.RestartCount}}' ifs_carga_worker); ww=$(docker inspect -f '{{.RestartCount}}' ifs_carga_web)
  fila=$(docker exec ifs_carga_mqtt sh -c 'mosquitto_sub -h localhost -u "$MQTT_USERNAME" -P "$MQTT_PASSWORD" -t "\$SYS/broker/store/messages/count" -C 1 -W 5 2>/dev/null')
  atraso=$(m "SELECT (SELECT MAX(id) FROM device_payloads) - (SELECT MAX(payload_id) FROM device_payload_values)")
  cx=$(m "SHOW GLOBAL STATUS LIKE 'Threads_connected'" | cut -f2)
  lw=$(du -m $C/logs/mqtt_subscriber.log 2>/dev/null | cut -f1)
  echo "$h,$n,$tb,$df,$wr,$ww,$fila,$atraso,$cx,$lw" >> $L/estado.csv
  sleep 300
done
wait $GL
echo "[$(date +%T)] fim do envio; conferindo integridade"
$PY $C/conferir.py $L/envio.json > $L/integridade.txt 2>&1
cat $L/integridade.txt
echo "[$(date +%T)] terminado"
