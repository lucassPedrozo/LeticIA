#!/usr/bin/env python3
"""
Minificação conservadora do JS e do CSS da tela, sem dependência nenhuma.

    python minificar.py            mostra quanto cada arquivo encolhe
    python minificar.py --saida X  grava as versões minificadas na pasta X

Conservadora de propósito. Tira comentário e espaço sobrando — que no código
daqui são metade do arquivo, porque o comentário explica o porquê — e não
reescreve nada: nome de variável, quebra de linha e ponto e vírgula ficam como
estão. Manter as quebras de linha é o que deixa isto seguro sem um parser de
verdade: a inserção automática de ponto e vírgula do JS depende delas.

O empacotar.py usa isto no .zip, e confere a sintaxe do JS com o Node antes de
aceitar o resultado. Se o Node não estiver instalado, o JS vai sem minificar.
"""

import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

# Depois destes caracteres, "/" abre expressão regular, não divisão.
_ANTES_DE_REGEX = set("(,=:[!&|?{};+-*%<>~^")
_PALAVRAS_ANTES_DE_REGEX = {
    "return", "typeof", "case", "do", "else", "in", "of", "new", "delete",
    "void", "throw", "yield", "await", "instanceof",
}


def js(texto: str) -> str:
    """Tira comentários e espaço sobrando, respeitando string, template e regex."""
    saida = []
    i, n = 0, len(texto)
    ultimo = ""          # último caractere significativo escrito
    palavra = ""         # última palavra escrita (para "return /x/")
    pilha_template = []  # profundidade de ${ } dentro de template

    def escrever(s):
        nonlocal ultimo, palavra
        saida.append(s)
        despido = s.strip()
        if despido:
            ultimo = despido[-1]
            m = re.search(r"[A-Za-z_$][\w$]*$", despido)
            palavra = m.group(0) if m and despido.endswith(m.group(0)) else ""

    while i < n:
        c = texto[i]

        # fim de ${ ... } dentro de template: volta para o template
        if c == "}" and pilha_template and pilha_template[-1] == 0:
            pilha_template.pop()
            j = _fim_template(texto, i + 1)
            escrever(texto[i:j])
            if j > 0 and texto[j - 1 : j + 1] == "${":
                pass
            i = j
            if texto[i - 2 : i] == "${":
                pilha_template.append(0)
            continue
        if pilha_template:
            if c == "{":
                pilha_template[-1] += 1
            elif c == "}":
                pilha_template[-1] -= 1

        if c in "\"'":
            j = _fim_string(texto, i, c)
            escrever(texto[i:j])
            i = j
            continue
        if c == "`":
            j = _fim_template(texto, i + 1)
            escrever(texto[i:j])
            i = j
            if texto[i - 2 : i] == "${":
                pilha_template.append(0)
            continue
        if c == "/" and i + 1 < n and texto[i + 1] == "/":
            while i < n and texto[i] != "\n":
                i += 1
            continue
        if c == "/" and i + 1 < n and texto[i + 1] == "*":
            fim = texto.find("*/", i + 2)
            i = n if fim < 0 else fim + 2
            # um comentário entre duas palavras não pode colá-las
            saida.append(" ")
            continue
        if c == "/" and (ultimo == "" or ultimo in _ANTES_DE_REGEX or palavra in _PALAVRAS_ANTES_DE_REGEX):
            j = _fim_regex(texto, i)
            escrever(texto[i:j])
            i = j
            continue

        escrever(c)
        i += 1

    # Espaço: uma linha por linha, sem recuo, sem linha vazia, e sem espaço
    # repetido fora de string. As strings já estão intactas no texto; só se
    # mexe no que está fora delas.
    linhas = []
    for linha in "".join(saida).split("\n"):
        enxuta = _colapsar_espacos(linha).strip()
        if enxuta:
            linhas.append(enxuta)
    return "\n".join(linhas) + "\n"


def _fim_string(texto, i, aspa):
    j = i + 1
    while j < len(texto):
        if texto[j] == "\\":
            j += 2
            continue
        if texto[j] == aspa or texto[j] == "\n":
            return j + 1
        j += 1
    return j


def _fim_template(texto, j):
    """Do caractere depois da crase (ou do }) até a crase final ou um ${."""
    while j < len(texto):
        if texto[j] == "\\":
            j += 2
            continue
        if texto[j] == "`":
            return j + 1
        if texto[j] == "$" and j + 1 < len(texto) and texto[j + 1] == "{":
            return j + 2
        j += 1
    return j


def _fim_regex(texto, i):
    j = i + 1
    classe = False
    while j < len(texto):
        c = texto[j]
        if c == "\\":
            j += 2
            continue
        if c == "\n":
            return j
        if c == "[":
            classe = True
        elif c == "]":
            classe = False
        elif c == "/" and not classe:
            j += 1
            while j < len(texto) and texto[j].isalpha():
                j += 1
            return j
        j += 1
    return j


def _colapsar_espacos(linha):
    """Junta espaços repetidos, mas só fora de string, template e regex simples."""
    partes = re.split(r"""("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`)""", linha)
    for k in range(0, len(partes), 2):
        partes[k] = re.sub(r"[ \t]+", " ", partes[k])
    return "".join(partes)


def css(texto: str) -> str:
    """Tira comentários e espaço sobrando. Não toca em string nem nos dois-pontos."""
    partes = re.split(r"""("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')""", texto)
    for k in range(0, len(partes), 2):
        p = re.sub(r"/\*.*?\*/", "", partes[k], flags=re.S)
        p = re.sub(r"\s+", " ", p)
        # Em volta de chaves, ponto e vírgula e vírgula o espaço nunca importa.
        # Em volta de dois-pontos importa (".a :hover" não é ".a:hover").
        p = re.sub(r"\s*([{};,])\s*", r"\1", p)
        p = p.replace(";}", "}")
        partes[k] = p
    return "".join(partes).strip() + "\n"


# ----------------------------------------------------------------- conferir

def js_valido(texto: str):
    """True/False se o Node disse; None se não há Node para perguntar."""
    node = shutil.which("node")
    if not node:
        return None
    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False, encoding="utf-8") as f:
        f.write(texto)
        caminho = f.name
    r = subprocess.run(
        [node, "-e", "new Function(require('fs').readFileSync(process.argv[1], 'utf8'))", caminho],
        capture_output=True, text=True,
    )
    Path(caminho).unlink(missing_ok=True)
    return r.returncode == 0


def css_valido(original: str, minificado: str) -> bool:
    """Mesmo número de blocos antes e depois: nenhuma chave comida."""
    tira = lambda t: re.sub(r"/\*.*?\*/", "", t, flags=re.S)
    return tira(original).count("{") == minificado.count("{") and tira(original).count("}") == minificado.count("}")


def main() -> int:
    base = Path(__file__).resolve().parent / "leticia" / "public"
    destino = None
    if "--saida" in sys.argv:
        destino = Path(sys.argv[sys.argv.index("--saida") + 1])
        destino.mkdir(parents=True, exist_ok=True)
    for nome in ("leticia.js", "leticia.css"):
        original = (base / nome).read_text(encoding="utf-8")
        mini = js(original) if nome.endswith(".js") else css(original)
        ok = js_valido(mini) if nome.endswith(".js") else css_valido(original, mini)
        print(f"{nome}: {len(original.encode()) // 1024} KB -> {len(mini.encode()) // 1024} KB - {'ok' if ok else ('sem node' if ok is None else 'QUEBROU')}")
        if destino:
            (destino / nome).write_text(mini, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
