#!/usr/bin/env python3
"""
ifscentral_leitura_loadtest.py - Gerador de carga de LEITURA do IFSCentral

Simula pessoas olhando os projetos e integrações consultando a API, para medir
o servidor também do lado da leitura (os outros geradores só enviam dados):

  - grafico : GET /api/obter-dados-grafico-renderizado?chart_id=N  (sessão logada;
              é o que a página do projeto pede ao abrir e a cada 60 s)
  - api     : GET /api/buscar-payloads?device_id=N&limit=50        (X-Api-Key do
              dispositivo; é o que integrações externas usam)

Cada conexão (--concurrency) faz login uma vez e mantém a sessão, como um
navegador. Rode junto com ifscentral_loadtest.py / ifscentral_mqtt_loadtest.py
para medir leitura e escrita ao mesmo tempo.

Config (.json):
  {
    "base_url": "https://IP:8443",
    "login": {"email": "...", "senha": "..."},
    "graficos": [12, 13, ...],                      # ids dos gráficos
    "dispositivos": [{"device_id": 5, "api_key": "..."}, ...],
    "mix": {"grafico": 70, "api": 30}                # proporção (opcional)
  }

Uso:
  python ifscentral_leitura_loadtest.py --config config.leitura.json --duration 60 --concurrency 10
  python ifscentral_leitura_loadtest.py --config config.leitura.json --duration 300 --rate 5 --concurrency 10

Saídas em --out: leitura_requests_<data>.csv e leitura_resumo_<data>.json (mesmo
formato de resumo dos outros geradores, mais o detalhamento por endpoint).
"""

import argparse
import asyncio
import csv
import json
import os
import random
import signal
import statistics
import sys
import time
from datetime import datetime, timezone

try:
    import aiohttp
except ImportError:
    sys.exit("Instale o aiohttp: pip install aiohttp")


def percentis(valores):
    if not valores:
        return {}
    s = sorted(valores)

    def p(q):
        return round(s[min(len(s) - 1, int(round(q / 100.0 * (len(s) - 1))))], 2)

    r = {"min": round(s[0], 2), "mean": round(statistics.fmean(s), 2), "median": round(statistics.median(s), 2),
         "p90": p(90), "p95": p(95), "p99": p(99), "max": round(s[-1], 2)}
    if len(s) > 1:
        r["stdev"] = round(statistics.stdev(s), 2)
    return r


class Resultados:
    def __init__(self, writer):
        self.writer = writer
        self.total = self.sucesso = self.falha = 0
        self.latencias = []
        self.por_endpoint = {}
        self.status = {}
        self.erros = {}

    def registrar(self, l):
        self.total += 1
        e = self.por_endpoint.setdefault(l["endpoint"], {"requests": 0, "sucesso": 0, "latencias": [], "bytes": 0})
        e["requests"] += 1
        if l["ok"]:
            self.sucesso += 1
            e["sucesso"] += 1
        else:
            self.falha += 1
            self.erros[l["error"]] = self.erros.get(l["error"], 0) + 1
        if l["status"] is not None:
            self.status[str(l["status"])] = self.status.get(str(l["status"]), 0) + 1
        if l["latency_ms"] is not None:
            self.latencias.append(l["latency_ms"])
            e["latencias"].append(l["latency_ms"])
        e["bytes"] += l["bytes"]
        self.writer.writerow([l["timestamp"], f'{l["elapsed_s"]:.3f}', l["endpoint"], l["alvo"], l["status"],
                              f'{l["latency_ms"]:.2f}' if l["latency_ms"] is not None else "", l["bytes"],
                              int(l["ok"]), l["error"] or ""])


async def login(session, base, cred):
    async with session.post(f"{base}/api/login", json={"email": cred["email"], "password": cred["senha"]},
                            headers={"X-Requested-With": "XMLHttpRequest"}) as r:
        corpo = await r.text()
        if r.status != 200:
            raise RuntimeError(f"login falhou ({r.status}): {corpo[:150]}")


async def worker(cfg, args, fila, resultados, t0):
    base = cfg["base_url"].rstrip("/")
    jar = aiohttp.CookieJar(unsafe=True)
    timeout = aiohttp.ClientTimeout(total=args.timeout)
    async with aiohttp.ClientSession(cookie_jar=jar, connector=aiohttp.TCPConnector(ssl=False, limit=1),
                                     timeout=timeout) as session:
        if cfg.get("graficos"):
            await login(session, base, cfg["login"])
        while True:
            job = await fila.get()
            if job is None:
                fila.task_done()
                return
            tipo = job
            if tipo == "grafico":
                alvo = random.choice(cfg["graficos"])
                url = f"{base}/api/obter-dados-grafico-renderizado?chart_id={alvo}"
                headers = {"X-Requested-With": "XMLHttpRequest"}
            else:
                d = random.choice(cfg["dispositivos"])
                alvo = d["device_id"]
                url = f"{base}/api/buscar-payloads?device_id={alvo}&limit=50"
                headers = {"X-Api-Key": d["api_key"]}
            status = lat = None
            ok, erro, nbytes = False, None, 0
            ini = time.perf_counter()
            try:
                async with session.get(url, headers=headers) as r:
                    status = r.status
                    corpo = await r.read()
                    nbytes = len(corpo)
                    lat = (time.perf_counter() - ini) * 1000
                    ok = r.status == 200
                    if not ok:
                        erro = f"HTTP {r.status}"
            except asyncio.TimeoutError:
                lat = (time.perf_counter() - ini) * 1000
                erro = "timeout"
            except aiohttp.ClientError as e:
                erro = f"conexao: {type(e).__name__}"
            resultados.registrar({"timestamp": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
                                  "elapsed_s": time.perf_counter() - t0, "endpoint": tipo, "alvo": alvo,
                                  "status": status, "latency_ms": lat, "bytes": nbytes, "ok": ok, "error": erro})
            fila.task_done()


