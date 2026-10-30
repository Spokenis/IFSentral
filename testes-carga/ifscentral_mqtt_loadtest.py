#!/usr/bin/env python3
"""
ifscentral_mqtt_loadtest.py - Gerador de carga MQTT para o IFSCentral.

Publica payloads JSON no broker MQTT da plataforma (SOMENTE edicao completa; o
Lite nao tem MQTT), no topico:
    mqtt/projects/{project_id}/devices/{device_id}

Cada dispositivo autentica no broker como usuario "device_{id}" com a senha
gerada no cadastro. A ferramenta mede a latencia de PUBLISH->PUBACK (QoS 1),
ou seja, o tempo ate o broker confirmar o recebimento. (Nao e a latencia ate o
dado ser gravado no banco - isso depende do worker consumidor.)

Uso:
    python ifscentral_mqtt_loadtest.py --config config.mqtt.yaml --requests 1000 --concurrency 20

Requer: paho-mqtt  (pip install paho-mqtt). PyYAML opcional (configs .yaml).
"""

import argparse
import csv
import json
import os
import random
import signal
import statistics
import sys
import threading
import time
from datetime import datetime, timezone

try:
    import paho.mqtt.client as mqtt
except ImportError:
    sys.exit(
        "ERRO: a biblioteca 'paho-mqtt' nao esta instalada.\n"
        "Instale com:  pip install paho-mqtt"
    )


# --------------------------------------------------------------------------- #
# Config / payload (compartilhado em espirito com o gerador HTTP)
# --------------------------------------------------------------------------- #
def carregar_config(caminho):
    with open(caminho, "r", encoding="utf-8") as f:
        texto = f.read()
    if caminho.lower().endswith((".yaml", ".yml")):
        try:
            import yaml
        except ImportError:
            sys.exit("Config em YAML exige PyYAML (pip install pyyaml) ou use .json.")
        return yaml.safe_load(texto)
    return json.loads(texto)


def validar_targets(config):
    targets = config.get("targets")
    if not targets:
        sys.exit("Config sem 'targets'. Veja config.mqtt.example.yaml.")
    broker = config.get("broker", {})
    host_g = broker.get("host")
    port_g = broker.get("port", 1883)
    tls_g = broker.get("tls", False)
    norm = []
    for i, t in enumerate(targets):
        for campo in ("project_id", "device_id", "mqtt_username", "mqtt_password"):
            if t.get(campo) in (None, ""):
                sys.exit(f"Target #{i}: falta '{campo}'.")
        norm.append(
            {
                "name": t.get("name", f"device-{t['device_id']}"),
                "host": t.get("host", host_g),
                "port": int(t.get("port", port_g)),
                "tls": bool(t.get("tls", tls_g)),
                "project_id": int(t["project_id"]),
                "device_id": int(t["device_id"]),
                "username": t["mqtt_username"],
                "password": t["mqtt_password"],
                "topic": f"mqtt/projects/{int(t['project_id'])}/devices/{int(t['device_id'])}",
                "payload_template": t.get("payload", {"valor": {"type": "float", "min": 0, "max": 100}}),
                "weight": float(t.get("weight", 1)),
            }
        )
        if not norm[-1]["host"]:
            sys.exit(f"Target #{i}: falta 'host' (ou defina broker.host global).")
    return norm


def gerar_valor(spec):
    if not isinstance(spec, dict):
        return spec
    tipo = spec.get("type", "float")
    if tipo == "float":
        return round(random.uniform(spec.get("min", 0), spec.get("max", 100)), spec.get("decimals", 2))
    if tipo == "int":
        return random.randint(int(spec.get("min", 0)), int(spec.get("max", 100)))
    if tipo == "bool":
        return random.random() < spec.get("p", 0.5)
    if tipo == "choice":
        return random.choice(spec["values"])
    if tipo == "fixed":
        return spec.get("value")
    return None


def gerar_payload(template):
    return {k: gerar_valor(v) for k, v in template.items()}


