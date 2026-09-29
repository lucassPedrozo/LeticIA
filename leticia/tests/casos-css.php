<?php
/**
 * O CSS.
 *
 * O caso que importa é o primeiro, e ele existe por causa de um defeito que já
 * aconteceu no projeto vizinho: sem o prefixo `.leticia-raiz`, uma regra vale
 * 0-1-0 de especificidade e perde para qualquer `.classe-do-tema button`. Foi
 * assim que um kit do Elementor transformou um botão de fechar num retângulo
 * azul de 72x40 — e ninguém percebeu até um cliente reclamar.
 *
 * Ler CSS com expressão regular é grosseiro, e aqui é justamente o que se quer:
 * o teste precisa ser burro o bastante para continuar valendo quando alguém
 * escrever a próxima regra às pressas.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$css = function () {
	$caminho = LETICIA_DIR . 'public/leticia.css';
	return is_readable( $caminho ) ? file_get_contents( $caminho ) : '';
};

/** Tira comentários e devolve os seletores, um a um. */
$seletores = function ( $css ) {
	$css   = preg_replace( '#/\*.*?\*/#s', '', $css );
	$saida = array();

	if ( preg_match_all( '/([^{}]+)\{[^{}]*\}/', $css, $achados ) ) {
		foreach ( $achados[1] as $bruto ) {
			$bruto = trim( $bruto );

			// @media, @supports e @keyframes não são seletores; os passos de
			// um keyframe (from, to, 50%) também não.
			if ( '' === $bruto || 0 === strpos( $bruto, '@' ) ) {
				continue;
			}
			if ( preg_match( '/^(from|to|[\d.]+%)(\s*,\s*(from|to|[\d.]+%))*$/', $bruto ) ) {
				continue;
			}

			foreach ( explode( ',', $bruto ) as $seletor ) {
				$seletor = trim( $seletor );
				if ( '' !== $seletor ) {
					$saida[] = $seletor;
				}
			}
		}
	}

	return $saida;
};

