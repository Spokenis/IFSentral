#!/usr/bin/env python3
"""
generate_mqtt_hashes.py
Gera hashes PBKDF2 SHA256 compatíveis com Mosquitto 2.x
"""

import hashlib
import hmac
import base64
import os
import sys
import mysql.connector
from mysql.connector import Error

# Configuração do banco (lê do config.php)
DB_HOST = 'localhost'
DB_USER = 'root'
DB_PASSWORD = ''  # Senha vazia por padrão
DB_NAME = 'ifsentral_bd'

def pbkdf2_sha256(password, salt, iterations=1000):
    """
    Gera hash PBKDF2 com SHA256 no formato do Mosquitto
    Formato: $7$base64(salt)$base64(hash)
    """
    hash_obj = hashlib.pbkdf2_hmac('sha256', password.encode(), salt, iterations)
    
    # Formato Mosquitto: $7$base64(salt)$base64(hash)
    salt_b64 = base64.b64encode(salt).decode('ascii')
    hash_b64 = base64.b64encode(hash_obj).decode('ascii')
    
    return f"$7${salt_b64}${hash_b64}"

def generate_salt(length=12):
    """Gera sal aleatório"""
    return os.urandom(length)

def main():
    print("=== Gerador de Hashes PBKDF2 para Mosquitto ===\n")
    
    try:
        # Conecta ao banco
        connection = mysql.connector.connect(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME
        )
        
        if connection.is_connected():
            print("✅ Conectado ao banco de dados\n")
        
        cursor = connection.cursor(dictionary=True)
        
        # Lê credenciais
        query = """
        SELECT 
            mc.id,
            mc.device_id,
            mc.mqtt_username,
            d.name as device_name
        FROM mqtt_credentials mc
        JOIN devices d ON mc.device_id = d.id
        WHERE mc.enabled = 1
        ORDER BY mc.mqtt_username
        """
        
        cursor.execute(query)
        credentials = cursor.fetchall()
        
        if not credentials:
            print("❌ Nenhuma credencial MQTT encontrada")
            return False
        
        print(f"📋 Processando {len(credentials)} credenciais...\n")
        
        # Gera arquivo passwd
        passwd_content = "# Auto-gerado por generate_mqtt_hashes.py\n"
        passwd_content += f"# Gerado em: {os.popen('date').read().strip()}\n"
        passwd_content += "# Formato: username:hash_pbkdf2\n\n"
        
        # Também gera um arquivo com senhas em plain text (guardar seguro!)
        credentials_content = "# CREDENCIAIS MQTT - GUARDAR COM SEGURANÇA\n"
        credentials_content += f"# Gerado em: {os.popen('date').read().strip()}\n\n"
        
        for cred in credentials:
            username = cred['mqtt_username']
            device_id = cred['device_id']
            device_name = cred['device_name']
            
            # Gera nova senha aleatória
            import string
            import random
            chars = string.ascii_letters + string.digits
            password = ''.join(random.choice(chars) for _ in range(16))
            
            # Gera salt e hash
            salt = generate_salt()
            hash_pbkdf2 = pbkdf2_sha256(password, salt)
            
            # Salva no banco
            update_query = """
            UPDATE mqtt_credentials 
            SET mqtt_password_hash = %s, updated_at = NOW()
            WHERE id = %s
            """
            
            cursor.execute(update_query, (hash_pbkdf2, cred['id']))
            connection.commit()
            
            # Adiciona ao arquivo passwd
            passwd_content += f"{username}:{hash_pbkdf2}\n"
            
            # Adiciona ao arquivo de credenciais
            credentials_content += f"[Device #{device_id}] {device_name}\n"
            credentials_content += f"  Username: {username}\n"
            credentials_content += f"  Password: {password}\n"
            credentials_content += f"  Hash: {hash_pbkdf2}\n\n"
            
            print(f"✅ {username} (Device #{device_id} - {device_name})")
        
        # Escreve arquivo passwd
        passwd_file = '/etc/mosquitto/passwd'
        try:
            with open(passwd_file, 'w') as f:
                f.write(passwd_content)
            os.chmod(passwd_file, 0o600)
            print(f"\n✅ Arquivo passwd gerado: {passwd_file}")
        except PermissionError:
            print(f"\n⚠️  Sem permissão para escrever em {passwd_file}")
            print("    Salvando em /tmp/mosquitto_passwd")
            passwd_file = '/tmp/mosquitto_passwd'
            with open(passwd_file, 'w') as f:
                f.write(passwd_content)
            os.chmod(passwd_file, 0o600)
        
        # Escreve arquivo de credenciais (BACKUP SEGURO)
        backup_file = '/root/mqtt_credentials_backup.txt'
        try:
            with open(backup_file, 'w') as f:
                f.write(credentials_content)
            os.chmod(backup_file, 0o600)
            print(f"💾 Credenciais salvas (BACKUP): {backup_file}")
        except:
            backup_file = f"{os.path.dirname(__file__)}/../../mqtt_credentials_backup.txt"
            with open(backup_file, 'w') as f:
                f.write(credentials_content)
            os.chmod(backup_file, 0o600)
            print(f"💾 Credenciais salvas (BACKUP): {backup_file}")
        
        print(f"📊 Total de usuários: {len(credentials)}")
        print("\n=== Próximos Passos ===")
        print("1. Verificar arquivo passwd:")
        print(f"   sudo ls -la {passwd_file}")
        print("\n2. Gerar arquivo ACL:")
        print("   php src/mqtt/generate_mosquitto_acl.php")
        print("\n3. Configurar mosquitto.conf com:")
        print("   allow_anonymous false")
        print(f"   password_file {passwd_file}")
        print("   acl_file /etc/mosquitto/conf.d/acl.acl")
        print("\n4. Recarregar Mosquitto:")
        print("   sudo systemctl reload mosquitto")
        
        cursor.close()
        connection.close()
        
        return True
    
    except Error as e:
        print(f"❌ Erro no banco: {e}")
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
