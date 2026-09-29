<?php
/**
 * O contrato com o modelo.
 *
 * Uma chamada por mensagem do cliente. Nunca se chama o modelo para **produzir**
 * a pergunta: as 15 perguntas são texto estático da base, com variantes escritas
 * à mão. Clique em botão, pulo, volta, upload e validação de formato também não
 * vão ao modelo.
 *
 * O que sai daqui é sempre o mesmo formato, dê certo ou dê errado:
 *
 *   array(
 *     'tipo'            => 'resposta' | 'duvida' | 'ajuda' | 'fora_de_escopo',
 *     'suficiente'      => bool,
 *     'valor_limpo'     => string|null,
 *     'comentario'      => string|null,
 *     'repergunta'      => string|null,
 *     'resposta_duvida' => string|null,
 *     'proposta'        => string|null,  o rascunho, nos campos de conteúdo
 *     'degradado'       => bool,     não houve modelo nesta rodada
 *     'bloqueio'        => string|null,  motivo, quando a trava barrou
 *   )
 *
 * **Quando o JSON quebra, avança.** Duas tentativas, e então a resposta é aceita
 * como está, sem comentário, e o caso fica registrado. Briefing que prende a
 * pessoa num campo é pior que briefing com um campo fraco: a equipe conserta
 * campo fraco por WhatsApp, mas não conserta cliente que fechou a aba.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Modelo {

	/** Teto de tamanho do que a LetícIA fala. Três parágrafos curtos cabem aqui. */
	const TETO_FALA = 700;

	/** Teto do rascunho: 80 palavras de parágrafo, ou oito linhas de serviço. */
	const TETO_PROPOSTA = 1000;

	/**
	 * Vale gastar uma chamada com esta resposta?
	 *
	 * O modelo não é chamado para confirmar o que o PHP já sabe. Domínio com
	 * formato válido, @ de rede social, contato com número: a reação escrita
	 * da base dá conta, e a resposta sai na hora. Ele fica para o que só ele
	 * faz — julgar resposta de conteúdo, escrever o rascunho, responder dúvida
	 * e ajudar quem pediu.
	 *
	 * Antes, os seis campos que comentam chamavam sempre: seis chamadas fixas
	 * por briefing, metade delas para dizer "anotado" com outras palavras.
	 *
	 * @param array $validado o que Leticia_Validacao::checar() devolveu
	 * @return string '' quando vale chamar; senão, o motivo de poupar
	 */
	public static function motivo_para_poupar( array $campo, $bruto, array $estado, array $validado ) {
		if ( ! empty( $validado['negado'] ) ) {
			return 'negado';
		}
		if ( Leticia_Validacao::pede_ajuda( $bruto ) || Leticia_Validacao::parece_duvida( $bruto ) ) {
			return '';
		}
		if ( empty( $campo['comenta'] ) ) {
			return 'nao_comenta';
		}

		switch ( isset( $campo['poupa'] ) ? $campo['poupa'] : '' ) {
			case 'valido':
				return ! empty( $validado['ok'] ) ? 'formato_valido' : '';

			case 'contato':
				if ( '' !== trim( (string) $bruto ) && trim( (string) $bruto ) === Leticia_Roteiro::sugestao( $estado, $campo ) ) {
					return 'sugestao_aceita';
				}
				$plano = Leticia_Validacao::simplificar( $bruto );
				if ( Leticia_Trava::achar_telefones( $bruto ) || Leticia_Trava::achar_emails( $bruto )
					|| preg_match( '/\b(whats|whatsapp|zap|telefone|fone|celular|e-?mail|instagram|insta|facebook|endereco|horario)\b/u', $plano ) ) {
					return 'tem_contato';
				}
				return '';

			case 'perfil':
				return preg_match( '/(^|[\s,;(])@[\w.]{2,}|(instagram|facebook|fb|tiktok|linkedin|youtube|x|twitter|threads)\.com\/\S+/iu', (string) $bruto )
					? 'tem_perfil'
					: '';

			case 'lista':
				$itens = preg_split( '/\s*(?:,|;|\n|\s+e\s+)\s*/u', trim( (string) $bruto ), -1, PREG_SPLIT_NO_EMPTY );
				return count( $itens ) >= 2 ? 'lista_clara' : '';
		}

		return '';
	}

	/**
	 * Consulta o modelo sobre uma mensagem do cliente.
	 *
	 * @param array  $campo    o campo atual
	 * @param string $bruto    o que o cliente escreveu
	 * @param array  $contexto empresa, ramo, servicos — para conduzir
	 * @param array  $opcoes   'reperguntando' => bool, 'curto' => bool, 'sugerir' => bool, 'sessao' => string
	 */
	public static function consultar( array $campo, $bruto, array $contexto = array(), array $opcoes = array() ) {
		$sessao        = isset( $opcoes['sessao'] ) ? $opcoes['sessao'] : '';
		$reperguntando = ! empty( $opcoes['reperguntando'] );

		if ( ! Leticia_Limites::pode_consultar_modelo( $sessao, $bruto ) ) {
			return self::degradado();
		}

		$instrucao = Leticia_Prompt::instrucao( $campo );
		$anterior  = isset( $opcoes['anterior'] ) ? (string) $opcoes['anterior'] : '';
		$turno     = Leticia_Prompt::turno( $campo, $bruto, $contexto, $reperguntando, isset( $opcoes['ultima_ponte'] ) ? $opcoes['ultima_ponte'] : '', $anterior, ! empty( $opcoes['curto'] ), ! empty( $opcoes['sugerir'] ) );

		// Teto de saída por campo: rascunho precisa de espaço, o resto não. É o
		// teto baixo que corta cedo um modelo em loop.
		$max    = ! empty( $campo['propoe'] ) ? Leticia_Gemini::MAX_TOKENS_RASCUNHO : Leticia_Gemini::MAX_TOKENS;
		$comeco = microtime( true );

		$resposta = Leticia_Gemini::gerar( $instrucao, $turno, array( 'max_tokens' => $max ) );
		Leticia_Limites::registrar_chamada( $sessao );

		if ( is_wp_error( $resposta ) ) {
			do_action( 'leticia_modelo_falhou', $campo['chave'], $resposta->get_error_code(), $resposta->get_error_message() );
			return self::degradado();
		}

		$saida = self::ler_json( $resposta['texto'] );

		if ( null === $saida ) {
			$cortada = isset( $resposta['finish'] ) && 'MAX_TOKENS' === $resposta['finish'];
			$gasto   = microtime( true ) - $comeco;

			// Uma segunda tentativa, e só uma — e nem essa quando não adianta:
			// - cortada no teto é o modelo em loop, e a mesma pergunta degenerou
			//   três vezes seguidas na medição;
			// - sem metade do prazo sobrando, a pessoa já esperou demais.
			if ( $cortada || $gasto > Leticia_Gemini::PRAZO_TOTAL / 2 ) {
				do_action( 'leticia_json_quebrado', $campo['chave'], $cortada ? 'cortada' : 'sem_tempo' );
				return self::degradado();
			}

			$resposta = Leticia_Gemini::gerar( $instrucao, $turno, array( 'max_tokens' => $max, 'prazo' => Leticia_Gemini::PRAZO_TOTAL - $gasto ) );
			Leticia_Limites::registrar_chamada( $sessao );

			$saida = is_wp_error( $resposta ) ? null : self::ler_json( $resposta['texto'] );

			if ( null === $saida ) {
				do_action( 'leticia_json_quebrado', $campo['chave'], 'ilegivel' );
				return self::degradado();
			}
		}

		return self::higienizar( $saida, $campo, trim( $anterior . "\n" . $bruto ) );
	}

	/**
	 * O que sai quando não houve modelo.
	 *
	 * Não é erro, é um modo de operação: a resposta do cliente é aceita como
	 * veio, sem comentário e sem repergunta, e o briefing segue. É o mesmo
	 * retorno para chave ausente, cota estourada, rede caída e JSON quebrado —
	 * o cliente não precisa saber qual dos quatro aconteceu.
	 */
	public static function degradado() {
		return array(
			'tipo'            => 'resposta',
			'suficiente'      => true,
			'valor_limpo'     => null,
			'comentario'      => null,
			'repergunta'      => null,
			'resposta_duvida' => null,
			'proposta'        => null,
			'degradado'       => true,
			'bloqueio'        => null,
		);
	}

	/**
	 * Lê o JSON que voltou.
	 *
	 * Tolera a crase de bloco de código: com o schema declarado ela não deveria
	 * aparecer, mas custa três linhas aceitar e evita descartar uma resposta boa
	 * por causa de um envelope.
	 *
	 * @return array|null
	 */
	public static function ler_json( $texto ) {
		$texto = trim( (string) $texto );
		$texto = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $texto );

		$dados = json_decode( $texto, true );

		if ( ! is_array( $dados ) ) {
			// Último recurso: o primeiro objeto que houver no meio do texto.
			if ( preg_match( '/\{.*\}/s', $texto, $achado ) ) {
				$dados = json_decode( $achado[0], true );
			}
		}

		if ( ! is_array( $dados ) || ! isset( $dados['tipo'] ) ) {
			return null;
		}

		return $dados;
	}

	/**
	 * Põe a resposta do modelo em forma, e passa a trava por cima.
	 *
	 * Nada que o modelo devolve é confiado: o tipo é conferido contra a lista,
	 * os textos são cortados no teto, e tudo que vira fala passa pela trava
	 * antes de poder chegar à tela.
	 */
	private static function higienizar( array $bruto_json, array $campo, $bruto ) {
		$tipos = array( 'resposta', 'duvida', 'ajuda', 'fora_de_escopo' );
		$tipo  = isset( $bruto_json['tipo'] ) ? (string) $bruto_json['tipo'] : 'resposta';
		if ( ! in_array( $tipo, $tipos, true ) ) {
			$tipo = 'resposta';
		}

		$saida = array(
			'tipo'            => $tipo,
			'suficiente'      => isset( $bruto_json['suficiente'] ) ? (bool) $bruto_json['suficiente'] : true,
			// Não é mais pedido ao modelo (era onde ele entrava em loop). A
			// chave fica, sempre nula, para quem lê o contrato.
			'valor_limpo'     => null,
			'comentario'      => self::texto( $bruto_json, 'comentario' ),
			'repergunta'      => self::texto( $bruto_json, 'repergunta' ),
			'resposta_duvida' => self::texto( $bruto_json, 'resposta_duvida' ),
			// Rascunho só em campo de conteúdo, e só quando a resposta basta ou
			// a pessoa pediu ajuda. Fora disso, o modelo que escreveu um está
			// adiantando uma decisão que não é dele.
			'proposta'        => ! empty( $campo['propoe'] ) ? self::rascunho( $bruto_json ) : null,
			'degradado'       => false,
			'bloqueio'        => null,
		);

		// Pedido de ajuda sem rascunho e sem nada a dizer não pode virar
		// resposta — seria gravar "me dá uma ideia" como o ramo da empresa. Ganha
		// a fala escrita de conduzir.
		if ( 'ajuda' === $tipo && null === $saida['resposta_duvida'] && null === $saida['proposta'] ) {
			$saida['resposta_duvida'] = Leticia_Validacao::fala_de_conducao( $campo['chave'] );
		}

		// 'duvida' mantém a pessoa no mesmo campo, então não pode vir com
		// suficiente = true e nada para dizer: sem texto, vira resposta.
		if ( in_array( $tipo, array( 'duvida', 'fora_de_escopo' ), true ) && null === $saida['resposta_duvida'] ) {
			$saida['tipo'] = 'resposta';
		}
		if ( 'resposta' === $saida['tipo'] && false === $saida['suficiente'] ) {
			$saida['proposta'] = null;
		}
		if ( in_array( $saida['tipo'], array( 'duvida', 'fora_de_escopo' ), true ) ) {
			$saida['proposta'] = null;
		}
		if ( ! $saida['suficiente'] && null === $saida['repergunta'] ) {
			$saida['suficiente'] = true;
		}

		return self::travar( $saida, $bruto );
	}

	/**
	 * A trava, sobre tudo que vira fala.
	 *
	 * Um campo barrado não derruba os outros: se o comentário inventou um
	 * telefone, o comentário some e a repergunta continua valendo. O que some
	 * some inteiro — nada de cortar no meio da frase.
	 */
	private static function travar( array $saida, $bruto ) {
		$permitidos = Leticia_Trava::somar_do_cliente( Leticia_Trava::permitidos(), $bruto );
		$base       = self::base_plana();

		foreach ( array( 'comentario', 'repergunta', 'resposta_duvida', 'proposta' ) as $campo_texto ) {
			if ( null === $saida[ $campo_texto ] ) {
				continue;
			}
			$motivo = Leticia_Trava::verificar( $saida[ $campo_texto ], $permitidos, $base );
			if ( null === $motivo ) {
				continue;
			}

			$saida['bloqueio'] = $motivo;

			// A dúvida é a única que não pode ficar sem resposta: a pessoa
			// perguntou alguma coisa e está esperando. Troca pelo texto seguro.
			$saida[ $campo_texto ] = 'resposta_duvida' === $campo_texto
				? Leticia_Trava::resposta_segura()
				: null;

			if ( 'repergunta' === $campo_texto ) {
				$saida['suficiente'] = true;   // sem repergunta, o campo avança
			}

			do_action( 'leticia_trava_barrou', $campo_texto, $motivo );
		}

		return $saida;
	}

	/** O texto da base, para a detecção de recitação. Memoizado por requisição. */
	private static function base_plana() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$dados = Leticia_Base::carregar();
		if ( is_wp_error( $dados ) ) {
			return $memo = '';
		}
		$texto = '';
		foreach ( $dados['campos'] as $bloco ) {
			foreach ( $bloco as $parte ) {
				$texto .= ( is_array( $parte ) ? implode( "\n", $parte ) : $parte ) . "\n";
			}
		}
		return $memo = $texto;
	}

	/**
	 * O rascunho em forma: sem marcação de documento, uma linha por item.
	 *
	 * O schema pede texto puro, e o modelo às vezes devolve lista com hífen e
	 * negrito mesmo assim. Aqui isso sai, em vez de a tela mostrar asterisco.
	 */
	private static function rascunho( array $dados ) {
		if ( ! isset( $dados['proposta'] ) || null === $dados['proposta'] ) {
			return null;
		}
		$texto  = wp_strip_all_tags( (string) $dados['proposta'] );
		$texto  = str_replace( array( '**', '__' ), '', $texto );
		$linhas = array();
		foreach ( preg_split( '/\R/u', $texto ) as $linha ) {
			$linha = trim( preg_replace( '/^\s*(?:[-*•·]|\d+[.)])\s+/u', '', $linha ) );
			if ( '' !== $linha ) {
				$linhas[] = $linha;
			}
		}
		$texto = implode( "\n", $linhas );
		if ( '' === $texto ) {
			return null;
		}
		if ( mb_strlen( $texto, 'UTF-8' ) > self::TETO_PROPOSTA ) {
			// Corta na última linha ou frase inteira: rascunho que termina no
			// meio de uma palavra não se aprova.
			$texto = mb_substr( $texto, 0, self::TETO_PROPOSTA, 'UTF-8' );
			$fim   = max( strrpos( $texto, "\n" ), strrpos( $texto, '.' ) );
			if ( $fim > self::TETO_PROPOSTA / 2 ) {
				$texto = rtrim( substr( $texto, 0, $fim + 1 ) );
			}
		}
		return $texto;
	}

	private static function texto( array $dados, $chave ) {
		if ( ! isset( $dados[ $chave ] ) || null === $dados[ $chave ] ) {
			return null;
		}
		$texto = trim( wp_strip_all_tags( (string) $dados[ $chave ] ) );
		if ( '' === $texto ) {
			return null;
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $texto, 'UTF-8' ) > self::TETO_FALA ) {
			$texto = mb_substr( $texto, 0, self::TETO_FALA, 'UTF-8' );
			// Corta no fim da última frase inteira, para não terminar no meio
			// de uma palavra.
			$ponto = max( strrpos( $texto, '.' ), strrpos( $texto, '!' ), strrpos( $texto, '?' ) );
			if ( $ponto > self::TETO_FALA / 2 ) {
				$texto = substr( $texto, 0, $ponto + 1 );
			}
		}
		return $texto;
	}
}