def percentis(valores):
    if not valores:
        return {}
    s = sorted(valores)

    def pct(p):
        k = max(0, min(len(s) - 1, int(round(p / 100.0 * (len(s) - 1)))))
        return s[k]

    r = {
        "min": round(s[0], 2),
        "mean": round(statistics.fmean(s), 2),
        "median": round(statistics.median(s), 2),
        "p90": round(pct(90), 2),
        "p95": round(pct(95), 2),
        "p99": round(pct(99), 2),
        "max": round(s[-1], 2),
    }
    if len(s) > 1:
        r["stdev"] = round(statistics.stdev(s), 2)
    return r


# --------------------------------------------------------------------------- #
# Coletor de resultados (thread-safe)
# --------------------------------------------------------------------------- #
class Resultados:
    def __init__(self, csv_writer):
        self.lock = threading.Lock()
        self.csv_writer = csv_writer
        self.latencias = []
        self.total = self.sucesso = self.falha = 0
        self.erros = {}
        self.por_target = {}

    def registrar(self, linha):
        with self.lock:
            self.total += 1
            alvo = self.por_target.setdefault(
                linha["target"], {"requests": 0, "sucesso": 0, "falha": 0, "latencias": []}
            )
            alvo["requests"] += 1
            if linha["latency_ms"] is not None:
                self.latencias.append(linha["latency_ms"])
                alvo["latencias"].append(linha["latency_ms"])
            if linha["ok"]:
                self.sucesso += 1
                alvo["sucesso"] += 1
            else:
                self.falha += 1
                alvo["falha"] += 1
                if linha["error"]:
                    self.erros[linha["error"]] = self.erros.get(linha["error"], 0) + 1
            self.csv_writer.writerow(
                [
                    linha["timestamp"],
                    f'{linha["elapsed_s"]:.3f}',
                    linha["target"],
                    linha["device_id"],
                    f'{linha["latency_ms"]:.2f}' if linha["latency_ms"] is not None else "",
                    int(linha["ok"]),
                    linha["error"] or "",
                ]
            )


# --------------------------------------------------------------------------- #
# Fonte de jobs compartilhada (controla total/duracao/taxa)
# --------------------------------------------------------------------------- #
class FonteJobs:
    def __init__(self, total, duration, rate, t0, parar):
        self.total = total
        self.duration = duration
        self.rate = rate
        self.t0 = t0
        self.parar = parar
        self.lock = threading.Lock()
        self.count = 0
        self.next_time = t0

    def proximo(self):
        with self.lock:
            if self.parar.is_set():
                return False
            if self.total is not None and self.count >= self.total:
                return False
            if self.duration is not None and (time.perf_counter() - self.t0) >= self.duration:
                return False
            self.count += 1
            espera = 0.0
            if self.rate:
                self.next_time += 1.0 / self.rate
                espera = self.next_time - time.perf_counter()
        if espera > 0:
            time.sleep(espera)
        return True


# --------------------------------------------------------------------------- #
# Worker: um cliente MQTT persistente ligado a um target
# --------------------------------------------------------------------------- #
def criar_cliente(target, idx):
    client_id = f"loadtest-{target['device_id']}-{idx}-{random.randint(0, 1 << 30):x}"
    try:  # paho-mqtt >= 2.0
        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=client_id)
    except (AttributeError, TypeError):  # paho-mqtt 1.x
        client = mqtt.Client(client_id=client_id)
    client.username_pw_set(target["username"], target["password"])
    if target["tls"]:
        client.tls_set()
        client.tls_insecure_set(True)  # aceita cert self-signed (teste)
    client.connect(target["host"], target["port"], keepalive=60)
    client.loop_start()
    return client


