#!/usr/bin/env python3
"""
Bateria de testes ponta a ponta (E2E) do IFSCentral e do IFSCentral Lite.

Exercita a API pelas mesmas rotas que o navegador usa, com dados válidos e
inválidos, e confere o código HTTP e o efeito no banco. Precisa de uma
instalação de TESTE (o script cria usuários, projetos e apaga dados):

    python3 e2e_ifscentral.py --base https://127.0.0.1:28443 --edicao completa \
        --db-container ifs_e2e-db-1 --db-name ifsentral_bd --mqtt-port 21883

    python3 e2e_ifscentral.py --base http://127.0.0.1:28090 --edicao lite \
        --db-container ifs_e2e-db-1 --db-name ifsentral_lite

O acesso direto ao banco (docker exec ... mysql) é usado só para preparar o
cenário (promover o primeiro Admin, limpar tentativas de login) e para
conferir efeitos que a API não expõe.
"""
import argparse
import base64
import hashlib
import hmac
import io
import json
import struct
import subprocess
import sys
import re
import time
import uuid
from datetime import datetime, timedelta, timezone

import requests
import urllib3

urllib3.disable_warnings()

# --------------------------------------------------------------------------
# Infraestrutura
# --------------------------------------------------------------------------

RESULTADOS = []  # (secao, descricao, ok, detalhe)
SECAO = ['']


def secao(nome):
    SECAO[0] = nome
    print(f"\n=== {nome} ===")


def check(descricao, ok, detalhe=''):
    RESULTADOS.append((SECAO[0], descricao, bool(ok), detalhe))
    marca = 'OK   ' if ok else 'FALHA'
    linha = f"  [{marca}] {descricao}"
    if not ok and detalhe:
        linha += f"\n          -> {detalhe}"
    print(linha)
    return ok


def resumo(r):
    """Código + início do corpo, para mensagens de falha."""
    if r is None:
        return 'sem resposta'
    corpo = r.text.strip().replace('\n', ' ')
    return f"HTTP {r.status_code}: {corpo[:220]}"


def status(descricao, r, esperado):
    esperados = esperado if isinstance(esperado, (list, tuple, set)) else [esperado]
    return check(f"{descricao} (espera {'/'.join(map(str, esperados))})",
                 r is not None and r.status_code in esperados, resumo(r))


def js(r):
    try:
        return r.json()
    except Exception:
        return {}


class Cliente:
    """Uma sessão de navegador (cookies + token CSRF)."""

    def __init__(self, base):
        self.base = base.rstrip('/')
        self.s = requests.Session()
        self.s.verify = False
        self.s.headers['X-Requested-With'] = 'XMLHttpRequest'
        self.csrf = None

    def url(self, path):
        return f"{self.base}/{path.lstrip('/')}"

    def token(self):
        if self.csrf is None:
            r = self.s.get(self.url('api/obter-csrf-token'))
            self.csrf = js(r).get('csrf_token')
        return self.csrf

    def get(self, path, **kw):
        return self.s.get(self.url(path), allow_redirects=kw.pop('allow_redirects', False), **kw)

    def req(self, metodo, path, json_body=None, csrf=True, headers=None, **kw):
        h = dict(headers or {})
        if csrf:
            h['X-CSRF-Token'] = self.token() or ''
        if json_body is not None and 'data' not in kw:
            kw['data'] = json.dumps(json_body)
            h['Content-Type'] = 'application/json'
        return self.s.request(metodo, self.url(path), headers=h, allow_redirects=False, **kw)

    def post(self, path, json_body=None, **kw):
        return self.req('POST', path, json_body, **kw)

    def put(self, path, json_body=None, **kw):
        return self.req('PUT', path, json_body, **kw)

    def delete(self, path, json_body=None, **kw):
        return self.req('DELETE', path, json_body, **kw)

    def login(self, email, senha):
        r = self.post('api/login', {'email': email, 'password': senha}, csrf=False)
        self.csrf = None  # o login regenera o token CSRF
        return r


class Banco:
    def __init__(self, container, nome):
        self.container, self.nome = container, nome

    def sql(self, comando):
        p = subprocess.run(
            ['docker', 'exec', '-i', self.container, 'mysql', '-uroot', '-proot',
             '--default-character-set=utf8mb4', '-N', '-B', self.nome, '-e', comando],
            capture_output=True, text=True)
        if p.returncode != 0:
            raise RuntimeError(p.stderr.strip())
        return [linha.split('\t') for linha in p.stdout.strip().split('\n') if linha]

    def valor(self, comando):
        linhas = self.sql(comando)
        return linhas[0][0] if linhas else None


