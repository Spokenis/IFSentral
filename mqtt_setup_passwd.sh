#!/bin/bash
# Script para gerar arquivo passwd do Mosquitto
# Gerado em: 2026-03-02 03:04:09

PASSWD_FILE=/etc/mosquitto/passwd

# Cria arquivo vazio
sudo touch $PASSWD_FILE
sudo chmod 600 $PASSWD_FILE

# Adicionar device_2 (será solicitada a senha)
# sudo mosquitto_passwd $PASSWD_FILE device_2
# Adicionar device_3 (será solicitada a senha)
# sudo mosquitto_passwd $PASSWD_FILE device_3
# Adicionar device_4 (será solicitada a senha)
# sudo mosquitto_passwd $PASSWD_FILE device_4
# Adicionar device_5 (será solicitada a senha)
# sudo mosquitto_passwd $PASSWD_FILE device_5
# Adicionar device_6 (será solicitada a senha)
# sudo mosquitto_passwd $PASSWD_FILE device_6
