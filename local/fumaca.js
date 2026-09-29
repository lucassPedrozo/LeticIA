/*
 * Teste de fumaça da tela: um briefing inteiro, de ponta a ponta, sozinho.
 *
 *   http://localhost:8765/?fumaca=1          com os arquivos de leticia/public
 *   http://localhost:8765/?fumaca=1&min=1    com os minificados, como no .zip
 *
 * Clica, digita e decide como um cliente faria — apresentação, perguntas,
 * rascunho, sugestão de contatos, a tela das redes e páginas, a logo para
 * depois, revisão e envio — e no fim diz se chegou em "Briefing recebido"
 * sem erro de JavaScript, sem a barra de retomada sobrando e sem salto de
 * layout. O resultado aparece num quadro no canto e fica em window.__fumaca.
 *
 * Usa um briefing novo: o rascunho que estava neste navegador é guardado
 * antes e devolvido no fim. O e-mail do envio, localmente, vira arquivo em
 * local/dados/emails/. Com chave do Gemini no .env, o ramo e os serviços
 * chamam o modelo de verdade.
 *
 * Só existe no servidor local; não vai no .zip.
 */
(function () {
  'use strict';

  var CHAVE_TOKEN = 'lt-token-v1';
  var GUARDADO = 'fumaca-token-original';
  var RODANDO = 'fumaca-rodando';

  // Primeira passada: guarda o rascunho deste navegador, zera, e recarrega
  // para a tela abrir num briefing novo.
  if (sessionStorage.getItem(RODANDO) !== '1') {
    sessionStorage.setItem(GUARDADO, localStorage.getItem(CHAVE_TOKEN) || '');
    sessionStorage.setItem(RODANDO, '1');
    sessionStorage.removeItem('leticia-apresentacao-vista');
    localStorage.removeItem(CHAVE_TOKEN);
    location.reload();
    return;
  }
  sessionStorage.removeItem(RODANDO);

  var R = { ok: false, passos: [], erros: [], saltos: [], minificado: /[?&]min=1/.test(location.search) };
  window.__fumaca = R;

  window.addEventListener('error', function (e) { R.erros.push(e.message); });
  window.addEventListener('unhandledrejection', function (e) {
    R.erros.push(String((e.reason && e.reason.message) || e.reason));
  });

  // ---------------------------------------------------------------- quadro
  var quadro = document.createElement('pre');
  quadro.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;max-width:420px;max-height:45vh;overflow:auto;' +
    'margin:0;padding:10px 12px;font:12px/1.45 ui-monospace,monospace;background:#111827;color:#e5e7eb;border-radius:10px;opacity:.94;white-space:pre-wrap';
  document.body.appendChild(quadro);
  function nota(texto) {
    R.passos.push(texto);
    quadro.textContent = 'fumaça' + (R.minificado ? ' · minificado' : '') + '\n' + R.passos.slice(-14).join('\n');
  }

  // ---------------------------------------------------------------- ajudas
  function esperar(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function ate(condicao, rotulo, maximo) {
    var inicio = Date.now();
    return new Promise(function (resolve, reject) {
      (function tentar() {
        var v;
        try { v = condicao(); } catch (e) { v = null; }
        if (v) { return resolve(v); }
        if (Date.now() - inicio > (maximo || 20000)) { return reject(new Error('esperei demais: ' + rotulo)); }
        setTimeout(tentar, 80);
      })();
    });
  }

  function visivel(el) { return el && !el.hidden && el.offsetParent !== null; }

  function botao(comeco) {
    return Array.prototype.slice.call(document.querySelectorAll('.leticia-raiz button')).filter(visivel).filter(function (b) {
      return b.textContent.trim().indexOf(comeco) === 0;
    })[0] || null;
  }

  function pergunta() {
    var p = document.querySelector('.lt-pergunta .lt-invisivel') || document.querySelector('.lt-pergunta, .lt-titulo');
    return p ? p.textContent.trim() : '';
  }

  // O que muda quando a tela anda: a pergunta, ou a fase.
  function assinatura() {
    var raiz = document.getElementById('lt-raiz');
    return (raiz ? raiz.getAttribute('data-fase') : '') + '|' + pergunta() + '|' + (document.querySelector('.lt-proposta') ? 'proposta' : '');
  }

  // --------------------------------------------------------- saltos de tela
  // Amostra a posição da pergunta: um pulo grande entre duas amostras, com a
  // mesma pergunta na tela e sem troca de turno em curso, é salto de layout.
  // Deslize é movimento de propósito: anota quando um começou, e movimento
  // logo depois de um deslize não é salto.
  var ultimoDeslize = 0;
  var animarOriginal = Element.prototype.animate;
  Element.prototype.animate = function () {
    ultimoDeslize = performance.now();
    return animarOriginal.apply(this, arguments);
  };

  var ultimo = null;
  R.amostrasPuladas = 0;
  var vigia = setInterval(function () {
    var el = document.querySelector('.lt-enunciado');
    var palco = document.getElementById('lt-palco');
    if (!el || (palco && palco.style.opacity === '0')) { ultimo = null; return; }
    var agora = { texto: pergunta(), y: Math.round(el.getBoundingClientRect().top), t: performance.now() };
    if (ultimo && ultimo.texto === agora.texto && Math.abs(agora.y - ultimo.y) > 40) {
      // Com a aba em segundo plano o navegador espaça os temporizadores: duas
      // amostras a um segundo uma da outra não dizem se foi salto ou deslize.
      var espacadas = agora.t - ultimo.t > 120;
      var deslizando = agora.t - ultimoDeslize < 400 || ultimo.t - ultimoDeslize < 400;
      if (espacadas) {
        R.amostrasPuladas++;
      } else if (!deslizando) {
        R.saltos.push(agora.texto.slice(0, 40) + ': ' + ultimo.y + ' → ' + agora.y);
      }
    }
    ultimo = agora;
  }, 30);

  // ---------------------------------------------------------- as respostas
  // Pelo exemplo do campo (o placeholder), que é estável e não depende da
  // variante de pergunta sorteada.
  var RESPOSTAS = {
    'Ex.: Marina Alves': 'Marina Alves',
    'Nome da empresa': 'Padaria Aurora',
    '(47) 99999-9999': '47999998888',
    'voce@empresa.com.br': 'marina@padariaaurora.com.br',
    'suaempresa.com.br': 'padariaaurora.com.br',
    'Conte do seu jeito': 'Padaria artesanal de bairro em Joinville, com pães de fermentação natural, bolos de festa por encomenda e café da manhã aos sábados.',
    'Um serviço por linha': 'Pães de fermentação natural\nBolos de festa por encomenda\nCafé da manhã aos sábados',
    'Rua, número, cidade': 'Rua das Flores, 120, Joinville',
    'WhatsApp, e-mail, horário': 'WhatsApp (47) 99999-8888'
  };

  function escrever(texto) {
    var ta = document.getElementById('lt-entrada');
    ta.value = texto;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    var enviar = document.querySelector('.lt-enviar');
    enviar.click();
  }

  // Um passo: descobre em que tela está e faz o que um cliente faria.
  function passo() {
    var fase = document.getElementById('lt-raiz').getAttribute('data-fase');

    if (botao('Começar')) { botao('Começar').click(); return 'apresentação → Começar'; }
    if (fase === 'fim') { return null; }

    if (fase === 'revisao') {
      var aceite = document.getElementById('lt-consentimento');
      if (aceite && !aceite.checked) { aceite.click(); }
      botao('Enviar briefing').click();
      return 'revisão → aceite e enviar';
    }

    var usar = botao('Usar esta lista') || botao('Usar este texto');
    if (usar) { usar.click(); return 'rascunho → ' + usar.textContent.trim(); }
    if (botao('Usar esses contatos')) { botao('Usar esses contatos').click(); return 'contatos → usar os sugeridos'; }
    if (botao('Não tenho nenhum dos dois')) { botao('Não tenho nenhum dos dois').click(); return 'redes e páginas → nenhum'; }
    if (botao('Sim, podem usar')) { botao('Sim, podem usar').click(); return 'imagens → sim'; }
    if (botao('Não estou com o arquivo agora')) { botao('Não estou com o arquivo agora').click(); return 'logo → mandar depois'; }
    if (document.querySelector('.lt-solta') && botao('Pular')) { botao('Pular').click(); return 'arquivo opcional → pular'; }

    var ta = document.getElementById('lt-entrada');
    if (visivel(ta)) {
      var resposta = RESPOSTAS[ta.placeholder];
      if (!resposta) { throw new Error('não sei responder o campo com o exemplo "' + ta.placeholder + '"'); }
      escrever(resposta);
      return pergunta().slice(0, 34) + ' → ' + resposta.split('\n')[0].slice(0, 28);
    }
    return '';
  }

  function concluir() {
    clearInterval(vigia);
    var original = sessionStorage.getItem(GUARDADO);
    if (original) { localStorage.setItem(CHAVE_TOKEN, original); } else { localStorage.removeItem(CHAVE_TOKEN); }
    sessionStorage.removeItem(GUARDADO);
    sessionStorage.removeItem('leticia-apresentacao-vista');

    var problemas = [];
    if (!R.chegou) { problemas.push('não chegou em "Briefing recebido"'); }
    if (R.erros.length) { problemas.push(R.erros.length + ' erro(s) de JavaScript'); }
    if (R.retomadaNoFim) { problemas.push('a barra de retomada ficou na tela final'); }
    if (R.saltos.length) { problemas.push(R.saltos.length + ' salto(s) de layout'); }
    R.ok = !problemas.length;
    R.problemas = problemas;

    nota('');
    nota(R.ok ? '✔ tudo certo' : '✘ ' + problemas.join(' · '));
    if (R.amostrasPuladas) { nota('  (' + R.amostrasPuladas + ' movimento(s) sem como julgar: a aba estava em segundo plano)'); }
    R.erros.forEach(function (e) { nota('  erro: ' + e); });
    R.saltos.slice(0, 5).forEach(function (s) { nota('  salto: ' + s); });
    nota('(o rascunho anterior deste navegador foi devolvido)');
    quadro.style.background = R.ok ? '#064e3b' : '#7f1d1d';
    console.log('[fumaça]', JSON.stringify(R));
  }

  // --------------------------------------------------------------- o laço
  (async function () {
    try {
      await ate(function () { return pergunta() || botao('Começar'); }, 'a primeira tela', 15000);
      for (var voltas = 0; voltas < 60; voltas++) {
        // Só age com a tela quieta: a pergunta já apareceu e ninguém está pensando.
        await ate(function () {
          var esperando = Array.prototype.some.call(document.querySelectorAll('.lt-aguarda'), visivel);
          return !document.querySelector('.lt-pensando') && !esperando &&
            !document.querySelector('.lt-sugerindo') && (document.getElementById('lt-entrada') || document.querySelector('.leticia-raiz button:not([hidden])'));
        }, 'a tela ficar quieta', 20000);
        await esperar(350);

        var antes = assinatura();
        var feito = passo();
        if (feito === null) { break; }
        if (feito) { nota('· ' + feito); }

        // A tela anda — ou fica, quando ela pede mais (e aí a próxima volta
        // responde de novo, mais completo).
        try {
          await ate(function () { return assinatura() !== antes; }, 'a tela andar', 9000);
        } catch (e) {
          if (!document.getElementById('lt-entrada')) { throw e; }
          nota('  (ficou no mesmo campo — respondendo de novo)');
        }
      }

      await ate(function () { return /Briefing recebido/.test(document.body.textContent); }, 'a tela final', 20000);
      R.chegou = true;
      R.retomadaNoFim = !!document.querySelector('#lt-retomada > *');
      nota('· tela final: Briefing recebido');
    } catch (e) {
      R.erros.push(e.message);
    }
    concluir();
  })();
})();