def totp(segredo_b32, passo=None):
    chave = base64.b32decode(segredo_b32 + '=' * (-len(segredo_b32) % 8))
    passo = int(time.time() // 30) if passo is None else passo
    h = hmac.new(chave, struct.pack('>Q', passo), hashlib.sha1).digest()
    o = h[-1] & 0x0F
    return str((struct.unpack('>I', h[o:o + 4])[0] & 0x7FFFFFFF) % 10 ** 6).zfill(6)


def png_minimo():
    # PNG 1x1 válido
    return base64.b64decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')


# --------------------------------------------------------------------------
# Testes
# --------------------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    ap.add_argument('--edicao', choices=['completa', 'lite'], required=True)
    ap.add_argument('--db-container', required=True)
    ap.add_argument('--db-name', required=True)
    ap.add_argument('--mqtt-host', default='127.0.0.1')
    ap.add_argument('--mqtt-port', type=int, default=0)
    ap.add_argument('--worker-container', help='contêiner do worker MQTT (para testar a fila com o worker parado)')
    ap.add_argument('--ttn-mock', help='URL de controle do TTN simulado (ex.: http://127.0.0.1:28444)')
    ap.add_argument('--ttn-mqtt-port', type=int, default=0, help='porta do MQTT do TTN simulado (usuário publicador)')
    ap.add_argument('--ttn-bridge-container', help='contêiner da ponte TTN (edição completa)')
    ap.add_argument('--json', help='salva os resultados neste arquivo')
    a = ap.parse_args()

    db = Banco(a.db_container, a.db_name)
    completa = a.edicao == 'completa'
    rota_admin_usuarios = 'admin/usuarios' if completa else 'admin-usuarios'
    rota_admin_rate = 'admin/rate-limiting' if completa else 'admin-rate-limiting'
    sufixo = uuid.uuid4().hex[:6]
    SENHA = 'Senha@123'

    def email(nome):
        return f"{nome}{sufixo}@teste.dev"

    def limpar_tentativas():
        db.sql("DELETE FROM login_attempts")

    limpar_tentativas()
    anon = Cliente(a.base)

    # ------------------------------------------------------------------
    secao('1. Páginas públicas e proteção de rotas (sem login)')
    for rota in ['', 'login', 'registrar', 'esqueci-senha', 'termos']:
        r = anon.get(rota)
        status(f"GET /{rota} abre", r, 200)
        check(f"GET /{rota} sem erro de PHP no HTML",
              not any(x in r.text for x in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:')),
              r.text[:200])
    for rota in ['meus-projetos', 'explorar-projetos', 'meus-dispositivos', 'perfil', 'configuracoes',
                 'novo-projeto', 'projeto?id=1', 'dispositivo?id=1', 'admin', rota_admin_usuarios,
                 rota_admin_rate, 'documentacao']:
        r = anon.get(rota)
        status(f"GET /{rota} sem login redireciona", r, [301, 302, 303, 401])
    for rota in ['api/listar-projetos', 'api/obter-perfil-usuario', 'api/listar-devices',
                 'api/obter-notificacoes', 'api/listar-tags', 'api/obter-projeto?id=1']:
        r = anon.get(rota)
        status(f"GET /{rota} sem login é recusado", r, [401, 403])
    r = anon.get('api/listar-projetos-publicos')
    status("GET /api/listar-projetos-publicos sem login (usado pela página inicial)", r, 200)

    # ------------------------------------------------------------------
    secao('2. Cadastro de usuário')
    def cadastrar(**campos):
        base = {'name': 'Usuário Teste', 'email': email('u'), 'username': 'u' + sufixo,
                'password': SENHA}
        base.update(campos)
        base = {k: v for k, v in base.items() if v is not None}
        return anon.post('api/cadastrar-usuario', base, csrf=False)

    usuarios = {}
    for papel in ['admin', 'gerente', 'membro', 'externo']:
        r = cadastrar(name=f"Teste {papel.title()}", email=email(papel), username=f"{papel[:6]}{sufixo}")
        status(f"cadastro válido ({papel})", r, 201)
        usuarios[papel] = {'email': email(papel), 'id': js(r).get('insertedId'),
                           'username': f"{papel[:6]}{sufixo}"}

    status("e-mail repetido é recusado", cadastrar(email=email('admin'), username='novo' + sufixo), [400, 409])
    status("nome de usuário repetido é recusado", cadastrar(email=email('outro'), username=usuarios['admin']['username']), [400, 409])
    status("sem e-mail é recusado", cadastrar(email=None), 400)
    status("sem senha é recusado", cadastrar(password=None, email=email('x1')), 400)
    status("senha vazia é recusada", cadastrar(password='', email=email('x2')), 400)
    status("nome vazio é recusado", cadastrar(name='', email=email('x3'), username='x3' + sufixo), 400)
    status("e-mail sem formato válido ('abc') é recusado", cadastrar(email='abc', username='x4' + sufixo), 400)
    status("nome com 51 caracteres é recusado", cadastrar(name='n' * 51, email=email('x5'), username='x5' + sufixo), 400)
    status("e-mail com 51 caracteres é recusado", cadastrar(email='e' * 41 + '@teste.dev', username='x6' + sufixo), 400)
    status("username com 2 caracteres é recusado", cadastrar(username='ab', email=email('x7')), 400)
    status("username com 21 caracteres é recusado", cadastrar(username='u' * 21, email=email('x8')), 400)
    status("senha com 5 caracteres é recusada", cadastrar(password='12345', email=email('x9'), username='x9' + sufixo), 400)
    status("senha com 73 caracteres é recusada", cadastrar(password='s' * 73, email=email('x10'), username='x10' + sufixo), 400)
    status("username com espaço/símbolos é recusado", cadastrar(username='a b<c>', email=email('x11')), 400)
    r = anon.post('api/cadastrar-usuario', data='{nao e json', csrf=False, headers={'Content-Type': 'application/json'})
    status("JSON malformado é recusado", r, 400)
    status("GET em /api/cadastrar-usuario é recusado", anon.get('api/cadastrar-usuario'), 405)
    r = cadastrar(email=email('esperto'), username='esp' + sufixo, profile='Admin')
    status("cadastro com profile=Admin no corpo", r, 201)
    check("…mas a conta é criada como User",
          db.valor(f"SELECT profile FROM users WHERE email='{email('esperto')}'") == 'User')

    # Bootstrap: o primeiro Admin só pode ser definido direto no banco
    db.sql(f"UPDATE users SET profile='Admin' WHERE id={int(usuarios['admin']['id'])}")

    # ------------------------------------------------------------------
    secao('3. Login e sessão')
    c = Cliente(a.base)
    status("senha errada", c.login(email('membro'), 'errada'), 401)
    status("e-mail inexistente", c.login(email('ninguem'), SENHA), 401)
    status("sem senha", c.post('api/login', {'email': email('membro')}, csrf=False), 400)
    status("GET em /api/login", c.get('api/login'), 405)
    status("e-mail em maiúsculas", c.login(email('membro').upper(), SENHA), 200)
    limpar_tentativas()
    r = c.login(email('membro'), SENHA)
    status("login correto", r, 200)
    status("sessão vale: obter perfil", c.get('api/obter-perfil-usuario'), 200)
    status("página meus-projetos abre logado", c.get('meus-projetos'), 200)
    status("logout", c.post('api/logout', csrf=False), [200, 302])
    status("depois do logout a sessão acabou", c.get('api/obter-perfil-usuario'), [401, 403])

    limpar_tentativas()
    bloqueio = Cliente(a.base)
    for i in range(5):
        bloqueio.login(email('externo'), 'errada')
    status("6ª tentativa errada seguida é bloqueada", bloqueio.login(email('externo'), 'errada'), 429)
    status("…e bloqueia também a senha certa do mesmo usuário", bloqueio.login(email('externo'), SENHA), 429)
    outro = Cliente(a.base)
    r = outro.login(email('gerente'), SENHA)
    check("bloqueio por erros de UM usuário não deveria barrar OUTRO usuário do mesmo IP",
          r.status_code == 200, resumo(r) + " (o limite conta falhas por e-mail OU por IP)")
    limpar_tentativas()

    # Sessões dos personagens
    S = {}
    for papel in ['admin', 'gerente', 'membro', 'externo']:
        S[papel] = Cliente(a.base)
        r = S[papel].login(usuarios[papel]['email'], SENHA)
        status(f"login de {papel}", r, 200)
    limpar_tentativas()

    # ------------------------------------------------------------------
    secao('4. CSRF')
    status("POST sem token CSRF é recusado (criar projeto)",
           S['admin'].post('api/criar-projeto', {'name': 'x'}, csrf=False), 403)
    status("POST com token CSRF errado é recusado (atualizar perfil)",
           S['membro'].post('api/atualizar-perfil', {'name': 'x'}, csrf=False, headers={'X-CSRF-Token': 'abc'}), 403)
    status("token CSRF de outra sessão é recusado",
           S['membro'].post('api/atualizar-perfil', {'name': 'x'}, csrf=False,
                            headers={'X-CSRF-Token': S['gerente'].token()}), 403)

    # ------------------------------------------------------------------
    secao('5. Perfil e senha')
    m = S['membro']
    status("ver perfil", m.get('api/obter-perfil-usuario'), 200)
    base_perfil = {'name': 'Membro Teste', 'email': email('membro'), 'username': usuarios['membro']['username']}
    status("atualizar nome", m.put('api/atualizar-perfil', dict(base_perfil, name='Membro Renomeado')), 200)
    check("…nome gravado", db.valor(f"SELECT name FROM users WHERE id={usuarios['membro']['id']}") == 'Membro Renomeado')
    status("nome vazio", m.put('api/atualizar-perfil', dict(base_perfil, name='')), 400)
    status("nome com 51", m.put('api/atualizar-perfil', dict(base_perfil, name='n' * 51)), 400)
    status("e-mail inválido", m.put('api/atualizar-perfil', dict(base_perfil, email='semarroba')), 400)
    status("e-mail com 51", m.put('api/atualizar-perfil', dict(base_perfil, email='e' * 41 + '@teste.dev')), 400)
    status("e-mail de outro usuário", m.put('api/atualizar-perfil', dict(base_perfil, email=email('gerente'))), [400, 409])
    status("username de outro usuário", m.put('api/atualizar-perfil', dict(base_perfil, username=usuarios['gerente']['username'])), [400, 409])
    status("username com 2", m.put('api/atualizar-perfil', dict(base_perfil, username='ab')), 400)

    def senha(atual, nova, conf=None):
        return m.put('api/atualizar-senha', {'current_password': atual, 'new_password': nova,
                                             'confirm_password': nova if conf is None else conf})
    status("senha atual errada", senha('errada', 'NovaSenha@1'), [400, 401, 403])
    status("nova senha curta (5)", senha(SENHA, '12345'), 400)
    status("nova senha com 73", senha(SENHA, 's' * 73), 400)
    status("confirmação diferente", senha(SENHA, 'NovaSenha@1', 'Outra@1'), 400)
    status("troca de senha válida", senha(SENHA, 'NovaSenha@1'), 200)
    limpar_tentativas()
    status("login com a senha antiga falha", Cliente(a.base).login(email('membro'), SENHA), 401)
    status("login com a senha nova funciona", Cliente(a.base).login(email('membro'), 'NovaSenha@1'), 200)
    senha('NovaSenha@1', SENHA)
    limpar_tentativas()

    # Foto de perfil
    arq = {'profile_picture': ('foto.png', png_minimo(), 'image/png')}
    status("upload de foto PNG", m.post('api/upload-foto-perfil', files=arq), 200)
    falso = {'profile_picture': ('foto.png', b'<?php echo 1; ?>', 'image/png')}
    status("upload de PHP disfarçado de PNG é recusado", m.post('api/upload-foto-perfil', files=falso), 400)
    grande = {'profile_picture': ('g.png', png_minimo() + b'0' * (6 * 1024 * 1024), 'image/png')}
    status("upload acima do limite de tamanho é recusado", m.post('api/upload-foto-perfil', files=grande), [400, 413])
    status("apagar foto", m.post('api/deletar-foto-perfil'), 200)

    # ------------------------------------------------------------------
    secao('6. Autenticação em dois fatores (2FA)')
    e = S['externo']
    status("status 2FA", e.get('api/2fa-status'), 200)
    r = e.post('api/2fa-iniciar-configuracao')
    status("iniciar configuração", r, 200)
    segredo = js(r).get('secret', '')
    status("confirmar com código errado", e.post('api/2fa-confirmar-configuracao', {'code': '000000'}), 400)
    passo = int(time.time() // 30)
    r = e.post('api/2fa-confirmar-configuracao', {'code': totp(segredo, passo)})
    status("confirmar com código certo", r, 200)
    c2 = Cliente(a.base)
    r = c2.login(email('externo'), SENHA)
    check("login passa a pedir o código", js(r).get('twofa_required') is True, resumo(r))
    status("sem código, a sessão não está autenticada", c2.get('api/obter-perfil-usuario'), [401, 403])
    status("código 2FA errado", c2.post('api/verificar-2fa', {'code': '123456'}, csrf=False), [400, 401])
    status("mesmo código já usado na confirmação (replay) é recusado",
           c2.post('api/verificar-2fa', {'code': totp(segredo, passo)}, csrf=False), [400, 401])
    r = c2.post('api/verificar-2fa', {'code': totp(segredo, passo + 1)}, csrf=False)
    status("código 2FA do próximo passo é aceito", r, 200)
    status("sessão autenticada após 2FA", c2.get('api/obter-perfil-usuario'), 200)
    status("desativar 2FA com senha errada", e.post('api/2fa-desativar', {'password': 'errada'}), [400, 401, 403])
    status("desativar 2FA com senha certa", e.post('api/2fa-desativar', {'password': SENHA}), 200)
    limpar_tentativas()
    r = Cliente(a.base).login(email('externo'), SENHA)
    check("login volta a não pedir código", r.status_code == 200 and not js(r).get('twofa_required'), resumo(r))

    # ------------------------------------------------------------------
    secao('7. Pedido de perfil Moderador (quem pode criar projetos)')
    g = S['gerente']
    status("User não pode criar projeto", g.post('api/criar-projeto', {'name': 'Antes de promover'}), 403)
    status("pedido com mensagem curta (<5)", g.post('api/solicitacoes-perfil', {'message': 'oi'}), 400)
    status("pedido com mensagem de 2001", g.post('api/solicitacoes-perfil', {'message': 'm' * 2001}), 400)
    status("pedido válido", g.post('api/solicitacoes-perfil', {'message': 'Preciso criar projetos do lab'}), [200, 201])
    status("segundo pedido pendente é recusado", g.post('api/solicitacoes-perfil', {'message': 'De novo, por favor'}), [400, 409])
    req_id = db.valor(f"SELECT id FROM profile_requests WHERE user_id={usuarios['gerente']['id']} ORDER BY id DESC LIMIT 1")
    status("User não pode aprovar pedidos", S['membro'].put('api/solicitacoes-perfil', {'request_id': req_id, 'status': 'aprovado'}), 403)
    status("Admin lista pedidos", S['admin'].get('api/solicitacoes-perfil'), 200)
    status("Admin com status inválido", S['admin'].put('api/solicitacoes-perfil', {'request_id': req_id, 'status': 'talvez'}), 400)
    status("Admin aprova", S['admin'].put('api/solicitacoes-perfil', {'request_id': req_id, 'status': 'aprovado'}), 200)
    check("…usuário virou Moderator", db.valor(f"SELECT profile FROM users WHERE id={usuarios['gerente']['id']}") == 'Moderator')

    # ------------------------------------------------------------------
    secao('8. Projetos: criar, ler, editar')
    def criar(**campos):
        corpo = {'name': 'Projeto E2E', 'description': 'Descrição', 'public': 1, 'maxUsers': None, 'tags': []}
        corpo.update(campos)
        return g.post('api/criar-projeto', corpo)
    r = criar(name='Estação E2E', tags=['Temperatura', 'nova-tag-' + sufixo])
    status("criar projeto válido com tags", r, 201)
    pid = int(db.valor(f"SELECT id FROM projects WHERE name='Estação E2E' ORDER BY id DESC LIMIT 1"))
    check("…gerente associado com papel 1", db.valor(f"SELECT role_id FROM users_projects WHERE project_id={pid} AND user_id={usuarios['gerente']['id']}") == '1')
    check("…2 tags associadas", db.valor(f"SELECT COUNT(*) FROM project_tags WHERE project_id={pid}") == '2')
    status("nome vazio", criar(name=''), 400)
    status("nome só com espaços", criar(name='   '), 400)
    status("nome com 31", criar(name='n' * 31), 400)
    status("nome com 30 (limite)", criar(name='ç' * 30), 201)
    status("descrição com 201", criar(description='d' * 201), 400)
    status("maxUsers negativo", criar(maxUsers=-1), 400)
    status("maxUsers gigante", criar(maxUsers=99999999999999), 400)
    status("maxUsers texto", criar(maxUsers='abc'), 400)
    status("tag com 51", criar(tags=['t' * 51]), 400)
    status("tag numérica que não é ID de tag existente vira tag nova", criar(name='Tag 2026', tags=['2026', 999999]), 201)
    pid_tag = db.valor("SELECT id FROM projects WHERE name='Tag 2026' ORDER BY id DESC LIMIT 1")
    nomes_tag = sorted(x[0] for x in db.sql(f"SELECT t.name FROM project_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.project_id={pid_tag}"))
    check("…com os nomes digitados", nomes_tag == ['2026', '999999'], str(nomes_tag))
    r = criar(name='Privado E2E', public=0, maxUsers=2)
    status("criar projeto privado com limite de 2", r, 201)
    pid_priv = int(db.valor("SELECT id FROM projects WHERE name='Privado E2E' ORDER BY id DESC LIMIT 1"))

    status("listar meus projetos", g.get('api/listar-projetos'), 200)
    status("obter projeto (gerente)", g.get(f'api/obter-projeto?id={pid}'), 200)
    status("obter projeto privado (não membro) é recusado", S['externo'].get(f'api/obter-projeto?id={pid_priv}'), [403, 404])
    # 403 de propósito: não revela se o projeto existe
    status("obter projeto inexistente", g.get('api/obter-projeto?id=999999'), [403, 404])
    status("obter projeto sem id", g.get('api/obter-projeto'), 400)
    r = anon.get('api/listar-projetos-publicos?search=Estação')
    nomes = [p['name'] for p in js(r).get('data', [])]
    check("busca pública encontra o projeto público", 'Estação E2E' in nomes, resumo(r))
    r = anon.get('api/listar-projetos-publicos?search=Privado E2E')
    check("busca pública NÃO mostra o projeto privado", 'Privado E2E' not in [p['name'] for p in js(r).get('data', [])], resumo(r))
    status("página pública do projeto (ver-projeto) logado", S['externo'].get(f'ver-projeto?id={pid}'), 200)
    status("página de gerenciar projeto (gerente)", g.get(f'projeto?id={pid}'), 200)
    status("página de gerenciar projeto (não membro) é barrada", S['externo'].get(f'projeto?id={pid_priv}'), [302, 403, 404])

    up = lambda pid_, **cps: g.post('api/atualizar-projeto', dict({'project_id': pid_, 'name': 'Estação E2E', 'description': 'nova', 'tags': ['Temperatura']}, **cps))
    status("editar nome/descrição/tags", up(pid), 200)
    check("…tags substituídas (1 tag)", db.valor(f"SELECT COUNT(*) FROM project_tags WHERE project_id={pid}") == '1')
    status("editar com nome de 31", up(pid, name='n' * 31), 400)
    status("não gerente não pode editar", S['externo'].post('api/atualizar-projeto', {'project_id': pid, 'name': 'Invasão'}), 403)
    status("tornar privado", g.post('api/alterar-visibilidade-projeto', {'project_id': pid, 'public': 0}), 200)
    status("tornar público", g.post('api/alterar-visibilidade-projeto', {'project_id': pid, 'public': 1}), 200)
    status("não gerente não muda visibilidade", S['externo'].post('api/alterar-visibilidade-projeto', {'project_id': pid, 'public': 0}), 403)

    # ------------------------------------------------------------------
    secao('9. Participação: pedidos, convites, papéis')
    mb = S['membro']
    status("pedir participação com mensagem de 2001", mb.post('api/enviar-solicitacao-participacao', {'project_id': pid, 'message': 'm' * 2001}), 400)
    status("pedir participação", mb.post('api/enviar-solicitacao-participacao', {'project_id': pid, 'message': 'Quero ajudar'}), [200, 201])
    status("pedido repetido é recusado", mb.post('api/enviar-solicitacao-participacao', {'project_id': pid}), [400, 409])
    status("pedir participação em projeto privado é recusado", mb.post('api/enviar-solicitacao-participacao', {'project_id': pid_priv}), [400, 403])
    status("pedir participação em projeto inexistente", mb.post('api/enviar-solicitacao-participacao', {'project_id': 999999}), 404)
    status("gerente lista pedidos", g.get(f'api/listar-solicitacoes-participacao?project_id={pid}'), 200)
    jr = db.valor(f"SELECT id FROM project_join_requests WHERE project_id={pid} AND user_id={usuarios['membro']['id']}")
    status("não gerente não responde pedido", mb.post('api/responder-solicitacao-participacao', {'request_id': jr, 'action': 'aceitar'}), 403)
    status("ação inválida", g.post('api/responder-solicitacao-participacao', {'request_id': jr, 'action': 'talvez'}), 400)
    status("gerente aceita", g.post('api/responder-solicitacao-participacao', {'request_id': jr, 'action': 'aceitar'}), 200)
    check("…membro entrou como participante (2)", db.valor(f"SELECT role_id FROM users_projects WHERE project_id={pid} AND user_id={usuarios['membro']['id']}") == '2')
    status("responder o mesmo pedido de novo", g.post('api/responder-solicitacao-participacao', {'request_id': jr, 'action': 'recusar'}), [400, 409])
    status("membro vê o projeto", mb.get(f'api/obter-projeto?id={pid}'), 200)
    status("listar participantes", mb.get(f'api/listar-participantes?project_id={pid}'), 200)
    status("contagem de participantes", mb.get(f'api/obter-contagem-participantes?project_id={pid}'), 200)

    status("convite com e-mail inválido", g.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': 'xx', 'role_id': 2}), 400)
    status("convite com e-mail de 51", g.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': 'e' * 41 + '@teste.dev', 'role_id': 2}), 400)
    status("não gerente não convida", mb.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': email('externo'), 'role_id': 2}), 403)
    status("convite válido", g.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': email('externo'), 'role_id': 2}), [200, 201])
    status("convite repetido", g.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': email('externo'), 'role_id': 2}), [400, 409])
    status("convite a quem já é membro", g.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': email('gerente'), 'role_id': 2}), [400, 409])
    r = S['externo'].get('api/listar-convites')
    status("convidado lista convites", r, 200)
    inv = db.valor(f"SELECT id FROM invitations WHERE project_id={pid_priv} AND invited_email='{email('externo')}'")
    status("outro usuário não aceita convite alheio", mb.post('api/aceitar-convite', {'invitation_id': inv}), [403, 404])
    status("convidado aceita", S['externo'].post('api/aceitar-convite', {'invitation_id': inv}), 200)
    check("…externo entrou no privado", db.valor(f"SELECT COUNT(*) FROM users_projects WHERE project_id={pid_priv} AND user_id={usuarios['externo']['id']}") == '1')
    # projeto privado tem limite 2 (gerente + externo): convite extra deve respeitar o limite
    g.post('api/enviar-convite', {'project_id': pid_priv, 'invited_email': email('membro'), 'role_id': 2})
    inv2 = db.valor(f"SELECT id FROM invitations WHERE project_id={pid_priv} AND invited_email='{email('membro')}'")
    r = mb.post('api/aceitar-convite', {'invitation_id': inv2})
    check("aceitar convite respeita o limite de participantes (maxUsers=2, já lotado)",
          r.status_code in (400, 403, 409), resumo(r))
    check("…e o membro não entrou", db.valor(f"SELECT COUNT(*) FROM users_projects WHERE project_id={pid_priv} AND user_id={usuarios['membro']['id']}") == '0')
    db.sql(f"DELETE FROM users_projects WHERE project_id={pid_priv} AND user_id={usuarios['membro']['id']}")

    status("promover membro a gerente (não gerente tenta)", mb.post('api/promover-gerente', {'project_id': pid, 'user_id': usuarios['membro']['id']}), 403)
    status("expulsar (não gerente tenta)", mb.post('api/expulsar-participante', {'project_id': pid, 'user_id': usuarios['gerente']['id']}), 403)
    status("gerente não pode expulsar a si mesmo", g.post('api/expulsar-participante', {'project_id': pid, 'user_id': usuarios['gerente']['id']}), [400, 403])
    r = g.post('api/sair-projeto', {'project_id': pid})
    check("único gerente não pode sair do projeto", r.status_code in (400, 403, 409), resumo(r))

    # ------------------------------------------------------------------
    secao('10. Dispositivos, tags e mapeamentos')
    def novo_disp(**cps):
        return g.post('api/cadastrar-device', dict({'project_id': pid, 'name': 'Sensor 1', 'description': 'DHT22'}, **cps))
    r = novo_disp()
    status("cadastrar dispositivo", r, 201)
    did, chave = js(r).get('insertedId'), js(r).get('api_key')
    did = int(did) if did else 0
    check("…chave de API com 64 caracteres", chave and len(chave) == 64)
    status("nome vazio", novo_disp(name=''), 400)
    status("nome com 101", novo_disp(name='n' * 101), 400)
    status("descrição com 201", novo_disp(description='d' * 201), 400)
    status("projeto de que não participa", S['externo'].post('api/cadastrar-device', {'project_id': pid, 'name': 'x'}), 403)
    r = mb.post('api/cadastrar-device', {'project_id': pid, 'name': 'Sensor do membro'})
    status("participante cadastra dispositivo", r, 201)
    did_membro = int(js(r).get('insertedId') or 0)
    r = g.post('api/cadastrar-device', {'project_id': pid_priv, 'name': 'Sensor privado'})
    did_priv, chave_priv = int(js(r).get('insertedId') or 0), js(r).get('api_key')

    status("info do dispositivo (membro)", mb.get(f'api/obter-info-dispositivo?device_id={did}'), 200)
    status("info do dispositivo (não membro) é recusada", mb.get(f'api/obter-info-dispositivo?device_id={did_priv}'), [403, 404])
    status("chaves do dispositivo (não membro) recusadas", mb.get(f'api/obter-chaves-dispositivo?device_id={did_priv}'), [403, 404])
    status("listar meus dispositivos", g.get('api/listar-meus-dispositivos'), 200)
    status("listar devices", g.get('api/listar-devices'), 200)
    status("página do dispositivo", g.get(f'dispositivo?id={did}&project_id={pid}'), 200)

    status("tags: adicionar nova e existente", g.post('api/gerenciar-tags-dispositivo', {'device_id': did, 'tags': ['externo', 'Temperatura']}), 200)
    status("tags: tag com 51", g.post('api/gerenciar-tags-dispositivo', {'device_id': did, 'tags': ['t' * 51]}), 400)
    status("tags: listar", g.get(f'api/gerenciar-tags-dispositivo?device_id={did}'), 200)
    status("tags: não membro", S['externo'].post('api/gerenciar-tags-dispositivo', {'device_id': did, 'tags': ['x']}), 403)

    mp = lambda **c: g.post('api/gerenciar-mapeamentos-dispositivo', dict({'device_id': did, 'json_key': 'status', 'value_read': '1', 'description': 'Ligado'}, **c))
    r = mp()
    status("mapeamento: criar", r, [200, 201])
    status("mapeamento: mesmo campo/valor atualiza a descrição", mp(description='Ligado (editado)'), 200)
    check("…sem duplicar", db.valor(f"SELECT COUNT(*) FROM state_mappings WHERE device_id={did} AND json_key='status'") == '1')
    status("mapeamento: campo vazio", mp(json_key=''), 400)
    status("mapeamento: campo com 101", mp(json_key='k' * 101), 400)
    status("mapeamento: descrição com 201", mp(json_key='outro', description='d' * 201), 400)
    status("mapeamento: listar", g.get(f'api/gerenciar-mapeamentos-dispositivo?device_id={did}'), 200)
    mid = db.valor(f"SELECT id FROM state_mappings WHERE device_id={did} LIMIT 1")
    status("mapeamento: não membro não apaga", S['externo'].delete('api/gerenciar-mapeamentos-dispositivo', {'mapping_id': mid}), [403, 404])
    status("mapeamento: apagar", g.delete('api/gerenciar-mapeamentos-dispositivo', {'mapping_id': mid}), 200)

    # ------------------------------------------------------------------
    secao('11. Envio de dados por HTTP (dispositivo)')
    dev = Cliente(a.base)
    env = lambda corpo, key=chave, **kw: dev.post('api/enviar-payload', corpo, csrf=False, headers={'X-Api-Key': key} if key else {}, **kw)
    r = env({'device_id': did, 'payload': {'temperatura': 25.5, 'umidade': 60, 'status': 1}})
    status("payload válido", r, 201)
    for i in range(3):
        env({'device_id': did, 'payload': {'temperatura': 20 + i, 'umidade': 50 + i}})
    status("sem chave de API", env({'device_id': did, 'payload': {'t': 1}}, key=None), 401)
    status("chave errada", env({'device_id': did, 'payload': {'t': 1}}, key='0' * 64), 401)
    status("chave de outro dispositivo", env({'device_id': did_priv, 'payload': {'t': 1}}), 403)
    status("sem device_id", env({'payload': {'t': 1}}), 400)
    status("sem payload", env({'device_id': did}), 400)
    r = dev.post('api/enviar-payload', data='{quebrado', csrf=False, headers={'X-Api-Key': chave, 'Content-Type': 'application/json'})
    status("JSON malformado", r, 400)
    status("corpo maior que 2 KB", env({'device_id': did, 'payload': {'x': 'a' * 2100}}), 413)
    status("mais de 50 chaves", env({'device_id': did, 'payload': {f'k{i}': i for i in range(51)}}), 400)
    status("chave com 41 caracteres", env({'device_id': did, 'payload': {'k' * 41: 1}}), 400)
    status("valor texto com 256", env({'device_id': did, 'payload': {'s': 'v' * 256}}), 400)
    status("aninhamento com 7 níveis", env({'device_id': did, 'payload': {'a': {'b': {'c': {'d': {'e': {'f': {'g': 1}}}}}}}}), 400)
    status("payload como texto em vez de objeto", env({'device_id': did, 'payload': 'oi'}), 400)
    status("GET em /api/enviar-payload", dev.get('api/enviar-payload'), 405)
    total = db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did}")
    check("…apenas os 4 payloads válidos foram gravados", total == '4', f"gravados: {total}")

    r = dev.get(f'api/buscar-payloads?device_id={did}&limit=2', headers={'X-Api-Key': chave})
    status("buscar payloads com chave", r, 200)
    status("buscar payloads sem chave", dev.get(f'api/buscar-payloads?device_id={did}'), 401)
    status("buscar payloads de outro device com a chave", dev.get(f'api/buscar-payloads?device_id={did_priv}', headers={'X-Api-Key': chave}), 403)
    status("dados para gráfico (sessão)", g.get(f'api/obter-dados-grafico?device_id={did}&json_key=temperatura'), 200)
    status("dados para gráfico de device alheio", mb.get(f'api/obter-dados-grafico?device_id={did_priv}&json_key=t'), [403, 404])
    status("estatísticas do projeto", g.get(f'api/obter-stats-payloads?project_id={pid}'), 200)

    # TTN
    ttn = {'end_device_ids': {'device_id': 'lora-1'}, 'uplink_message': {'decoded_payload': {'temperatura': 22.1}}}
    r = dev.post(f'api/ttn-webhook?device_id={did}', ttn, csrf=False, headers={'X-Api-Key': chave})
    status("TTN webhook válido", r, [200, 201])
    status("TTN sem chave", dev.post(f'api/ttn-webhook?device_id={did}', ttn, csrf=False), 401)
    status("TTN chave errada", dev.post(f'api/ttn-webhook?device_id={did}', ttn, csrf=False, headers={'X-Api-Key': 'f' * 64}), [401, 403])
    status("TTN sem device_id na URL", dev.post('api/ttn-webhook', ttn, csrf=False, headers={'X-Api-Key': chave}), [400, 401])
    status("TTN sem uplink_message", dev.post(f'api/ttn-webhook?device_id={did}', {'x': 1}, csrf=False, headers={'X-Api-Key': chave}), 400)

    # Rate limit
    a_rl = S['admin']
    html = a_rl.get(rota_admin_rate).text
    import re
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html)
    tok = tok.group(1) if tok else ''
    def salvar_rl(rpm):
        return a_rl.s.post(a_rl.url(rota_admin_rate), allow_redirects=False, data={
            'csrf_token': tok, 'update_setting': '1',
            'settings[RATE_LIMIT_ENABLED]': '1', 'settings[RATE_LIMIT_REQUESTS_PER_MINUTE]': str(rpm),
            'settings[RATE_LIMIT_WINDOW_MINUTES]': '1', 'settings[RATE_LIMIT_SOFT_LIMIT_PERCENT]': '80',
            'settings[LOG_RATE_LIMIT_VIOLATIONS]': '1'})
    r = salvar_rl(3)
    check("admin salva limite de 3 req/min", r.status_code == 200 and 'Configurações atualizadas' in r.text, resumo(r))
    codigos = [env({'device_id': did_membro, 'payload': {'n': i}}, key=db.valor(f"SELECT api_key FROM devices WHERE id={did_membro}")).status_code for i in range(5)]
    check("a partir da 4ª requisição no minuto vem 429", codigos[:3] == [201] * 3 and 429 in codigos[3:], str(codigos))
    salvar_rl(60)
    check("violação registrada", int(db.valor(f"SELECT COUNT(*) FROM rate_limit_violations WHERE device_id={did_membro}") or 0) >= 1)

    # ------------------------------------------------------------------
    secao('12. Gráficos')
    gr = lambda **c: g.post('api/salvar-grafico-avancado', dict({
        'project_id': pid, 'name': 'Temperatura', 'chart_type': 'line', 'time_range': 'all',
        'datasets': [{'device_id': did, 'variable_name': 'temperatura', 'alias': 'Temp', 'color': '#ff0000'}]}, **c))
    r = gr()
    status("criar gráfico", r, [200, 201])
    cid = db.valor(f"SELECT id FROM charts WHERE project_id={pid} ORDER BY id DESC LIMIT 1")
    status("nome com 101", gr(name='n' * 101), 400)
    status("sem datasets", gr(datasets=[]), 400)
    status("dataset com device de outro projeto", gr(datasets=[{'device_id': did_priv, 'variable_name': 't'}]), 403)
    status("tipo de gráfico inválido", gr(chart_type='<script>'), 400)
    for tipo, gravado in [('line', 'line'), ('bar', 'bar'), ('area', 'area'), ('scatter', 'scatter'), ('linha', 'line'), ('barra', 'bar')]:
        r = gr(name=f'Tipo {tipo}', chart_type=tipo)
        ok = r.status_code in (200, 201) and db.valor(f"SELECT chart_type FROM charts WHERE project_id={pid} AND name='Tipo {tipo}'") == gravado
        check(f"tipo '{tipo}' é aceito e gravado como '{gravado}'", ok, resumo(r))
    status("dataset sem variable_name", gr(datasets=[{'device_id': did}]), 400)
    status("dataset com cor inválida", gr(datasets=[{'device_id': did, 'variable_name': 't', 'color': 'vermelho'}]), 400)
    status("não membro não cria", S['externo'].post('api/salvar-grafico-avancado', {'project_id': pid, 'name': 'x', 'chart_type': 'line', 'datasets': [{'device_id': did, 'variable_name': 't'}]}), 403)
    status("listar gráficos", g.get(f'api/listar-graficos?project_id={pid}'), 200)
    r = g.get(f'api/obter-dados-grafico-renderizado?chart_id={cid}')
    status("dados do gráfico renderizado", r, 200)
    status("dados do gráfico avançado", g.get(f'api/obter-dados-grafico-avancado?chart_id={cid}'), 200)
    status("não membro não vê gráfico de projeto privado (lista)", mb.get(f'api/listar-graficos?project_id={pid_priv}'), [403, 404])
    status("editar gráfico (PUT)", g.put('api/atualizar-grafico-avancado', {'chart_id': cid, 'name': 'Temp. editada'}), 200)
    status("editar gráfico com nome de 101", g.put('api/atualizar-grafico-avancado', {'chart_id': cid, 'name': 'n' * 101}), 400)
    status("não gerente não edita", S['externo'].put('api/atualizar-grafico-avancado', {'chart_id': cid, 'name': 'x'}), 403)
    status("tornar gráfico público", g.post('api/alterar-visibilidade-grafico', {'chart_id': cid, 'is_public': 1}), 200)
    status("página novo-grafico", g.get(f'novo-grafico?project_id={pid}'), 200)

    # ------------------------------------------------------------------
    secao('13. Exportação e notificações')
    r = g.get(f'api/exportar-dados-projeto?project_id={pid}&page=1&per_page=100')
    status("exportar dados (gerente)", r, 200)
    status("exportar dados (não membro)", S['externo'].get(f'api/exportar-dados-projeto?project_id={pid}'), 403)
    status("notificações", mb.get('api/obter-notificacoes'), 200)
    status("listar tags", mb.get('api/listar-tags'), 200)
    status("listar usuários (para convite)", g.get('api/listar-usuarios'), 200)

    # ------------------------------------------------------------------
    if a.ttn_mock:
        secao('14. The Things Network (Storage Integration)')
        APP, CHAVE, CLUSTER = 'app-e2e', 'NNSXS.E2ETESTKEY.0123456789ABCDEF', 'eu1.cloud.thethings.network'
        ttn = requests.Session()

        def ttn_ctl(rota, corpo):
            return ttn.post(a.ttn_mock.rstrip('/') + rota, json=corpo, timeout=10).json()

        def ttn_instante(segundos_atras):
            t = datetime.now(timezone.utc) - timedelta(seconds=segundos_atras)
            return t.strftime('%Y-%m-%dT%H:%M:%S.%f') + '123Z'  # 9 casas decimais, como o TTN

        def unix(rfc3339):
            return datetime.fromisoformat(re.sub(r'\.(\d{6})\d+', r'.\1', rfc3339.replace('Z', '+00:00'))).timestamp()

        # Na completa, a ponte também busca no Storage: fica parada até a seção dela
        if completa and a.ttn_bridge_container:
            subprocess.run(['docker', 'stop', a.ttn_bridge_container], capture_output=True)
        ttn_ctl('/reset', {})

        r = g.post('api/cadastrar-device', {'project_id': pid, 'name': 'Sensor LoRa'})
        did_lora = int(js(r).get('insertedId') or 0)

        def ttn_gravados():
            return int(db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did_lora}"))

        def cfg(cliente=g, csrf=True, **campos):
            corpo = dict({'project_id': pid, 'cluster': CLUSTER, 'application_id': APP, 'api_key': CHAVE, 'enabled': 1}, **campos)
            return cliente.post('api/ttn-configuracao', corpo, csrf=csrf)

        r = g.get(f'api/ttn-configuracao?project_id={pid}')
        check("sem integração: configured=false", r.status_code == 200 and js(r).get('configured') is False, resumo(r))
        status("cluster fora da lista é recusado (evita o servidor buscar URLs arbitrárias)", cfg(cluster='169.254.169.254'), 400)
        status("ID da aplicação com maiúsculas/sublinhado é recusado", cfg(application_id='App_E2E'), 400)
        status("chave com formato inválido é recusada", cfg(api_key='curta'), 400)
        r = cfg(api_key='NNSXS.CHAVEERRADA.0000000000000000')
        check("chave errada é recusada depois de testada no TTN", r.status_code == 400 and 'recusou' in js(r).get('error', ''), resumo(r))
        r = cfg(application_id='app-inexistente')
        check("aplicação inexistente (ou sem Storage Integration) é recusada",
              r.status_code == 400 and 'não encontrada' in js(r).get('error', ''), resumo(r))
        status("participante não configura", cfg(cliente=mb), 403)
        status("sem token CSRF é recusado", cfg(csrf=False), 403)
        status("configuração válida", cfg(), 200)
        check("…chave gravada", db.valor(f"SELECT api_key FROM ttn_applications WHERE project_id={pid}") == CHAVE)
        r = mb.get(f'api/ttn-configuracao?project_id={pid}')
        check("membro vê o status, mas não a chave", r.status_code == 200 and js(r).get('configured') is True
              and 'api_key' not in js(r) and CHAVE not in r.text, resumo(r))
        status("não membro não vê a configuração", S['externo'].get(f'api/ttn-configuracao?project_id={pid}'), 403)
        status("salvar sem reenviar a chave", cfg(api_key=''), 200)
        check("…mantém a chave atual", db.valor(f"SELECT api_key FROM ttn_applications WHERE project_id={pid}") == CHAVE)

        def ligar(ttn_id, cliente=g, device=None):
            return cliente.post('api/ttn-dispositivo', {'device_id': device or did_lora, 'ttn_device_id': ttn_id})
        status("ID do TTN inválido", ligar('Sensor_1'), 400)
        status("ligar o dispositivo ao end device 'sensor-1'", ligar('sensor-1'), 200)
        status("o mesmo end device em outro dispositivo do projeto", ligar('sensor-1', device=did), 409)
        status("não membro não liga", ligar('sensor-x', cliente=S['externo']), 403)
        r = g.get(f'api/obter-info-dispositivo?device_id={did_lora}')
        check("info do dispositivo traz o ID do TTN", js(r).get('ttn_device_id') == 'sensor-1', resumo(r))

        t1, t2, t3 = ttn_instante(7200), ttn_instante(3600), ttn_instante(60)
        for t, temp in [(t1, 21.5), (t2, 22.0), (t3, 23.5)]:
            ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': t, 'decoded_payload': {'temperatura': temp}})
        ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'nao-ligado', 'received_at': ttn_instante(50), 'decoded_payload': {'t': 1}})
        ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': ttn_instante(40)})
        ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': ttn_instante(30),
                            'decoded_payload': {f'k{i}': i for i in range(51)}})
        r = g.post('api/ttn-sincronizar', {'project_id': pid})
        rr = js(r)
        check("buscar agora: 3 gravados, 1 de end device não ligado, 1 sem decoded_payload, 1 fora dos limites",
              r.status_code == 200 and (rr.get('saved'), rr.get('unmapped'), rr.get('no_payload'), rr.get('invalid')) == (3, 1, 1, 1),
              resumo(r))
        horarios = [float(x[0]) for x in db.sql(f"SELECT UNIX_TIMESTAMP(created_at) FROM device_payloads WHERE device_id={did_lora} ORDER BY created_at")]
        esperados = [unix(t1), unix(t2), unix(t3)]
        check("…cada dado fica com o horário de recepção no TTN (não o da busca)",
              len(horarios) == 3 and all(abs(h - e) < 1.5 for h, e in zip(horarios, esperados)), f"{horarios} vs {esperados}")
        check("…origem 'ttn'", db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did_lora} AND source='ttn'") == '3')
        visto = float(db.valor(f"SELECT UNIX_TIMESTAMP(last_seen) FROM device_mqtt_status WHERE device_id={did_lora}") or 0)
        check("…'visto por último' é o último uplink, não o momento da busca", abs(visto - unix(t3)) < 1.5, f"{visto} vs {unix(t3)}")
        r = g.post('api/ttn-sincronizar', {'project_id': pid})
        check("buscar de novo não grava em dobro", js(r).get('saved') == 0 and ttn_gravados() == 3, resumo(r))
        # Uplink que entra no Storage fora de ordem: horário anterior ao último já lido
        ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': ttn_instante(90),
                            'decoded_payload': {'temperatura': 23.0}})
        r = g.post('api/ttn-sincronizar', {'project_id': pid})
        check("uplink que entra no Storage fora de ordem (anterior ao último lido) também é recuperado",
              js(r).get('saved') == 1 and ttn_gravados() == 4, resumo(r))

        chave_lora = db.valor(f"SELECT api_key FROM devices WHERE id={did_lora}")
        r = dev.post(f'api/ttn-webhook?device_id={did_lora}', {
            'end_device_ids': {'device_id': 'sensor-1'}, 'received_at': t2,
            'uplink_message': {'decoded_payload': {'temperatura': 22.0}}}, csrf=False, headers={'X-Api-Key': chave_lora})
        check("webhook com um uplink que o Storage já trouxe não grava em dobro",
              r.status_code in (200, 201) and ttn_gravados() == 4, resumo(r))

        anonimo = Cliente(a.base)
        db.sql(f"UPDATE ttn_applications SET last_sync_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE project_id={pid}")
        ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': ttn_instante(5), 'decoded_payload': {'temperatura': 24.0}})
        r = anonimo.post('api/ttn-sincronizar', csrf=False)
        check("busca automática, sem login (como as páginas disparam), grava o uplink novo",
              r.status_code == 200 and ttn_gravados() == 5, resumo(r))
        ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': ttn_instante(1), 'decoded_payload': {'temperatura': 25.0}})
        anonimo.post('api/ttn-sincronizar', csrf=False)
        check("…no máximo uma busca automática por minuto", ttn_gravados() == 5, f"gravados: {ttn_gravados()}")
        status("buscar um projeto específico exige login", anonimo.post('api/ttn-sincronizar', {'project_id': pid}, csrf=False), 401)
        status("buscar projeto de que não participa", S['externo'].post('api/ttn-sincronizar', {'project_id': pid}), 403)
        if not completa:
            check("páginas do Lite disparam a busca (ttn-sync.js)", 'ttn-sync.js' in g.get('meus-projetos').text
                  and 'ttn-sync.js' in anon.get('').text)

        ttn_ctl('/chave', {'application_id': APP, 'api_key': 'NNSXS.OUTRACHAVE.0000000000000000'})
        r = g.post('api/ttn-sincronizar', {'project_id': pid})
        check("chave revogada no TTN: erro claro", r.status_code == 502 and 'recusou' in js(r).get('error', ''), resumo(r))
        check("…e aparece no status do projeto", 'recusou' in (js(g.get(f'api/ttn-configuracao?project_id={pid}')).get('last_error') or ''))
        ttn_ctl('/chave', {'application_id': APP, 'api_key': CHAVE})
        r = g.post('api/ttn-sincronizar', {'project_id': pid})
        check("com a chave válida de novo, recupera o que ficou pendente",
              js(r).get('saved') == 1 and ttn_gravados() == 6, resumo(r))
        check("…e o erro some do status", js(g.get(f'api/ttn-configuracao?project_id={pid}')).get('last_error') is None)

        if completa and a.ttn_bridge_container and a.ttn_mqtt_port:
            secao('15. Ponte MQTT com o TTN (somente edição completa)')
            import paho.mqtt.client as mqtt_client
            ttn_ctl('/uplink', {'application_id': APP, 'device_id': 'sensor-1', 'received_at': ttn_instante(2), 'decoded_payload': {'temperatura': 26.0}})
            base_ttn = ttn_gravados()
            subprocess.run(['docker', 'start', a.ttn_bridge_container], capture_output=True)
            for _ in range(45):
                time.sleep(1)
                logs = subprocess.run(['docker', 'logs', a.ttn_bridge_container], capture_output=True, text=True).stdout
                if f"Projeto {pid}: conectado ao TTN" in logs and ttn_gravados() > base_ttn:
                    break
            check("ao subir, a ponte conecta ao MQTT do TTN (TLS)", f"Projeto {pid}: conectado ao TTN" in logs, logs[-400:])
            check("…e recupera pelo Storage o que chegou enquanto estava parada", ttn_gravados() == base_ttn + 1,
                  f"gravados: {ttn_gravados() - base_ttn}")

            def publicar_ttn(device_id, payload):
                cli = mqtt_client.Client(client_id=f'e2e-ttn-{sufixo}')
                cli.username_pw_set('publicador', 'publicador')
                cli.connect(a.mqtt_host, a.ttn_mqtt_port)
                cli.loop_start()
                cli.publish(f'v3/{APP}@ttn/devices/{device_id}/up', json.dumps({
                    'end_device_ids': {'device_id': device_id, 'application_ids': {'application_id': APP}},
                    'received_at': ttn_instante(0),
                    'uplink_message': {'decoded_payload': payload}})).wait_for_publish()
                cli.loop_stop()
                cli.disconnect()

            base_ttn = ttn_gravados()
            publicar_ttn('sensor-1', {'temperatura': 27.0})
            for _ in range(10):
                time.sleep(1)
                if ttn_gravados() > base_ttn:
                    break
            check("uplink publicado no MQTT do TTN é gravado em segundos", ttn_gravados() == base_ttn + 1,
                  f"gravados: {ttn_gravados() - base_ttn}")
            publicar_ttn('nao-ligado', {'t': 1})
            time.sleep(3)
            check("uplink de end device não ligado é ignorado", ttn_gravados() == base_ttn + 1)

    # ------------------------------------------------------------------
    if completa:
        secao('16. MQTT (somente edição completa)')
        r = dev.get('api/get-mqtt-credentials', headers={'X-Api-Key': chave})
        status("credenciais MQTT do dispositivo", r, 200)
        usuario_mqtt = js(r).get('mqtt_username')
        check("senha MQTT não vem sem reveal", 'mqtt_password' not in js(r), resumo(r))
        r = dev.get('api/get-mqtt-credentials?reveal=true', headers={'X-Api-Key': chave})
        senha_mqtt = js(r).get('mqtt_password')
        check("senha MQTT revelada com reveal=true", bool(senha_mqtt), resumo(r))
        status("credenciais MQTT sem chave", dev.get('api/get-mqtt-credentials'), 401)
        status("credenciais MQTT com chave curta", dev.get('api/get-mqtt-credentials', headers={'X-Api-Key': 'abc'}), [400, 401])
        status("logs MQTT (admin)", S['admin'].get('api/obter-logs-mqtt'), 200)
        status("logs MQTT (usuário comum)", mb.get('api/obter-logs-mqtt'), 403)
        if a.mqtt_port and usuario_mqtt and senha_mqtt:
            try:
                import paho.mqtt.publish as publish
                topico = f"mqtt/projects/{pid}/devices/{did}"
                antes = int(db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did} AND source='mqtt'"))
                time.sleep(6)  # o broker recarrega passwd/ACL de forma assíncrona
                publish.single(topico, json.dumps({'temperatura': 30.5}), hostname=a.mqtt_host, port=a.mqtt_port,
                               auth={'username': usuario_mqtt, 'password': senha_mqtt})
                for _ in range(20):
                    time.sleep(1)
                    depois = int(db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did} AND source='mqtt'"))
                    if depois > antes:
                        break
                check("publicação MQTT com credenciais do device é gravada pelo worker", depois > antes, f"antes={antes} depois={depois}")
                erro = None
                try:
                    publish.single(topico, '{"t":1}', hostname=a.mqtt_host, port=a.mqtt_port,
                                   auth={'username': usuario_mqtt, 'password': 'senha-errada'})
                except Exception as ex:
                    erro = ex
                check("publicação MQTT com senha errada é rejeitada pelo broker", erro is not None, 'broker aceitou a conexão')
                antes_alheio = int(db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did_priv}"))
                publish.single(f"mqtt/projects/{pid_priv}/devices/{did_priv}", '{"t":1}', hostname=a.mqtt_host,
                               port=a.mqtt_port, auth={'username': usuario_mqtt, 'password': senha_mqtt})
                time.sleep(4)
                check("ACL: device não grava no tópico de outro device",
                      int(db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did_priv}")) == antes_alheio)

                import paho.mqtt.client as mqtt_client

                def publicar_lote(n, rotulo):
                    cli = mqtt_client.Client(client_id=f'e2e-{rotulo}-{sufixo}')
                    cli.username_pw_set(usuario_mqtt, senha_mqtt)
                    cli.connect(a.mqtt_host, a.mqtt_port)
                    cli.loop_start()
                    for i in range(n):
                        cli.publish(topico, json.dumps({rotulo: i}), qos=1).wait_for_publish()
                    cli.loop_stop()
                    cli.disconnect()

                def mqtt_gravados():
                    return int(db.valor(f"SELECT COUNT(*) FROM device_payloads WHERE device_id={did} AND source='mqtt'"))

                base_lote = mqtt_gravados()
                publicar_lote(20, 'lote')
                time.sleep(6)
                check("20 publicações MQTT seguidas: todas gravadas", mqtt_gravados() - base_lote == 20,
                      f"gravadas: {mqtt_gravados() - base_lote}")

                # Criar um dispositivo reescreve passwd/ACL e recarrega o broker
                g.post('api/cadastrar-device', {'project_id': pid, 'name': 'Força recarga'})
                time.sleep(5)
                base_lote = mqtt_gravados()
                publicar_lote(20, 'recarga')
                time.sleep(6)
                check("20 publicações logo após recarga do broker: todas gravadas", mqtt_gravados() - base_lote == 20,
                      f"gravadas: {mqtt_gravados() - base_lote}")

                if a.worker_container:
                    subprocess.run(['docker', 'stop', a.worker_container], capture_output=True)
                    base_lote = mqtt_gravados()
                    publicar_lote(10, 'offline')
                    subprocess.run(['docker', 'start', a.worker_container], capture_output=True)
                    time.sleep(15)
                    check("10 publicações com o worker parado são gravadas quando ele volta",
                          mqtt_gravados() - base_lote == 10, f"gravadas: {mqtt_gravados() - base_lote}")
            except ImportError:
                check("paho-mqtt instalado para testar o broker", False, 'pip install paho-mqtt')
        r = dev.post('api/regenerate-mqtt-password', csrf=False, headers={'X-Api-Key': chave})
        status("regenerar senha MQTT", r, 200)

    # ------------------------------------------------------------------
    secao('17. Administração')
    adm = S['admin']
    status("painel admin (admin)", adm.get('admin'), 200)
    status("painel admin (usuário comum)", mb.get('admin'), [302, 403])
    status(f"página {rota_admin_usuarios} (admin)", adm.get(rota_admin_usuarios), 200)
    status(f"página {rota_admin_rate} (comum)", mb.get(rota_admin_rate), [302, 403])
    status("listar usuários (admin)", adm.get('api/admin-usuarios?search=' + sufixo), 200)
    status("listar usuários (comum)", mb.get('api/admin-usuarios'), 403)
    status("mudar perfil para valor inválido", adm.put('api/admin-usuarios', {'user_id': usuarios['membro']['id'], 'profile': 'Root'}), 400)
    status("comum não muda perfil", mb.put('api/admin-usuarios', {'user_id': usuarios['membro']['id'], 'profile': 'Admin'}), 403)
    status("admin muda perfil do membro para Moderator", adm.put('api/admin-usuarios', {'user_id': usuarios['membro']['id'], 'profile': 'Moderator'}), 200)
    r = adm.put('api/admin-usuarios', {'user_id': usuarios['admin']['id'], 'profile': 'User'})
    check("admin não pode rebaixar a si mesmo (evita ficar sem admin)", r.status_code in (400, 403), resumo(r))
    db.sql(f"UPDATE users SET profile='Admin' WHERE id={usuarios['admin']['id']}")

    # ------------------------------------------------------------------
    secao('18. Páginas logadas (sem erros de PHP)')
    for rota in ['meus-projetos', 'explorar-projetos', 'meus-dispositivos', 'perfil', 'configuracoes',
                 'documentacao', 'novo-projeto', f'novo-dispositivo?project_id={pid}', f'projeto?id={pid}',
                 f'ver-projeto?id={pid}', f'dispositivo?id={did}&project_id={pid}', 'termos']:
        r = g.get(rota)
        ok = r.status_code == 200 and not any(x in r.text for x in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'))
        check(f"/{rota} abre sem erro", ok, resumo(r))

    # ------------------------------------------------------------------
    secao('19. Exclusões')
    status("não gerente não apaga dispositivo", mb.post('api/deletar-dispositivo', {'device_id': did}), 403)
    status("apagar gráfico (não gerente)", S['externo'].post('api/deletar-grafico', {'chart_id': cid}), 403)
    status("apagar gráfico", g.post('api/deletar-grafico', {'chart_id': cid}), 200)
    status("apagar dispositivo", g.post('api/deletar-dispositivo', {'device_id': did_membro}), 200)
    r = dev.post('api/enviar-payload', {'device_id': did_membro, 'payload': {'t': 1}}, csrf=False,
                 headers={'X-Api-Key': db.valor(f"SELECT api_key FROM devices WHERE id={did_membro}")})
    status("dispositivo apagado não envia mais dados", r, 401)
    status("participante sai do projeto", mb.post('api/sair-projeto', {'project_id': pid}), 200)
    status("…e perde o acesso", mb.get(f'api/obter-projeto?id={pid}'), [403, 404])
    status("não gerente não apaga projeto", S['externo'].post('api/deletar-projeto', {'project_id': pid}), 403)
    status("gerente apaga projeto", g.post('api/deletar-projeto', {'project_id': pid}), 200)
    status("projeto apagado some", g.get(f'api/obter-projeto?id={pid}'), [403, 404])
    r = dev.post('api/enviar-payload', {'device_id': did, 'payload': {'t': 1}}, csrf=False, headers={'X-Api-Key': chave})
    check("device de projeto apagado não envia mais dados", r.status_code in (401, 403, 404), resumo(r))
    status("device de projeto apagado não aparece mais", g.get(f'api/obter-info-dispositivo?device_id={did}'), [403, 404])
    lista = js(g.get('api/listar-projetos'))
    lista = lista if isinstance(lista, list) else lista.get('data', [])
    check("projeto apagado some da lista de projetos", pid not in [int(p.get('id', 0)) for p in lista])
    status("admin apaga usuário", adm.delete('api/admin-usuarios', {'user_id': usuarios['externo']['id']}), 200)
    limpar_tentativas()
    status("usuário apagado não loga", Cliente(a.base).login(email('externo'), SENHA), 401)

    # ------------------------------------------------------------------
    total = len(RESULTADOS)
    falhas = [r for r in RESULTADOS if not r[2]]
    print(f"\nRESULTADO ({a.edicao}): {total - len(falhas)}/{total} verificações OK, {len(falhas)} falhas")
    for s, d, _, det in falhas:
        print(f"  - [{s}] {d}\n      {det}")
    if a.json:
        with open(a.json, 'w', encoding='utf-8') as f:
            json.dump([{'secao': s, 'teste': d, 'ok': ok, 'detalhe': det} for s, d, ok, det in RESULTADOS],
                      f, ensure_ascii=False, indent=1)
    return 1 if falhas else 0


if __name__ == '__main__':
    sys.exit(main())
