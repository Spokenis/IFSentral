#!/usr/bin/env python3
"""
bateria.py - Roda uma bateria de testes com concorrencias crescentes e junta
tudo numa tabela (CSV) e num grafico (PNG). Serve para a validacao do TCC:
mostra como throughput e latencia (p95/p99) variam com a carga.

Chama o gerador HTTP (ifscentral_loadtest.py) ou o MQTT
(ifscentral_mqtt_loadtest.py) uma vez por nivel de concorrencia, le o resumo
JSON de cada rodada e consolida.

Exemplos:
    # HTTP, 1000 req por nivel, niveis 1/10/50/100
    python bateria.py --tool http --config config.yaml \
        --levels 1,10,50,100 --requests 1000

    # MQTT, 30 s por nivel
    python bateria.py --tool mqtt --config config.mqtt.yaml \
        --levels 1,5,20,50 --duration 30

Requer matplotlib para o grafico (pip install matplotlib). Sem ele, gera so a
tabela CSV.
"""

import argparse
import glob
import json
import os
import subprocess
import sys
from datetime import datetime

AQUI = os.path.dirname(os.path.abspath(__file__))
SCRIPTS = {
    "http": os.path.join(AQUI, "ifscentral_loadtest.py"),
    "mqtt": os.path.join(AQUI, "ifscentral_mqtt_loadtest.py"),
}
PADRAO_RESUMO = {"http": "resumo_*.json", "mqtt": "mqtt_resumo_*.json"}


def rodar_nivel(args, nivel, out_dir):
    cmd = [
        sys.executable, SCRIPTS[args.tool],
        "--config", args.config,
        "--concurrency", str(nivel),
        "--out", out_dir,
    ]
    if args.requests is not None:
        cmd += ["--requests", str(args.requests)]
    else:
        cmd += ["--duration", str(args.duration)]
    if args.rate is not None:
        cmd += ["--rate", str(args.rate)]
    if args.timeout is not None:
        cmd += ["--timeout", str(args.timeout)]
    if args.seed is not None:
        cmd += ["--seed", str(args.seed)]

    print(f"\n### Nivel de concorrencia = {nivel}")
    subprocess.run(cmd, check=True)

    resumos = sorted(glob.glob(os.path.join(out_dir, PADRAO_RESUMO[args.tool])))
    if not resumos:
        sys.exit(f"Nenhum resumo encontrado em {out_dir}")
    with open(resumos[-1], encoding="utf-8") as f:
        return json.load(f)


def extrair_linha(nivel, resumo):
    t = resumo["totais"]
    lat = resumo.get("latency_ms", {})
    return {
        "concurrency": nivel,
        "requests": t["requests"],
        "sucesso": t["sucesso"],
        "falha": t["falha"],
        "taxa_sucesso_pct": t["taxa_sucesso_pct"],
        "throughput_rps": t["throughput_rps"],
        "lat_mean": lat.get("mean"),
        "lat_median": lat.get("median"),
        "lat_p95": lat.get("p95"),
        "lat_p99": lat.get("p99"),
        "lat_max": lat.get("max"),
    }


def salvar_csv(linhas, caminho):
    import csv
    campos = list(linhas[0].keys())
    with open(caminho, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=campos)
        w.writeheader()
        w.writerows(linhas)


def imprimir_tabela(linhas, protocolo):
    unid = "req/s" if protocolo == "http" else "msg/s"
    print("\n" + "=" * 78)
    print("BATERIA - RESUMO CONSOLIDADO")
    print("=" * 78)
    cab = f"{'conc.':>6} | {'reqs':>7} | {'ok%':>6} | {'throughput':>12} | " \
          f"{'media':>8} | {'p95':>8} | {'p99':>8}"
    print(cab)
    print("-" * 78)
    for l in linhas:
        print(f"{l['concurrency']:>6} | {l['requests']:>7} | {l['taxa_sucesso_pct']:>6} | "
              f"{str(l['throughput_rps'])+' '+unid:>12} | "
              f"{str(l['lat_mean']):>8} | {str(l['lat_p95']):>8} | {str(l['lat_p99']):>8}")
    print("=" * 78)


def gerar_grafico(linhas, caminho, protocolo):
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
    except ImportError:
        print("matplotlib nao instalado - pulando grafico (pip install matplotlib).",
              file=sys.stderr)
        return False

    x = [l["concurrency"] for l in linhas]
    thr = [l["throughput_rps"] for l in linhas]
    media = [l["lat_mean"] for l in linhas]
    p95 = [l["lat_p95"] for l in linhas]
    p99 = [l["lat_p99"] for l in linhas]
    unid = "req/s" if protocolo == "http" else "msg/s"

    fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(11, 4.2))

    ax1.plot(x, thr, "o-", color="#1f77b4")
    ax1.set_title(f"Throughput x Concorrencia ({protocolo.upper()})")
    ax1.set_xlabel("Conexoes simultaneas")
    ax1.set_ylabel(f"Throughput ({unid})")
    ax1.grid(True, alpha=0.3)

    ax2.plot(x, media, "o-", label="media", color="#2ca02c")
    ax2.plot(x, p95, "s--", label="p95", color="#ff7f0e")
    ax2.plot(x, p99, "^:", label="p99", color="#d62728")
    ax2.set_title("Latencia x Concorrencia")
    ax2.set_xlabel("Conexoes simultaneas")
    ax2.set_ylabel("Latencia (ms)")
    ax2.grid(True, alpha=0.3)
    ax2.legend()

    fig.tight_layout()
    fig.savefig(caminho, dpi=150)
    plt.close(fig)
    return True


def parse_args():
    p = argparse.ArgumentParser(
        description="Bateria de testes com concorrencias crescentes (HTTP ou MQTT).",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--tool", choices=["http", "mqtt"], required=True)
    p.add_argument("--config", required=True)
    p.add_argument("--levels", required=True,
                   help="Niveis de concorrencia separados por virgula, ex.: 1,10,50,100")
    g = p.add_mutually_exclusive_group(required=True)
    g.add_argument("--requests", type=int, help="Requisicoes por nivel")
    g.add_argument("--duration", type=float, help="Duracao (s) por nivel")
    p.add_argument("--rate", type=float, default=None)
    p.add_argument("--timeout", type=float, default=None)
    p.add_argument("--seed", type=int, default=None)
    p.add_argument("--out", default="resultados", help="Pasta base de saida")
    return p.parse_args()


def main():
    args = parse_args()
    niveis = [int(n) for n in args.levels.split(",") if n.strip()]
    carimbo = datetime.now().strftime("%Y%m%d-%H%M%S")
    base = os.path.join(args.out, f"bateria_{args.tool}_{carimbo}")
    os.makedirs(base, exist_ok=True)

    linhas = []
    protocolo = args.tool
    for nivel in niveis:
        out_dir = os.path.join(base, f"conc_{nivel}")
        resumo = rodar_nivel(args, nivel, out_dir)
        protocolo = resumo.get("protocolo", args.tool)
        linhas.append(extrair_linha(nivel, resumo))

    csv_path = os.path.join(base, "bateria_consolidado.csv")
    png_path = os.path.join(base, "bateria_grafico.png")
    salvar_csv(linhas, csv_path)
    imprimir_tabela(linhas, protocolo)
    ok_png = gerar_grafico(linhas, png_path, protocolo)

    print(f"\nTabela consolidada: {csv_path}")
    if ok_png:
        print(f"Grafico           : {png_path}")
    print(f"Rodadas por nivel : {base}/conc_<n>/")


if __name__ == "__main__":
    main()
