#!/usr/bin/env python3
"""Calcula a nota SUS (0 a 100) de cada participante e a média.

Uso:  python3 calcular_sus.py respostas.csv

respostas.csv: cabeçalho "participante,q1,...,q10" e uma linha por pessoa,
com as respostas de 1 a 5. Itens ímpares contam (resposta - 1), pares
contam (5 - resposta); a soma vezes 2,5 dá a nota.
"""
import csv
import statistics
import sys


def nota(respostas):
    total = 0
    for i, r in enumerate(respostas, start=1):
        if not 1 <= r <= 5:
            raise ValueError(f'resposta fora de 1..5 no item {i}: {r}')
        total += (r - 1) if i % 2 == 1 else (5 - r)
    return total * 2.5


def faixa(n):
    if n < 50:
        return 'inaceitável'
    if n < 70:
        return 'marginal'
    if n < 85:
        return 'bom'
    return 'excelente'


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    notas = []
    with open(sys.argv[1], newline='', encoding='utf-8') as f:
        for linha in csv.DictReader(f):
            respostas = [int(linha[f'q{i}']) for i in range(1, 11)]
            n = nota(respostas)
            notas.append(n)
            print(f"{linha['participante']:<15} {n:5.1f}  ({faixa(n)})")
    if not notas:
        sys.exit('nenhuma resposta no arquivo')
    media = statistics.mean(notas)
    desvio = statistics.stdev(notas) if len(notas) > 1 else 0.0
    print(f"\n{len(notas)} participante(s): média {media:.1f} ({faixa(media)}), desvio {desvio:.1f}, "
          f"mín. {min(notas):.1f}, máx. {max(notas):.1f}")


if __name__ == '__main__':
    main()
