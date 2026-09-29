/**
 * LetícIA — a tela.
 *
 * Este arquivo **não tem roteiro**. Ele não sabe quantos campos existem, qual
 * vem depois, o que é obrigatório nem quando o briefing acabou: tudo isso vem
 * pronto do servidor, no mesmo formato em toda resposta, e aqui só se desenha.
 *
 * É o que garante que validação, ordem e progresso não possam ser contornados
 * pelo console do navegador — e é o que faz voltar, pular, responder e retomar
 * deixarem a tela exatamente no mesmo tipo de estado.
 *
 * O que mora aqui é o ritmo: a revelação palavra por palavra, os pontinhos, a
 * troca de turno e o fatiamento dos arquivos.
 */
(function () {
  'use strict';

  var CFG = window.LETICIA || {};
  var CHAVE_TOKEN = 'lt-token-v1';

  /* =======================================================================
     ÍCONES — oito, traço 1,5, sem biblioteca e sem CDN.
     Um CDN entregaria o IP de cada cliente da JoinVix a um terceiro.
     ======================================================================= */
  var ICO = {
    enviar:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M12 5l7 7-7 7"/></svg>',
    check:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"/></svg>',
    remover: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    alerta:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9L2.9 17.1A2 2 0 004.6 20h14.8a2 2 0 001.7-2.9L13.7 3.9a2 2 0 00-3.4 0z"/></svg>',
    arquivo: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5"/><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z"/></svg>',
    microfone: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0013 0M12 17.5V21"/></svg>',
    parar:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="3"/></svg>',
    selo:    '<svg class="lt-selo" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="24" cy="24" r="20"/><path d="M15 24.5l6.5 6.5L33 19"/></svg>'
  };

  /* =======================================================================
     UTILITÁRIOS
     ======================================================================= */
  function $(id) { return document.getElementById(id); }

  function criar(tag, classe, html) {
    var el = document.createElement(tag);
    if (classe) { el.className = classe; }
    if (html !== undefined) { el.innerHTML = html; }
    return el;
  }
  function espera(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function reduzido() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
  function escapar(t) {
    return (t || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function tamanhoLegivel(bytes) {
    if (bytes < 1024) { return bytes + ' B'; }
    if (bytes < 1048576) { return Math.round(bytes / 1024) + ' KB'; }
    return (bytes / 1048576).toFixed(bytes > 10485760 ? 0 : 1).replace('.', ',') + ' MB';
  }
  function anunciar(texto) {
    var alvo = $('lt-anuncio');
    if (alvo) { alvo.textContent = texto; }
  }

  /** Quanto cada palavra leva para assentar. Casa com `lt-palavra` no CSS. */
  var PALAVRA_MS = 380;

  /**
   * Revelação palavra por palavra — por esmaecimento, não por digitação.
   *
   * A versão anterior acrescentava uma palavra de cada vez ao texto. Duas
   * coisas faziam aquilo parecer cru: cada palavra aparecia seca, de uma vez;
   * e, com o texto centralizado, a linha inteira se recentralizava e quebrava
   * em outro ponto a cada palavra nova — o texto "pulava" enquanto nascia.
   *
   * Agora o texto entra inteiro e invisível, com as quebras de linha já
   * definitivas, e as palavras acendem em cascata: sobem um pouco e ganham
   * opacidade, com um respiro depois de vírgula e de ponto. Nada se mexe no
   * layout — só opacity e transform, que não refazem a página.
   *
   * Teto de ~0,9 s para a cascata inteira: encenação longa demais deixa de
   * parecer humana e passa a parecer lenta. A promessa resolve quando a
   * última palavra começou a aparecer, para o que vem depois não esperar o
   * esmaecimento terminar — a cena flui em vez de andar em degraus.
   *
   * O leitor de tela recebe o texto completo **uma vez**, pelo aria-live —
   * anunciar palavra a palavra transformaria a revelação numa metralhadora.
   */
  function revelar(el, texto) {
    anunciar(texto);
    texto = texto || '';
    if (reduzido()) { el.textContent = texto; return Promise.resolve(); }

    var partes = texto.split(/(\s+)/).filter(function (p) { return p !== ''; });
    var palavras = partes.filter(function (p) { return !/^\s+$/.test(p); });

    // O ritmo: um passo por palavra, respiro na pontuação. Texto curto anda
    // mais devagar que texto longo, para a pergunta de quatro palavras não
    // piscar e a fala de quarenta não arrastar.
    var passo = Math.max(22, Math.min(55, 520 / Math.max(1, palavras.length)));
    var respiroFrase = 150;
    var respiroVirgula = 60;
    var total = 0;
    palavras.forEach(function (p) {
      total += passo + (/[.!?]$/.test(p) ? respiroFrase : (/[,;:]$/.test(p) ? respiroVirgula : 0));
    });
    var escala = total > 900 ? 900 / total : 1;

    // As palavras animadas ficam escondidas do leitor de tela, e o texto
    // inteiro vai ao lado, invisível: quem navega pelo título ouve a frase
    // de uma vez, e não palavra por palavra.
    var inteiro = criar('span', 'lt-invisivel');
    inteiro.textContent = texto;
    var fragmento = criar('span');
    fragmento.setAttribute('aria-hidden', 'true');
    var atraso = 0;
    partes.forEach(function (parte) {
      if (/^\s+$/.test(parte)) {
        fragmento.appendChild(document.createTextNode(parte));
        return;
      }
      var palavra = document.createElement('span');
      palavra.className = 'lt-palavra';
      palavra.textContent = parte;
      palavra.style.animationDelay = Math.round(atraso) + 'ms';
      fragmento.appendChild(palavra);
      atraso += (passo + (/[.!?]$/.test(parte) ? respiroFrase : (/[,;:]$/.test(parte) ? respiroVirgula : 0))) * escala;
    });

    semPulo(function () {
      el.textContent = '';
      el.appendChild(inteiro);
      el.appendChild(fragmento);
    });

    var ultima = Math.max(0, atraso - passo * escala);
    return espera(Math.round(ultima + PALAVRA_MS * 0.45));
  }

  /* =======================================================================
     O SERVIDOR
     ======================================================================= */
  var Api = {
    token: null,
    // O link de "mandar a logo depois" fala com o servidor por outra chave, e
    // ela não vai para o localStorage: não é rascunho, e guardá-la faria a
    // próxima visita à página tentar retomar um briefing já enviado.
    anexo: null,

    guardar: function (token) {
      if (this.anexo) { return; }
      this.token = token;
      try {
        // localStorage, e não sessionStorage: aqui retomar dias depois é o
        // comportamento desejado. O computador compartilhado tem a saída
        // explícita do "não é você?".
        localStorage.setItem(CHAVE_TOKEN, token);
      } catch (e) { /* aba privada: a sessão dura o que a aba durar */ }
    },

    lembrar: function () {
      try { return localStorage.getItem(CHAVE_TOKEN) || ''; } catch (e) { return ''; }
    },

    esquecer: function () {
      this.token = null;
      try { localStorage.removeItem(CHAVE_TOKEN); } catch (e) { /* segue */ }
    },

    pedir: function (rota, dados) {
      var eu = this;
      var corpo = Object.assign(
        this.anexo ? { anexo: this.anexo } : { token: this.token || '', pagina: CFG.pagina || '' },
        dados || {}
      );

      return fetch(CFG.rotas + rota, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
        body: JSON.stringify(corpo)
      }).then(function (r) {
        return r.json().then(function (json) {
          if (!r.ok) {
            var erro = new Error((json && json.message) || 'não consegui falar com o servidor');
            erro.codigo = json && json.code;
            throw erro;
          }
          if (json && json.token) { eu.guardar(json.token); }
          return json;
        });
      });
    },

    /** O pedaço vai no corpo cru; o resto vai na URL. */
    pedaco: function (id, indice, fatia) {
      var url = CFG.rotas + '/arquivo/pedaco?' +
        (this.anexo ? 'anexo=' + encodeURIComponent(this.anexo) : 'token=' + encodeURIComponent(this.token)) +
        '&id=' + encodeURIComponent(id) + '&indice=' + indice;

      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream', 'X-WP-Nonce': CFG.nonce || '' },
        body: fatia
      }).then(function (r) {
        if (!r.ok) { throw new Error('um pedaço não subiu'); }
        return r.json();
      });
    },

    /**
     * A gravação, no corpo cru — como o pedaço de arquivo. Em base64 dentro
     * de JSON, seria um terço a mais subindo pelo 4G.
     */
    voz: function (campo, blob) {
      var eu = this;
      var url = CFG.rotas + '/voz?token=' + encodeURIComponent(this.token || '') +
        '&campo=' + encodeURIComponent(campo);

      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': blob.type || 'application/octet-stream', 'X-WP-Nonce': CFG.nonce || '' },
        body: blob
      }).then(function (r) {
        return r.json().catch(function () { return null; }).then(function (json) {
          if (!r.ok || !json || !json.voz) {
            var erro = new Error((json && json.message) || 'Não consegui entender o áudio agora. Pode escrever aqui?');
            erro.codigo = json && json.code;
            throw erro;
          }
          if (json.token) { eu.guardar(json.token); }
          return json.voz;
        });
      });
    }
  };

  /* =======================================================================
     A VOZ — gravar no navegador
     O servidor diz se há quem ouça (t.voz); quem diz se dá para gravar é o
     navegador. Faltando qualquer um dos dois, o microfone não aparece e o
     campo é o de sempre. Nenhum erro daqui trava o briefing.
     ======================================================================= */
  var Voz = {
    // Permissão negada ou aparelho sem microfone: não se oferece de novo
    // nesta visita. Um botão que já se sabe que não funciona é pior que
    // botão nenhum.
    bloqueada: false,
    atual: null,

    disponivel: function () {
      return !this.bloqueada &&
        window.isSecureContext !== false &&
        !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    },

    /**
     * O formato, na ordem do que o Gemini lê sem conversão: Opus em WebM
     * (Chrome, Android, Edge), Opus em Ogg (Firefox), AAC em MP4 (Safari).
     */
    formato: function () {
      var tipos = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm'];
      if (!MediaRecorder.isTypeSupported) { return ''; }
      for (var i = 0; i < tipos.length; i++) {
        if (MediaRecorder.isTypeSupported(tipos[i])) { return tipos[i]; }
      }
      return '';
    },

    cancelar: function () {
      if (this.atual) { this.atual.cancelar(); }
    },

    /**
     * Grava até `segundos`.
     *
     * Mono, com a redução de ruído e o ganho automático do próprio navegador
     * — o que mais ajuda com gravação de celular em lugar barulhento — e Opus
     * a 24 kbps: um minuto fica perto de 180 KB.
     *
     * @param opcoes { segundos, aoAbrir(), aoMedir(nivel 0..1), aoContar(s) }
     * @return { pronto: Promise<{ blob, segundos, comVoz }>, parar(), cancelar() }
     */
    gravar: function (opcoes) {
      var eu = this;
      var fluxo = null, gravador = null, contexto = null, relogio = null, medidor = null;
      var pedacos = [], comeco = 0, cancelado = false, pico = 0, algumSinal = false, soltou = false;
      var resolver, rejeitar;
      var pronto = new Promise(function (ok, falha) { resolver = ok; rejeitar = falha; });

      function erroCancelado() {
        var e = new Error('cancelado');
        e.cancelado = true;
        return e;
      }

      // O microfone fecha em todo caminho de saída: a bolinha vermelha do
      // navegador acesa depois de gravar é o jeito mais rápido de perder a
      // confiança de alguém.
      function soltar() {
        if (soltou) { return; }
        soltou = true;
        clearInterval(relogio);
        clearInterval(medidor);
        if (fluxo) { fluxo.getTracks().forEach(function (trilha) { trilha.stop(); }); }
        if (contexto && contexto.close) { contexto.close().catch(function () { /* já fechado */ }); }
        if (eu.atual === controle) { eu.atual = null; }
      }

      var controle = {
        pronto: pronto,
        parar: function () {
          if (gravador && gravador.state !== 'inactive') { gravador.stop(); }
        },
        cancelar: function () {
          cancelado = true;
          if (gravador && gravador.state !== 'inactive') { gravador.stop(); return; }
          soltar();
          rejeitar(erroCancelado());
        }
      };
      eu.atual = controle;

      navigator.mediaDevices.getUserMedia({
        audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true }
      }).then(function (s) {
        fluxo = s;
        if (cancelado) { soltou = false; soltar(); return rejeitar(erroCancelado()); }

        var tipo = eu.formato();
        var config = { audioBitsPerSecond: 24000 };
        if (tipo) { config.mimeType = tipo; }
        try { gravador = new MediaRecorder(s, config); } catch (e) { gravador = new MediaRecorder(s); }

        gravador.ondataavailable = function (ev) { if (ev.data && ev.data.size) { pedacos.push(ev.data); } };
        gravador.onerror = function (ev) { soltar(); rejeitar(ev.error || new Error('a gravação parou sozinha')); };
        gravador.onstop = function () {
          var segundos = (Date.now() - comeco) / 1000;
          soltar();
          if (cancelado) { return rejeitar(erroCancelado()); }
          resolver({
            blob: new Blob(pedacos, { type: gravador.mimeType || tipo || 'audio/webm' }),
            segundos: segundos,
            // Sem medidor (ou com medidor mudo, como o Safari às vezes deixa),
            // não dá para afirmar silêncio — então a gravação vai.
            comVoz: !algumSinal || pico > 0.02
          });
        };

        // O medidor: desenha as ondas e descobre a gravação sem voz nenhuma,
        // que não vale uma chamada ao modelo.
        var AC = window.AudioContext || window.webkitAudioContext;
        if (AC) {
          try {
            contexto = new AC();
            if (contexto.resume) { contexto.resume(); }
            var analisador = contexto.createAnalyser();
            analisador.fftSize = 1024;
            contexto.createMediaStreamSource(s).connect(analisador);
            var amostras = new Uint8Array(analisador.fftSize);
            // Intervalo, e não requestAnimationFrame: o quadro para quando a
            // aba perde o foco, e a medição tem que continuar.
            medidor = setInterval(function () {
              analisador.getByteTimeDomainData(amostras);
              var soma = 0;
              for (var i = 0; i < amostras.length; i++) {
                var v = (amostras[i] - 128) / 128;
                soma += v * v;
              }
              var rms = Math.sqrt(soma / amostras.length);
              if (rms > 0) { algumSinal = true; }
              if (rms > pico) { pico = rms; }
              if (opcoes.aoMedir) { opcoes.aoMedir(Math.min(1, rms * 7)); }
            }, 80);
          } catch (e) { /* sem medidor: grava do mesmo jeito */ }
        }

        gravador.start(1000);
        comeco = Date.now();
        if (opcoes.aoAbrir) { opcoes.aoAbrir(); }

        relogio = setInterval(function () {
          var passados = (Date.now() - comeco) / 1000;
          if (opcoes.aoContar) { opcoes.aoContar(passados); }
          if (passados >= opcoes.segundos) { controle.parar(); }
        }, 250);
      }).catch(function (e) {
        soltar();
        rejeitar(e);
      });

      return controle;
    }
  };

  /* =======================================================================
     A TELA
     ======================================================================= */
  var raiz, palco, turno, area, perguntaEl, detalheEl, notaEl, enunciado;
  var atual = null;          // o último estado que o servidor devolveu
  var ocupado = false;
  var historicoAberto = false;
  var gaveta = [];           // arquivos já enviados, campo ainda aberto

  function prepararPalco() {
    Voz.cancelar();
    palco = $('lt-palco');
    palco.innerHTML = '';
    // A tela nova se monta sem FLIP até o fim desta volta do laço de eventos
    // — o que for montado em seguida, inclusive em promessa, ainda não foi
    // desenhado.
    montando = true;
    setTimeout(function () { montando = false; }, 0);
    turno = criar('div', 'lt-turno lt-entra');
    // A pergunta e a explicação dela andam juntas: o título grande é só a
    // pergunta, e o "é opcional", o exemplo e o porquê vêm embaixo, menores.
    // Juntos no mesmo título, viravam um parágrafo inteiro em letra de 30px.
    enunciado = criar('div', 'lt-enunciado');
    perguntaEl = criar('h2', 'lt-pergunta');
    detalheEl = criar('p', 'lt-detalhe');
    detalheEl.hidden = true;
    notaEl = criar('p', 'lt-nota');
    notaEl.hidden = true;
    enunciado.appendChild(perguntaEl);
    enunciado.appendChild(detalheEl);
    enunciado.appendChild(notaEl);
    area = criar('div', 'lt-resposta');
    turno.appendChild(enunciado);
    palco.appendChild(turno);
    palco.appendChild(area);
  }

  function acimaDaPergunta(el) { semPulo(function () { turno.insertBefore(el, enunciado); }); }

  /* -------------------------------------------------------- sem pulo --- */

  /*
   * A conversa é centralizada na vertical. Toda mudança de altura — o balão
   * que entra, os pontinhos que saem, a barra de retomada dispensada, o
   * detalhe que aparece depois da pergunta — recentraliza o bloco inteiro, e
   * sem cuidado ele dava saltos: quatro ou cinco por resposta.
   *
   * O remédio é o FLIP: anota onde cada bloco estava, faz a mudança, e anima
   * só o transform, da posição velha até a nova. Nada de animar altura ou
   * margem — é transform, como o resto das animações daqui. Bloco que acabou
   * de entrar (ou que ainda está entrando) não participa: a entrada dele já
   * é a animação.
   */
  function blocosVisiveis() {
    return Array.prototype.slice.call(raiz.querySelectorAll(
      '#lt-retomada > *, .lt-turno > *, .lt-resposta, .lt-aviso-ia summary, .lt-rodape'
    ));
  }

  // Montando uma tela nova: nada dela foi desenhado ainda, então não há de
  // onde deslizar. Sem isto, o FLIP animava a pergunta de uma posição que
  // ninguém chegou a ver — um deslize de cem pixels a cada pergunta.
  var montando = false;

  function semPulo(mudar) {
    if (montando || reduzido() || !palco || !Element.prototype.animate) { return mudar(); }
    // O getBoundingClientRect() já inclui o deslize em andamento — até o que
    // acabou de ser criado: é a posição que a pessoa está vendo.
    var antes = blocosVisiveis().map(function (el) { return [el, el.getBoundingClientRect().top]; });
    var resultado = mudar();
    antes.forEach(function (par) {
      var el = par[0];
      if (!el.isConnected || el.hidden) { return; }
      // Um deslize em andamento é substituído: o novo parte de onde o bloco
      // está agora, visualmente, e não de onde o anterior começou.
      if (el.__ltFlip) { el.__ltFlip.cancel(); el.__ltFlip = null; }
      var dy = par[1] - el.getBoundingClientRect().top;
      if (Math.abs(dy) < 1) { return; }
      // A animação de entrada do próprio bloco ainda rodando: ela manda.
      var entrando = el.getAnimations && el.getAnimations().some(function (a) {
        return a.playState === 'running' && a.animationName;
      });
      if (entrando) { return; }
      var flip = el.animate(
        [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'none' }],
        { duration: 260, easing: 'cubic-bezier(.2,0,0,1)' }
      );
      el.__ltFlip = flip;
      flip.onfinish = function () { if (el.__ltFlip === flip) { el.__ltFlip = null; } };
    });
    return resultado;
  }

  /** O aviso que muda o campo para esta pessoa, numa nota discreta embaixo. */
  function mostrarNota(texto) {
    if (!texto) { notaEl.hidden = true; return; }
    semPulo(function () {
      notaEl.textContent = texto;
      notaEl.hidden = false;
      notaEl.classList.add('lt-entra-curto');
    });
  }

  /** O detalhe entra inteiro, depois da pergunta: ele se lê, não se acompanha. */
  function mostrarDetalhe(texto) {
    if (!texto) { detalheEl.hidden = true; return; }
    semPulo(function () {
      detalheEl.textContent = texto;
      detalheEl.hidden = false;
      detalheEl.classList.add('lt-entra-curto');
    });
  }

  function falar(texto, classe) {
    var p = criar('p', 'lt-fala' + (classe ? ' ' + classe : ''));
    acimaDaPergunta(p);
    return revelar(p, texto);
  }

  function pensar() {
    var box = criar('div', 'lt-pensando lt-entra-curto', '<i></i><i></i><i></i><span class="lt-pensando-rotulo">escrevendo…</span>');
    box.setAttribute('aria-hidden', 'true');
    acimaDaPergunta(box);
    anunciar('escrevendo');
    return function () { if (box.parentNode) { semPulo(function () { box.parentNode.removeChild(box); }); } };
  }

  function mostrarBalao(texto) {
    var b = criar('div', 'lt-balao lt-sobe');
    b.textContent = texto;
    acimaDaPergunta(b);
  }

  function marcarConferido() {
    var m = criar('p', 'lt-conferido', ICO.check + '<span>anotado</span>');
    acimaDaPergunta(m);
    setTimeout(function () {
      if (!m.parentNode) { return; }
      m.style.transition = 'opacity var(--lt-padrao) var(--lt-acelera)';
      m.style.opacity = '0';
      setTimeout(function () { if (m.parentNode) { m.parentNode.removeChild(m); } }, 240);
    }, 1200);
  }

  /* --------------------------------------------------------- progresso --- */

  /**
   * O peso de cada segmento: quantos campos a etapa tem, e o envio como um
   * passo a mais do último. É o que faz a barra não prometer 100% antes de a
   * equipe ter recebido qualquer coisa. Vem do servidor — quem decide em que
   * etapa cada campo mora é o PHP.
   */
  function pesosDaBarra(p) {
    var ps = (p && p.por_secao) || {};
    return [ps[1] || 5, ps[2] || 7, (ps[3] || 3) + 1];
  }

  function montarBarra(p) {
    var barra = $('lt-barra');
    if (barra.children.length) { return; }
    pesosDaBarra(p).forEach(function (peso) {
      var seg = criar('div', 'lt-segmento');
      seg.style.flex = peso + ' 1 0';
      seg.appendChild(criar('i'));
      barra.appendChild(seg);
    });
  }

  function atualizarProgresso(t) {
    var p = t.progresso;
    montarBarra(p);

    var totais = pesosDaBarra(p);
    var feitos = [0, 0, 0];
    var n = 0;

    Object.keys(t.respostas || {}).forEach(function () { n++; });

    // Quantos de cada seção: o servidor manda o mapa por seção.
    var porSecao = p.por_secao || {};
    var acumulado = n;
    [1, 2, 3].forEach(function (secao, i) {
      var cabem = porSecao[secao] || totais[i];
      feitos[i] = Math.max(0, Math.min(cabem, acumulado));
      acumulado -= feitos[i];
    });
    if (t.fase === 'fim') { feitos[2] = totais[2]; }

    Array.prototype.forEach.call($('lt-barra').children, function (seg, i) {
      seg.querySelector('i').style.width = Math.min(1, feitos[i] / totais[i]) * 100 + '%';
      seg.classList.toggle('lt-segmento--feito', feitos[i] >= totais[i]);
    });

    $('lt-barra').setAttribute('aria-valuenow', p.respondidos);

    // Tempo, e não contagem: "faltam uns 4 min" diz a quem está sem tempo o
    // que "7 de 15" não diz. A barra de três partes continua mostrando o
    // andamento, e o leitor de tela ouve o número pelo aria-valuenow.
    var texto;
    if (t.fase === 'conversa') {
      var min = p.minutos || 0;
      texto = 'Etapa ' + p.secao + ' de ' + p.secoes + ' · ' +
        (min > 1 ? 'faltam uns ' + min + ' min' : 'falta 1 min');
    } else {
      texto = t.fase === 'fim' ? 'Enviado' : 'Revisão';
    }
    var alvo = $('lt-contagem');
    if (alvo.textContent !== texto) {
      alvo.textContent = texto;
      if (!reduzido()) {
        alvo.classList.remove('lt-entra-curto');
        void alvo.offsetWidth;
        alvo.classList.add('lt-entra-curto');
      }
    }
  }

  /* --------------------------------------------------------- histórico --- */

  var saidaDoHistorico = 0;   // o fechamento em curso; abrir de novo o cancela

  function redesenharHistorico(t) {
    var alvo = $('lt-historico');
    var botao = $('lt-abrir');
    var estavaAberta = !alvo.hidden;

    var chaves = Object.keys(t.respostas || {});
    var atualChave = t.campo ? t.campo.chave : '';
    var linhas = [];

    chaves.forEach(function (chave) {
      if (chave === atualChave) { return; }
      var r = t.respostas[chave];
      var linha = criar('button', 'lt-passado');
      linha.type = 'button';
      linha.innerHTML = '<span class="lt-passado-texto"><b>' + escapar(r.rotulo) + '</b> · ' +
        escapar(resumoDaResposta(r) || '(pulado)') + '</span>' +
        '<span class="lt-passado-acao">corrigir</span>';
      linha.addEventListener('click', function () { historicoAberto = false; voltarPara(chave); });
      linhas.push(linha);
    });

    var quantos = linhas.length;
    botao.hidden = quantos === 0 || t.fase !== 'conversa';
    botao.textContent = quantos === 1
      ? (historicoAberto ? 'Esconder' : 'Ver') + ' a resposta anterior'
      : (historicoAberto ? 'Esconder' : 'Ver') + ' as ' + quantos + ' respostas anteriores';
    botao.setAttribute('aria-expanded', historicoAberto ? 'true' : 'false');

    var mostrar = historicoAberto && !botao.hidden;
    var vez = ++saidaDoHistorico;

    // Fechando: a lista some com um recolher curto, e só então é trocada.
    if (!mostrar && estavaAberta && !botao.hidden && !reduzido()) {
      alvo.classList.remove('lt-historico--abre');
      alvo.classList.add('lt-historico--fecha');
      setTimeout(function () {
        if (vez !== saidaDoHistorico) { return; }
        alvo.classList.remove('lt-historico--fecha');
        alvo.hidden = true;
        trocarLinhas(alvo, linhas);
      }, 150);
      return;
    }

    alvo.classList.remove('lt-historico--fecha');
    // Abrindo agora: a caixa desce inteira, de uma vez. Redesenho com ela já
    // aberta não anima de novo.
    var abrindo = mostrar && !estavaAberta;
    trocarLinhas(alvo, linhas);
    if (abrindo) {
      alvo.classList.remove('lt-historico--abre');
      void alvo.offsetWidth;
      alvo.classList.add('lt-historico--abre');
    }
    alvo.hidden = !mostrar;
  }

  // As linhas entram junto com a caixa, sem animação própria: cada uma
  // subindo até a opacidade cheia e depois voltando ao tom apagado parecia
  // o texto escurecendo e clareando, linha por linha.
  function trocarLinhas(alvo, linhas) {
    alvo.innerHTML = '';
    linhas.forEach(function (linha) { alvo.appendChild(linha); });
  }

  function resumoDaResposta(r) {
    // Pendente com valor é o domínio ("ainda não tenho"); sem valor, é arquivo
    // que vem depois. "Vai mandar depois" no domínio dizia outra coisa.
    if (r.pendente) { return r.valor || 'vai mandar depois'; }
    if (r.arquivos && r.arquivos.length) {
      return r.arquivos.map(function (a) { return a.nome; }).join(', ');
    }
    return r.valor || r.link || '';
  }

  /* =======================================================================
     APLICAR O ESTADO QUE VEIO DO SERVIDOR
     ======================================================================= */
  function aplicar(t) {
    atual = t;
    // Só a tela de pergunta espera a vez; qualquer outra foca na hora.
    aguardando = false;
    focoPendente = null;
    raiz.setAttribute('data-fase', t.proposta ? 'conversa' : t.fase);
    atualizarProgresso(t);
    mostrarAvisoIa(t);
    atualizarContinuar(t);

    // Antes da primeira pergunta, a apresentação — uma vez por visita.
    if (t.apresentacao && t.fase === 'conversa' && !t.proposta && !apresentacaoVista()) {
      return telaApresentacao(t);
    }

    // O rascunho vem antes da próxima pergunta — e antes da revisão, quando
    // o campo dele era o último de texto.
    if (t.proposta) { return telaProposta(t); }
    if (t.fase === 'revisao') { return telaRevisao(t); }
    if (t.fase === 'fim') { return telaFim(t); }

    prepararPalco();
    montando = true;
    redesenharHistorico(t);

    var fila = Promise.resolve();

    // Na primeira pergunta de cada etapa, uma etiqueta — e não um parágrafo.
    if (t.etapa) {
      acimaDaPergunta(criar('p', 'lt-etapa', 'Etapa ' + t.etapa.numero + ' de ' + t.etapa.total + ' · ' + escapar(t.etapa.nome)));
    }

    // Uma fala só acima da pergunta: a reação à resposta anterior ou, quando
    // não houver, a abertura da etapa. As duas empilhadas, mais o detalhe e o
    // aviso, eram texto demais para quem nunca preencheu um briefing.
    // A abertura da primeira etapa é a apresentação, que já teve tela própria.
    var fala = t.ponte || (t.etapa && t.etapa.numero > 1 ? t.etapa.abertura : '') || '';

    // Tudo no lugar antes de qualquer coisa aparecer: a fala, a pergunta, o
    // detalhe e a área entram já com o tamanho final, invisíveis, e vão
    // acendendo em sequência. Montados um depois do outro, cada peça nova
    // recentralizava o bloco, e a pergunta subia aos trancos — ou deslizava
    // cem pixels quando o campo aparecia.
    aguardando = true;
    focoPendente = null;
    var ponte = null;
    if (fala) {
      ponte = criar('p', 'lt-ponte lt-aguarda');
      ponte.textContent = fala;
      turno.insertBefore(ponte, enunciado);
    }
    perguntaEl.textContent = t.campo.pergunta || '';
    perguntaEl.classList.add('lt-aguarda');
    mostrarDetalhe(t.campo.detalhe);
    mostrarNota(t.aviso);
    [detalheEl, notaEl, area].forEach(function (el) { el.classList.add('lt-aguarda'); });
    try { desenharArea(t); } finally { montando = false; }

    if (ponte) {
      fila = fila.then(function () {
        ponte.classList.remove('lt-aguarda');
        return revelar(ponte, fala);
      }).then(function () { return espera(260); });
    }

    return fila
      .then(function () {
        perguntaEl.classList.remove('lt-aguarda');
        return revelar(perguntaEl, t.campo.pergunta);
      })
      .then(function () {
        if (atual !== t) { return; }
        aguardando = false;
        [detalheEl, notaEl, area].forEach(acender);
        soltarFoco();
        if (t.sugerir) { pedirSugestao(t); }
      });
  }

  /* ----------------------------------------------- o que espera a vez --- */

  var aguardando = false;
  var focoPendente = null;

  /** Tira o véu de um bloco que já estava no lugar, com a entrada de sempre. */
  function acender(el) {
    if (!el || !el.classList.contains('lt-aguarda')) { return; }
    el.classList.remove('lt-aguarda');
    // Oculto (a nota sem aviso, o detalhe vazio): só sai da espera.
    if (el.hidden) { return; }
    el.classList.remove('lt-entra-curto');
    void el.offsetWidth;
    el.classList.add('lt-entra-curto');
    // O aceno do microfone é para quando ele aparece, não enquanto espera.
    Array.prototype.forEach.call(el.querySelectorAll('.lt-microfone--chama'), function (m) {
      m.classList.remove('lt-microfone--chama');
      void m.offsetWidth;
      m.classList.add('lt-microfone--chama');
    });
  }

  /**
   * O foco do campo espera a pergunta aparecer. No celular, focar é abrir o
   * teclado — e abrir o teclado antes de a pessoa ler a pergunta empurra a
   * tela e esconde justamente o que ela ia ler.
   */
  function focarDepois(fn) {
    if (aguardando) { focoPendente = fn; return; }
    setTimeout(fn, 20);
  }

  function soltarFoco() {
    var fn = focoPendente;
    focoPendente = null;
    if (fn) { setTimeout(fn, 20); }
  }

  /**
   * A lista sugerida: ao chegar em serviços, ela propõe uma a partir do ramo.
   *
   * Pedida depois que a pergunta já está na tela — a pessoa lê enquanto o
   * modelo escreve. Se ela começar a digitar antes, a sugestão não passa por
   * cima do que está sendo escrito.
   */
  function pedirSugestao(t) {
    var aviso = criar('p', 'lt-sugerindo', 'Montando uma sugestão com base no que você contou…');
    area.appendChild(aviso);
    anunciar(aviso.textContent);

    Api.pedir('/sugerir', { campo: t.sugerir }).then(function (n) {
      if (aviso.parentNode) { aviso.remove(); }
      var entrada = $('lt-entrada');
      var mexeu = (entrada && entrada.value.trim()) || atual !== t || ocupado;
      if (n.proposta && !mexeu) { return aplicar(n); }
      if (!mexeu) { atual = n; }
    }).catch(function () {
      if (aviso.parentNode) { aviso.remove(); }
    });
  }

  /**
   * O aviso de que a conversa passa por IA. Por inteiro, ele aparece na
   * apresentação; depois fica recolhido embaixo da conversa, numa linha que
   * abre o texto. Some na revisão e no fim: ali quem fala disso é o aceite.
   */
  function mostrarAvisoIa(t) {
    var aviso = raiz.querySelector('#lt-aviso-ia');
    if (!aviso) { return; }
    var texto = (t && t.aviso_ia) || '';
    aviso.querySelector('p').textContent = texto;
    aviso.hidden = !texto || t.fase === 'revisao' || t.fase === 'fim';
  }

  /* =======================================================================
     A APRESENTAÇÃO
     ======================================================================= */
  var CHAVE_APRESENTACAO = 'leticia-apresentacao-vista';
  var apresentacaoNaMemoria = false;

  // Por aba, não para sempre: quem fecha e volta dias depois, sem ter
  // respondido nada, vê a apresentação de novo — e é bom que veja.
  function apresentacaoVista() {
    if (apresentacaoNaMemoria) { return true; }
    try { return sessionStorage.getItem(CHAVE_APRESENTACAO) === '1'; } catch (e) { return false; }
  }

  function marcarApresentacao() {
    apresentacaoNaMemoria = true;
    try { sessionStorage.setItem(CHAVE_APRESENTACAO, '1'); } catch (e) { /* segue na memória */ }
  }

  function telaApresentacao(t) {
    Voz.cancelar();
    palco = $('lt-palco');
    palco.innerHTML = '';
    raiz.setAttribute('data-fase', 'apresentacao');
    // Na apresentação não há o que guardar nem o que rever — mesmo quando a
    // equipe já preencheu os dados pelo link.
    esconderContinuar();
    $('lt-abrir').hidden = true;

    // O aviso vai por inteiro aqui dentro; o recolhido fica para depois.
    var recolhido = raiz.querySelector('#lt-aviso-ia');
    if (recolhido) { recolhido.hidden = true; }

    var bloco = criar('div', 'lt-apresentacao lt-entra');
    var simbolo = raiz.querySelector('.lt-marca .lt-simbolo');
    if (simbolo) { bloco.appendChild(simbolo.cloneNode(true)); }

    var titulo = criar('h2', 'lt-titulo');
    titulo.textContent = t.apresentacao.titulo || '';
    bloco.appendChild(titulo);
    if (t.apresentacao.detalhe) {
      bloco.appendChild(criar('p', 'lt-fala', escapar(t.apresentacao.detalhe)));
    }

    var comecar = criar('button', 'lt-btn-primario', 'Começar');
    comecar.type = 'button';
    comecar.addEventListener('click', function () {
      marcarApresentacao();
      trocarPara(function () { return aplicar(t); });
    });
    bloco.appendChild(comecar);

    if (t.aviso_ia) {
      bloco.appendChild(criar('p', 'lt-apresentacao-ia', escapar(t.aviso_ia)));
    }

    palco.appendChild(bloco);
    anunciar((t.apresentacao.titulo || '') + ' ' + (t.apresentacao.detalhe || ''));
    comecar.focus({ preventScroll: true });
  }

  /** Redesenha só a área, mantendo a pergunta e o que foi dito acima dela. */
  function reabrirArea(t, opcoes) {
    atual = t;
    semPulo(function () {
      area.style.minHeight = '';
      desenharArea(t, opcoes);
    });
  }

  /**
   * Esvazia a área sem que ela encolha: enquanto a resposta vai e volta, ela
   * guarda a altura que tinha. Sem isto, o campo sumia, o bloco centralizado
   * descia, e subia de novo quando a próxima pergunta chegava.
   */
  function esvaziarArea() {
    if (!area) { return; }
    area.style.minHeight = area.offsetHeight + 'px';
    area.innerHTML = '';
  }

  /* =======================================================================
     A ÁREA DE RESPOSTA
     ======================================================================= */
  function desenharArea(t, opcoes) {
    Voz.cancelar();
    opcoes = opcoes || {};
    area.innerHTML = '';
    area.classList.add('lt-entra-curto');

    var campo = t.campo;

    if (campo.tipo === 'escolha') { return areaEscolha(t); }
    if (campo.tipo === 'arquivo') { return areaArquivo(t); }
    if (t.sugestao && !opcoes.digitando) { return areaSugestao(t); }
    if (t.junto && !opcoes.digitando) { return areaJunto(t); }
    return areaTexto(t, opcoes);
  }

  function linhaDeAcoes(t, extras) {
    var linha = criar('div', 'lt-linha-abaixo');
    var acoes = criar('div', 'lt-acoes');
    var anteriores = Object.keys(t.respostas || {});

    if (anteriores.length) {
      var voltar = criar('button', 'lt-btn', 'Voltar');
      voltar.type = 'button';
      voltar.addEventListener('click', function () {
        voltarPara(anteriores[anteriores.length - 1]);
      });
      acoes.appendChild(voltar);
    }

    if (!t.campo.obrigatorio) {
      var pular = criar('button', 'lt-btn', 'Pular');
      pular.type = 'button';
      pular.addEventListener('click', function () { chamar('/pular', { campo: t.campo.chave }); });
      acoes.appendChild(pular);
    }

    (extras || []).forEach(function (b) { acoes.appendChild(b); });
    linha.appendChild(acoes);
    return linha;
  }

  /** O "por que perguntamos": vem junto com o campo e não custa requisição. */
  function painelPorque(campo, dentroDaPilula) {
    var painel = criar('div', 'lt-porque');
    painel.id = 'lt-porque';
    painel.hidden = true;
    painel.textContent = campo.ajuda;

    var botao = dentroDaPilula ? criar('button', 'lt-redondo', '?') : criar('button', 'lt-btn', 'Por que perguntamos?');
    botao.type = 'button';
    botao.setAttribute('aria-label', 'Por que perguntamos isso?');
    botao.setAttribute('aria-expanded', 'false');
    botao.setAttribute('aria-controls', 'lt-porque');

    botao.addEventListener('click', function () {
      var abrindo = painel.hidden;
      painel.hidden = !abrindo;
      botao.classList.toggle('lt-redondo--marcado', abrindo && dentroDaPilula);
      botao.setAttribute('aria-expanded', abrindo ? 'true' : 'false');
      if (abrindo) {
        painel.classList.remove('lt-entra-curto');
        void painel.offsetWidth;
        painel.classList.add('lt-entra-curto');
        anunciar(painel.textContent);
      }
    });

    return { botao: botao, painel: painel };
  }

  function areaTexto(t, opcoes) {
    var campo = t.campo;
    var caixa = criar('div', 'lt-campo');
    var ta = document.createElement('textarea');
    ta.id = 'lt-entrada';
    ta.rows = 1;
    ta.maxLength = 1200;
    ta.value = opcoes.valor || t.eco || '';
    ta.placeholder = campo.dica || '';
    ta.setAttribute('aria-label', campo.rotulo);
    ta.setAttribute('aria-describedby', 'lt-erro');

    var ajuda = painelPorque(campo, true);

    var botao = criar('button', 'lt-enviar', ICO.enviar);
    botao.type = 'button';
    botao.disabled = !ta.value.trim();
    botao.setAttribute('aria-label', 'Enviar resposta');

    // O microfone mora no lugar do enviar: com o campo vazio, falar; com
    // texto, mandar. Um botão redondo só, como no Gemini e no WhatsApp.
    var microfone = null;
    if (t.voz && campo.voz && Voz.disponivel()) {
      microfone = criar('button', 'lt-microfone', ICO.microfone);
      microfone.type = 'button';
      microfone.setAttribute('aria-label', 'Responder falando');
      microfone.addEventListener('click', function () {
        if (ocupado) { return; }
        areaGravando(t);
      });
    }

    caixa.appendChild(ajuda.botao);
    caixa.appendChild(ta);
    if (microfone) { caixa.appendChild(microfone); }
    caixa.appendChild(botao);
    area.appendChild(caixa);
    area.appendChild(ajuda.painel);

    var erro = criar('p', 'lt-erro');
    erro.id = 'lt-erro';
    erro.hidden = true;
    area.appendChild(erro);

    // O que aconteceu com a voz, quando ela não deu: dito uma vez, sem
    // tremer a pílula — não é erro de quem está respondendo.
    if (opcoes.aviso) {
      var aviso = criar('p', 'lt-aviso-voz');
      aviso.textContent = opcoes.aviso;
      area.appendChild(aviso);
      anunciar(opcoes.aviso);
    }

    // Nos campos de conteúdo, o microfone ganha uma frase e um aceno — leigo
    // conta melhor do que escreve, e o ícone sozinho passa despercebido.
    // As frases que falam do microfone somem enquanto há texto — aí o botão
    // no lugar dele é o de enviar. Somem sem sair do lugar: só a opacidade.
    var sobreMicrofone = [];
    if (microfone && campo.fala) {
      var dicaVoz = criar('p', 'lt-dica-voz', escapar(campo.fala));
      area.appendChild(dicaVoz);
      sobreMicrofone.push(dicaVoz);
      if (!reduzido()) { microfone.classList.add('lt-microfone--chama'); }
    }

    var linha = linhaDeAcoes(t);
    var contador = criar('span', 'lt-contador');
    if (!Object.keys(t.respostas || {}).length) {
      var dica = criar('span', 'lt-dica', microfone ? 'Escreva ou toque no microfone para falar' : 'Enter envia · Shift+Enter quebra linha');
      linha.appendChild(dica);
      if (microfone) { sobreMicrofone.push(dica); }
    } else {
      linha.appendChild(contador);
    }
    area.appendChild(linha);

    if (t.erro) { mostrarErro(caixa, erro, t.erro, ta); }

    // Campo vazio não se mede: o Chrome conta o placeholder no scrollHeight,
    // e na primeira medição o campo ainda não tem a largura final — o texto
    // de exemplo quebra em várias linhas estreitas e a pílula nasce com o
    // teto de altura. Sem valor, a altura é a de uma linha, pelo rows="1".
    function crescer() {
      if (!ta.value) { ta.style.height = ''; return; }
      ta.style.height = '0px';
      ta.style.height = Math.min(Math.max(ta.scrollHeight, 40), 144) + 'px';
    }
    function mudou() {
      botao.disabled = !ta.value.trim();
      if (microfone) {
        microfone.hidden = !!ta.value.trim();
        botao.hidden = !microfone.hidden;
        sobreMicrofone.forEach(function (el) { el.classList.toggle('lt-apagado', microfone.hidden); });
      }
      crescer();
      contador.textContent = ta.value.length > ta.maxLength * 0.8 ? ta.value.length + ' / ' + ta.maxLength : '';
    }

    ta.addEventListener('input', mudou);
    ta.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); disparar(); }
    });
    botao.addEventListener('click', disparar);

    function disparar() {
      var bruto = ta.value.trim();
      if (!bruto || ocupado) { return; }
      erro.hidden = true;
      caixa.classList.remove('lt-campo--erro');
      responder(campo.chave, bruto);
    }

    mudou();
    // Texto que volta no campo (a repergunta devolve o que a pessoa escreveu)
    // precisa ser medido de novo quando a largura assentar.
    // Observa a pílula, não o campo, e só reage a mudança de largura: mexer
    // na altura de quem está sendo observado dispara o observador de novo.
    if (window.ResizeObserver) {
      var larguraAnterior = 0;
      new ResizeObserver(function (entradas) {
        var largura = entradas[0].contentRect.width;
        if (largura !== larguraAnterior) {
          larguraAnterior = largura;
          crescer();
        }
      }).observe(caixa);
    } else {
      requestAnimationFrame(crescer);
    }
    focarDepois(function () { ta.focus(); });
  }

  function mostrarErro(caixa, erro, texto, ta) {
    erro.textContent = texto;
    erro.hidden = false;
    erro.classList.remove('lt-entra-curto');
    void erro.offsetWidth;
    erro.classList.add('lt-entra-curto');
    // Deslocamento lateral, não vermelho piscando.
    caixa.classList.add('lt-campo--erro');
    caixa.classList.remove('lt-treme');
    void caixa.offsetWidth;
    caixa.classList.add('lt-treme');
    anunciar(texto);
    if (ta) { ta.focus(); }
  }

  /* ----------------------------------------------------------------- voz - */

  function doisDigitos(n) { return (n < 10 ? '0' : '') + n; }
  function relogio(s) { s = Math.floor(s); return Math.floor(s / 60) + ':' + doisDigitos(s % 60); }

  /**
   * Gravando: a pílula vira um medidor, com o tempo e o teto à vista.
   *
   * A pergunta continua em cima. Quem fala precisa lembrar o que está
   * respondendo, e é bem quando a pessoa tira os olhos da tela.
   */
  function areaGravando(t) {
    Voz.cancelar();
    dispensarRetomada();
    area.innerHTML = '';
    area.classList.add('lt-entra-curto');

    var painel = criar('div', 'lt-gravando');
    painel.setAttribute('role', 'group');
    painel.setAttribute('aria-label', 'Gravando a resposta');

    var ponto = criar('span', 'lt-gravando-ponto');
    ponto.setAttribute('aria-hidden', 'true');
    var ondas = criar('div', 'lt-ondas');
    ondas.setAttribute('aria-hidden', 'true');
    var barras = [];
    for (var i = 0; i < 32; i++) { barras.push(ondas.appendChild(criar('i'))); }
    var tempo = criar('span', 'lt-gravando-tempo', '0:00 / ' + relogio(t.voz));

    painel.appendChild(ponto);
    painel.appendChild(ondas);
    painel.appendChild(tempo);

    var dica = criar('p', 'lt-ajuda', 'Abrindo o microfone…');

    var cancelar = criar('button', 'lt-btn', 'Cancelar');
    cancelar.type = 'button';
    var pronto = criar('button', 'lt-btn-primario lt-btn-primario--menor', ICO.parar + '<span>Pronto</span>');
    pronto.type = 'button';
    pronto.disabled = true;

    var acoes = criar('div', 'lt-acoes');
    acoes.appendChild(cancelar);
    acoes.appendChild(pronto);

    area.appendChild(painel);
    area.appendChild(dica);
    area.appendChild(acoes);

    var niveis = [];
    var gravacao = Voz.gravar({
      segundos: t.voz,
      aoAbrir: function () {
        painel.classList.add('lt-gravando--ativo');
        dica.textContent = 'Pode falar. Toque em Pronto quando terminar.';
        pronto.disabled = false;
        pronto.focus();
        anunciar('Gravando. Toque em Pronto quando terminar.');
      },
      aoMedir: function (nivel) {
        niveis.push(nivel);
        if (niveis.length > barras.length) { niveis.shift(); }
        var inicio = barras.length - niveis.length;
        barras.forEach(function (barra, j) {
          var n = j >= inicio ? niveis[j - inicio] : 0;
          barra.style.transform = 'scaleY(' + Math.max(0.08, n).toFixed(2) + ')';
        });
      },
      aoContar: function (s) {
        tempo.textContent = relogio(s) + ' / ' + relogio(t.voz);
        var perto = t.voz - s <= 10;
        tempo.classList.toggle('lt-gravando-tempo--fim', perto);
        if (perto && !tempo.getAttribute('data-avisou')) {
          tempo.setAttribute('data-avisou', '1');
          anunciar('Faltam dez segundos.');
        }
      }
    });

    cancelar.addEventListener('click', function () {
      gravacao.cancelar();
      desenharArea(t, { digitando: true });
    });
    pronto.addEventListener('click', function () {
      pronto.disabled = true;
      gravacao.parar();
    });
    acoes.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { cancelar.click(); }
    });

    gravacao.pronto.then(function (gravado) {
      enviarAudio(t, gravado);
    }).catch(function (e) {
      if (e && e.cancelado) { return; }
      desenharArea(t, { digitando: true, aviso: avisoDoMicrofone(e) });
    });
  }

  /**
   * Por que o microfone não abriu, na língua de quem não sabe o que é
   * permissão de site. Permissão negada e aparelho sem microfone não voltam
   * a oferecer o botão; o resto deixa tentar de novo.
   */
  function avisoDoMicrofone(e) {
    var nome = e && e.name;
    if (nome === 'NotAllowedError' || nome === 'SecurityError' || nome === 'PermissionDeniedError') {
      Voz.bloqueada = true;
      return 'O navegador não liberou o microfone. Tudo bem, você pode escrever aqui.';
    }
    if (nome === 'NotFoundError' || nome === 'NotReadableError' || nome === 'OverconstrainedError' || nome === 'NotSupportedError') {
      Voz.bloqueada = true;
      return 'Não encontrei um microfone funcionando neste aparelho. Você pode escrever aqui.';
    }
    return 'O microfone não abriu agora. Pode escrever aqui, ou tentar de novo.';
  }

  /** Manda a gravação, a menos que ela não tenha nada para ouvir. */
  function enviarAudio(t, gravado) {
    // Não vai ao servidor: gravação de meio segundo ou sem voz nenhuma só
    // gastaria uma chamada para ouvir "não entendi".
    if (gravado.segundos < 0.8 || !gravado.blob.size) {
      return desenharArea(t, { digitando: true, aviso: 'Foi rápido demais. Toque no microfone e fale com calma.' });
    }
    if (!gravado.comVoz) {
      return desenharArea(t, { digitando: true, aviso: 'Não ouvi nada. Confira se o microfone não está no mudo e tente de novo.' });
    }

    ocupado = true;
    esvaziarArea();
    var esperando = criar('div', 'lt-ouvindo');
    esperando.appendChild(criar('div', 'lt-pensando', '<i></i><i></i><i></i><span class="lt-pensando-rotulo">ouvindo…</span>'));
    esperando.appendChild(criar('p', 'lt-ajuda', 'Ouvindo o que você disse…'));
    area.appendChild(esperando);
    anunciar('Ouvindo o que você disse');

    Api.voz(t.campo.chave, gravado.blob).then(function (r) {
      ocupado = false;
      if (!r.ouviu) { return desenharArea(t, { digitando: true, aviso: r.aviso }); }
      areaConfirmaVoz(t, r);
    }).catch(function (e) {
      ocupado = false;
      if (e.codigo === 'ja_enviado') { area.innerHTML = ''; return falhaDeRede(e); }
      if (e.codigo === 'voz_indisponivel') { Voz.bloqueada = true; }
      desenharArea(t, { digitando: true, aviso: e.message });
    });
  }

  /**
   * "Entendi que o seu WhatsApp é (47) 99999-8888. Está certo?"
   *
   * Nada foi gravado ainda. Confirmado, o texto segue por /responder como se
   * tivesse sido digitado — a validação, a condução e o comentário dela são
   * os mesmos de quem escreveu.
   *
   * Com dúvida, o cartão fica cor de atenção, "gravar de novo" passa a ser o
   * botão em destaque, e confirmar pede um segundo toque.
   */
  function areaConfirmaVoz(t, r) {
    area.innerHTML = '';
    area.classList.remove('lt-entra-curto');
    void area.offsetWidth;
    area.classList.add('lt-entra-curto');

    var duvida = !!r.confianca_baixa;
    var cartao = criar('div', 'lt-ouvido' + (duvida ? ' lt-ouvido--duvida' : ''));
    cartao.appendChild(criar('p', 'lt-ouvido-rotulo', escapar(r.confirmacao)));
    var texto = criar('p', 'lt-ouvido-texto');
    texto.textContent = r.texto;
    cartao.appendChild(texto);

    var aviso = null;
    if (duvida && r.aviso) {
      aviso = criar('p', 'lt-ouvido-aviso', ICO.alerta + '<span></span>');
      aviso.querySelector('span').textContent = r.aviso;
      cartao.appendChild(aviso);
    }
    if (!duvida && r.nota) {
      var nota = criar('p', 'lt-ouvido-nota');
      nota.textContent = r.nota;
      cartao.appendChild(nota);
    }
    cartao.appendChild(criar('p', 'lt-ouvido-pergunta', 'Está certo?'));
    area.appendChild(cartao);

    var certo = criar('button', 'lt-escolha', duvida ? 'Está certo assim' : 'Está certo');
    certo.type = 'button';
    var corrigir = criar('button', 'lt-escolha', 'Corrigir escrevendo');
    corrigir.type = 'button';
    var regravar = criar('button', 'lt-escolha', 'Gravar de novo');
    regravar.type = 'button';

    var confirmouUmaVez = false;
    certo.addEventListener('click', function () {
      if (ocupado) { return; }
      if (duvida && !confirmouUmaVez) {
        confirmouUmaVez = true;
        certo.textContent = 'Sim, confirmo';
        certo.classList.add('lt-escolha--marcada');
        regravar.classList.remove('lt-escolha--marcada');
        if (aviso) { aviso.querySelector('span').textContent = 'Leia o texto acima mais uma vez e toque em “Sim, confirmo”.'; }
        anunciar('Confira mais uma vez e toque em Sim, confirmo.');
        return;
      }
      responder(t.campo.chave, r.texto, r.texto);
    });
    corrigir.addEventListener('click', function () {
      desenharArea(t, { digitando: true, valor: r.texto });
    });
    regravar.addEventListener('click', function () {
      if (ocupado) { return; }
      areaGravando(t);
    });

    var botoes = criar('div', 'lt-escolhas');
    if (duvida) {
      regravar.classList.add('lt-escolha--marcada');
      [regravar, corrigir, certo].forEach(function (b) { botoes.appendChild(b); });
    } else {
      [certo, corrigir, regravar].forEach(function (b) { botoes.appendChild(b); });
    }
    area.appendChild(botoes);
    area.appendChild(linhaDeAcoes(t));

    anunciar(r.confirmacao + ' ' + r.texto + '. Está certo?' + (duvida && r.aviso ? ' ' + r.aviso : (r.nota ? ' ' + r.nota : '')));
    focarDepois(function () { (duvida ? regravar : certo).focus(); });
  }

  /** Botão não gasta cota: o clique não vai ao modelo. */
  function areaEscolha(t) {
    var box = criar('div', 'lt-escolhas');

    t.campo.opcoes.forEach(function (op) {
      var b = criar('button', 'lt-escolha');
      b.type = 'button';
      b.textContent = op.texto;
      b.addEventListener('click', function () {
        if (ocupado) { return; }
        box.querySelectorAll('.lt-escolha').forEach(function (o) { o.disabled = true; });
        responder(t.campo.chave, op.valor, op.texto);
      });
      box.appendChild(b);
    });

    var ajuda = painelPorque(t.campo, false);
    area.appendChild(box);
    area.appendChild(ajuda.painel);
    area.appendChild(linhaDeAcoes(t, [ajuda.botao]));

    focarDepois(function () { var p = box.querySelector('button'); if (p) { p.focus(); } });
  }

  /**
   * A resposta já sugerida.
   *
   * A pessoa acabou de digitar WhatsApp e e-mail; pedir de novo é o que faz um
   * formulário parecer burro. E confirmar por botão não gasta chamada de API.
   */
  function areaSugestao(t) {
    // Os contatos vêm antes dos botões, em destaque: o botão só faz sentido
    // para quem já leu o que ele confirma. Embaixo e miúdos, "Pode publicar"
    // parecia confirmar outra coisa.
    var cartao = criar('div', 'lt-sugestao');
    cartao.appendChild(criar('p', 'lt-sugestao-rotulo', 'Os contatos que você já me passou:'));
    // Um contato por linha: juntos, o e-mail quebrava no meio do rótulo.
    cartao.appendChild(criar('p', 'lt-sugestao-valor', t.sugestao.split(' · ').map(escapar).join('<br>')));
    area.appendChild(cartao);

    var box = criar('div', 'lt-escolhas');

    var sim = criar('button', 'lt-escolha', 'Usar esses contatos');
    sim.type = 'button';
    sim.addEventListener('click', function () {
      if (ocupado) { return; }
      responder(t.campo.chave, t.sugestao, 'Usar esses contatos');
    });

    var muda = criar('button', 'lt-escolha', 'Quero outros');
    muda.type = 'button';
    muda.addEventListener('click', function () { desenharArea(t, { digitando: true }); });

    box.appendChild(sim);
    box.appendChild(muda);

    area.appendChild(box);
    area.appendChild(linhaDeAcoes(t));

    focarDepois(function () { sim.focus(); });
  }

  /**
   * Dois opcionais na mesma tela: redes sociais e páginas a mais.
   *
   * Cada um continua sendo um campo — o servidor grava, valida e comenta um
   * de cada vez. Aqui só se economiza a troca de tela: com os dois em branco,
   * um toque pula os dois; se o primeiro precisar de conversa (dúvida,
   * formato), a tela volta ao modo de um campo só, e o segundo vem depois.
   */
  function areaJunto(t) {
    var campos = [t.campo, t.junto];
    var box = criar('div', 'lt-junto');
    var entradas = [];

    campos.forEach(function (c, i) {
      var linha = criar('label', 'lt-junto-linha');
      linha.appendChild(criar('span', 'lt-junto-rotulo', escapar(c.rotulo_curto || c.rotulo)));
      var entrada = document.createElement('input');
      entrada.type = 'text';
      entrada.id = 'lt-junto-' + i;
      entrada.maxLength = 600;
      entrada.placeholder = c.dica || '';
      entrada.autocomplete = 'off';
      linha.appendChild(entrada);
      box.appendChild(linha);
      entradas.push(entrada);
    });

    var seguir = criar('button', 'lt-btn-primario', 'Continuar');
    seguir.type = 'button';
    var nenhum = criar('button', 'lt-btn', 'Não tenho nenhum dos dois');
    nenhum.type = 'button';

    var atualizar = function () {
      var algum = entradas.some(function (e) { return e.value.trim(); });
      seguir.hidden = !algum;
      nenhum.hidden = algum;
    };
    entradas.forEach(function (e, i) {
      e.addEventListener('input', atualizar);
      e.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.preventDefault();
        if (i === 0) { entradas[1].focus(); } else if (!seguir.hidden) { seguir.click(); }
      });
    });
    atualizar();

    var enviar = function () {
      enviarJunto(t, entradas.map(function (e) { return e.value.trim(); }));
    };
    seguir.addEventListener('click', enviar);
    nenhum.addEventListener('click', enviar);

    var acoes = criar('div', 'lt-junto-acoes');
    acoes.appendChild(seguir);
    acoes.appendChild(nenhum);

    area.appendChild(box);
    area.appendChild(acoes);
    // Sem o "Pular" de um campo só: aqui quem pula os dois é o botão acima.
    area.appendChild(linhaDeAcoes(Object.assign({}, t, { campo: Object.assign({}, t.campo, { obrigatorio: true }) })));
    focarDepois(function () { entradas[0].focus({ preventScroll: true }); });
  }

  function enviarJunto(t, valores) {
    if (ocupado) { return; }
    ocupado = true;
    dispensarRetomada();

    var a = t.campo;
    var b = t.junto;
    esvaziarArea();
    mostrarBalao(valores[0] || valores[1]
      ? [a, b].map(function (c, i) { return valores[i] ? (c.rotulo_curto || c.rotulo) + ': ' + valores[i] : ''; }).filter(Boolean).join(' · ')
      : 'Não tenho nenhum dos dois');

    // Um campo em branco é pulo; com texto, é resposta — e pode ir ao modelo.
    var um = function (campo, valor) {
      return valor
        ? Api.pedir('/responder', { campo: campo.chave, texto: valor })
        : Api.pedir('/pular', { campo: campo.chave });
    };

    var fim = pensar();
    var primeira = null;
    um(a, valores[0]).then(function (r) {
      // O primeiro pediu conversa, ou a tela seguiu para outro lugar: daqui
      // em diante é um campo de cada vez.
      if (r.permanece || !r.campo || r.campo.chave !== b.chave) {
        r.junto = null;
        return r;
      }
      primeira = r;
      return um(b, valores[1]);
    }).then(function (r) {
      // Respondeu o primeiro e deixou o segundo em branco: a reação que vale
      // é à resposta, não ao pulo — "essa pode ficar em branco" depois de a
      // pessoa ter escrito o Instagram soava como se não tivesse lido.
      if (primeira && valores[0] && !valores[1] && primeira.ponte) { r.ponte = primeira.ponte; }
      fim();
      r.junto = null;
      return depoisDeResponder(r);
    }).catch(function (e) {
      fim();
      ocupado = false;
      falhaDeRede(e);
    });
  }

  /* ------------------------------------------------------------ arquivos - */

  function areaArquivo(t, aoContinuar) {
    var campo = t.campo;
    var lista = criar('div', 'lt-arquivos');
    var fila = [];   // o que está subindo agora

    // No celular não há o que arrastar.
    var toque = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    var zona = criar('div', 'lt-solta',
      '<p class="lt-solta-titulo">' + (toque ? 'Escolha o arquivo no seu celular' : 'Arraste o arquivo aqui ou clique para escolher') + '</p>' +
      '<p class="lt-solta-sub">' + escapar(campo.aceita_texto) + (campo.multiplo ? ' · pode mandar mais de um' : '') + '</p>');

    var entrada = document.createElement('input');
    entrada.type = 'file';
    entrada.multiple = !!campo.multiplo;
    entrada.accept = campo.aceita.map(function (e) { return '.' + e; }).join(',');
    entrada.className = 'lt-invisivel';
    entrada.addEventListener('change', function () { receber(entrada.files); entrada.value = ''; });

    var escolher = criar('button', 'lt-btn-primario', 'Escolher arquivo');
    escolher.type = 'button';
    escolher.addEventListener('click', function () { entrada.click(); });
    zona.appendChild(escolher);

    // A logo pela câmera: no celular, um botão que abre a câmera direto. A
    // foto chega como JPG — o iPhone converte o HEIC antes de mandar.
    var camera = null;
    if (campo.foto && toque) {
      camera = document.createElement('input');
      camera.type = 'file';
      camera.accept = 'image/*';
      camera.setAttribute('capture', 'environment');
      camera.className = 'lt-invisivel';
      camera.addEventListener('change', function () { receber(camera.files); camera.value = ''; });

      var tirar = criar('button', 'lt-btn lt-btn--contorno', 'Tirar foto da logo');
      tirar.type = 'button';
      tirar.addEventListener('click', function () { camera.click(); });
      zona.appendChild(tirar);
    }

    ['dragenter', 'dragover'].forEach(function (ev) {
      zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('lt-solta--arrastando'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('lt-solta--arrastando'); });
    });
    zona.addEventListener('drop', function (e) { if (e.dataTransfer) { receber(e.dataTransfer.files); } });

    area.appendChild(zona);
    area.appendChild(entrada);
    if (camera) { area.appendChild(camera); }
    if (campo.foto) { area.appendChild(criar('p', 'lt-solta-dica', escapar(campo.foto))); }
    area.appendChild(lista);

    var campoLink = null;
    if (campo.aceita_link) {
      var caixaLink = criar('div', 'lt-campo');
      campoLink = document.createElement('textarea');
      campoLink.rows = 1;
      campoLink.placeholder = 'Ou cole o endereço de uma pasta (Drive, WeTransfer)';
      campoLink.setAttribute('aria-label', 'Endereço de uma pasta com os materiais');
      caixaLink.appendChild(campoLink);
      area.appendChild(caixaLink);
    }

    var extras = [];
    if (campo.pode_ficar_pendente) {
      var depois = criar('button', 'lt-btn', 'Não estou com o arquivo agora');
      depois.type = 'button';
      depois.addEventListener('click', function () {
        responder(campo.chave, '', 'Não estou com o arquivo agora', { pendente: true });
      });
      extras.push(depois);
    }

    var ajuda = painelPorque(campo, false);
    extras.push(ajuda.botao);

    area.appendChild(ajuda.painel);
    area.appendChild(linhaDeAcoes(t, extras));

    var continuar = criar('button', 'lt-btn-primario', 'Continuar');
    continuar.type = 'button';
    continuar.style.alignSelf = 'center';
    continuar.addEventListener('click', function () {
      var link = campoLink ? campoLink.value.trim() : '';
      if (aoContinuar) { return aoContinuar(continuar); }
      responder(campo.chave, '', '', { link: link });
    });
    area.appendChild(continuar);

    function podeSeguir() {
      var subindo = fila.some(function (f) { return !f.pronto && !f.erro; });
      var prontos = gaveta.length;
      var temLink = campoLink && campoLink.value.trim() !== '';
      continuar.disabled = subindo || (campo.obrigatorio && !prontos && !temLink);
      continuar.textContent = subindo ? 'Enviando…' : 'Continuar';
    }

    function receber(arquivos) {
      Array.prototype.forEach.call(arquivos, function (f) {
        var item = {
          chave: 'f' + Math.random().toString(36).slice(2, 9),
          nome: f.name,
          tamanho: f.size,
          progresso: 0,
          pedaco: 0,
          pedacos: Math.max(1, Math.ceil(f.size / (CFG.pedaco || 4194304))),
          pronto: false,
          erro: null,
          url: /\.(png|jpe?g|webp)$/i.test(f.name) && window.URL ? URL.createObjectURL(f) : null
        };
        fila.push(item);
        desenharLista();
        subir(f, item);
      });
      podeSeguir();
    }

    function subir(arquivo, item) {
      var tamanhoPedaco = CFG.pedaco || 4194304;

      Api.pedir('/arquivo/iniciar', {
        campo: campo.chave,
        nome: arquivo.name,
        tamanho: arquivo.size,
        pedacos: item.pedacos
      }).then(function (abertura) {
        item.id = abertura.id;

        // Um pedaço por vez, de propósito: paralelo aqui só adianta a barra e
        // atrasa o resto da página numa rede de celular.
        var proximo = function (i) {
          if (i >= item.pedacos) {
            return Api.pedir('/arquivo/concluir', { id: item.id });
          }
          var fatia = arquivo.slice(i * tamanhoPedaco, (i + 1) * tamanhoPedaco);
          return Api.pedaco(item.id, i, fatia).then(function () {
            item.pedaco = i + 1;
            item.progresso = (i + 1) / item.pedacos;
            desenharLista();
            return proximo(i + 1);
          });
        };

        return proximo(0);
      }).then(function (fim) {
        item.pronto = true;
        item.meta = fim.arquivo;
        item.aviso = fim.arquivo.aviso;
        gaveta = fim.arquivos;
        desenharLista();
        podeSeguir();
      }).catch(function (e) {
        item.erro = e.message;
        item.pronto = true;
        desenharLista();
        podeSeguir();
      });
    }

    function desenharLista() {
      lista.innerHTML = '';

      fila.forEach(function (item) {
        var cartao = criar('div', 'lt-arquivo' + (item.erro ? ' lt-arquivo--ruim' : ''));

        var mini = criar('div', 'lt-arquivo-mini', item.url ? '' : ICO.arquivo);
        if (item.url) {
          var img = document.createElement('img');
          img.src = item.url;
          img.alt = '';
          mini.appendChild(img);
        }

        var meio = criar('div');
        meio.appendChild(criar('p', 'lt-arquivo-nome', escapar(item.nome)));

        var sub = criar('p', 'lt-arquivo-sub' + (item.erro || item.aviso ? ' lt-arquivo-sub--ruim' : ''));
        if (item.erro) {
          sub.textContent = item.erro;
        } else if (!item.pronto) {
          sub.textContent = tamanhoLegivel(item.tamanho) + ' · pedaço ' + item.pedaco + ' de ' + item.pedacos;
        } else {
          sub.textContent = item.aviso ? item.aviso : tamanhoLegivel(item.tamanho) + ' · enviado';
        }
        meio.appendChild(sub);

        if (!item.erro && !item.pronto) {
          var barra = criar('div', 'lt-arquivo-barra', '<i></i>');
          barra.firstChild.style.width = (item.progresso * 100) + '%';
          meio.appendChild(barra);
        }

        var tirar = criar('button', 'lt-remover', ICO.remover);
        tirar.type = 'button';
        tirar.setAttribute('aria-label', 'Remover ' + item.nome);
        tirar.addEventListener('click', function () {
          fila = fila.filter(function (f) { return f.chave !== item.chave; });
          desenharLista();
          podeSeguir();
          if (item.meta) {
            Api.pedir('/arquivo/remover', { id: item.meta.id }).then(function (r) {
              gaveta = r.arquivos || [];
              podeSeguir();
            }).catch(function () { /* some da tela de qualquer jeito */ });
          }
        });

        cartao.appendChild(mini);
        cartao.appendChild(meio);
        cartao.appendChild(tirar);
        lista.appendChild(cartao);
      });
    }

    if (campoLink) { campoLink.addEventListener('input', podeSeguir); }
    podeSeguir();
    focarDepois(function () { escolher.focus(); });
  }

  /* =======================================================================
     O CICLO DE UMA RESPOSTA
     ======================================================================= */
  /** Seguiu o briefing: a barra de retomada já cumpriu o papel dela. */
  function dispensarRetomada() {
    var barra = $('lt-retomada');
    if (barra && barra.children.length) { semPulo(function () { barra.innerHTML = ''; }); }
  }

  function responder(chave, texto, rotuloNaTela, extra) {
    if (ocupado) { return; }
    ocupado = true;
    dispensarRetomada();

    esvaziarArea();
    if (rotuloNaTela !== '' ) { mostrarBalao(rotuloNaTela || texto); }

    var vaiPensar = !!(atual.campo && (atual.campo.tipo !== 'escolha'));
    var fim = null;

    espera(200).then(function () {
      if (vaiPensar) { fim = pensar(); }
      return Api.pedir('/responder', Object.assign({ campo: chave, texto: texto }, extra || {}));
    }).then(function (t) {
      if (fim) { fim(); }
      return depoisDeResponder(t);
    }).catch(function (e) {
      if (fim) { fim(); }
      ocupado = false;
      falhaDeRede(e);
    });
  }

  function depoisDeResponder(t) {
    var dela = t.dela || {};
    var texto = dela.resposta_duvida || dela.repergunta || null;

    // A pessoa continua no mesmo campo: perguntou, ou a resposta veio curta
    // demais para a equipe trabalhar.
    if (t.permanece) {
      if (texto) {
        // A condução dela passa a ser o assunto da tela: a reação à resposta
        // anterior, a abertura da etapa e o detalhe da pergunta já foram lidos
        // e, empilhados em cima da condução, viravam um muro de texto.
        semPulo(function () {
          Array.prototype.forEach.call(turno.querySelectorAll('.lt-ponte, .lt-etapa, .lt-fala'), function (el) {
            el.parentNode.removeChild(el);
          });
          detalheEl.hidden = true;
          notaEl.hidden = true;
        });
      }
      var falaPrimeiro = texto ? falar(texto) : Promise.resolve();
      return falaPrimeiro.then(function () {
        gaveta = [];
        ocupado = false;
        reabrirArea(t, { digitando: true, valor: t.eco || '' });
      });
    }

    if (t.conferido) { marcarConferido(); }

    // O comentário dela não é dito aqui: ele chega como a ponte da próxima
    // pergunta. Dito aqui e repetido lá, a pessoa lia a mesma frase duas vezes.
    return espera(t.conferido ? 360 : 120).then(function () {
      gaveta = [];
      ocupado = false;
      return trocarTurno(t);
    });
  }

  /** O turno sai, depois o próximo entra. Nunca os dois ao mesmo tempo. */
  function trocarTurno(t) {
    if (reduzido() || !palco) { return aplicar(t); }
    palco.style.transition = 'opacity var(--lt-padrao) var(--lt-acelera)';
    palco.style.opacity = '0';
    return espera(220).then(function () {
      palco.style.transition = '';
      palco.style.opacity = '';
      return aplicar(t);
    });
  }

  function voltarPara(chave) {
    if (ocupado) { return; }
    chamar('/voltar', { campo: chave });
  }

  function chamar(rota, dados) {
    if (ocupado) { return; }
    ocupado = true;
    dispensarRetomada();
    return Api.pedir(rota, dados).then(function (t) {
      ocupado = false;
      gaveta = [];
      return t.permanece ? aplicar(t) : trocarTurno(t);
    }).catch(function (e) {
      ocupado = false;
      falhaDeRede(e);
    });
  }

  /* =======================================================================
     O RASCUNHO
     ======================================================================= */
  /**
   * O texto que ela escreveu a partir do que a pessoa contou.
   *
   * Três saídas, e nenhuma trava o briefing: usar, ajustar ou deixar de lado.
   * Quando veio de uma resposta, ela já está gravada — o rascunho é um a
   * mais, não uma troca. Quando veio de um pedido de ajuda, usar o rascunho é
   * responder o campo com ele.
   */
  function telaProposta(t) {
    var p = t.proposta;
    prepararPalco();
    $('lt-abrir').hidden = true; esconderContinuar();
    $('lt-historico').hidden = true;

    acimaDaPergunta(criar('p', 'lt-etapa', escapar(p.rotulo)));

    // Como na pergunta: tudo no lugar desde o primeiro quadro, invisível, e
    // acendendo em sequência. O cartão do rascunho é alto; montado depois do
    // título, empurrava o bloco inteiro mais de cem pixels.
    aguardando = true;
    focoPendente = null;
    var ponte = null;
    if (p.intro) {
      ponte = criar('p', 'lt-ponte lt-aguarda');
      ponte.textContent = p.intro;
      turno.insertBefore(ponte, enunciado);
    }
    perguntaEl.textContent = p.titulo || '';
    perguntaEl.classList.add('lt-aguarda');
    area.classList.add('lt-aguarda');
    areaProposta(t, false);

    var fila = Promise.resolve();
    if (ponte) {
      fila = fila.then(function () {
        ponte.classList.remove('lt-aguarda');
        return revelar(ponte, p.intro);
      }).then(function () { return espera(220); });
    }
    return fila
      .then(function () {
        perguntaEl.classList.remove('lt-aguarda');
        return revelar(perguntaEl, p.titulo);
      })
      .then(function () {
        if (atual !== t) { return; }
        aguardando = false;
        acender(area);
        soltarFoco();
      });
  }

  function areaProposta(t, editando) {
    var p = t.proposta;
    area.innerHTML = '';
    area.classList.remove('lt-entra-curto');
    void area.offsetWidth;
    area.classList.add('lt-entra-curto');

    if (editando) {
      var caixa = criar('div', 'lt-campo lt-campo--longo');
      var ta = document.createElement('textarea');
      ta.id = 'lt-entrada';
      ta.rows = p.tipo === 'lista' ? 8 : 6;
      ta.maxLength = 1200;
      ta.value = p.texto;
      ta.setAttribute('aria-label', 'Ajustar o texto sugerido');
      caixa.appendChild(ta);
      area.appendChild(caixa);

      var cancelar = criar('button', 'lt-btn', 'Voltar para a sugestão');
      cancelar.type = 'button';
      cancelar.addEventListener('click', function () { areaProposta(t, false); });

      var salvar = criar('button', 'lt-btn-primario lt-btn-primario--menor', 'Salvar este texto');
      salvar.type = 'button';
      salvar.addEventListener('click', function () {
        var texto = ta.value.trim();
        if (!texto) { ta.focus(); return; }
        decidirProposta(t, 'ajustar', texto);
      });
      ta.addEventListener('input', function () { salvar.disabled = !ta.value.trim(); });

      var acoes = criar('div', 'lt-acoes');
      acoes.appendChild(cancelar);
      acoes.appendChild(salvar);
      area.appendChild(acoes);

      focarDepois(function () { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); });
      return;
    }

    var cartao = criar('div', 'lt-proposta' + (p.tipo === 'lista' ? ' lt-proposta--lista' : ''));
    if (p.tipo === 'lista') {
      // Um item por bloco, com o nome do serviço em destaque: numa linha
      // corrida só, três serviços quebrados no celular viravam um parágrafo.
      p.texto.split('\n').forEach(function (linha) {
        var item = criar('p', 'lt-proposta-item');
        var dois = linha.indexOf(': ');
        if (dois > 0 && dois < 80) {
          item.appendChild(criar('b', null, escapar(linha.slice(0, dois))));
          item.appendChild(document.createTextNode(' — ' + linha.slice(dois + 2)));
        } else {
          item.textContent = linha;
        }
        cartao.appendChild(item);
      });
    } else {
      var texto = criar('p', 'lt-proposta-texto');
      texto.textContent = p.texto;
      cartao.appendChild(texto);
    }
    area.appendChild(cartao);
    if (p.convite) { area.appendChild(criar('p', 'lt-ajuda', escapar(p.convite))); }

    var usar = criar('button', 'lt-escolha lt-escolha--marcada', p.tipo === 'lista' ? 'Usar esta lista' : 'Usar este texto');
    usar.type = 'button';
    usar.addEventListener('click', function () { decidirProposta(t, 'usar'); });

    var ajustar = criar('button', 'lt-escolha', 'Ajustar');
    ajustar.type = 'button';
    ajustar.addEventListener('click', function () { areaProposta(t, true); });

    var dispensar = criar('button', 'lt-escolha', p.origem === 'ajuda' ? 'Escrever do meu jeito' : 'Não usar');
    dispensar.type = 'button';
    dispensar.addEventListener('click', function () { decidirProposta(t, 'dispensar'); });

    var botoes = criar('div', 'lt-escolhas');
    botoes.appendChild(usar);
    botoes.appendChild(ajustar);
    botoes.appendChild(dispensar);
    area.appendChild(botoes);

    anunciar(p.titulo + '. ' + p.texto);
    focarDepois(function () { usar.focus(); });
  }

  function decidirProposta(t, acao, texto) {
    if (ocupado) { return; }
    ocupado = true;
    dispensarRetomada();

    var balao = {
      usar: t.proposta.tipo === 'lista' ? 'Usar esta lista' : 'Usar este texto',
      ajustar: t.proposta.tipo === 'lista' ? 'Ajustei a lista' : 'Ajustei o texto',
      dispensar: t.proposta.origem === 'ajuda' ? 'Vou escrever do meu jeito' : 'Não usar'
    };
    esvaziarArea();
    mostrarBalao(balao[acao]);

    Api.pedir('/proposta', { campo: t.proposta.campo, acao: acao, texto: texto || '' }).then(function (r) {
      ocupado = false;
      return espera(160).then(function () { return trocarTurno(r); });
    }).catch(function (e) {
      ocupado = false;
      areaProposta(t, acao === 'ajustar');
      falhaDeRede(e);
    });
  }

  /* =======================================================================
     REVISÃO
     ======================================================================= */
  function telaRevisao(t) {
    Voz.cancelar();
    $('lt-historico').hidden = true;
    $('lt-abrir').hidden = true; esconderContinuar();

    palco = $('lt-palco');
    palco.innerHTML = '';

    var bloco = criar('div', 'lt-revisao');
    var cab = criar('div');
    cab.style.display = 'flex';
    cab.style.flexDirection = 'column';
    cab.style.gap = 'var(--lt-e2)';
    // Na revisão, a fala é o fecho dela ("pronto, era isso que eu precisava"),
    // não a reação ao último campo — que ali soava como resto de conversa.
    var fecho = (t.falas && t.falas[0]) || '';
    if (fecho) { cab.appendChild(criar('p', 'lt-ponte lt-ponte--esquerda', escapar(fecho))); }
    cab.appendChild(criar('h2', 'lt-titulo', 'Confira antes de enviar'));
    bloco.appendChild(cab);

    var pendencias = caixaDePendencias(t.pendencias, false);
    if (pendencias) { bloco.appendChild(pendencias); }

    var lista = criar('div', 'lt-lista');
    var i = 0;

    Object.keys(t.respostas).forEach(function (chave) {
      var r = t.respostas[chave];
      var item = criar('div', 'lt-item');

      if (i < 12 && !reduzido()) {
        item.classList.add('lt-entra-curto');
        item.style.animationDelay = (i * 40) + 'ms';
      }
      i++;

      item.appendChild(criar('p', 'lt-item-rotulo', escapar(r.rotulo)));

      var texto = resumoDaResposta(r);
      var valor = criar('div', 'lt-item-valor' + (texto ? '' : ' lt-item-valor--vazio'));
      valor.textContent = texto || 'não informado';
      // O texto aprovado vai embaixo do que a pessoa respondeu: é ele que a
      // equipe usa, e é ele que ela está conferindo.
      if (r.texto_site) {
        var site = criar('p', 'lt-item-site');
        site.appendChild(criar('b', null, 'Texto pro site: '));
        site.appendChild(document.createTextNode(r.texto_site));
        valor.appendChild(site);
      }
      item.appendChild(valor);

      var editar = criar('button', 'lt-editar', 'Editar');
      editar.type = 'button';
      editar.setAttribute('aria-label', 'Editar ' + r.rotulo);
      editar.addEventListener('click', function () { voltarPara(chave); });
      item.appendChild(editar);

      lista.appendChild(item);
    });
    bloco.appendChild(lista);

    var aceite = criar('div', 'lt-aceite');
    var caixa = document.createElement('input');
    caixa.type = 'checkbox';
    caixa.id = 'lt-consentimento';
    var rotulo = document.createElement('label');
    rotulo.setAttribute('for', 'lt-consentimento');
    rotulo.textContent = t.consentimento || '';
    aceite.appendChild(caixa);
    aceite.appendChild(rotulo);
    bloco.appendChild(aceite);

    var rodape = criar('div');
    rodape.style.display = 'flex';
    rodape.style.flexDirection = 'column';
    rodape.style.gap = 'var(--lt-e3)';
    rodape.style.alignItems = 'flex-start';

    var enviar = criar('button', 'lt-btn-primario', 'Enviar briefing');
    enviar.type = 'button';
    enviar.disabled = true;
    caixa.addEventListener('change', function () { enviar.disabled = !caixa.checked; });

    enviar.addEventListener('click', function () {
      if (ocupado) { return; }
      ocupado = true;
      enviar.disabled = true;
      enviar.textContent = 'Enviando…';

      Api.pedir('/enviar', { consentimento: true }).then(function (fim) {
        ocupado = false;
        aplicar(fim);
      }).catch(function (e) {
        ocupado = false;
        enviar.disabled = false;
        enviar.textContent = 'Enviar briefing';
        falhaDeRede(e, enviar);
      });
    });

    rodape.appendChild(enviar);
    rodape.appendChild(criar('p', 'lt-ajuda', 'O envio termina quando aparecer “Briefing recebido”.'));
    bloco.appendChild(rodape);

    palco.appendChild(bloco);
    window.scrollTo({ top: 0, behavior: reduzido() ? 'auto' : 'smooth' });
    anunciar('Revisão do briefing. Confira as respostas antes de enviar.');
  }

  /** Uma linha curta por pendência. O detalhe do prazo vai numa linha só, embaixo. */
  function textoDaPendencia(chave) {
    if (atual && atual.pendencias_texto && atual.pendencias_texto[chave]) { return atual.pendencias_texto[chave]; }
    return (atual && atual.respostas && atual.respostas[chave] ? atual.respostas[chave].rotulo : 'Um campo') + ': fica para depois.';
  }

  /**
   * As pendências numa caixa só, em lista.
   *
   * Eram uma caixa por pendência, cada uma com um parágrafo explicando o
   * prazo — duas caixas de três linhas antes da lista de respostas.
   */
  function caixaDePendencias(chaves, soNaoArquivo) {
    // Na tela final, o que vai pelo link já está no bloco do link.
    var lista = (chaves || []).filter(function (c) {
      var r = atual && atual.respostas ? atual.respostas[c] : null;
      return !(soNaoArquivo && r && r.arquivos && !r.valor);
    });
    if (!lista.length) { return null; }
    var caixa = criar('div', 'lt-pendencia');
    var corpo = criar('div');
    corpo.appendChild(criar('p', 'lt-pendencia-titulo', 'Fica para depois'));
    var ul = criar('ul');
    lista.forEach(function (c) { ul.appendChild(criar('li', null, escapar(textoDaPendencia(c)))); });
    corpo.appendChild(ul);
    corpo.appendChild(criar('p', 'lt-ajuda', 'As 72 horas começam quando isso estiver resolvido.'));
    caixa.innerHTML = ICO.alerta;
    caixa.appendChild(corpo);
    return caixa;
  }

  /* =======================================================================
     FIM
     ======================================================================= */
  function telaFim(t) {
    // Briefing enviado não se retoma — e "começar do zero" ao lado de um
    // briefing que a equipe acabou de receber é um botão que não pode estar ali.
    $('lt-retomada').innerHTML = '';
    palco = $('lt-palco');
    palco.innerHTML = '';
    $('lt-abrir').hidden = true; esconderContinuar();
    $('lt-historico').hidden = true;

    // O briefing chegou: o token do rascunho não serve mais para nada, e
    // deixá-lo faria a próxima visita oferecer retomar o que já foi entregue.
    Api.esquecer();

    var bloco = criar('div', 'lt-final');
    bloco.innerHTML = ICO.selo;

    bloco.appendChild(criar('h2', 'lt-titulo', 'Briefing recebido'));
    var p1 = criar('p', 'lt-fala');
    bloco.appendChild(p1);

    // Três frases no máximo antes do que falta. O resto a equipe diz no WhatsApp.
    var pendentes = (t.pendencias || []).length > 0;
    if (!pendentes) {
      bloco.appendChild(criar('p', 'lt-fala lt-fala--suave', 'As 72 horas já começaram a contar.'));
    }
    if (t.anexo) { bloco.appendChild(blocoDoLink(t.anexo)); }
    var resto = caixaDePendencias(t.pendencias, !!t.anexo);
    if (resto) { bloco.appendChild(resto); }
    palco.appendChild(bloco);

    var nome = t.respostas && t.respostas.responsavel ? t.respostas.responsavel.valor.split(/\s+/)[0] : '';
    revelar(p1, 'Está tudo com a equipe da JoinVix' + (nome ? ', ' + nome : '') + '. Se faltar algo, eles chamam você no WhatsApp.');
  }

  /**
   * O link de mandar a logo depois, na tela final.
   *
   * Com botão de copiar, porque a cópia do e-mail só existe para quem deixou
   * e-mail — e o e-mail é opcional.
   */
  function blocoDoLink(link) {
    var caixa = criar('div', 'lt-link-anexo');
    caixa.appendChild(criar('p', 'lt-link-anexo-titulo', 'Ficou um arquivo para depois'));
    caixa.appendChild(criar('p', 'lt-ajuda', 'Guarde este link para mandar o arquivo quando estiver com ele.'));

    caixa.appendChild(linhaDeCopiar(link, 'Link para mandar o arquivo depois'));
    return caixa;
  }

  /** O link num campo só de leitura, com o botão de copiar do lado. */
  function linhaDeCopiar(link, rotulo) {
    var linha = criar('div', 'lt-link-anexo-linha');
    var campo = document.createElement('input');
    campo.type = 'text';
    campo.readOnly = true;
    campo.value = link;
    campo.setAttribute('aria-label', rotulo);
    campo.addEventListener('focus', function () { campo.select(); });

    var copiar = criar('button', 'lt-btn-primario lt-btn-primario--menor', 'Copiar link');
    copiar.type = 'button';
    copiar.addEventListener('click', function () {
      var feito = function () {
        copiar.textContent = 'Copiado';
        anunciar('Link copiado');
        setTimeout(function () { copiar.textContent = 'Copiar link'; }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(feito, function () { campo.focus(); });
      } else {
        campo.focus();
        try { document.execCommand('copy'); feito(); } catch (e) { /* o texto fica selecionado */ }
      }
    });

    linha.appendChild(campo);
    linha.appendChild(copiar);
    return linha;
  }

  /* =======================================================================
     CONTINUAR DEPOIS
     O rascunho mora neste navegador. O link leva o briefing para outro
     aparelho — do celular para o computador onde está a logomarca.
     ======================================================================= */
  var continuarAberto = false;

  /** O botão aparece na conversa, a partir da primeira resposta. */
  function atualizarContinuar(t) {
    var botao = $('lt-continuar');
    if (!botao) { return; }
    var tem = t && t.fase === 'conversa' && !Api.anexo && Object.keys(t.respostas || {}).length > 0;
    botao.hidden = !tem;
    if (!tem) { fecharContinuar(false); }
  }

  function esconderContinuar() {
    var botao = $('lt-continuar');
    if (botao) { botao.hidden = true; }
    fecharContinuar(false);
  }

  function fecharContinuar(devolverFoco) {
    var painel = $('lt-continuar-painel');
    if (!painel || !continuarAberto) { return; }
    continuarAberto = false;
    $('lt-continuar').setAttribute('aria-expanded', 'false');
    painel.classList.remove('lt-historico--abre');
    if (devolverFoco) { $('lt-continuar').focus(); }
    if (reduzido()) { painel.hidden = true; return; }
    painel.classList.add('lt-historico--fecha');
    setTimeout(function () {
      painel.classList.remove('lt-historico--fecha');
      if (!continuarAberto) { painel.hidden = true; }
    }, 150);
  }

  function abrirContinuar() {
    var painel = $('lt-continuar-painel');
    // Um cartão aberto por vez.
    if (historicoAberto) { historicoAberto = false; redesenharHistorico(atual); }
    continuarAberto = true;
    $('lt-continuar').setAttribute('aria-expanded', 'true');

    painel.innerHTML = '';
    painel.appendChild(criar('p', 'lt-continuar-titulo', 'Continue de onde parou'));
    painel.appendChild(criar('p', 'lt-ajuda', 'Está tudo guardado. Com este link, você volta ao briefing em qualquer aparelho, por 30 dias.'));
    var status = criar('p', 'lt-continuar-status', 'Gerando o link…');
    painel.appendChild(status);

    painel.hidden = false;
    painel.classList.remove('lt-historico--fecha');
    if (!reduzido()) {
      painel.classList.remove('lt-historico--abre');
      void painel.offsetWidth;
      painel.classList.add('lt-historico--abre');
    }

    Api.pedir('/continuar', {}).then(function (r) {
      if (!continuarAberto) { return; }
      status.remove();
      var copiar = linhaDeCopiar(r.link, 'Link para continuar o briefing');
      painel.appendChild(copiar);
      // O foco vai para o que se faz aqui — copiar —, e quem usa teclado ou
      // leitor de tela não fica parado no botão que abriu o cartão.
      var botaoCopiar = copiar.querySelector('button');
      if (botaoCopiar) { botaoCopiar.focus({ preventScroll: true }); }

      var acoes = criar('div', 'lt-continuar-acoes');
      if (r.whatsapp) {
        // Botão, e não link: botão é o que o sistema de cores da tela pinta.
        // O wa.me para o próprio número abre a conversa "comigo mesmo".
        var zap = criar('button', 'lt-btn', 'Mandar para o meu WhatsApp');
        zap.type = 'button';
        zap.addEventListener('click', function () {
          window.open('https://wa.me/' + r.whatsapp + '?text=' + encodeURIComponent('Link para continuar o briefing do meu site: ' + r.link), '_blank', 'noopener');
        });
        acoes.appendChild(zap);
      }
      if (r.email) {
        var mail = criar('button', 'lt-btn', 'Mandar para ' + escapar(r.email));
        mail.type = 'button';
        var aviso = criar('p', 'lt-continuar-status');
        aviso.hidden = true;
        mail.addEventListener('click', function () {
          mail.disabled = true;
          Api.pedir('/continuar', { enviar: 'email' }).then(function (e) {
            aviso.hidden = false;
            aviso.className = 'lt-continuar-status' + (e.enviado ? ' lt-continuar-status--bom' : '');
            aviso.textContent = e.enviado ? 'Enviado para ' + e.email + '. Confira também a caixa de spam.' : e.erro;
            anunciar(aviso.textContent);
            if (!e.enviado) { mail.disabled = false; }
          }).catch(function (erro) {
            mail.disabled = false;
            aviso.hidden = false;
            aviso.textContent = (erro && erro.message) || 'Não consegui mandar agora. Copie o link, por enquanto.';
          });
        });
        acoes.appendChild(mail);
        acoes.appendChild(aviso);
      }
      if (acoes.children.length) { painel.appendChild(acoes); }
    }).catch(function (e) {
      status.textContent = (e && e.message) || 'Não consegui gerar o link agora. Tente de novo em instantes.';
    });
  }

  /* =======================================================================
     O LINK DE MANDAR A LOGO DEPOIS
     ======================================================================= */
  function telaAnexo(t) {
    atual = t;
    raiz.setAttribute('data-fase', t.fase);
    $('lt-contagem').textContent = t.fase === 'anexo' ? 'Arquivo pendente' : 'Tudo entregue';
    $('lt-barra').hidden = true;

    if (t.fase !== 'anexo') { return telaAnexoFim(t); }

    prepararPalco();
    return revelar(perguntaEl, t.campo.pergunta).then(function () {
      mostrarDetalhe(t.campo.detalhe);
      area.innerHTML = '';
      areaArquivo(t, function (botao) {
        if (ocupado) { return; }
        ocupado = true;
        botao.disabled = true;
        botao.textContent = 'Enviando…';
        Api.pedir('/anexo/concluir', { campo: t.campo.chave }).then(function (fim) {
          ocupado = false;
          trocarPara(function () { telaAnexo(fim); });
        }).catch(function (e) {
          ocupado = false;
          botao.disabled = false;
          botao.textContent = 'Continuar';
          falhaDeRede(e, botao);
        });
      });
    });
  }

  function telaAnexoFim(t) {
    palco = $('lt-palco');
    palco.innerHTML = '';

    var bloco = criar('div', 'lt-final');
    bloco.innerHTML = ICO.selo;
    var titulo = t.chegou ? (t.titulo || 'Recebido!') : 'Não falta mais nada';
    bloco.appendChild(criar('h2', 'lt-titulo', titulo));
    var p = criar('p', 'lt-fala');
    bloco.appendChild(p);
    palco.appendChild(bloco);

    var nome = t.nome ? ', ' + t.nome : '';
    revelar(p, t.chegou
      ? (t.mensagem || 'Já está com a equipe da JoinVix' + nome + '. Agora está tudo com eles, e as 72 horas começam a contar.')
      : 'O arquivo que faltava já chegou na equipe' + nome + '. Não precisa mandar de novo.');
  }

  function trocarPara(desenhar) {
    if (reduzido() || !palco) { return desenhar(); }
    palco.style.transition = 'opacity var(--lt-padrao) var(--lt-acelera)';
    palco.style.opacity = '0';
    return espera(220).then(function () {
      palco.style.transition = '';
      palco.style.opacity = '';
      return desenhar();
    });
  }

  /* =======================================================================
     QUANDO DÁ ERRADO
     ======================================================================= */
  function falhaDeRede(e, perto) {
    var aviso = criar('div', 'lt-pendencia', ICO.alerta +
      '<p>' + escapar(e.message || 'Não consegui falar com o servidor.') +
      ' <button type="button" class="lt-editar" data-lt="retomar">Tentar de novo</button></p>');

    var onde = perto ? perto.parentNode : area;
    onde.appendChild(aviso);

    aviso.querySelector('[data-lt="retomar"]').addEventListener('click', function () {
      aviso.remove();
      if (atual) { aplicar(atual); }
    });

    // A LetícIA nunca é o único caminho.
    if (CFG.classico) {
      var saida = criar('p', 'lt-saida');
      saida.innerHTML = 'Se não passar desta vez, o formulário de sempre continua no ar: ' +
        '<a href="' + escapar(CFG.classico) + '" target="_blank" rel="noopener">formulários da JoinVix</a>.';
      onde.appendChild(saida);
    }

    anunciar(e.message || 'Não consegui falar com o servidor.');
  }

  /* =======================================================================
     RETOMADA
     ======================================================================= */
  /** O link de continuar não abriu: expirou, ou o briefing já foi enviado. */
  function avisarDoLink(t) {
    if (!t.aviso_link) { return; }
    var barra = criar('div', 'lt-retomada');
    barra.appendChild(criar('p', null, escapar(t.aviso_link)));
    var ok = criar('button', null, 'Entendi');
    ok.type = 'button';
    ok.addEventListener('click', function () { barra.remove(); });
    barra.appendChild(ok);
    $('lt-retomada').appendChild(barra);
  }

  function ofertarRetomada(t) {
    var r = t.retomada;
    if (!r) { return; }

    var barra = criar('div', 'lt-retomada');
    barra.appendChild(criar('p', null, escapar(r.frase) + ' ' + r.respondidos + ' de ' + r.total + ' respondidos.'));

    var seguir = criar('button', null, 'Continuar');
    seguir.type = 'button';
    seguir.addEventListener('click', function () { $('lt-retomada').innerHTML = ''; });

    // A saída existe por causa do computador compartilhado — e de quem
    // simplesmente quer recomeçar.
    var zero = criar('button', null, 'Não é você? Começar do zero');
    zero.type = 'button';
    zero.addEventListener('click', function () {
      $('lt-retomada').innerHTML = '';
      Api.pedir('/descartar', {}).then(aplicar).catch(falhaDeRede);
    });

    barra.appendChild(seguir);
    barra.appendChild(zero);
    $('lt-retomada').appendChild(barra);
  }

  /* =======================================================================
     ARRANQUE
     ======================================================================= */
  /**
   * A largura da janela sem a barra de rolagem.
   *
   * O modo página sangra até as bordas com 100vw — e 100vw inclui a barra de
   * rolagem vertical. Com a página rolando, sobravam oito pixels para o lado e
   * aparecia rolagem horizontal. clientWidth é a largura de verdade.
   */
  function medirLargura() {
    raiz.style.setProperty('--lt-largura', document.documentElement.clientWidth + 'px');

    // A altura é a janela menos o que vem antes da LetícIA na página — a barra
    // do WordPress de quem está logado, o cabeçalho do tema. 100dvh sozinho
    // somava essas alturas e deixava sempre um pouco de rolagem.
    var topo = raiz.getBoundingClientRect().top + (window.pageYOffset || 0);
    var altura = Math.max(420, Math.round(window.innerHeight - topo));
    raiz.style.setProperty('--lt-altura', altura + 'px');
  }

  function iniciar() {
    raiz = $('lt-raiz');
    if (!raiz) { return; }

    medirLargura();
    window.addEventListener('resize', medirLargura);
    if (window.ResizeObserver) { new ResizeObserver(medirLargura).observe(document.documentElement); }

    montarBarra();

    // O aviso de IA abre no pé e diminui o espaço da conversa: o FLIP mede
    // antes do clique e anima depois que o navegador abriu.
    var avisoIa = $('lt-aviso-ia');
    if (avisoIa) {
      avisoIa.querySelector('summary').addEventListener('click', function (e) {
        e.preventDefault();
        semPulo(function () { avisoIa.open = !avisoIa.open; });
      });
    }

    // A lista flutua sobre a conversa: fecha com Esc e com clique fora dela.
    function fecharHistorico(devolverFoco) {
      if (!historicoAberto) { return; }
      historicoAberto = false;
      redesenharHistorico(atual);
      if (devolverFoco) { $('lt-abrir').focus(); }
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { fecharHistorico(true); fecharContinuar(true); }
    });
    document.addEventListener('click', function (e) {
      var ancora = raiz.querySelector('.lt-historico-ancora');
      if (ancora && !ancora.contains(e.target)) { fecharHistorico(false); fecharContinuar(false); }
    });

    $('lt-continuar').addEventListener('click', function () {
      if (continuarAberto) { fecharContinuar(true); } else { abrirContinuar(); }
    });

    $('lt-abrir').addEventListener('click', function () {
      fecharContinuar(false);
      historicoAberto = !historicoAberto;
      redesenharHistorico(atual);
      if (historicoAberto) {
        var primeira = $('lt-historico').querySelector('.lt-passado');
        if (primeira) { primeira.focus(); }
      } else {
        $('lt-abrir').focus();
      }
    });

    // O teclado do celular não pode cobrir o campo.
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', function () {
        var foco = document.activeElement;
        if (foco && /TEXTAREA|INPUT/.test(foco.tagName)) {
          setTimeout(function () { foco.scrollIntoView({ block: 'center', behavior: 'auto' }); }, 60);
        }
      });
    }

    // Cabeçalho e barra entram em 220 ms; a primeira pergunta, 120 ms depois.
    raiz.querySelector('.lt-topo').classList.add('lt-entra-curto');

    var anexo = '';
    try { anexo = new URLSearchParams(window.location.search).get('leticia_anexo') || ''; } catch (e) { /* navegador antigo: segue o briefing */ }

    if (anexo) {
      Api.anexo = anexo;
      Api.pedir('/anexo/abrir', {}).then(function (t) {
        return espera(reduzido() ? 0 : 340).then(function () { return telaAnexo(t); });
      }).catch(function (e) {
        prepararPalco();
        perguntaEl.textContent = 'Não consegui abrir este link.';
        falhaDeRede(e);
      });
      return;
    }

    Api.token = Api.lembrar();

    // O link de "continuar depois": sai da barra de endereço na hora — ele é
    // uma chave, e não deve ficar no histórico nem ser copiado junto com a
    // página por quem a compartilhar.
    var retomar = '';
    try {
      var busca = new URLSearchParams(window.location.search);
      retomar = busca.get('leticia_retomar') || '';
      if (retomar && window.history && window.history.replaceState) {
        busca.delete('leticia_retomar');
        var resto = busca.toString();
        window.history.replaceState(null, '', window.location.pathname + (resto ? '?' + resto : '') + window.location.hash);
      }
    } catch (e) { /* navegador antigo: segue com o token deste aparelho */ }

    Api.pedir('/sessao', retomar ? { retomar: retomar } : {}).then(function (t) {
      avisarDoLink(t);
      ofertarRetomada(t);
      return espera(reduzido() ? 0 : 340).then(function () { return aplicar(t); });
    }).catch(function (e) {
      prepararPalco();
      perguntaEl.textContent = 'Não consegui abrir o briefing agora.';
      falhaDeRede(e);
    });
  }

  /*
   * Toda troca do que está na área de resposta — o campo, a gravação, o
   * cartão da voz, o rascunho — passa pelo FLIP: a altura muda, e o que está
   * acima desliza em vez de pular. A altura travada por esvaziarArea() sai
   * aqui, porque o conteúdo novo é que manda.
   */
  [
    ['desenharArea', function (f) { desenharArea = f; }, desenharArea],
    ['areaGravando', function (f) { areaGravando = f; }, areaGravando],
    ['areaConfirmaVoz', function (f) { areaConfirmaVoz = f; }, areaConfirmaVoz],
    ['areaProposta', function (f) { areaProposta = f; }, areaProposta]
  ].forEach(function (item) {
    var original = item[2];
    item[1](function () {
      var args = arguments;
      return semPulo(function () {
        if (area) { area.style.minHeight = ''; }
        return original.apply(null, args);
      });
    });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