def worker(idx, target, fonte, resultados, timeout, t0, parar):
    try:
        client = criar_cliente(target, idx)
    except Exception as e:  # noqa: BLE001
        # Falha de conexao: registra uma falha e sai
        resultados.registrar(
            {
                "timestamp": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
                "elapsed_s": time.perf_counter() - t0,
                "target": target["name"],
                "device_id": target["device_id"],
                "latency_ms": None,
                "ok": False,
                "error": f"conexao: {type(e).__name__}: {e}",
            }
        )
        return

    try:
        while not parar.is_set() and fonte.proximo():
            corpo = json.dumps(gerar_payload(target["payload_template"]))
            latency_ms = None
            ok = False
            erro = None
            inicio = time.perf_counter()
            try:
                info = client.publish(target["topic"], corpo, qos=1)
                info.wait_for_publish(timeout)
                if info.is_published():
                    latency_ms = (time.perf_counter() - inicio) * 1000.0
                    ok = True
                else:
                    erro = f"nao confirmado (rc={info.rc})"
            except Exception as e:  # noqa: BLE001  (timeout/desconexao)
                erro = f"{type(e).__name__}"
            resultados.registrar(
                {
                    "timestamp": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
                    "elapsed_s": time.perf_counter() - t0,
                    "target": target["name"],
                    "device_id": target["device_id"],
                    "latency_ms": latency_ms,
                    "ok": ok,
                    "error": erro,
                }
            )
    finally:
        try:
            client.loop_stop()
            client.disconnect()
        except Exception:  # noqa: BLE001
            pass


# --------------------------------------------------------------------------- #
# Distribui workers entre targets (cada conexao e de um device)
# --------------------------------------------------------------------------- #
def distribuir_workers(targets, concurrency):
    pool = []
    for t in targets:
        pool += [t] * max(1, int(round(t["weight"])))
    return [pool[i % len(pool)] for i in range(concurrency)]


def executar(args, targets):
    os.makedirs(args.out, exist_ok=True)
    carimbo = datetime.now().strftime("%Y%m%d-%H%M%S")
    csv_path = os.path.join(args.out, f"mqtt_requests_{carimbo}.csv")
    json_path = os.path.join(args.out, f"mqtt_resumo_{carimbo}.json")

    csv_file = open(csv_path, "w", newline="", encoding="utf-8")
    writer = csv.writer(csv_file)
    writer.writerow(["timestamp_utc", "elapsed_s", "target", "device_id", "latency_ms", "ok", "error"])
    resultados = Resultados(writer)

    parar = threading.Event()

    def _sinal(*_):
        print("\nInterrompido - finalizando...", file=sys.stderr)
        parar.set()

    try:
        signal.signal(signal.SIGINT, _sinal)
    except ValueError:
        pass

    t0 = time.perf_counter()
    inicio_iso = datetime.now(timezone.utc).isoformat(timespec="seconds")
    fonte = FonteJobs(args.requests, args.duration, args.rate, t0, parar)
    worker_targets = distribuir_workers(targets, args.concurrency)

    threads = []
    for i, tg in enumerate(worker_targets):
        th = threading.Thread(
            target=worker, args=(i, tg, fonte, resultados, args.timeout, t0, parar), daemon=True
        )
        th.start()
        threads.append(th)

    # progresso
    while any(th.is_alive() for th in threads):
        time.sleep(2.0)
        print(f"  ... {resultados.total} publicadas | {resultados.sucesso} ok | "
              f"{resultados.falha} falha", file=sys.stderr)
        if parar.is_set():
            break
    for th in threads:
        th.join()

    wall = time.perf_counter() - t0
    csv_file.close()

    resumo = montar_resumo(args, targets, resultados, inicio_iso, wall)
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(resumo, f, indent=2, ensure_ascii=False)
    imprimir_resumo(resumo, csv_path, json_path)
    return resumo


