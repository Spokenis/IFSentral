"""
Simulador do The Things Network para os testes E2E.

- :443  HTTPS, como eu1.cloud.thethings.network: Storage Integration
        GET /api/v3/as/applications/{app}/packages/storage/uplink_message
        (Authorization: Bearer, after, limit; resposta no formato real: um
        objeto {"result": ...} formatado por registro, separados por linha em branco)
- :8883 repassa TCP para o broker MQTT simulado (ttn-mqtt:8883), para que o
        mesmo nome de host atenda HTTPS e MQTT, como no TTN de verdade
- :8080 HTTP de controle, usado só pelo script de teste:
        POST /uplink   {"application_id", "device_id", "received_at"?, "decoded_payload"?}
        POST /chave    {"application_id", "api_key"}  (simula chave trocada/revogada)
        POST /reset

Aplicações e chaves válidas vêm de TTN_APPS="app1:chave1,app2:chave2".
"""
import json
import os
import re
import socket
import ssl
import threading
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

CHAVES = dict(par.split(':', 1) for par in os.environ.get('TTN_APPS', '').split(',') if ':' in par)
UPLINKS = {app: [] for app in CHAVES}
TRAVA = threading.Lock()
ROTA = re.compile(r'^/api/v3/as/applications/([^/]+)/packages/storage/uplink_message$')


def instante(rfc3339):
    # Python lê no máximo 6 casas decimais
    texto = re.sub(r'\.(\d{6})\d+', r'.\1', rfc3339.replace('Z', '+00:00'))
    return datetime.fromisoformat(texto)


def agora_rfc3339():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%f') + '123Z'  # 9 casas, como o TTN


class Storage(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def responder(self, codigo, corpo, tipo='application/json'):
        dados = corpo.encode()
        self.send_response(codigo)
        self.send_header('Content-Type', tipo)
        self.send_header('Content-Length', str(len(dados)))
        self.end_headers()
        self.wfile.write(dados)

    def do_GET(self):
        url = urlparse(self.path)
        m = ROTA.match(url.path)
        if not m or m.group(1) not in CHAVES:
            return self.responder(404, json.dumps({'code': 5, 'message': 'not found'}))
        app = m.group(1)
        if self.headers.get('Authorization') != f'Bearer {CHAVES[app]}':
            return self.responder(401, json.dumps({'code': 16, 'message': 'unauthenticated'}))
        q = parse_qs(url.query)
        limite = int(q.get('limit', ['100'])[0])
        with TRAVA:
            registros = sorted(UPLINKS[app], key=lambda r: instante(r['received_at']))
        if 'after' in q:
            depois = instante(q['after'][0])
            registros = [r for r in registros if instante(r['received_at']) > depois]
        corpo = '\n\n'.join(json.dumps({'result': r}, indent=2) for r in registros[:limite])
        self.responder(200, corpo + ('\n' if corpo else ''), 'text/event-stream')


class Controle(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        dados = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))) or b'{}')
        with TRAVA:
            if self.path == '/uplink':
                app = dados['application_id']
                registro = {
                    'end_device_ids': {'device_id': dados['device_id'], 'application_ids': {'application_id': app}},
                    'received_at': dados.get('received_at') or agora_rfc3339(),
                    'uplink_message': {},
                }
                if 'decoded_payload' in dados:
                    registro['uplink_message']['decoded_payload'] = dados['decoded_payload']
                UPLINKS.setdefault(app, []).append(registro)
                resposta = registro
            elif self.path == '/chave':
                CHAVES[dados['application_id']] = dados['api_key']
                resposta = {'ok': True}
            elif self.path == '/reset':
                for lista in UPLINKS.values():
                    lista.clear()
                resposta = {'ok': True}
            else:
                self.send_response(404)
                self.end_headers()
                return
        corpo = json.dumps(resposta).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(corpo)))
        self.end_headers()
        self.wfile.write(corpo)


def repassar_mqtt():
    servidor = socket.create_server(('0.0.0.0', 8883))
    while True:
        cliente, _ = servidor.accept()
        destino = socket.create_connection(('ttn-mqtt', 8883))

        def copiar(de, para):
            try:
                while (bloco := de.recv(65536)):
                    para.sendall(bloco)
            except OSError:
                pass
            finally:
                for s in (de, para):
                    try:
                        s.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass

        threading.Thread(target=copiar, args=(cliente, destino), daemon=True).start()
        threading.Thread(target=copiar, args=(destino, cliente), daemon=True).start()


if __name__ == '__main__':
    https = ThreadingHTTPServer(('0.0.0.0', 443), Storage)
    contexto = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    contexto.load_cert_chain('/certs/ttn.pem', '/certs/ttn.key')
    https.socket = contexto.wrap_socket(https.socket, server_side=True)
    threading.Thread(target=https.serve_forever, daemon=True).start()
    threading.Thread(target=repassar_mqtt, daemon=True).start()
    print('Simulador do TTN no ar: aplicações', list(CHAVES), flush=True)
    ThreadingHTTPServer(('0.0.0.0', 8080), Controle).serve_forever()
