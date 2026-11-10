#!/usr/bin/env python3
"""
preparar_cenario.py - Cria projetos e dispositivos para o teste de carga

Entra na plataforma com uma conta Admin ou Moderator, cria N projetos com M
dispositivos cada (pela API, como um usuário faria) e grava os arquivos de
configuração dos geradores de carga:

  - config.cenario.json       -> ifscentral_loadtest.py (HTTP)
  - config.mqtt.cenario.json  -> ifscentral_mqtt_loadtest.py (MQTT, só edição completa)

Uso:
  python preparar_cenario.py --base https://IP:8443 --email admin@x --senha 'S3nha' \\
      --projetos 20 --dispositivos 10 --mqtt-host IP --mqtt-port 1883

Os projetos ficam com o nome "Carga NN" para serem fáceis de achar e apagar
depois (Configurações do projeto > Excluir projeto).
"""

import argparse
import json
import sys

import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

PAYLOAD = {
    "temperatura": {"type": "float", "min": 18, "max": 35, "decimals": 2},
    "umidade": {"type": "int", "min": 35, "max": 95},
    "bateria": {"type": "float", "min": 3.3, "max": 4.2, "decimals": 2},
    "porta_aberta": {"type": "bool", "p": 0.1},
}


class Sessao:
    def __init__(self, base):
        self.base = base.rstrip("/")
        self.s = requests.Session()
        self.s.verify = False
        self.s.headers["X-Requested-With"] = "XMLHttpRequest"
        self.csrf = None

    def _url(self, rota):
        return f"{self.base}/{rota.lstrip('/')}"

    def post(self, rota, corpo, csrf=True):
        h = {"Content-Type": "application/json"}
        if csrf:
            if self.csrf is None:
                self.csrf = self.s.get(self._url("api/obter-csrf-token")).json().get("csrf_token")
            h["X-CSRF-Token"] = self.csrf or ""
        return self.s.post(self._url(rota), data=json.dumps(corpo), headers=h, allow_redirects=False)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", required=True, help="endereço da plataforma (ex.: https://10.50.0.30:8443)")
    ap.add_argument("--email", required=True, help="conta Admin ou Moderator")
    ap.add_argument("--senha", required=True)
    ap.add_argument("--projetos", type=int, default=10)
    ap.add_argument("--dispositivos", type=int, default=10, help="dispositivos por projeto")
    ap.add_argument("--mqtt-host", help="host do broker (padrão: o mesmo da --base)")
    ap.add_argument("--mqtt-port", type=int, default=1883)
    ap.add_argument("--saida-http", default="config.cenario.json")
    ap.add_argument("--saida-mqtt", default="config.mqtt.cenario.json")
    a = ap.parse_args()

    s = Sessao(a.base)
    r = s.post("api/login", {"email": a.email, "password": a.senha}, csrf=False)
    if r.status_code != 200:
        sys.exit(f"Login falhou ({r.status_code}): {r.text[:200]}")
    if r.json().get("requires_2fa") or r.json().get("2fa_required"):
        sys.exit("A conta tem 2FA ligado; use uma conta sem 2FA para o teste.")

    alvos_http, alvos_mqtt = [], []
    for p in range(1, a.projetos + 1):
        nome = f"Carga {p:02d}"
        r = s.post("api/criar-projeto", {"name": nome, "description": "Projeto criado para o teste de carga",
                                         "public": 0, "maxUsers": None, "tags": []})
        if r.status_code != 201:
            sys.exit(f"Criar projeto falhou ({r.status_code}): {r.text[:200]}")
        corpo = r.json()
        pid = corpo.get("project_id") or corpo.get("insertedId") or corpo.get("id")
        if not pid:
            sys.exit(f"Resposta de criar projeto sem id: {corpo}")
        for d in range(1, a.dispositivos + 1):
            r = s.post("api/cadastrar-device", {"project_id": pid, "name": f"Sensor {p:02d}-{d:02d}",
                                                "description": "Dispositivo do teste de carga"})
            if r.status_code != 201:
                sys.exit(f"Cadastrar dispositivo falhou ({r.status_code}): {r.text[:200]}")
            dev = r.json()
            did = int(dev["insertedId"])
            alvos_http.append({"name": f"{nome} / Sensor {d:02d}", "device_id": did,
                               "api_key": dev["api_key"], "payload": PAYLOAD})
            mq = dev.get("mqtt") or {}
            if mq.get("username"):
                alvos_mqtt.append({"name": f"{nome} / Sensor {d:02d}", "project_id": int(pid), "device_id": did,
                                   "mqtt_username": mq["username"], "mqtt_password": mq["password"],
                                   "payload": PAYLOAD})
        print(f"{nome}: projeto {pid}, {a.dispositivos} dispositivos")

    with open(a.saida_http, "w", encoding="utf-8") as f:
        json.dump({"base_url": a.base, "targets": alvos_http}, f, indent=2, ensure_ascii=False)
    print(f"\n{len(alvos_http)} dispositivos -> {a.saida_http}")

    if alvos_mqtt:
        host = a.mqtt_host or a.base.split("://", 1)[-1].split("/")[0].split(":")[0]
        with open(a.saida_mqtt, "w", encoding="utf-8") as f:
            json.dump({"broker": {"host": host, "port": a.mqtt_port, "tls": a.mqtt_port == 8883},
                       "targets": alvos_mqtt}, f, indent=2, ensure_ascii=False)
        print(f"{len(alvos_mqtt)} credenciais MQTT -> {a.saida_mqtt}")
    else:
        print("Sem credenciais MQTT (edição Lite?): só o teste HTTP se aplica.")


if __name__ == "__main__":
    main()
