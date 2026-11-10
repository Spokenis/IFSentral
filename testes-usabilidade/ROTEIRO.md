# Teste de usabilidade do IFSCentral

Duração: cerca de 25 min por participante. Recomendado: **5 a 8 participantes**
(alunos e servidores que nunca usaram a plataforma). Cinco já revelam a maior
parte dos problemas de uso.

## Antes de começar

- Use o servidor do câmpus ou a pilha local, com **uma conta nova por participante**
  (perfil Moderador, para poder criar projeto).
- Tenha um dispositivo de teste (ESP32 com o firmware de `firmware-teste/`) ou o
  comando `curl` abaixo para simular o envio.
- Não ajude durante as tarefas. Peça para o participante **pensar em voz alta** e anote
  onde ele hesita, erra ou pede ajuda.
- Anote, por tarefa: concluiu sozinho (S), concluiu com dica (D) ou não concluiu (N), e o tempo.

## Fala inicial (ler para o participante)

> "Estamos testando a plataforma, não você. Não existe resposta errada. Diga em voz alta
> o que está pensando enquanto usa. Se travar, tudo bem: isso é exatamente o que
> queremos descobrir."

## Tarefas

| # | Tarefa (ler para o participante) | Concluída quando |
|---|---|---|
| 1 | "Entre na plataforma com a conta que eu passei." | Está em Meus Projetos |
| 2 | "Crie um projeto chamado *Estufa* para monitorar uma estufa." | Projeto aparece na lista |
| 3 | "Cadastre um dispositivo chamado *Sensor 1* nesse projeto e encontre a chave que ele usa para enviar dados." | Mostrou a chave de API |
| 4 | (Facilitador envia 5 leituras com o comando abaixo.) "Veja se o sensor já mandou dados e qual foi a última temperatura." | Disse o valor |
| 5 | "Crie um gráfico de temperatura das últimas 24 horas." | Gráfico salvo e visível |
| 6 | "Baixe os dados do projeto em planilha (Excel)." | Arquivo baixado |
| 7 | "Descubra o que um colega precisaria configurar para enviar dados por MQTT." | Achou tópico/usuário/senha ou a documentação |
| 8 | "Torne o projeto público e confira como ele aparece para quem não tem conta." | Abriu Explorar Projetos / ver-projeto |

Envio simulado para a tarefa 4 (troque ID e chave):

```sh
for t in 21.5 22.0 22.4 23.1 23.8; do
  curl -sk -X POST https://SERVIDOR:8443/api/enviar-payload \
    -H "X-Api-Key: CHAVE_DO_DISPOSITIVO" -H "Content-Type: application/json" \
    -d "{\"device_id\": ID, \"payload\": {\"temperatura\": $t, \"umidade\": 60}}"
  sleep 2
done
```

## Depois das tarefas

1. Entregue o questionário SUS (`questionario-sus.md`). O participante responde sozinho.
2. Pergunte:
   - "O que foi mais difícil?"
   - "O que você mudaria primeiro?"
   - "Usaria no seu projeto? Por quê?"
3. Lance as respostas SUS em `respostas.csv` (uma linha por participante) e rode
   `python3 calcular_sus.py respostas.csv`.

## Como ler o resultado

- **SUS**: abaixo de 50, inaceitável; de 50 a 70, marginal; de 70 a 85, bom; acima de 85,
  excelente. A média de mercado é 68.
- **Taxa de sucesso** por tarefa: S conta 1, D conta 0,5, N conta 0. Abaixo de 80% numa
  tarefa indica problema de uso ali.
- Para o TCC, registre: número de participantes e perfil (aluno/servidor, já programou ESP?),
  a nota SUS (média e desvio), a taxa de sucesso por tarefa e os três problemas mais citados.
