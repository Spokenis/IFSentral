#!/usr/bin/env python3
"""Teste de controle de acesso (IDOR): um usuário comum tenta ler e alterar
recursos de outro usuário em todos os endpoints da API."""
import json, sys, requests, urllib3, subprocess
urllib3.disable_warnings()
B = 'https://127.0.0.1:38443'
D = sys.argv[1]


def sessao(email, senha='Seg@2026xx'):
    s = requests.Session(); s.verify = False; s.headers['X-Requested-With'] = 'XMLHttpRequest'
    r = s.post(f'{B}/api/login', json={'email': email, 'password': senha}); assert r.ok, r.text
    s.headers['X-CSRF-Token'] = s.get(f'{B}/api/obter-csrf-token').json()['csrf_token']
    return s


h = json.load(open(f'{D}/v.http.json'))['targets']; q = json.load(open(f'{D}/v.mqtt.json'))['targets']
P = q[0]['project_id']; DEV = h[0]['device_id']; KEY = h[0]['api_key']; MQU = q[0]['mqtt_username']
v = sessao('vitima@teste.dev')
r = requests.post(f'{B}/api/enviar-payload', verify=False, headers={'X-Api-Key': KEY},
                  json={'device_id': DEV, 'payload': {'temperatura': 21.5, 'segredo': 'SEGREDO_VITIMA'}})
print('payload da vítima:', r.status_code)
r = v.post(f'{B}/api/salvar-grafico-avancado', json={'project_id': P, 'name': 'GraficoVitima', 'chart_type': 'line', 'time_range': '24h',
           'datasets': [{'device_id': DEV, 'variable_name': 'temperatura', 'alias': 'T', 'color': '#123456'}]})
j = r.json(); CH = int(j.get('chart_id') or j.get('id') or j.get('insertedId'))
nome_proj = v.get(f'{B}/api/obter-projeto', params={'id': P}).json()
print('projeto', P, 'disp', DEV, 'gráfico', CH)
MARCAS = [KEY, MQU, 'SEGREDO_VITIMA', 'GraficoVitima']

a = sessao('atacante@teste.dev')
ids = {'project_id': P, 'id': P, 'device_id': DEV, 'chart_id': CH, 'user_id': 7, 'request_id': 1}
ROTAS = ['listar-devices', 'listar-graficos', 'obter-projeto', 'obter-chaves-dispositivo', 'obter-dados-grafico', 'obter-info-dispositivo',
         'get-mqtt-credentials', 'regenerate-mqtt-password', 'gerenciar-tags-dispositivo', 'gerenciar-mapeamentos-dispositivo',
         'atualizar-grafico', 'salvar-grafico', 'deletar-grafico', 'alterar-visibilidade-grafico', 'alterar-visibilidade-projeto',
         'atualizar-grafico-avancado', 'atualizar-projeto', 'buscar-payloads', 'deletar-projeto', 'enviar-convite', 'exportar-dados-projeto',
         'expulsar-participante', 'listar-participantes', 'listar-solicitacoes-participacao', 'obter-contagem-participantes',
         'obter-dados-grafico-avancado', 'obter-dados-grafico-renderizado', 'obter-stats-payloads', 'promover-gerente', 'sair-projeto',
         'salvar-grafico-avancado', 'ttn-configuracao', 'ttn-dispositivo', 'ttn-sincronizar', 'deletar-dispositivo', 'cadastrar-device',
         'admin-usuarios', 'preparar-graficos', 'documentacao', 'obter-logs-mqtt']
extra = {'is_public': 1, 'public': 1, 'name': 'HACK', 'reveal': 1, 'reveal_password': True, 'email': 'atacante@teste.dev', 'enabled': 1,
         'datasets': [{'device_id': DEV, 'variable_name': 'temperatura'}], 'tags': ['x'], 'ttn_device_id': 'x', 'application_id': 'x',
         'api_key': 'x', 'cluster': 'eu1', 'action': 'approve', 'acao': 'salvar', 'conteudo': '<b>x</b>', 'description': 'x', 'mappings': []}
achados = []
for rota in ROTAS:
    for metodo in ('GET', 'POST', 'PUT', 'DELETE'):
        corpo = {**ids, **extra}
        try:
            if metodo == 'GET':
                r = a.get(f'{B}/api/{rota}', params=ids, timeout=20)
            else:
                r = a.request(metodo, f'{B}/api/{rota}', params=ids, json=corpo, timeout=20)
        except Exception as e:
            print(f'{rota} {metodo}: {type(e).__name__}'); continue
        t = r.text
        vaz = [m for m in MARCAS if m in t]
        suc = r.status_code < 300 and ('"success":true' in t.replace(' ', ''))
        if vaz or suc:
            achados.append((rota, metodo, r.status_code, vaz, t[:160]))
        print(f'{rota:36} {metodo:6} {r.status_code} {"VAZOU " + str(vaz) if vaz else ""}{" SUCESSO" if suc else ""}')

print('\n=== suspeitos ===')
for x in achados: print(x)
# estado da vítima depois do ataque
print('\n=== vítima depois ===')
print(json.dumps(v.get(f'{B}/api/obter-projeto', params={'id': P}).json(), ensure_ascii=False)[:300])
print(v.get(f'{B}/api/listar-devices', params={'project_id': P}).text[:300])
print(v.get(f'{B}/api/listar-graficos', params={'project_id': P}).text[:300])
