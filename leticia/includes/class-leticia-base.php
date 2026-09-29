<?php
/**
 * A base dos campos — as palavras que a LetícIA diz.
 *
 * Lê `conhecimento/campos.md` e devolve um mapa de blocos. A estrutura do
 * briefing (ordem, obrigatoriedade, formato) não mora aqui: mora em
 * Leticia_Campos. Aqui é só texto, e é assim de propósito — mudar a pergunta de
 * um campo tem que ser editar um arquivo de texto, não mexer em PHP.
 *
 * Um teste de paridade cobra que os dois estejam de acordo.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Base {

	const CACHE = 'leticia_base_cache';

	/** As seis rubricas de um campo. Faltou uma, o bloco está incompleto. */
	const RUBRICAS = array( 'pergunta', 'porque', 'serve', 'borda', 'guiar', 'depois' );

	/** As rubricas escritas como lista de variantes, e não como prosa. */
	const LISTAS = array( 'pergunta', 'depois' );

	/** Como cada rubrica aparece escrita no arquivo. */
	const TITULOS = array(
		'Pergunta'            => 'pergunta',
		'Por que perguntamos' => 'porque',
		'O que serve'         => 'serve',
		'Casos de borda'      => 'borda',
		'Como guiar'          => 'guiar',
		'Depois da resposta'  => 'depois',
	);

	/** @var array|null memo por requisição */
	private static $memo = null;

	public static function caminho() {
		return apply_filters( 'leticia_caminho_base', LETICIA_DIR . 'conhecimento/campos.md' );
	}

	public static function existe() {
		$caminho = self::caminho();
		return is_string( $caminho ) && is_readable( $caminho ) && filesize( $caminho ) > 0;
	}

	/**
	 * Tudo que o arquivo tem, já dividido.
	 *
	 *   array(
	 *     'campos' => array( 'ramo' => array( 'pergunta' => array(...), 'porque' => '...', ... ) ),
	 *     'textos' => array( 'consentimento' => '...' ),
	 *   )
	 *
	 * @return array|WP_Error
	 */
	public static function carregar() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$caminho = self::caminho();
		if ( ! self::existe() ) {
			return new WP_Error( 'base_ausente', sprintf( 'Não encontrei a base dos campos em %s.', $caminho ) );
		}

		$assinatura = md5( $caminho . '|' . filemtime( $caminho ) . '|' . filesize( $caminho ) );

		$cache = get_transient( self::CACHE );
		if ( is_array( $cache ) && isset( $cache['assinatura'], $cache['dados'] ) && $cache['assinatura'] === $assinatura ) {
			self::$memo = $cache['dados'];
			return self::$memo;
		}

		$texto = file_get_contents( $caminho ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $texto || '' === trim( $texto ) ) {
			return new WP_Error( 'base_ilegivel', sprintf( 'A base em %s está vazia ou não pôde ser lida.', $caminho ) );
		}

		$dados = self::interpretar( $texto );

		set_transient( self::CACHE, array( 'assinatura' => $assinatura, 'dados' => $dados ), DAY_IN_SECONDS );
		self::$memo = $dados;

		return $dados;
	}

	/**
	 * O interpretador. Deliberadamente burro: dois níveis de título e nada mais.
	 *
	 * `## campo: <chave>` abre um bloco de campo, `## texto: <chave>` abre um
	 * texto solto, e `### <rubrica>` divide o bloco. Qualquer outro `##` é
	 * prosa para quem edita o arquivo e é ignorado — é o que permite o cabeçalho
	 * explicativo conviver com os dados no mesmo arquivo.
	 */
	public static function interpretar( $texto ) {
		// Quebra de linha sempre LF, venha o arquivo do Windows ou do Linux.
		$texto = str_replace( array( "\r\n", "\r" ), "\n", $texto );

		$campos  = array();
		$textos  = array();
		$alvo    = null;   // array( 'tipo' => 'campo'|'texto', 'chave' => '...' )
		$rubrica = null;
		$buffer  = array();

		$fechar = function () use ( &$campos, &$alvo, &$rubrica, &$buffer ) {
			if ( null === $alvo || 'campo' !== $alvo['tipo'] || null === $rubrica ) {
				$buffer = array();
				return;
			}
			$conteudo = trim( implode( "\n", $buffer ) );
			if ( in_array( $rubrica, self::LISTAS, true ) ) {
				$campos[ $alvo['chave'] ][ $rubrica ] = self::itens( $conteudo );
			} else {
				$campos[ $alvo['chave'] ][ $rubrica ] = $conteudo;
			}
			$buffer = array();
		};

		foreach ( explode( "\n", $texto ) as $linha ) {

			if ( 0 === strpos( $linha, '## ' ) ) {
				$fechar();
				$rubrica = null;
				$titulo  = trim( substr( $linha, 3 ) );

				if ( preg_match( '/^campo:\s*([a-z0-9_]+)$/i', $titulo, $achado ) ) {
					$alvo = array( 'tipo' => 'campo', 'chave' => strtolower( $achado[1] ) );
					if ( ! isset( $campos[ $alvo['chave'] ] ) ) {
						$campos[ $alvo['chave'] ] = array();
					}
				} elseif ( preg_match( '/^texto:\s*([a-z0-9_\-]+)$/i', $titulo, $achado ) ) {
					$alvo = array( 'tipo' => 'texto', 'chave' => strtolower( $achado[1] ) );
					$textos[ $alvo['chave'] ] = '';
				} else {
					$alvo = null;   // prosa do cabeçalho
				}
				continue;
			}

			if ( 0 === strpos( $linha, '### ' ) && $alvo && 'campo' === $alvo['tipo'] ) {
				$fechar();
				$titulo  = trim( substr( $linha, 4 ) );
				$rubrica = isset( self::TITULOS[ $titulo ] ) ? self::TITULOS[ $titulo ] : null;
				continue;
			}

			// Separador entre campos. Não é conteúdo de ninguém.
			if ( '---' === trim( $linha ) ) {
				continue;
			}

			if ( $alvo && 'texto' === $alvo['tipo'] ) {
				$textos[ $alvo['chave'] ] = trim( $textos[ $alvo['chave'] ] . "\n" . $linha );
				continue;
			}

			if ( $rubrica ) {
				$buffer[] = $linha;
			}
		}

		$fechar();

		return array( 'campos' => $campos, 'textos' => $textos );
	}

	/** As linhas que começam com "- " viram itens; o resto é descartado. */
	private static function itens( $bloco ) {
		$saida = array();
		foreach ( explode( "\n", $bloco ) as $linha ) {
			$linha = trim( $linha );
			if ( 0 === strpos( $linha, '- ' ) ) {
				$item = trim( substr( $linha, 2 ) );
				if ( '' !== $item ) {
					$saida[] = $item;
				}
			}
		}
		return $saida;
	}

	/**
	 * O bloco de um campo. É o único trecho da base que vai ao modelo.
	 *
	 * @return array|null
	 */
	public static function bloco( $chave ) {
		$dados = self::carregar();
		if ( is_wp_error( $dados ) ) {
			return null;
		}
		return isset( $dados['campos'][ $chave ] ) ? $dados['campos'][ $chave ] : null;
	}

	/** Um texto solto: abertura de seção, consentimento. */
	public static function texto( $chave, $padrao = '' ) {
		$dados = self::carregar();
		if ( is_wp_error( $dados ) ) {
			return $padrao;
		}
		return isset( $dados['textos'][ $chave ] ) && '' !== $dados['textos'][ $chave ]
			? $dados['textos'][ $chave ]
			: $padrao;
	}

	// ------------------------------------------------------- variantes e partes

	/**
	 * Um texto solto como lista de variantes.
	 *
	 * Escrito com linhas "- ", cada linha é uma variante; escrito como prosa, o
	 * texto inteiro é a única. É o mesmo formato das perguntas, para quem edita
	 * a base não precisar aprender dois.
	 *
	 * @return string[]
	 */
	public static function variantes( $chave ) {
		$texto = self::texto( $chave );
		if ( '' === $texto ) {
			return array();
		}
		$itens = self::itens( $texto );
		return $itens ? $itens : array( $texto );
	}

	/**
	 * Troca {nome} e {empresa} pelo que a pessoa respondeu.
	 *
	 * Devolve null quando falta valor para alguma marcação. Null, e não o texto
	 * com o buraco: "Prazer, !" é pior do que usar outra variante — e é
	 * exatamente o que quem escolhe a variante faz com o null.
	 *
	 * @return string|null
	 */
	public static function preencher( $texto, array $valores ) {
		$faltou = false;
		$saida  = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			function ( $m ) use ( $valores, &$faltou ) {
				$valor = isset( $valores[ $m[1] ] ) ? trim( (string) $valores[ $m[1] ] ) : '';
				if ( '' === $valor ) {
					$faltou = true;
					return '';
				}
				return $valor;
			},
			(string) $texto
		);
		return $faltou ? null : $saida;
	}

	/**
	 * Título e detalhe de uma variante escrita como "título | detalhe".
	 *
	 * Na tela, o título é a pergunta grande e o detalhe vem embaixo, menor. Sem
	 * a barra, a variante inteira é título — e é por isso que a regra ficou no
	 * texto e não num campo à parte: quem escreve a pergunta decide o que é
	 * pergunta e o que é explicação.
	 *
	 * @return array array( 'titulo' => string, 'detalhe' => string )
	 */
	public static function partes( $variante ) {
		$pedacos = explode( ' | ', (string) $variante, 2 );
		return array(
			'titulo'  => trim( $pedacos[0] ),
			'detalhe' => isset( $pedacos[1] ) ? trim( $pedacos[1] ) : '',
		);
	}

	/** A variante sem a barra, como frase corrida — para o modelo e o e-mail. */
	public static function corrida( $variante ) {
		$p = self::partes( $variante );
		return trim( $p['titulo'] . ' ' . $p['detalhe'] );
	}

	/**
	 * Escolhe uma variante presa à semente, pulando as que não dá para preencher.
	 *
	 * Começa na posição da semente e anda para a frente. Assim a mesma pessoa
	 * vê sempre a mesma redação, e a variante com "{nome}" só aparece para quem
	 * disse o nome.
	 *
	 * @param string|string[] $variantes chave de texto solto, ou a lista pronta
	 * @return array|null array( 'titulo', 'detalhe' ), ou null se nenhuma serve
	 */
	public static function escolher( $variantes, $semente, array $valores = array() ) {
		$lista = is_array( $variantes ) ? array_values( $variantes ) : self::variantes( $variantes );
		$total = count( $lista );

		for ( $i = 0; $i < $total; $i++ ) {
			$cheia = self::preencher( $lista[ ( abs( (int) $semente ) + $i ) % $total ], $valores );
			if ( null !== $cheia ) {
				return self::partes( $cheia );
			}
		}
		return null;
	}

	/**
	 * "Marina Alves" vira "Marina". Usado para chamar a pessoa pelo nome.
	 *
	 * "Dra. Marina" também vira "Marina", e "eu" não vira nome nenhum: a
	 * primeira pessoa que testou respondeu "eu" e foi chamada de "Eu" até o fim.
	 */
	public static function primeiro_nome( $nome ) {
		$partes = preg_split( '/\s+/', trim( (string) $nome ) );
		$partes = is_array( $partes ) ? $partes : array();

		while ( $partes && preg_match( '/^(sr|sra|srta|dr|dra|prof|profa)\.?$/iu', $partes[0] ) ) {
			array_shift( $partes );
		}
		$nome = $partes && '' !== $partes[0] ? $partes[0] : '';

		$simples = function_exists( 'mb_strtolower' ) ? mb_strtolower( $nome, 'UTF-8' ) : strtolower( $nome );
		if ( in_array( $simples, array( 'eu', 'mim', 'nós', 'nos', 'a', 'o', 'gente', 'ninguém', 'ninguem', 'sou', 'meu', 'minha' ), true )
			|| ! preg_match( '/^\p{L}/u', $nome ) ) {
			return '';
		}
		// Nome todo em minúscula ou maiúscula ("marina", "MARINA") volta com a
		// inicial maiúscula: chamar alguém de "marina" parece descuido.
		if ( '' !== $nome && function_exists( 'mb_convert_case' ) && ( mb_strtolower( $nome, 'UTF-8' ) === $nome || mb_strtoupper( $nome, 'UTF-8' ) === $nome ) ) {
			$nome = mb_convert_case( $nome, MB_CASE_TITLE, 'UTF-8' );
		}
		return $nome;
	}

	public static function limpar_cache() {
		self::$memo = null;
		delete_transient( self::CACHE );
	}

	/** Dados para a tela de configuração. */
	public static function info() {
		$caminho = self::caminho();
		if ( ! self::existe() ) {
			return array( 'ok' => false, 'caminho' => $caminho );
		}
		return array(
			'ok'         => true,
			'caminho'    => $caminho,
			'bytes'      => filesize( $caminho ),
			'modificado' => filemtime( $caminho ),
		);
	}
}
