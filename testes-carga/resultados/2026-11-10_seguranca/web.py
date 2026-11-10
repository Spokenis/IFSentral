#!/usr/bin/env python3
"""Sem login, CSRF, força bruta, cookies, cabeçalhos, injeção e arquivos sensíveis."""
import re, time, json, requests, urllib3
urllib3.disable_warnings()
B = 'https://127.0.0.1:38443'
ht = open('/home/denis/Área de trabalho/TCC/IFSentral/.htaccess').read()
rotas = sorted(set(re.findall(r'RewriteRule \^(api/[a-z0-9_-]+)\$', ht)))
paginas = ['meus-projetos', 'meus-dispositivos', 'perfil', 'configuracoes', 'novo-projeto', 'projeto?id=30', 'dispositivo?id=218',
           'novo-grafico', 'admin', 'admin/usuarios', 'admin/rate-limiting']
publicas = {'api/login', 'api/cadastrar-usuario', 'api/esqueci-senha', 'api/redefinir-senha', 'api/verificar-2fa', 'api/enviar-payload',
            'api/obter-csrf-token', 'api/saude', 'api/ttn-webhook', 'api/listar-projetos-publicos', 'api/logout'}

print('== 1) sem login')
anon = requests.Session(); anon.verify = False
for r_ in rotas:
    for m in ('GET', 'POST'):
        r = anon.request(m, f'{B}/{r_}', json={}, timeout=20, allow_redirects=False)
        if r.status_code < 300 and r_ not in publicas:
            print(f'  ABERTO? {m} {r_} {r.status_code} {r.text[:120]!r}')
        if r.status_code >= 500:
            print(f'  ERRO 5xx {m} {r_} {r.status_code} {r.text[:120]!r}')
for p in paginas:
    r = anon.get(f'{B}/{p}', allow_redirects=False)
    if r.status_code == 200: print(f'  PÁGINA ABERTA {p}')
print('  (fim; só aparece acima o que respondeu 2xx sem login fora das rotas públicas)')

print('== 2) CSRF: requisição autenticada sem token')
s = requests.Session(); s.verify = False; s.headers['X-Requested-With'] = 'XMLHttpRequest'
r = s.post(f'{B}/api/login', json={'email': 'vitima@teste.dev', 'password': 'Seg@2026xx'})
print('  login', r.status_code)
for rota, corpo in [('api/criar-projeto', {'name': 'csrf'}), ('api/atualizar-perfil', {'name': 'csrf'}),
                    ('api/alterar-visibilidade-projeto', {'project_id': 30, 'public': 1}), ('api/deletar-projeto', {'project_id': 30})]:
    r = s.post(f'{B}/{rota}', json=corpo)
    print(f'  {rota}: {r.status_code} {r.text[:80]!r}')
# formulário de outro site (sem X-Requested-With, content-type de form)
r = requests.post(f'{B}/api/atualizar-perfil', data={'name': 'csrf'}, cookies=s.cookies, verify=False, headers={'Origin': 'https://mal.example'})
print(f'  form de outra origem: {r.status_code} {r.text[:80]!r}')

print('== 3) cookies e cabeçalhos')
r = requests.post(f'{B}/api/login', json={'email': 'vitima@teste.dev', 'password': 'Seg@2026xx'}, verify=False)
print('  Set-Cookie:', r.headers.get('Set-Cookie'))
r = requests.get(f'{B}/meus-projetos', cookies=s.cookies, verify=False)
for h in ['Strict-Transport-Security', 'Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy',
          'Permissions-Policy', 'Server', 'X-Powered-By']:
    print(f'  {h}: {r.headers.get(h)}')
r = requests.get(f'{B}/api/obter-projeto?id=30', cookies=s.cookies, verify=False)
print('  API Content-Type:', r.headers.get('Content-Type'), '| Cache-Control:', r.headers.get('Cache-Control'))
r = requests.get('http://127.0.0.1:38080/login', allow_redirects=False)
print('  HTTP -> HTTPS:', r.status_code, r.headers.get('Location'))

print('== 4) sessão: logout invalida? id muda no login?')
s2 = requests.Session(); s2.verify = False; s2.headers['X-Requested-With'] = 'XMLHttpRequest'
s2.get(f'{B}/login'); antes = dict(s2.cookies)
s2.post(f'{B}/api/login', json={'email': 'vitima@teste.dev', 'password': 'Seg@2026xx'}); depois = dict(s2.cookies)
print('  id antes/depois do login iguais?', antes.get('PHPSESSID') == depois.get('PHPSESSID') and antes.get('PHPSESSID') is not None, list(depois))
velho = dict(s2.cookies)
s2.headers['X-CSRF-Token'] = s2.get(f'{B}/api/obter-csrf-token').json()['csrf_token']
print('  logout', s2.post(f'{B}/api/logout').status_code)
r = requests.get(f'{B}/api/obter-projeto?id=30', cookies=velho, verify=False, headers={'X-Requested-With': 'XMLHttpRequest'})
print('  cookie antigo após logout:', r.status_code, r.text[:60])

