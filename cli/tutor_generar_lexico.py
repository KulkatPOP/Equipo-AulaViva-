"""Compila las equivalencias español-inglés de MCR 3.0 y WordNet 3.0.

Uso: python cli/tutor_generar_lexico.py omw-1.4.zip wordnet.zip directorio
"""

import argparse
import collections
import gzip
import pathlib
import re
import zipfile


def generar(omw, wordnet, destino):
    with zipfile.ZipFile(omw) as multilingue, zipfile.ZipFile(wordnet) as ingles:
        lemas = collections.defaultdict(set)
        for linea in multilingue.read("omw-1.4/mcr/wn-data-spa.tab").decode().splitlines():
            campos = linea.split("\t")
            if len(campos) != 3 or campos[1] != "lemma":
                continue
            lema = campos[2].lower()
            if re.fullmatch(r"[a-záéíóúüñ]{2,30}(?: [a-záéíóúüñ]{1,30}){0,3}", lema):
                lemas[campos[0]].add(lema)

        pares = set()
        for categoria in ("noun", "verb", "adj", "adv"):
            for linea in ingles.read(f"wordnet/data.{categoria}").decode().splitlines():
                if not linea[:1].isdigit():
                    continue
                datos = linea.split(" | ", 1)[0].split()
                categoria_synset = "a" if datos[2] == "s" else datos[2]
                traducciones = lemas.get(f"{datos[0]}-{categoria_synset}", ())
                if not traducciones:
                    continue
                for lema in datos[4:4 + 2 * int(datos[3], 16):2]:
                    lema = lema.lower().replace("_", " ")
                    if re.fullmatch(r"[a-z]{2,30}(?: [a-z]{1,30}){0,3}", lema):
                        pares.update((traduccion, lema) for traduccion in traducciones)

        with (destino / "lexico-en-es.tsv.gz").open("wb") as archivo:
            with gzip.GzipFile(filename="", fileobj=archivo, mode="wb", mtime=0) as comprimido:
                comprimido.write("".join(f"{es}\t{en}\n" for es, en in sorted(pares)).encode())
        (destino / "lexico-wordnet-LICENSE.txt").write_bytes(ingles.read("wordnet/LICENSE"))
        (destino / "lexico-mcr-LICENSE.txt").write_bytes(multilingue.read("omw-1.4/mcr/LICENSE"))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("omw", type=pathlib.Path)
    parser.add_argument("wordnet", type=pathlib.Path)
    parser.add_argument("destino", type=pathlib.Path)
    argumentos = parser.parse_args()
    generar(argumentos.omw, argumentos.wordnet, argumentos.destino)
