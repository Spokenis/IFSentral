#!/usr/bin/env python3
"""
ifscentral_loadtest.py - Gerador de carga / testes para a plataforma IFSCentral.

Envia payloads para o endpoint HTTP /api/enviar_payload.php (funciona na edicao
completa e no Lite), com:
  - numero de requisicoes OU duracao configuravel;
  - paralelismo (pool de workers assincronos) com limite de concorrencia;
  - limite opcional de taxa (requisicoes por segundo);
  - varios projetos/dispositivos no mesmo arquivo de configuracao;
  - registro de cada requisicao (CSV) e um resumo estatistico (JSON) para
    validacao: taxa de sucesso, latencia (media/mediana/p90/p95/p99),
    throughput e contagem de erros.

Uso basico:
    python ifscentral_loadtest.py --config config.yaml --requests 1000 --concurrency 50

Por duracao (ex. 60 s a 100 req/s com 20 conexoes simultaneas):
    python ifscentral_loadtest.py --config config.yaml --duration 60 --rate 100 --concurrency 20

Requer: aiohttp (pip install -r requirements.txt). PyYAML e opcional (so para
configs .yaml; configs .json funcionam sem ele).
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
    sys.exit(
        "ERRO: a biblioteca 'aiohttp' nao esta instalada.\n"
        "Instale com:  pip install -r requirements.txt   (ou: pip install aiohttp)"
    )


# --------------------------------------------------------------------------- #
# Configuracao
# --------------------------------------------------------------------------- #
def carregar_config(caminho):
    """Le o arquivo de configuracao (.json ou .yaml/.yml)."""
    with open(caminho, "r", encoding="utf-8") as f:
        texto = f.read()
    if caminho.lower().endswith((".yaml", ".yml")):
        try:
            import yaml
        except ImportError:
            sys.exit(
                "Config em YAML exige PyYAML.  Instale com: pip install pyyaml\n"
                "Ou converta a config para .json."
            )
        return yaml.safe_load(texto)
    return json.loads(texto)


def validar_targets(config):
    """Garante que cada alvo tem os campos obrigatorios e aplica defaults."""
    targets = config.get("targets")
    if not targets:
        sys.exit("Config sem 'targets'. Veja config.example.yaml.")

    base_url_global = config.get("base_url")
    normalizados = []
    for i, t in enumerate(targets):
        base = t.get("base_url") or base_url_global
        if not base:
            sys.exit(f"Target #{i}: falta 'base_url' (ou defina um 'base_url' global).")
        if "device_id" not in t:
            sys.exit(f"Target #{i}: falta 'device_id'.")
        if not t.get("api_key"):
            sys.exit(f"Target #{i}: falta 'api_key'.")
        normalizados.append(
            {
                "name": t.get("name", f"target-{t['device_id']}"),
                "url": base.rstrip("/") + "/api/enviar_payload.php",
                "device_id": int(t["device_id"]),
                "api_key": t["api_key"],
                "payload_template": t.get("payload", {"valor": {"type": "float", "min": 0, "max": 100}}),
                "weight": float(t.get("weight", 1)),
            }
        )
    return normalizados


# --------------------------------------------------------------------------- #
# Geracao de payload a partir do template
# --------------------------------------------------------------------------- #
def gerar_valor(spec):
    """Gera um valor a partir da especificacao de um campo do payload."""
    if not isinstance(spec, dict):
        return spec  # valor fixo (numero, string, bool)
    tipo = spec.get("type", "float")
    if tipo == "float":
        v = random.uniform(spec.get("min", 0), spec.get("max", 100))
        return round(v, spec.get("decimals", 2))
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
    return {chave: gerar_valor(spec) for chave, spec in template.items()}


# --------------------------------------------------------------------------- #
# Execucao da carga
# --------------------------------------------------------------------------- #
class Resultados:
    """Acumula metricas e grava o CSV incrementalmente."""

    def __init__(self, csv_writer):
        self.csv_writer = csv_writer
        self.latencias = []          # ms, apenas das que receberam resposta
        self.total = 0
        self.sucesso = 0
        self.falha = 0
        self.status_codes = {}
        self.erros = {}
        self.por_target = {}         # name -> dict de contadores/latencias

    def registrar(self, linha):
        self.total += 1
        name = linha["target"]
        alvo = self.por_target.setdefault(
            name, {"requests": 0, "sucesso": 0, "falha": 0, "latencias": []}
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

        sc = str(linha["status"]) if linha["status"] is not None else "-"
        self.status_codes[sc] = self.status_codes.get(sc, 0) + 1

        self.csv_writer.writerow(
            [
                linha["timestamp"],
                f'{linha["elapsed_s"]:.3f}',
                linha["target"],
                linha["device_id"],
                linha["status"] if linha["status"] is not None else "",
                f'{linha["latency_ms"]:.2f}' if linha["latency_ms"] is not None else "",
                int(linha["ok"]),
                linha["error"] or "",
            ]
        )


def percentis(valores):
    if not valores:
        return {}
    s = sorted(valores)

    def pct(p):
        # metodo nearest-rank
        k = max(0, min(len(s) - 1, int(round(p / 100.0 * (len(s) - 1)))))
        return s[k]

    resumo = {
        "min": round(s[0], 2),
        "mean": round(statistics.fmean(s), 2),
        "median": round(statistics.median(s), 2),
        "p90": round(pct(90), 2),
        "p95": round(pct(95), 2),
        "p99": round(pct(99), 2),
        "max": round(s[-1], 2),
    }
    if len(s) > 1:
        resumo["stdev"] = round(statistics.stdev(s), 2)
    return resumo


async def worker(nome, queue, session, timeout, resultados, t0, parar_evento):
    while True:
        item = await queue.get()
        if item is None:  # sentinela de encerramento
            queue.task_done()
            return
        target = item
        corpo = json.dumps(
            {"device_id": target["device_id"], "payload": gerar_payload(target["payload_template"])}
        )
        headers = {"Content-Type": "application/json", "X-Api-Key": target["api_key"]}

        status = None
        latency_ms = None
        ok = False
        erro = None
        inicio = time.perf_counter()
        try:
            async with session.post(
                target["url"], data=corpo, headers=headers, timeout=timeout
            ) as resp:
                status = resp.status
                await resp.read()
                latency_ms = (time.perf_counter() - inicio) * 1000.0
                ok = 200 <= status < 300
                if not ok:
                    erro = f"HTTP {status}"
        except asyncio.TimeoutError:
            latency_ms = (time.perf_counter() - inicio) * 1000.0
            erro = "timeout"
        except aiohttp.ClientError as e:
            erro = f"conexao: {type(e).__name__}"
        except Exception as e:  # noqa: BLE001
            erro = f"{type(e).__name__}: {e}"

        resultados.registrar(
            {
                "timestamp": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
                "elapsed_s": time.perf_counter() - t0,
                "target": target["name"],
                "device_id": target["device_id"],
                "status": status,
                "latency_ms": latency_ms,
                "ok": ok,
                "error": erro,
            }
        )
        queue.task_done()


async def feeder(queue, targets, args, parar_evento, t0):
    """Enfileira jobs ate atingir --requests ou --duration. Respeita --rate."""
    pesos = [t["weight"] for t in targets]
    intervalo = 1.0 / args.rate if args.rate else 0.0
    enfileirados = 0
    proximo = time.perf_counter()

    def ainda_rodando():
        if parar_evento.is_set():
            return False
        if args.requests is not None and enfileirados >= args.requests:
            return False
        if args.duration is not None and (time.perf_counter() - t0) >= args.duration:
            return False
        return True

    while ainda_rodando():
        alvo = random.choices(targets, weights=pesos, k=1)[0]
        await queue.put(alvo)
        enfileirados += 1
        if intervalo:
            proximo += intervalo
            atraso = proximo - time.perf_counter()
            if atraso > 0:
                await asyncio.sleep(atraso)

    # sentinelas para encerrar os workers
    for _ in range(args.concurrency):
        await queue.put(None)


async def progresso(resultados, parar_evento, intervalo=2.0):
    while not parar_evento.is_set():
        await asyncio.sleep(intervalo)
        print(
            f"  ... {resultados.total} enviadas | "
            f"{resultados.sucesso} ok | {resultados.falha} falha",
            file=sys.stderr,
        )


async def executar(args, targets):
    os.makedirs(args.out, exist_ok=True)
    carimbo = datetime.now().strftime("%Y%m%d-%H%M%S")
    csv_path = os.path.join(args.out, f"requests_{carimbo}.csv")
    json_path = os.path.join(args.out, f"resumo_{carimbo}.json")

    csv_file = open(csv_path, "w", newline="", encoding="utf-8")
    writer = csv.writer(csv_file)
    writer.writerow(
        ["timestamp_utc", "elapsed_s", "target", "device_id", "status", "latency_ms", "ok", "error"]
    )
    resultados = Resultados(writer)

    parar_evento = asyncio.Event()

    def _sinal(*_):
        print("\nInterrompido pelo usuario - finalizando e salvando...", file=sys.stderr)
        parar_evento.set()

    try:
        signal.signal(signal.SIGINT, _sinal)
    except ValueError:
        pass  # fora da thread principal

    queue = asyncio.Queue(maxsize=args.concurrency * 4)
    timeout = aiohttp.ClientTimeout(total=args.timeout)
    conector = aiohttp.TCPConnector(limit=args.concurrency, ssl=False)

    t0 = time.perf_counter()
    inicio_iso = datetime.now(timezone.utc).isoformat(timespec="seconds")

    async with aiohttp.ClientSession(connector=conector) as session:
        workers = [
            asyncio.create_task(worker(i, queue, session, timeout, resultados, t0, parar_evento))
            for i in range(args.concurrency)
        ]
        tarefa_prog = asyncio.create_task(progresso(resultados, parar_evento))
        await feeder(queue, targets, args, parar_evento, t0)
        await asyncio.gather(*workers)
        parar_evento.set()
        tarefa_prog.cancel()

    wall = time.perf_counter() - t0
    csv_file.close()

    # ---- Resumo ----
    resumo = montar_resumo(args, targets, resultados, inicio_iso, wall, csv_path)
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(resumo, f, indent=2, ensure_ascii=False)

    imprimir_resumo(resumo, csv_path, json_path)
    return resumo


def montar_resumo(args, targets, r, inicio_iso, wall, csv_path):
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
        "config": {
            "concurrency": args.concurrency,
            "requests": args.requests,
            "duration_s": args.duration,
            "rate_rps": args.rate,
            "timeout_s": args.timeout,
            "n_targets": len(targets),
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
        "status_codes": dict(sorted(r.status_codes.items())),
        "latency_ms": percentis(r.latencias),
        "erros": dict(sorted(r.erros.items(), key=lambda kv: -kv[1])),
        "por_target": por_target,
    }


def imprimir_resumo(resumo, csv_path, json_path):
    t = resumo["totais"]
    lat = resumo["latency_ms"]
    print("\n" + "=" * 60)
    print("RESUMO DO TESTE")
    print("=" * 60)
    print(f"Duracao total      : {resumo['wall_time_s']} s")
    print(f"Requisicoes        : {t['requests']}")
    print(f"Sucesso / Falha    : {t['sucesso']} / {t['falha']}  ({t['taxa_sucesso_pct']}% ok)")
    print(f"Throughput         : {t['throughput_rps']} req/s")
    if lat:
        print(f"Latencia (ms)      : media {lat['mean']} | mediana {lat['median']} "
              f"| p95 {lat['p95']} | p99 {lat['p99']} | max {lat['max']}")
    if resumo["status_codes"]:
        print(f"Status HTTP        : {resumo['status_codes']}")
    if resumo["erros"]:
        print(f"Erros              : {resumo['erros']}")
    print("-" * 60)
    for nome, d in resumo["por_target"].items():
        print(f"  [{nome}] {d['requests']} req | {d['taxa_sucesso_pct']}% ok", end="")
        if d["latency_ms"]:
            print(f" | media {d['latency_ms']['mean']} ms")
        else:
            print()
    print("-" * 60)
    print(f"CSV   : {csv_path}")
    print(f"Resumo: {json_path}")
    print("=" * 60)


def parse_args():
    p = argparse.ArgumentParser(
        description="Gerador de carga/testes HTTP para a plataforma IFSCentral.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--config", required=True, help="Arquivo de config (.yaml ou .json)")
    grupo = p.add_mutually_exclusive_group(required=True)
    grupo.add_argument("--requests", type=int, help="Numero total de requisicoes a enviar")
    grupo.add_argument("--duration", type=float, help="Duracao do teste em segundos")
    p.add_argument("--concurrency", type=int, default=10, help="Requisicoes simultaneas (workers)")
    p.add_argument("--rate", type=float, default=None, help="Limite de taxa (requisicoes/segundo)")
    p.add_argument("--timeout", type=float, default=10.0, help="Timeout por requisicao (s)")
    p.add_argument("--out", default="resultados", help="Pasta de saida dos relatorios")
    p.add_argument("--seed", type=int, default=None, help="Semente aleatoria (reprodutibilidade)")
    return p.parse_args()


def main():
    args = parse_args()
    if args.seed is not None:
        random.seed(args.seed)
    config = carregar_config(args.config)
    targets = validar_targets(config)

    print(f"Alvos carregados: {len(targets)}")
    for t in targets:
        print(f"  - {t['name']}  (device_id={t['device_id']})  -> {t['url']}")
    modo = f"{args.requests} requisicoes" if args.requests else f"{args.duration}s de duracao"
    print(f"Modo: {modo} | concorrencia={args.concurrency}"
          + (f" | taxa={args.rate} req/s" if args.rate else ""))
    print("Iniciando...\n")

    asyncio.run(executar(args, targets))


if __name__ == "__main__":
    main()