print('== 5) força bruta no login')
cod = {}
t0 = time.time()
for i in range(30):
    r = requests.post(f'{B}/api/login', json={'email': 'vitima@teste.dev', 'password': f'errada{i}'}, verify=False)
    cod[r.status_code] = cod.get(r.status_code, 0) + 1
    if i in (4, 5, 10, 29): print(f'  tentativa {i+1}: {r.status_code} {r.text[:90]!r}')
print('  códigos:', cod, f'{time.time()-t0:.1f}s')
r = requests.post(f'{B}/api/login', json={'email': 'vitima@teste.dev', 'password': 'Seg@2026xx'}, verify=False)
print('  senha CERTA logo depois:', r.status_code, r.text[:90])
cod = {}
for i in range(30):   # e-mails diferentes, mesmo IP (credential stuffing)
    r = requests.post(f'{B}/api/login', json={'email': f'x{i}@teste.dev', 'password': 'a'}, verify=False)
    cod[r.status_code] = cod.get(r.status_code, 0) + 1
print('  30 e-mails diferentes, mesmo IP:', cod)
r1 = requests.post(f'{B}/api/login', json={'email': 'naoexiste@teste.dev', 'password': 'a'}, verify=False)
r2 = requests.post(f'{B}/api/login', json={'email': 'atacante@teste.dev', 'password': 'a'}, verify=False)
print('  enumeração (inexistente x existente):', r1.status_code, r1.text[:70], '|', r2.status_code, r2.text[:70])
r1 = requests.post(f'{B}/api/esqueci-senha', json={'email': 'naoexiste@teste.dev'}, verify=False)
r2 = requests.post(f'{B}/api/esqueci-senha', json={'email': 'atacante@teste.dev'}, verify=False)
print('  esqueci-senha (inexistente x existente):', r1.status_code, r1.text[:70], '|', r2.status_code, r2.text[:70])

print('== 6) API pública de envio: chaves erradas em sequência')
cod = {}
for i in range(100):
    r = requests.post(f'{B}/api/enviar-payload', json={'device_id': 218, 'payload': {'a': 1}}, headers={'X-Api-Key': 'x' * 64}, verify=False)
    cod[r.status_code] = cod.get(r.status_code, 0) + 1
print('  100 chaves erradas:', cod)

print('== 7) injeção SQL e entradas estranhas')
s.headers['X-CSRF-Token'] = s.get(f'{B}/api/obter-csrf-token').json()['csrf_token']
for v in ["30'", "30 OR 1=1", "30) UNION SELECT 1,2,3-- -", "-1", "99999999999999999999", "[]", "abc"]:
    r = s.get(f'{B}/api/obter-projeto', params={'id': v})
    r2 = s.get(f'{B}/api/listar-devices', params={'project_id': v})
    r3 = s.get(f'{B}/api/admin-usuarios', params={'search': v})
    print(f'  {v!r:35} obter-projeto {r.status_code} listar-devices {r2.status_code} {"SQL" if "SQL" in r.text + r2.text else ""}')
r = requests.post(f'{B}/api/login', json={'email': "' OR '1'='1", 'password': "' OR '1'='1"}, verify=False)
print('  login com injeção:', r.status_code, r.text[:60])
r = requests.post(f'{B}/api/enviar-payload', data='{"device_id":', headers={'X-Api-Key': 'x', 'Content-Type': 'application/json'}, verify=False)
print('  JSON quebrado:', r.status_code, r.text[:80])
r = requests.post(f'{B}/api/enviar-payload', data='{"device_id":218,"payload":{"a":"' + 'x' * 2_000_000 + '"}}', headers={'X-Api-Key': 'x', 'Content-Type': 'application/json'}, verify=False)
print('  corpo de 2 MB:', r.status_code, r.text[:80])

print('== 8) arquivos que não podem ser servidos')
for p in ['.env', '.git/config', '.htaccess', 'docker-compose.yml', 'src/config/config.php', 'src/config/db.php', 'logs/mqtt_subscriber.log',
          'src/db/ifsentral_bd.sql', 'composer.json', 'vendor/autoload.php', 'manutencao/atualizar.sh', 'backups/', 'src/', 'src/uploads/',
          'src/mqtt/mqtt_subscriber.php', 'phpinfo.php', 'server-status', 'src/db/migrations/', '.env.example', 'src/api/']:
    r = requests.get(f'{B}/{p}', verify=False, allow_redirects=False)
    vaz = any(x in r.text for x in ['DB_PASS', 'define(', '[core]', 'CREATE TABLE', 'Index of', '<?php', 'mysql', 'Apache Server Status'])
    print(f'  {p:32} {r.status_code} {len(r.content):7}B {"VAZOU" if vaz and r.status_code == 200 else ""}')
r = requests.get(f'{B}/api/saude', verify=False)
print('  /api/saude:', r.status_code, r.text[:200])
r = requests.get(f'{B}/nao-existe-xyz', verify=False)
print('  404 mostra versão?', r.status_code, re.findall(r'Apache/[0-9.]+|nginx/[0-9.]+|PHP/[0-9.]+', r.text))
