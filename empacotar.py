#!/usr/bin/env python3
"""
Gera o .zip do plugin para subir no WordPress.

    python empacotar.py

Sai em dist/leticia-<versao>.zip, com a versão lida do cabeçalho de leticia.php.

Por que existe: zipar a pasta na mão leva junto leticia/tests/, que não tem o
que fazer num servidor público. Lá dentro está o stubs-wp.php, que redefine
get_option, update_option e os transients. Ele não faz estrago sozinho, e não
tem motivo nenhum para ficar acessível pela web.

Recusa empacotar se a suíte offline não estiver verde — pacote que sobe com
teste vermelho é pacote que volta.
"""

import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

import minificar

BASE = Path(__file__).resolve().parent
PLUGIN = BASE / "leticia"
DESTINO = BASE / "dist"

# O que NÃO vai para o servidor.
PASTAS_FORA = {"tests"}
ARQUIVOS_FORA = {".DS_Store", "Thumbs.db"}
SUFIXOS_FORA = {".rar", ".zip", ".log", ".pyc"}


def versao() -> str:
    cabecalho = (PLUGIN / "leticia.php").read_text(encoding="utf-8")
    achado = re.search(r"^\s*\*\s*Version:\s*(.+)$", cabecalho, re.M)
    return achado.group(1).strip() if achado else "0.0.0"


def suite_verde() -> bool:
    print("rodando a suíte offline antes de empacotar...")
    r = subprocess.run(
        [shutil.which("php") or "php", str(PLUGIN / "tests" / "rodar.php")],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    print((r.stdout or "").strip().splitlines()[-1] if r.stdout else "(sem saída)")
    return r.returncode == 0


def incluir(caminho: Path) -> bool:
    relativo = caminho.relative_to(PLUGIN)
    if relativo.parts and relativo.parts[0] in PASTAS_FORA:
        return False
    if caminho.name in ARQUIVOS_FORA or caminho.suffix.lower() in SUFIXOS_FORA:
        return False
    return True


def minificado(caminho: Path):
    """
    O JS e o CSS da tela vão minificados — só se o resultado for válido.

    O JS passa pelo Node antes de entrar; sem Node, ou se a sintaxe quebrar,
    vai o original. A fonte no repositório continua legível: é ela que se
    edita e que a suíte lê.
    """
    if caminho.parent.name != "public" or caminho.suffix not in (".js", ".css"):
        return None
    original = caminho.read_text(encoding="utf-8")
    if caminho.suffix == ".js":
        mini = minificar.js(original)
        ok = minificar.js_valido(mini)
        if not ok:
            motivo = "sem node para conferir" if ok is None else "a versão minificada não passou na sintaxe"
            print(f"aviso: {caminho.name} vai sem minificar ({motivo})")
            return None
        return mini
    mini = minificar.css(original)
    if not minificar.css_valido(original, mini):
        print(f"aviso: {caminho.name} vai sem minificar (a contagem de blocos não bateu)")
        return None
    return mini


def main() -> int:
    if not PLUGIN.is_dir():
        print(f"não encontrei {PLUGIN}")
        return 1

    if not suite_verde():
        print("\nsuíte vermelha — não empacotei. Corrija antes de subir.")
        return 1

    DESTINO.mkdir(exist_ok=True)
    saida = DESTINO / f"leticia-{versao()}.zip"

    entradas = sorted(c for c in PLUGIN.rglob("*") if c.is_file() and incluir(c))

    notas = []
    with zipfile.ZipFile(saida, "w", zipfile.ZIP_DEFLATED) as z:
        for caminho in entradas:
            # O WordPress espera a pasta do plugin na raiz do zip.
            destino = Path("leticia") / caminho.relative_to(PLUGIN)
            mini = minificado(caminho)
            if mini is not None:
                z.writestr(destino.as_posix(), mini)
                notas.append(f"{caminho.name}: {caminho.stat().st_size // 1024} KB -> {len(mini.encode()) // 1024} KB minificado")
            else:
                z.write(caminho, destino)

    kb = saida.stat().st_size / 1024
    for nota in notas:
        print(nota)
    print(f"\n{saida.relative_to(BASE)} · {len(entradas)} arquivos · {kb:.0f} KB\n")
    for caminho in entradas:
        print(f"  leticia/{caminho.relative_to(PLUGIN).as_posix()}")

    fora = sorted(c for c in PLUGIN.rglob("*") if c.is_file() and not incluir(c))
    if fora:
        print(f"\nficaram de fora ({len(fora)}):")
        for caminho in fora:
            print(f"  leticia/{caminho.relative_to(PLUGIN).as_posix()}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
