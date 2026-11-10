import re, requests, urllib3; urllib3.disable_warnings()
B='https://127.0.0.1:38443'
def login(email, senha):
    return requests.post(f'{B}/api/login', json={'email': email, 'password': senha}, verify=False)
adm = requests.Session(); adm.verify = False; adm.headers['X-Requested-With'] = 'XMLHttpRequest'
print('admin login', adm.post(f'{B}/api/login', json={'email': 'carga@teste.dev', 'password': 'Carga@2026'}).status_code)
def pagina():
    h = adm.get(f'{B}/admin/rate-limiting').text
    return h, re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
h, tok = pagina(); print('card presente:', 'Proteção do login' in h, '| bloqueados vazio:', 'Nenhuma conta ou IP bloqueado' in h)
# 1) padrão: 5 por conta
r = [login('vitima@teste.dev', 'x').status_code for _ in range(6)]; print('6 erradas na vítima:', r)
print('certa:', login('vitima@teste.dev', 'Seg@2026xx').json().get('error'))
h, tok = pagina(); print('vítima listada:', 'vitima@teste.dev' in h)
r = adm.post(f'{B}/admin/rate-limiting', data={'csrf_token': tok, 'desbloquear': 'conta', 'alvo': 'vitima@teste.dev'}); print('desbloquear:', 'desbloqueado' in r.text)
print('certa após desbloquear:', login('vitima@teste.dev', 'Seg@2026xx').status_code)
# 2) limite por IP = 8: contas diferentes do mesmo IP
h, tok = pagina()
r = adm.post(f'{B}/admin/rate-limiting', data={'csrf_token': tok, 'update_login': 1, 'login[LOGIN_RATE_LIMIT_ENABLED]': 1, 'login[LOGIN_RATE_LIMIT_MAX_ATTEMPTS]': 5,
    'login[LOGIN_RATE_LIMIT_WINDOW_MINUTES]': 15, 'login[LOGIN_RATE_LIMIT_MAX_PER_IP]': 8, 'login[LOGIN_RATE_LIMIT_IP_ISENTOS]': ''}); print('salvar por IP=8:', 'Proteção do login atualizada' in r.text)
r = [login(f'aluno{i}@teste.dev', 'x').status_code for i in range(9)]; print('9 contas diferentes:', r)
j = login('atacante@teste.dev', 'Seg@2026xx').json(); print('outra conta, senha certa:', j.get('error'))
# 3) isentar a rede (o IP visto pelo servidor aparece na tela)
h, tok = pagina(); ip = re.search(r'Seu IP agora: <code>([^<]+)', h).group(1); print('IP visto:', ip, '| IP listado:', ip in h)
r = adm.post(f'{B}/admin/rate-limiting', data={'csrf_token': tok, 'update_login': 1, 'login[LOGIN_RATE_LIMIT_ENABLED]': 1, 'login[LOGIN_RATE_LIMIT_MAX_ATTEMPTS]': 5,
    'login[LOGIN_RATE_LIMIT_WINDOW_MINUTES]': 15, 'login[LOGIN_RATE_LIMIT_MAX_PER_IP]': 8, 'login[LOGIN_RATE_LIMIT_IP_ISENTOS]': 'abc, 10.0.0.0/99'}); print('lista inválida recusada:', 'inválidos' in r.text)
h, tok = pagina()
r = adm.post(f'{B}/admin/rate-limiting', data={'csrf_token': tok, 'update_login': 1, 'login[LOGIN_RATE_LIMIT_ENABLED]': 1, 'login[LOGIN_RATE_LIMIT_MAX_ATTEMPTS]': 5,
    'login[LOGIN_RATE_LIMIT_WINDOW_MINUTES]': 15, 'login[LOGIN_RATE_LIMIT_MAX_PER_IP]': 8, 'login[LOGIN_RATE_LIMIT_IP_ISENTOS]': '172.16.0.0/12'}); print('isentar 172.16.0.0/12:', 'Proteção do login atualizada' in r.text)
print('outra conta, senha certa (rede isenta):', login('atacante@teste.dev', 'Seg@2026xx').status_code)
r = [login('atacante@teste.dev', 'x').status_code for _ in range(6)]; print('mas a conta ainda bloqueia após 5:', r)
# 4) sem token CSRF e sem ser admin
r = adm.post(f'{B}/admin/rate-limiting', data={'desbloquear': 'todos'}); print('sem CSRF:', r.status_code)
u = requests.Session(); u.verify = False; u.post(f'{B}/api/login', json={'email': 'vitima@teste.dev', 'password': 'Seg@2026xx'})
r = u.get(f'{B}/admin/rate-limiting', allow_redirects=False); print('usuário comum na página:', r.status_code, r.headers.get('Location'))
h, tok = pagina(); r = adm.post(f'{B}/admin/rate-limiting', data={'csrf_token': tok, 'desbloquear': 'todos'}); print('desbloquear todos:', 'Todos os bloqueios' in r.text)
# volta ao padrão
h, tok = pagina(); adm.post(f'{B}/admin/rate-limiting', data={'csrf_token': tok, 'update_login': 1, 'login[LOGIN_RATE_LIMIT_ENABLED]': 1, 'login[LOGIN_RATE_LIMIT_MAX_ATTEMPTS]': 5,
    'login[LOGIN_RATE_LIMIT_WINDOW_MINUTES]': 15, 'login[LOGIN_RATE_LIMIT_MAX_PER_IP]': 20, 'login[LOGIN_RATE_LIMIT_IP_ISENTOS]': ''})