$casos[] = array(
	'grupo'    => 'css · território',
	'nome'     => 'toda regra começa com .leticia-raiz',
	'executar' => function () use ( $css, $seletores ) {
		$texto = $css();
		if ( '' === $texto ) {
			return 'não achei public/leticia.css';
		}

		$fora = array();
		foreach ( $seletores( $texto ) as $seletor ) {
			if ( 0 !== strpos( $seletor, '.leticia-raiz' ) ) {
				$fora[] = $seletor;
			}
		}

		return $fora ? 'sem prefixo: ' . implode( ' | ', array_slice( $fora, 0, 5 ) ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'css · território',
	'nome'     => 'nenhum !important fora das duas exceções',
	'executar' => function () use ( $css ) {
		// Se precisou de !important, a regra está mal escrita ou o prefixo
		// faltou — com duas exceções, e só duas: [hidden] e movimento reduzido.
		// Nos dois ele é o mecanismo, não remendo. Esta regra já foi escrita
		// sem exceção nenhuma, e cumpri-la quebrou os dois sem nenhum outro
		// teste perceber.
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );

		// Tira o bloco de movimento reduzido inteiro, e a regra do [hidden].
		$texto = preg_replace( '/@media \(prefers-reduced-motion: reduce\) \{.*?
\}/s', '', $texto );
		$texto = str_replace( '.leticia-raiz [hidden] { display: none !important; }', '', $texto );

		return false === strpos( $texto, '!important' ) ? null : 'apareceu !important fora das exceções';
	},
);

$casos[] = array(
	'grupo'    => 'css · território',
	'nome'     => '[hidden] esconde mesmo, vença quem vencer por especificidade',
	'executar' => function () use ( $css ) {
		// O histórico declara display: flex. Sem !important no [hidden], ele
		// vence por ordem de fonte e aparece aberto quando devia estar fechado.
		return false !== strpos( $css(), '.leticia-raiz [hidden] { display: none !important; }' )
			? null
			: 'o [hidden] perdeu o !important';
	},
);

$casos[] = array(
	'grupo'    => 'css · movimento',
	'nome'     => 'o movimento reduzido vence as animações dos componentes',
	'executar' => function () use ( $css ) {
		// .leticia-raiz * vale 0-1-0 e .leticia-raiz .lt-entra vale 0-2-0: sem
		// !important, as animações continuam para quem pediu que não houvesse.
		if ( ! preg_match( '/@media \(prefers-reduced-motion: reduce\) \{(.*?)
\}/s', $css(), $bloco ) ) {
			return 'não há bloco de movimento reduzido';
		}
		foreach ( array( 'animation-duration', 'transition-duration' ) as $prop ) {
			if ( ! preg_match( '/' . $prop . ':[^;]*!important/', $bloco[1] ) ) {
				return $prop . ' no movimento reduzido não tem !important — perde para os componentes';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'css · território',
	'nome'     => 'a barra do topo é sticky, nunca fixed',
	'executar' => function () use ( $css ) {
		// Um único ancestral com `transform` — e temas fazem isso o tempo todo
		// em animação de entrada — faz `position: fixed` se medir por ele em
		// vez de pela janela, e a barra some do lugar.
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );
		return false === strpos( $texto, 'position: fixed' ) ? null : 'alguém usou position: fixed';
	},
);

$casos[] = array(
	'grupo'    => 'css · território',
	'nome'     => 'nada depende de background-image',
	'executar' => function () use ( $css ) {
		// A regra de carregamento preguiçoso do Elementor apaga essa
		// propriedade com !important em todo descendente.
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );
		return false === strpos( $texto, 'background-image' ) ? null : 'alguém dependeu de background-image';
	},
);

// ------------------------------------------------------------------ movimento

$casos[] = array(
	'grupo'    => 'css · movimento',
	'nome'     => 'nenhuma animação passa de 420 ms',
	'executar' => function () use ( $css ) {
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );

		// Os tokens de duração e os valores literais em animation/transition.
		preg_match_all( '/(\d+)ms/', $texto, $achados );

		$longas = array();
		foreach ( $achados[1] as $ms ) {
			// 1400ms é o ciclo dos pontinhos, que é o único que se repete, e
			// 1200ms é o tempo que a marca de conferido espera antes de sumir —
			// nenhum dos dois é deslocamento.
			if ( (int) $ms > 420 && ! in_array( (int) $ms, array( 1400, 1200 ), true ) ) {
				$longas[] = $ms . 'ms';
			}
		}

		return $longas ? 'duração longa demais: ' . implode( ', ', array_unique( $longas ) ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'css · movimento',
	'nome'     => 'só transform e opacity são animados',
	'executar' => function () use ( $css ) {
		// Animar height, top ou box-shadow força o navegador a refazer layout
		// a cada quadro, e num celular isso aparece como engasgo.
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );
		$proibidas = array( 'height', 'width', 'top', 'left', 'margin', 'padding', 'box-shadow', 'border-radius' );

		if ( ! preg_match_all( '/@keyframes[^{]+\{(.+?)\}\s*\}/s', $texto, $blocos ) ) {
			return null;
		}

		foreach ( $blocos[1] as $corpo ) {
			foreach ( $proibidas as $prop ) {
				if ( preg_match( '/(^|[;{\s])' . $prop . '\s*:/', $corpo ) ) {
					return 'um keyframe anima ' . $prop;
				}
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'css · movimento',
	'nome'     => 'movimento reduzido desliga a animação',
	'executar' => function () use ( $css ) {
		$texto = $css();
		if ( false === strpos( $texto, 'prefers-reduced-motion' ) ) {
			return 'não há bloco de movimento reduzido';
		}
		// E o indicador de digitação vira rótulo, em vez de sumir sem deixar
		// nada no lugar.
		return false !== strpos( $texto, 'lt-pensando-rotulo { display: block' ) ? null : 'o indicador some sem virar texto';
	},
);

// ------------------------------------------------------------------- celular

$casos[] = array(
	'grupo'    => 'css · celular',
	'nome'     => 'o campo tem 16px, senão o iOS dá zoom sozinho',
	'executar' => function () use ( $css ) {
		return preg_match( '/\.lt-campo textarea \{[^}]*font-size: 16px/s', $css() )
			? null
			: 'o campo de texto não está em 16px';
	},
);

$casos[] = array(
	'grupo'    => 'css · celular',
	'nome'     => 'a altura usa dvh, não vh',
	'executar' => function () use ( $css ) {
		// `vh` não encolhe quando a barra de endereço do celular aparece, e a
		// tela salta enquanto a pessoa rola.
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );
		if ( false === strpos( $texto, '100dvh' ) ) {
			return 'não usa dvh em lugar nenhum';
		}
		return preg_match( '/(?<!d)100vh/', $texto ) ? 'ainda há 100vh' : null;
	},
);

$casos[] = array(
	'grupo'    => 'css · celular',
	'nome'     => 'os alvos de toque têm pelo menos 44 px',
	'executar' => function () use ( $css ) {
		$texto = preg_replace( '#/\*.*?\*/#s', '', $css() );

		foreach ( array( 'lt-btn', 'lt-escolha', 'lt-remover', 'lt-editar' ) as $classe ) {
			if ( ! preg_match( '/\.' . $classe . ' \{[^}]*(min-height|height): (4[4-9]|[5-9]\d)px/s', $texto ) ) {
				return $classe . ' está abaixo de 44px';
			}
		}
		return null;
	},
);

// --------------------------------------------------------------- o shortcode

$casos[] = array(
	'grupo'    => 'css · tela',
	'nome'     => 'o JS não decide roteiro nenhum',
	'executar' => function () {
		// Se o navegador souber a lista de campos, ela pode ser adulterada pelo
		// console — e o roteiro deixa de ser do servidor.
		$caminho = LETICIA_DIR . 'public/leticia.js';
		$js      = is_readable( $caminho ) ? file_get_contents( $caminho ) : '';

		if ( '' === $js ) {
			return 'não achei public/leticia.js';
		}

		foreach ( Leticia_Campos::chaves() as $chave ) {
			// 'logo' aparece em 'logomarca' no texto das pendências; o que não
			// pode existir é uma lista de campos codificada.
			if ( preg_match( "/'" . $chave . "'\s*[,:\]]/", $js ) ) {
				return 'a chave "' . $chave . '" está escrita no JavaScript';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'css · foco',
	'nome'     => 'o campo de digitar não ganha contorno azul próprio',
	'executar' => function () use ( $css ) {
		// A pílula mostra o foco. O :focus-visible geral pintava um retângulo
		// azul de 2px dentro dela, e o tema somava a borda do textarea:focus.
		$c = preg_replace( '#/\*.*?\*/#s', '', $css() );
		if ( ! preg_match( '/\.lt-campo textarea:focus[^{]*\{[^}]*outline:\s*none[^}]*border:\s*0[^}]*box-shadow:\s*none/', $c ) ) {
			return 'o textarea da pílula não zera outline, borda e sombra no foco';
		}
		if ( preg_match( '/:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--lt-acento\)/', $c ) ) {
			return 'o foco geral voltou a ser o acento cheio';
		}
		return preg_match( '/\.lt-campo:focus-within\s*\{[^}]*--lt-acento/', $c ) ? 'a pílula em foco ganhou cor de acento' : null;
	},
);

$casos[] = array(
	'grupo'    => 'css · tela',
	'nome'     => 'o exemplo dentro do campo cabe numa linha de celular',
	'executar' => function () {
		// Com a explicação no detalhe, o placeholder é só um exemplo. Longo, ele
		// quebrava dentro da pílula de uma linha e saía cortado no meio.
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( isset( $campo['dica'] ) && mb_strlen( $campo['dica'], 'UTF-8' ) > 26 ) {
				return $campo['chave'] . ': "' . $campo['dica'] . '"';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'css · tela',
	'nome'     => 'pergunta e detalhe são elementos separados',
	'executar' => function () use ( $css ) {
		$js = file_get_contents( LETICIA_DIR . 'public/leticia.js' );
		if ( false === strpos( $js, "criar('p', 'lt-detalhe')" ) || false === strpos( $js, 't.campo.detalhe' ) ) {
			return 'o JS não desenha o detalhe à parte';
		}
		return false !== strpos( $css(), '.leticia-raiz .lt-detalhe' ) ? null : 'o detalhe não tem estilo';
	},
);

$casos[] = array(
	'grupo'    => 'css · território',
	'nome'     => 'nenhum botão pinta fundo, cor, borda ou sombra direto',
	'executar' => function () use ( $css ) {
		// Tema pinta botão em hover e foco (o Hello Elementor deixa vermelho).
		// Quem pinta botão aqui é uma regra só, pelas variáveis --lt-b-*; um
		// componente que volte a pintar direto perde para o tema no hover.
		$c = preg_replace( '#/\*.*?\*/#s', '', $css() );
		if ( ! preg_match( '/\.leticia-raiz\.leticia-raiz button:hover[^{]*\{[^}]*var\(--lt-b-fundo/', $c ) ) {
			return 'a regra única dos estados de botão sumiu';
		}
		$botoes = '(abrir-historico|passado|redondo|enviar|btn|btn-primario|escolha|remover|editar)';
		preg_match_all( '/([^{}]+)\{([^}]*)\}/', $c, $regras, PREG_SET_ORDER );
		foreach ( $regras as $r ) {
			foreach ( explode( ',', $r[1] ) as $seletor ) {
				$seletor = trim( $seletor );
				$e_botao = preg_match( '/\.lt-' . $botoes . '(?![\w-])[^\s]*$/', $seletor ) || preg_match( '/\.lt-retomada button[^\s]*$/', $seletor );
				if ( ! $e_botao ) {
					continue;
				}
				if ( preg_match( '/(?<![-\w])(background|color|border-color|box-shadow|border|border-(top|right|bottom|left))\s*:/', $r[2], $m ) ) {
					return $seletor . ' pinta ' . $m[1] . ' direto';
				}
			}
		}
		return null;
	},
);

return $casos;