async def alimentar(fila, args, mix, parar, t0):
    tipos = list(mix.keys())
    pesos = list(mix.values())
    intervalo = 1.0 / args.rate if args.rate else 0.0
    proximo = time.perf_counter()
    n = 0
    while not parar.is_set():
        if args.requests is not None and n >= args.requests:
            break
        if args.duration is not None and time.perf_counter() - t0 >= args.duration:
            break
        await fila.put(random.choices(tipos, weights=pesos, k=1)[0])
        n += 1
        if intervalo:
            proximo += intervalo
            atraso = proximo - time.perf_counter()
            if atraso > 0:
                await asyncio.sleep(atraso)
    for _ in range(args.concurrency):
        await fila.put(None)


async def executar(cfg, args):
    os.makedirs(args.out, exist_ok=True)
    carimbo = datetime.now().strftime("%Y%m%d-%H%M%S")
    csv_path = os.path.join(args.out, f"leitura_requests_{carimbo}.csv")
    json_path = os.path.join(args.out, f"leitura_resumo_{carimbo}.json")
    f = open(csv_path, "w", newline="", encoding="utf-8")
    w = csv.writer(f)
    w.writerow(["timestamp_utc", "elapsed_s", "endpoint", "alvo", "status", "latency_ms", "bytes", "ok", "error"])
    res = Resultados(w)

    mix = {k: v for k, v in (cfg.get("mix") or {"grafico": 70, "api": 30}).items() if v > 0}
    if not cfg.get("graficos"):
        mix.pop("grafico", None)
    if not cfg.get("dispositivos"):
        mix.pop("api", None)
    if not mix:
        sys.exit("Config sem gráficos nem dispositivos.")

    parar = asyncio.Event()
    try:
        signal.signal(signal.SIGINT, lambda *_: parar.set())
    except ValueError:
        pass
    fila = asyncio.Queue(maxsize=args.concurrency * 4)
    t0 = time.perf_counter()
    inicio = datetime.now(timezone.utc).isoformat(timespec="seconds")
    tarefas = [asyncio.create_task(worker(cfg, args, fila, res, t0)) for _ in range(args.concurrency)]
    await alimentar(fila, args, mix, parar, t0)
    await asyncio.gather(*tarefas)
    wall = time.perf_counter() - t0
    f.close()

    resumo = {
        "ferramenta": "ifscentral_leitura_loadtest", "inicio_utc": inicio, "wall_time_s": round(wall, 2),
        "parametros": {"concurrency": args.concurrency, "rate": args.rate, "duration": args.duration,
                       "requests": args.requests, "mix": mix},
        "totais": {"requests": res.total, "sucesso": res.sucesso, "falha": res.falha,
                   "taxa_sucesso_pct": round(100.0 * res.sucesso / res.total, 2) if res.total else 0.0,
                   "throughput_rps": round(res.total / wall, 2) if wall else 0.0},
        "latency_ms": percentis(res.latencias), "status_codes": res.status, "erros": res.erros,
        "por_endpoint": {k: {"requests": v["requests"], "sucesso": v["sucesso"],
                             "bytes_medio": round(v["bytes"] / v["requests"]) if v["requests"] else 0,
                             "latency_ms": percentis(v["latencias"])} for k, v in res.por_endpoint.items()},
        "csv": csv_path,
    }
    json.dump(resumo, open(json_path, "w", encoding="utf-8"), indent=2, ensure_ascii=False)

    t = resumo["totais"]
    print("=" * 60)
    print(f"Leituras           : {t['requests']}  ({t['taxa_sucesso_pct']}% ok)  {t['throughput_rps']} req/s")
    for k, v in resumo["por_endpoint"].items():
        lat = v["latency_ms"]
        if lat:
            print(f"  {k:8}: {v['requests']} req | mediana {lat['median']} | p95 {lat['p95']} | p99 {lat['p99']} ms"
                  f" | {v['bytes_medio']} bytes")
    if res.erros:
        print(f"Erros              : {res.erros}")
    print(f"Resumo: {json_path}")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--config", required=True)
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--requests", type=int)
    g.add_argument("--duration", type=float)
    ap.add_argument("--concurrency", type=int, default=5, help="conexões simultâneas (cada uma com sua sessão)")
    ap.add_argument("--rate", type=float, default=None, help="limite de leituras por segundo")
    ap.add_argument("--timeout", type=float, default=30.0)
    ap.add_argument("--out", default="resultados")
    ap.add_argument("--seed", type=int, default=None)
    args = ap.parse_args()
    if args.seed is not None:
        random.seed(args.seed)
    cfg = json.load(open(args.config, encoding="utf-8"))
    asyncio.run(executar(cfg, args))


if __name__ == "__main__":
    main()