def montar_resumo(args, targets, r, inicio_iso, wall):
    throughput = r.total / wall if wall > 0 else 0.0
    por_target = {}
    for nome, d in r.por_target.items():
        por_target[nome] = {
            "requests": d["requests"],
            "sucesso": d["sucesso"],
            "falha": d["falha"],
            "taxa_sucesso_pct": round(100 * d["sucesso"] / d["requests"], 2) if d["requests"] else 0,
            "latency_ms": percentis(d["latencias"]),
        }
    return {
        "protocolo": "mqtt",
        "config": {
            "concurrency": args.concurrency,
            "requests": args.requests,
            "duration_s": args.duration,
            "rate_rps": args.rate,
            "timeout_s": args.timeout,
            "n_targets": len(targets),
            "qos": 1,
        },
        "iniciado_em": inicio_iso,
        "finalizado_em": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "wall_time_s": round(wall, 3),
        "totais": {
            "requests": r.total,
            "sucesso": r.sucesso,
            "falha": r.falha,
            "taxa_sucesso_pct": round(100 * r.sucesso / r.total, 2) if r.total else 0,
            "throughput_rps": round(throughput, 2),
        },
        "latency_ms": percentis(r.latencias),
        "erros": dict(sorted(r.erros.items(), key=lambda kv: -kv[1])),
        "por_target": por_target,
    }


def imprimir_resumo(resumo, csv_path, json_path):
    t = resumo["totais"]
    lat = resumo["latency_ms"]
    print("\n" + "=" * 60)
    print("RESUMO DO TESTE (MQTT, latencia PUBLISH->PUBACK QoS1)")
    print("=" * 60)
    print(f"Duracao total      : {resumo['wall_time_s']} s")
    print(f"Mensagens          : {t['requests']}")
    print(f"Sucesso / Falha    : {t['sucesso']} / {t['falha']}  ({t['taxa_sucesso_pct']}% ok)")
    print(f"Throughput         : {t['throughput_rps']} msg/s")
    if lat:
        print(f"Latencia (ms)      : media {lat['mean']} | mediana {lat['median']} "
              f"| p95 {lat['p95']} | p99 {lat['p99']} | max {lat['max']}")
    if resumo["erros"]:
        print(f"Erros              : {resumo['erros']}")
    print("-" * 60)
    for nome, d in resumo["por_target"].items():
        print(f"  [{nome}] {d['requests']} msg | {d['taxa_sucesso_pct']}% ok", end="")
        print(f" | media {d['latency_ms']['mean']} ms" if d["latency_ms"] else "")
    print("-" * 60)
    print(f"CSV   : {csv_path}")
    print(f"Resumo: {json_path}")
    print("=" * 60)


def parse_args():
    p = argparse.ArgumentParser(
        description="Gerador de carga MQTT para o IFSCentral (edicao completa).",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--config", required=True, help="Config (.yaml ou .json)")
    g = p.add_mutually_exclusive_group(required=True)
    g.add_argument("--requests", type=int, help="Numero total de mensagens")
    g.add_argument("--duration", type=float, help="Duracao em segundos")
    p.add_argument("--concurrency", type=int, default=10, help="Conexoes simultaneas (workers)")
    p.add_argument("--rate", type=float, default=None, help="Limite de taxa (mensagens/segundo)")
    p.add_argument("--timeout", type=float, default=10.0, help="Timeout por PUBACK (s)")
    p.add_argument("--out", default="resultados", help="Pasta de saida")
    p.add_argument("--seed", type=int, default=None, help="Semente aleatoria")
    return p.parse_args()


def main():
    args = parse_args()
    if args.seed is not None:
        random.seed(args.seed)
    config = carregar_config(args.config)
    targets = validar_targets(config)
    print(f"Alvos carregados: {len(targets)}")
    for t in targets:
        proto = "mqtts" if t["tls"] else "mqtt"
        print(f"  - {t['name']}  {proto}://{t['host']}:{t['port']}  topico={t['topic']}")
    modo = f"{args.requests} mensagens" if args.requests else f"{args.duration}s"
    print(f"Modo: {modo} | conexoes={args.concurrency}"
          + (f" | taxa={args.rate} msg/s" if args.rate else ""))
    print("Iniciando...\n")
    executar(args, targets)


if __name__ == "__main__":
    main()
