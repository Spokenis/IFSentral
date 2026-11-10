#!/usr/bin/env python3
"""Compara o resultado do falhas_gerador.py com o banco isolado: perdas e duplicações."""
import json, subprocess, sys, time
r = json.load(open(sys.argv[1])); rotulo = r['rotulo']
# espera o banco parar de crescer (fila do worker)
ant = -1
for _ in range(120):
    n = subprocess.run(['docker', 'exec', 'ifs_carga_db', 'mysql', '-uroot', '-prootpassword', '-N', 'ifsentral_bd', '-e',
                        f"SELECT COUNT(*) FROM device_payloads WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.teste'))='{rotulo}'"],
                       capture_output=True, text=True).stdout.strip()
    if not n.isdigit():   # banco ainda voltando: tenta de novo
        time.sleep(3); continue
    if n == ant:
        break
    ant = n; time.sleep(3)
linhas = subprocess.run(['docker', 'exec', 'ifs_carga_db', 'mysql', '-uroot', '-prootpassword', '-N', 'ifsentral_bd', '-e',
                         f"SELECT device_id, source, JSON_EXTRACT(payload,'$.seq') FROM device_payloads WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.teste'))='{rotulo}'"],
                        capture_output=True, text=True).stdout.split('\n')
gravado = {}
for l in linhas:
    if l.strip():
        d, src, seq = l.split('\t'); gravado.setdefault((src, d), []).append(int(seq))
for tipo in ('http', 'mqtt'):
    if not r[tipo]:
        continue
    conf_tot = perdas = dup = gravadas = 0
    exemplos = []
    for d, v in r[tipo].items():
        g = gravado.get((tipo, d), [])
        gravadas += len(g)
        conf = set(v['confirmadas']); conf_tot += len(conf)
        falta = conf - set(g)
        perdas += len(falta)
        if falta and len(exemplos) < 3: exemplos.append(f"disp {d}: seq {sorted(falta)[:5]}")
        dup += len(g) - len(set(g))
    print(f"{tipo.upper():4}: confirmadas {conf_tot} | gravadas {gravadas} | PERDIDAS (confirmadas e ausentes) {perdas} | duplicadas {dup}" + (f" | ex.: {'; '.join(exemplos)}" if exemplos else ''))
