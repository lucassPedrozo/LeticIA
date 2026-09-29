# LetícIA

Plugin de WordPress que transforma o briefing de criação de sites em uma conversa: uma pergunta por vez, com o texto conduzido pelo Gemini e o roteiro decidido pelo servidor. A pessoa responde escrevendo ou falando, envia a logo do celular, pode parar no meio e voltar depois por um link, e a equipe recebe tudo por e-mail com os arquivos anexados. Entra em qualquer página pelo shortcode `[leticia]`.

## Visão geral

O produto atende clientes leigos e sem tempo, e a premissa que organiza o código todo é que **o briefing precisa chegar mesmo quando a IA não responde**. Por isso o roteiro — quais são os 15 campos, em que ordem, o que é obrigatório, o que vale como resposta — vive no PHP, e o modelo só escreve o texto que aparece na tela. Sem chave de API, com cota estourada ou com a API fora do ar, a LetícIA cai em modo degradado: as mesmas 15 perguntas, a mesma validação, o mesmo envio, sem comentário nenhum. Nenhum caminho do código deixa de coletar e entregar o briefing.

A validação é toda do servidor: o navegador não decide se um telefone é válido, se um campo pode avançar ou qual é a próxima pergunta. O JavaScript desenha o que o servidor mandou. Os links que o cliente recebe — continuar depois, mandar a logo depois — são tokens assinados com HMAC, com propósito e prazo próprios: o link de anexo não abre o briefing, e o de retomada não abre a pasta de arquivos.

O projeto roda inteiro fora do WordPress durante o desenvolvimento. Há um WordPress de mentira (`local/wp-falso.php`) que implementa as funções usadas, um servidor local que serve a tela, o painel e as páginas de e-mail, e uma versão de terminal do briefing completo. A suíte de testes tem 411 casos e não precisa de banco, de rede nem de PHPUnit.

## Funcionalidades

- Conversa de 15 campos em 3 seções, com pergunta, reação à resposta anterior e repergunta quando a resposta é curta demais para virar site.
- Modo degradado completo: sem modelo, o briefing continua sendo coletado, validado e entregue.
- Resposta por voz gravada no navegador, transcrita pelo modelo e sempre confirmada por escrito antes de valer.
- Rascunho do texto do negócio escrito pelo modelo a partir das respostas, para a pessoa aceitar, ajustar ou recusar.
- Lista de serviços sugerida pelo modelo, montada com o que já foi dito, em vez de uma caixa de texto em branco.
- Upload em pedaços com remontagem no servidor, checagem do tipo real do arquivo e opção de mandar a logo depois, por link.
- Link de "continuar depois" enviado por WhatsApp ou para o próprio e-mail do cliente, e lembrete automático de quem parou no meio.
- Shortcode `[leticia_links]` para a equipe gerar links já preenchidos com o que o vendedor sabe, sem nenhum dado do cliente na URL.
- Entrega por e-mail em HTML simples, com os arquivos anexados, divisão automática em vários e-mails quando o anexo passa do teto e fila de retentativa.
- Painel administrativo com os briefings, o funil de abandono por campo, o tempo real gasto em cada pergunta, o aparelho usado e o uso de cada recurso.
- Disjuntor de custo: teto diário de chamadas, limite por IP e por sessão, pausa automática do modelo que falha e modelo de reserva.
- Validação de formato no servidor para telefone, e-mail, domínio, CEP e redes sociais, com mensagem escrita para quem não é técnico.
- Acessibilidade e movimento contido: tudo navegável por teclado, e as animações respeitam `prefers-reduced-motion`.

## Estrutura do projeto

```text
.
|-- assets/
|   `-- leticia/
|       |-- img1.png
|       |-- img2.png
|       |-- img3.png
|       `-- img4.png
|-- docs/
|   `-- GUIA-TECNICO.md
|-- leticia/
|   |-- admin/
|   |   |-- class-leticia-admin.php
|   |   |-- class-leticia-painel.php
|   |   `-- leticia-admin.css
|   |-- conhecimento/
|   |   `-- campos.md
|   |-- includes/
|   |   |-- class-leticia-anexo.php
|   |   |-- class-leticia-armazem-json.php
|   |   |-- class-leticia-armazem-wpdb.php
|   |   |-- class-leticia-arquivos.php
|   |   |-- class-leticia-campos.php
|   |   |-- class-leticia-config.php
|   |   |-- class-leticia-entrega.php
|   |   |-- class-leticia-gemini.php
|   |   |-- class-leticia-limites.php
|   |   |-- class-leticia-links.php
|   |   |-- class-leticia-prompt.php
|   |   |-- class-leticia-rascunho.php
|   |   |-- class-leticia-registro.php
|   |   |-- class-leticia-rest.php
|   |   |-- class-leticia-retomada.php
|   |   |-- class-leticia-roteiro.php
|   |   |-- class-leticia-tela.php
|   |   |-- class-leticia-trava.php
|   |   |-- class-leticia-validacao.php
|   |   `-- class-leticia-voz.php
|   |-- public/
|   |   |-- leticia.css
|   |   |-- leticia-links.css
|   |   `-- leticia.js
|   |-- tests/
|   |   |-- casos-*.php
|   |   |-- rodar.php
|   |   |-- stubs-wp.php
|   |   `-- wpdb-falso.php
|   |-- leticia.php
|   `-- uninstall.php
|-- local/
|   |-- fumaca.js
|   |-- servidor.php
|   `-- wp-falso.php
|-- prototipo/
|   `-- leticia-prototipo.html
|-- .env.example
|-- briefing.php
|-- empacotar.py
|-- minificar.py
`-- README.md
```

