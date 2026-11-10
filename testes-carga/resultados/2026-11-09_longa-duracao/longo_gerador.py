#!/usr/bin/env python3
"""Gerador do teste de longa duração.

Cada dispositivo envia uma leitura numerada (seq) a cada --intervalo segundos
(metade por HTTP, metade por MQTT QoS 1). Guarda os seq CONFIRMADOS (para
conferir no banco) e as latências agrupadas em faixas de 10 min (percentis
por faixa), sem acumular uma lista por mensagem. Salva o resultado a cada
10 min (pode ser interrompido sem perder o que já mediu).
"""
import argparse, json, random, threading, time
import requests, urllib3
import paho.mqtt.client as mqtt

urllib3.disable_warnings()
FAIXA = 600
trava = threading.Lock()
faixas = {}          # inicio_faixa -> {'http': [lat], 'mqtt': [lat], 'erros': {}}
confirmadas = {'http': {}, 'mqtt': {}}


def registrar(tipo, lat=None, erro=None):
    f = int(time.time() // FAIXA * FAIXA)
    with trava:
        d = faixas.setdefault(f, {'http': [], 'mqtt': [], 'erros': {}})
        if lat is not None:
            d[tipo].append(round(lat, 1))
        if erro:
            k = f'{tipo}:{erro}'
            d['erros'][k] = d['erros'].get(k, 0) + 1


def http_disp(alvo, base, intervalo, fim, rotulo):
    s = requests.Session(); s.verify = False
    conf = confirmadas['http'].setdefault(str(alvo['device_id']), [])
    seq = 0
    time.sleep(random.random() * intervalo)       # espalha os dispositivos no tempo
    proximo = time.time()
    while time.time() < fim:
        seq += 1
        t0 = time.perf_counter()
        try:
            r = s.post(f"{base}/api/enviar-payload", timeout=15, headers={'X-Api-Key': alvo['api_key']},
                       json={'device_id': alvo['device_id'], 'payload': {'seq': seq, 'teste': rotulo, 'temperatura': round(18 + random.random() * 15, 2), 'umidade': random.randint(35, 95)}})
            if r.status_code == 201:
                registrar('http', (time.perf_counter() - t0) * 1000); conf.append(seq)
            else:
                registrar('http', erro=str(r.status_code))
        except Exception as e:
            registrar('http', erro=type(e).__name__)
        proximo += intervalo
        time.sleep(max(0, proximo - time.time()))


def mqtt_disp(alvo, host, porta, intervalo, fim, rotulo):
    conf = confirmadas['mqtt'].setdefault(str(alvo['device_id']), [])
    c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=f"longo-{alvo['device_id']}", clean_session=False)
    c.username_pw_set(alvo['mqtt_username'], alvo['mqtt_password'])
    pend = {}
    def on_publish(cl, ud, mid, rc, props):
        p = pend.pop(mid, None)
        if p:
            seq, t0 = p; conf.append(seq); registrar('mqtt', (time.perf_counter() - t0) * 1000)
    c.on_publish = on_publish
    c.reconnect_delay_set(1, 10)
    time.sleep(random.random() * intervalo)
    c.connect(host, porta, keepalive=30); c.loop_start()
    topico = f"mqtt/projects/{alvo['project_id']}/devices/{alvo['device_id']}"
    seq = 0; proximo = time.time()
    while time.time() < fim:
        seq += 1
        info = c.publish(topico, json.dumps({'seq': seq, 'teste': rotulo, 'temperatura': round(18 + random.random() * 15, 2), 'umidade': random.randint(35, 95)}), qos=1)
        pend[info.mid] = (seq, time.perf_counter())
        proximo += intervalo
        time.sleep(max(0, proximo - time.time()))
    limite = time.time() + 120
    while pend and time.time() < limite:
        time.sleep(1)
    registrar('mqtt', erro=f'sem_puback') if pend else None
    c.loop_stop(); c.disconnect()


def salvar(saida, rotulo, final=False):
    def pct(xs, q):
        xs = sorted(xs); return xs[min(len(xs) - 1, int(q / 100 * (len(xs) - 1)))] if xs else None
    with trava:
        res = {'rotulo': rotulo, 'final': final, 'faixas': [
            {'inicio': f, 'http_n': len(d['http']), 'http_p50': pct(d['http'], 50), 'http_p95': pct(d['http'], 95), 'http_p99': pct(d['http'], 99),
             'mqtt_n': len(d['mqtt']), 'mqtt_p50': pct(d['mqtt'], 50), 'mqtt_p95': pct(d['mqtt'], 95), 'mqtt_p99': pct(d['mqtt'], 99), 'erros': d['erros']}
            for f, d in sorted(faixas.items())],
            'http': {k: {'confirmadas': list(v), 'erros': {}} for k, v in confirmadas['http'].items()},
            'mqtt': {k: {'confirmadas': list(v), 'erros': {}} for k, v in confirmadas['mqtt'].items()}}
    json.dump(res, open(saida + '.tmp', 'w')); import os; os.replace(saida + '.tmp', saida)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--http'); ap.add_argument('--mqtt'); ap.add_argument('--base', default='https://127.0.0.1:38443')
    ap.add_argument('--mqtt-host', default='127.0.0.1'); ap.add_argument('--mqtt-porta', type=int, default=31883)
    ap.add_argument('--horas', type=float, required=True); ap.add_argument('--intervalo', type=float, default=10)
    ap.add_argument('--rotulo', default='longo'); ap.add_argument('--saida', required=True)
    a = ap.parse_args()
    fim = time.time() + a.horas * 3600
    ths = []
    for alvo in json.load(open(a.http))['targets']:
        ths.append(threading.Thread(target=http_disp, args=(alvo, a.base, a.intervalo, fim, a.rotulo), daemon=True))
    for alvo in json.load(open(a.mqtt))['targets']:
        ths.append(threading.Thread(target=mqtt_disp, args=(alvo, a.mqtt_host, a.mqtt_porta, a.intervalo, fim, a.rotulo), daemon=True))
    [t.start() for t in ths]
    while any(t.is_alive() for t in ths):
        time.sleep(600); salvar(a.saida, a.rotulo)
    salvar(a.saida, a.rotulo, final=True)


if __name__ == '__main__':
    main()
