<?php
/**
 * A trava — a camada que não depende do modelo colaborar.
 *
 * Instrução e temperatura baixa são pedido, não garantia. Esta classe varre o
 * texto **inteiro** antes de ele chegar à tela e descarta o que for invenção.
 * Sem streaming não há janela retida nem texto pela metade: a trava sempre vê a
 * resposta completa, e é por isso que ela pode ser simples e forte ao mesmo
 * tempo.
 *
 * A lista de permitidos tem duas origens, e a segunda é a que importa aqui:
 *
 *   1. Os exemplos didáticos da base — o (47) 99999-9999 que a LetícIA usa de
 *      propósito ao explicar um campo.
 *   2. **O que o próprio cliente digitou nesta conversa.** O briefing existe
 *      para coletar telefone, e-mail e domínio; confirmá-los de volta é o
 *      comportamento certo, e sem esta regra a trava cortaria justamente a
 *      resposta que o cliente mais precisa ver.
 *
 * Dinheiro e porcentagem nunca entram nos permitidos, venham de onde vierem.
 * "Me cobraram 500" ecoado de volta é a LetícIA falando de preço, que é o que
 * ela não pode fazer nem repetindo.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Trava {

	/** Telefone com DDD, com ou sem máscara, sem fatiar corrida longa de dígitos. */
	const RE_TELEFONE = '/(?<!\d)\+?\d{0,3}[\s.\-]?\(?\d{2}\)?[\s.\-]?\d{4,5}[\s.\-]?\d{4}(?!\d)/';

	const RE_EMAIL = '/[\w.+\-]+@[\w\-]+\.[\w.\-]*\w/u';

	const TLDS = 'com\.br|org\.br|net\.br|gov\.br|edu\.br|com|net|org|app|dev|site|online|blog|shop|info|me';

	/** Numerais por extenso, para pegar "mil e quinhentos reais". */
	const NUMERAIS = 'zero|uma?|dois|duas|tres|quatro|cinco|seis|sete|oito|nove|dez|onze|doze|treze|quatorze|catorze|quinze|dezesseis|dezessete|dezoito|dezenove|vinte|trinta|quarenta|cinquenta|sessenta|setenta|oitenta|noventa|cem|cento|duzentos|trezentos|quatrocentos|quinhentos|seiscentos|setecentos|oitocentos|novecentos|mil|milhao|milhoes';

	/** Palavras seguidas da base que denunciam recitação em vez de resposta. */
	const PALAVRAS_VAZAMENTO = 25;

	/**
	 * A lista base: os exemplos que a própria base usa para ensinar.
	 *
	 * Sai da base de verdade, e não de uma constante, porque se alguém trocar o
	 * telefone de exemplo no campos.md a trava tem que passar a aceitar o novo
	 * e barrar o antigo — sem ninguém precisar lembrar de mexer aqui.
	 */
	public static function permitidos() {
		$dados = Leticia_Base::carregar();
		if ( is_wp_error( $dados ) ) {
			return array( 'telefones' => array(), 'emails' => array(), 'sites' => array() );
		}

		$texto = '';
		foreach ( $dados['campos'] as $bloco ) {
			foreach ( $bloco as $parte ) {
				$texto .= is_array( $parte ) ? implode( "\n", $parte ) : $parte;
				$texto .= "\n";
			}
		}
		foreach ( $dados['textos'] as $solto ) {
			$texto .= $solto . "\n";
		}

		return array(
			'telefones' => self::achar_telefones( $texto ),
			'emails'    => self::achar_emails( $texto ),
			'sites'     => self::achar_sites( $texto ),
		);
	}

	/**
	 * Soma o que o cliente escreveu nesta conversa.
	 *
	 * Três categorias entram — telefone, e-mail e endereço de site — e duas
	 * ficam de fora, sempre: dinheiro e porcentagem.
	 *
	 * @param array        $permitidos lista base
	 * @param array|string $falas      o que o cliente digitou
	 */
	public static function somar_do_cliente( array $permitidos, $falas ) {
		$texto = is_array( $falas ) ? implode( "\n", $falas ) : (string) $falas;

		$permitidos['telefones'] = array_unique( array_merge( $permitidos['telefones'], self::achar_telefones( $texto ) ) );
		$permitidos['emails']    = array_unique( array_merge( $permitidos['emails'], self::achar_emails( $texto ) ) );
		$permitidos['sites']     = array_unique( array_merge( $permitidos['sites'], self::achar_sites( $texto ) ) );

		return $permitidos;
	}

	/**
	 * Varre a resposta. Devolve o motivo do bloqueio, ou null.
	 *
	 * @param string $resposta  o texto que iria para a tela
	 * @param array  $permitidos
	 * @param string $base      texto da base, para detectar recitação
	 */
	public static function verificar( $resposta, array $permitidos, $base = '' ) {
		$texto = (string) $resposta;

		// 1. Telefone que ninguém digitou e que não está na base.
		foreach ( self::achar_telefones( $texto ) as $telefone ) {
			if ( ! in_array( $telefone, $permitidos['telefones'], true ) ) {
				return 'telefone que não existe na base nem foi digitado pelo cliente';
			}
		}

		// 2. E-mail idem.
		foreach ( self::achar_emails( $texto ) as $email ) {
			if ( ! in_array( $email, $permitidos['emails'], true ) ) {
				return 'e-mail que não existe na base nem foi digitado pelo cliente';
			}
		}

		// 3. Endereço de site idem.
		foreach ( self::achar_sites( $texto ) as $site ) {
			if ( ! in_array( $site, $permitidos['sites'], true ) ) {
				return 'endereço de site fora da base';
			}
		}

		// 4. Dinheiro, sempre. Ela não fala de preço nem ecoando.
		if ( self::tem_dinheiro( $texto ) ) {
			return 'valor em dinheiro';
		}

		// 5. Porcentagem, sempre — desconto é conversa da equipe comercial.
		if ( self::tem_percentual( $texto ) ) {
			return 'percentual';
		}

		// 6. Prazo prometido que não é o publicado.
		$prazo = self::prazo_inventado( $texto );
		if ( $prazo ) {
			return 'promessa de prazo: ' . $prazo;
		}

		// 7. Recitação: a LetícIA devolvendo a instrução em vez de responder.
		if ( '' !== $base && self::esta_recitando( $texto, $base ) ) {
			return 'está recitando a base em vez de responder';
		}

		return null;
	}

	// ------------------------------------------------------------ detecções

	public static function achar_telefones( $texto ) {
		preg_match_all( self::RE_TELEFONE, (string) $texto, $achados );
		$saida = array();
		foreach ( $achados[0] as $bruto ) {
			$digitos = preg_replace( '/\D+/', '', $bruto );
			$digitos = preg_replace( '/^55(?=\d{10,11}$)/', '', $digitos );
			if ( strlen( $digitos ) >= 10 && strlen( $digitos ) <= 11 ) {
				$saida[] = $digitos;
			}
		}
		return array_values( array_unique( $saida ) );
	}

	public static function achar_emails( $texto ) {
		preg_match_all( self::RE_EMAIL, (string) $texto, $achados );
		$saida = array();
		foreach ( $achados[0] as $bruto ) {
			// A expressão é gulosa e engoliria o ponto final da frase, fazendo
			// de "contato@x.com.br" e "contato@x.com.br." chaves diferentes.
			$saida[] = strtolower( rtrim( $bruto, '.,;:' ) );
		}
		return array_values( array_unique( $saida ) );
	}

	public static function achar_sites( $texto ) {
		$re = '/\b(?:https?:\/\/)?(?:www\.)?([a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)*\.(?:' . self::TLDS . '))(\/[^\s,;)]*)?/i';
		preg_match_all( $re, (string) $texto, $achados, PREG_SET_ORDER );

		$saida = array();
		foreach ( $achados as $achado ) {
			$dominio = strtolower( $achado[1] );
			$caminho = isset( $achado[2] ) ? rtrim( $achado[2], '.,;:/' ) : '';
			// Domínio e caminho juntos: joinvix.com.br é permitido e
			// joinvix.com.br/painel não é, se a segunda não estiver na lista.
			$saida[] = $dominio . $caminho;
		}
		return array_values( array_unique( $saida ) );
	}

	public static function tem_dinheiro( $texto ) {
		$texto = (string) $texto;

		if ( preg_match( '/R\$\s*\d/i', $texto ) ) {
			return true;
		}
		// 1.500,00 sem o R$ — comparado por dígitos, não por máscara.
		if ( preg_match( '/\b\d{1,3}(\.\d{3})+,\d{2}\b|\b\d+,\d{2}\s*(reais|conto)/iu', $texto ) ) {
			return true;
		}
		if ( preg_match( '/\b\d+\s*(reais|conto|pila)\b/iu', $texto ) ) {
			return true;
		}
		// "mil e quinhentos reais"
		$plano = Leticia_Validacao::simplificar( $texto );
		if ( preg_match( '/\b(' . self::NUMERAIS . ')\b[\sa-z]{0,24}\b(reais|real)\b/u', $plano ) ) {
			return true;
		}
		return false;
	}

	public static function tem_percentual( $texto ) {
		if ( preg_match( '/\b\d{1,3}\s*%/', (string) $texto ) ) {
			return true;
		}
		$plano = Leticia_Validacao::simplificar( $texto );
		return (bool) preg_match( '/\b(' . self::NUMERAIS . ')\b[\sa-z]{0,16}\bpor\s*cento\b/u', $plano );
	}

	/**
	 * Promessa de prazo que não é o publicado.
	 *
	 * Só pega construção de promessa — "fica pronto em dois dias" —, não uma
	 * descrição qualquer com número e unidade de tempo. O cliente que escreve
	 * "bolo com três dias de antecedência" está descrevendo o próprio serviço, e
	 * bloquear isso seria bloquear o briefing.
	 *
	 * @return string|null o trecho que motivou o bloqueio
	 */
	public static function prazo_inventado( $texto ) {
		$plano = Leticia_Validacao::simplificar( $texto );
		$re    = '/\b(fica pronto|ficara pronto|entrego|entregamos|entregue|no ar|pronto)\b[^.!?]{0,40}?\b(\d{1,3})\s*(hora|horas|dia|dias|semana|semanas|mes|meses)\b/u';

		if ( ! preg_match( $re, $plano, $achado ) ) {
			return null;
		}
		// 72 horas é o prazo publicado do Site Express: esse pode.
		if ( '72' === $achado[2] && 0 === strpos( $achado[3], 'hora' ) ) {
			return null;
		}
		return trim( $achado[0] );
	}

	/**
	 * A LetícIA recitando a instrução em vez de responder.
	 *
	 * Duas pistas: título de markdown, que ela nunca usa ao falar, e uma corrida
	 * longa de palavras idênticas a um trecho da base.
	 */
	public static function esta_recitando( $texto, $base ) {
		if ( preg_match( '/^\s{0,3}#{1,6}\s+\S/m', (string) $texto ) ) {
			return true;
		}

		$palavras = preg_split( '/\s+/', Leticia_Validacao::simplificar( $texto ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $palavras ) < self::PALAVRAS_VAZAMENTO ) {
			return false;
		}

		$base_plana = Leticia_Validacao::simplificar( $base );

		for ( $i = 0; $i + self::PALAVRAS_VAZAMENTO <= count( $palavras ); $i++ ) {
			$trecho = implode( ' ', array_slice( $palavras, $i, self::PALAVRAS_VAZAMENTO ) );
			if ( false !== strpos( $base_plana, $trecho ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * O que a LetícIA diz quando a trava barra a resposta.
	 *
	 * Ela **não** encerra nada: diz que aquilo é assunto da equipe e continua no
	 * mesmo campo. Uma resposta barrada não pode virar um briefing abandonado.
	 */
	public static function resposta_segura() {
		return 'Essa parte quem resolve é a equipe, não eu. Mas deixei anotado para eles verem junto com o seu briefing.';
	}
}