## Como executar

Requisitos: PHP 7.4 ou superior para rodar e testar, Python 3 para empacotar, e uma chave da API do Gemini para ver a conversa conduzida pelo modelo. Sem chave, tudo funciona em modo degradado — e vale rodar assim de propósito de vez em quando.

Para rodar a suíte, que não precisa de nada instalado:

```bash
php leticia/tests/rodar.php
```

Para subir a tela, o painel e as páginas de e-mail sem WordPress nenhum:

```bash
php -S localhost:8765 local/servidor.php
```

Depois abra `http://localhost:8765/` para o briefing, `/links` para a página da equipe, `/emails` para o que teria sido enviado e `/wp-admin/admin.php?page=leticia` para o painel. A chave sai do `.env`, criado a partir do `.env.example`, que nunca é versionado.

Com o servidor no ar, `http://localhost:8765/?fumaca=1` percorre o briefing inteiro sozinho e acusa erro de JavaScript, sobra de tela e salto de layout. Com `?fumaca=1&min=1`, testa os arquivos minificados que vão no pacote.

Para rodar o briefing completo no terminal, sem navegador:

```bash
php briefing.php
```

Para gerar o `.zip` que se instala no WordPress:

```bash
python empacotar.py
```

O empacotamento deixa de fora os testes e o ambiente local, e leva o JS e o CSS minificados — o JS só entra minificado se passar na checagem de sintaxe do Node.

A arquitetura, as decisões de cada módulo, o formato do prompt, os limites de custo e o que ainda falta estão em [`docs/GUIA-TECNICO.md`](docs/GUIA-TECNICO.md).

## Stacks

- PHP 7.4
- WordPress (plugin, REST API, shortcode)
- JavaScript (sem framework)
- CSS (sem framework)
- API do Gemini
- MediaRecorder e Web Audio API
- Web Animations API
- Python 3
- Markdown como base de conteúdo

## Hook para portfólio

**Categoria do projeto:** Produto interno / IA aplicada

**Breve descrição:** Plugin de WordPress que substitui o formulário de briefing por uma conversa conduzida por IA, com validação no servidor, resposta por voz, upload de arquivos e entrega garantida mesmo com o modelo fora do ar.

**Contexto:** O briefing é a etapa que trava a entrega de um site em 72 horas. O formulário tradicional, com 15 campos de uma vez, era abandonado no meio ou devolvido com respostas de duas palavras, que não dão para escrever página nenhuma. O projeto nasceu para tratar o briefing como conversa: uma pergunta por vez, linguagem de quem não é técnico, e a possibilidade de responder falando, parar e voltar depois.

**Resultado:** Um plugin em produção que coleta os 15 campos conversando, cobra desenvolvimento quando a resposta é genérica demais, aceita voz e arquivos, guarda o andamento a cada resposta e entrega o briefing por e-mail com os anexos — e que continua fazendo tudo isso, sem o modelo, quando a API do Gemini não responde.

**Destaques:**

- Separação estrita entre roteiro e texto: o servidor decide o que perguntar e o que aceita como resposta, e o modelo só escreve a frase.
- Modo degradado tratado como requisito, e não como falha: sem chave, sem cota ou com a API fora do ar, o briefing é coletado e entregue igual.
- Links assinados com HMAC por propósito e prazo, de forma que o link de anexo não abra o briefing e o de retomada não abra os arquivos.
- Página da equipe que gera links já preenchidos sem colocar nenhum dado do cliente na URL: o rascunho nasce no servidor e o link leva só a chave assinada.
- Controle de custo com teto diário, limite por IP e por sessão, pausa do modelo que falha e modelo de reserva.
- Medição do tempo real gasto em cada pergunta, do aparelho e do uso de cada recurso, para decidir o que cortar com dado e não com palpite.
- Resposta por voz sempre confirmada por escrito antes de virar resposta, porque transcrição errada em nome ou e-mail custa caro.
- Teste de fumaça que percorre o briefing inteiro no navegador e acusa erro de JavaScript e salto de layout, também na versão minificada que vai no pacote.
- 411 testes automatizados que rodam com um comando, sem banco, sem rede e sem dependência.

**Stacks:**

- PHP
- WordPress
- JavaScript
- CSS
- API do Gemini
- Python

**Imagens:**

- `assets/leticia/img1.png` — a abertura, com o aviso de IA e o tempo estimado.
- `assets/leticia/img2.png` — uma pergunta no meio da conversa, com a reação à resposta anterior, o histórico recolhido e o "continuar depois".
- `assets/leticia/img3.png` — a página da equipe, que gera o link já preenchido e acompanha a situação de cada um.
- `assets/leticia/img4.png` — o painel: o funil de abandono por campo e o tempo real gasto em cada pergunta.

As capturas foram geradas no ambiente local, com dados fictícios (a cliente Marina, da Padaria Aurora), sem nenhuma chave, nenhum endereço da equipe e nenhum briefing real.
