<?php
/**
 * O cliente do Gemini da LetícIA.
 *
 * Próprio, e não emprestado da LivIA, por três motivos que não são de estilo:
 * a chave é outra (seção 15), a cadeia de modelos é outra, e o corpo é outro —
 * a LetícIA pede `responseMimeType: application/json` com schema declarado, e a
 * LivIA pede texto corrido.
 *
 * **Sem streaming.** A resposta é um JSON curto: JSON pela metade não se
 * parseia, e a trava precisa do texto inteiro de qualquer jeito. O ganho está
 * do lado do servidor — o processo de PHP-FPM é liberado em segundos em vez de
 * ficar preso segurando uma conexão aberta.
 *
 * **O prazo é da resposta, não da tentativa.** Quem espera é uma pessoa olhando
 * "escrevendo…". Passou do prazo, a LetícIA segue com a reação escrita — o
 * cliente não percebe diferença nenhuma, só não ganha o comentário.
 *
 * Modelo que está falhando é pausado por `Leticia_Modelos` e pulado pelas
 * chamadas seguintes: o disjuntor por modelo evita que cada cliente espere o
 * mesmo tempo esgotado, um de cada vez.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Gemini {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Prazo de uma tentativa, em segundos.
	 *
	 * Medido: o JSON da conversa volta em 1,1 a 2 s quando o modelo está bem —
	 * rascunho incluído. As chamadas de 5 a 22 s da medição eram o modelo em
	 * loop até o teto de saída, e esperar mais só adiava o mesmo resultado.
	 * Quatro segundos deixam folga para o dobro do normal; passou disso, a
	 * pessoa já está achando que travou, e a reserva (ou o texto escrito) é
	 * melhor que mais espera.
	 */
	const TIMEOUT = 4;

	/**
	 * Prazo de uma tentativa da checagem diária. Mais folgado que o da
	 * conversa: ninguém está esperando, e um modelo frio que demora oito
	 * segundos não está fora do ar — o e-mail de alarme precisa ser verdade.
	 */
	const TIMEOUT_SONDA = 12;

	/**
	 * Prazo de tudo junto numa resposta de conversa: a cadeia inteira cabe aqui.
	 * Principal (até 4 s) e reserva (o que sobrar): no pior caso, sete segundos
	 * até a resposta escrita — eram dez.
	 */
	const PRAZO_TOTAL = 7;

	/** O áudio sobe junto e o modelo ouve antes de escrever. */
	const PRAZO_VOZ = 20;

	const TENTATIVAS = 2;

	/**
	 * Teto de saída de um campo comum. O JSON tem cinco campos curtos: 150
	 * tokens é o normal, 400 é folga. Mais que isso é o modelo em loop, e o teto
	 * baixo corta cedo em vez de gastar dez segundos gerando lixo.
	 */
	const MAX_TOKENS = 400;

	/** Nos campos com rascunho: 80 palavras de texto, ou oito linhas de serviço. */
	const MAX_TOKENS_RASCUNHO = 900;

	/**
	 * 0,4. Com 0,2 a fala era sempre a mesma frase morna; com 0,6 o modelo
	 * disparava em repetição sem fim num campo de texto longo — três vezes
	 * seguidas na medição. 0,4 segura a variação sem soltar o loop.
	 */
	const TEMPERATURA = 0.4;

	/** Sem mais memória que isto: modelo que recusou o nível de raciocínio. */
	const PREFIXO_SEM_PENSAMENTO = 'leticia_sem_pensar_';

	/**
	 * O schema da resposta. É o contrato da seção 7, escrito onde a API o lê.
	 *
	 * Declarar o schema não substitui validar o que volta — a API pode
	 * devolver o campo com o tipo certo e o conteúdo errado —, mas elimina de
	 * uma vez a classe de falha mais comum: o modelo respondendo em prosa, ou
	 * embrulhando o JSON em crase.
	 *
	 * **Sem `valor_limpo`.** Era para devolver a resposta do cliente com a
	 * digitação arrumada, e foi onde o modelo entrou em loop — ecoando e
	 * estendendo o texto até o teto. Além disso, ele custava na saída o tamanho
	 * da resposta inteira, em toda chamada, para uma correção de maiúscula.
	 *
	 * Os campos curtos vêm primeiro: se algo degenerar, a classificação já foi
	 * escrita.
	 */
	public static function schema() {
		return array(
			'type'             => 'OBJECT',
			'properties'       => array(
				'tipo'            => array(
					'type' => 'STRING',
					'enum' => array( 'resposta', 'duvida', 'ajuda', 'fora_de_escopo' ),
				),
				'suficiente'      => array( 'type' => 'BOOLEAN' ),
				'comentario'      => array( 'type' => 'STRING', 'nullable' => true ),
				'repergunta'      => array( 'type' => 'STRING', 'nullable' => true ),
				'resposta_duvida' => array( 'type' => 'STRING', 'nullable' => true ),
				'proposta'        => array( 'type' => 'STRING', 'nullable' => true ),
			),
			'required'         => array( 'tipo', 'suficiente' ),
			'propertyOrdering' => array( 'tipo', 'suficiente', 'comentario', 'repergunta', 'resposta_duvida', 'proposta' ),
		);
	}

	public static function corpo( $instrucao, $turno, $max_tokens = self::MAX_TOKENS ) {
		return array(
			'contents'           => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $turno ) ),
				),
			),
			'system_instruction' => array( 'parts' => array( array( 'text' => $instrucao ) ) ),
			'generationConfig'   => array(
				'temperature'      => self::TEMPERATURA,
				'topP'             => 0.8,
				'maxOutputTokens'  => (int) $max_tokens,
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::schema(),
			),
		);
	}

	/**
	 * O raciocínio interno, no mínimo, dito explicitamente.
	 *
	 * Tokens de raciocínio são cobrados como saída e somam na latência, e aqui
	 * não compram nada: classificar uma resposta e escrever uma frase não pede
	 * cadeia de raciocínio. O padrão do modelo de hoje já é o mínimo, mas
	 * padrão muda de versão para versão sem aviso.
	 *
	 * A família 2.5 fala outra língua (orçamento em tokens); a 3 em diante,
	 * nível. Modelo que recusar o parâmetro é lembrado e segue sem ele.
	 *
	 * @return array|null
	 */
	public static function pensamento( $modelo ) {
		if ( get_transient( self::PREFIXO_SEM_PENSAMENTO . md5( $modelo ) ) ) {
			return null;
		}
		if ( preg_match( '/^gemini-2\.5-flash/', $modelo ) ) {
			return array( 'thinkingBudget' => 0 );
		}
		if ( preg_match( '/^gemini-([3-9]|flash|flash-lite)/', $modelo ) ) {
			return array( 'thinkingLevel' => 'minimal' );
		}
		return null;
	}

	/**
	 * A cadeia de modelos: o principal e, se houver, a reserva.
	 *
	 * Cota estourada e modelo que sumiu não melhoram tentando de novo — o que
	 * resolve é trocar de modelo.
	 */
	public static function cadeia() {
		$cadeia  = array( Leticia_Config::modelo() );
		$reserva = Leticia_Config::modelo_reserva();
		if ( '' !== $reserva && $reserva !== $cadeia[0] ) {
			$cadeia[] = $reserva;
		}
		return array_values( array_filter( $cadeia ) );
	}

	/**
	 * Manda o pedido e devolve o texto cru da resposta.
	 *
	 * @param array $opcoes 'max_tokens' => int, 'prazo' => segundos
	 * @return array|WP_Error array( 'texto', 'modelo', 'uso', 'finish' )
	 */
	public static function gerar( $instrucao, $turno, array $opcoes = array() ) {
		// Atalho no estilo dos filtros "pre_" do WordPress: devolvendo algo
		// diferente de null, a chamada de rede não acontece. É o que permite a
		// suíte cobrir o fluxo inteiro sem gastar cota nem depender da internet.
		$curto = apply_filters( 'leticia_pre_gerar', null, $instrucao, $turno, $opcoes );
		if ( null !== $curto ) {
			return $curto;
		}

		$max   = isset( $opcoes['max_tokens'] ) ? (int) $opcoes['max_tokens'] : self::MAX_TOKENS;
		$prazo = isset( $opcoes['prazo'] ) ? (float) $opcoes['prazo'] : self::PRAZO_TOTAL;

		return self::mandar( self::corpo( $instrucao, $turno, $max ), $prazo );
	}

	// ------------------------------------------------------------------ voz

	/**
	 * A temperatura da transcrição.
	 *
	 * Aqui é o contrário da conversa: quer-se o que a pessoa disse, e nenhuma
	 * criatividade no jeito de dizer. 0,1 e não 0 — com zero cravado, áudio
	 * ruim às vezes volta em loop de palavra repetida.
	 */
	const TEMPERATURA_VOZ = 0.1;

	/** Um minuto de fala dá umas 150 palavras; o JSON leva o texto uma vez só. */
	const MAX_TOKENS_VOZ = 900;

	public static function schema_voz() {
		return array(
			'type'             => 'OBJECT',
			'properties'       => array(
				'houve_fala'         => array( 'type' => 'BOOLEAN' ),
				'transcricao'        => array( 'type' => 'STRING', 'nullable' => true ),
				'texto_interpretado' => array( 'type' => 'STRING', 'nullable' => true ),
				'confianca_baixa'    => array( 'type' => 'BOOLEAN' ),
				'motivo'             => array(
					'type' => 'STRING',
					'enum' => array( 'nenhum', 'ruido', 'cortado', 'ambiguo', 'soletrar', 'fora_do_campo' ),
				),
			),
			'required'         => array( 'houve_fala', 'confianca_baixa', 'motivo' ),
			// A transcrição literal antes da interpretada: escrevendo nesta
			// ordem, o modelo interpreta o que ele mesmo acabou de transcrever,
			// e não o que imaginou ter ouvido.
			'propertyOrdering' => array( 'houve_fala', 'transcricao', 'texto_interpretado', 'confianca_baixa', 'motivo' ),
		);
	}

	/**
	 * O pedido com áudio: o contexto em texto e o áudio como inline_data.
	 *
	 * inline_data, e não a File API: gravação de um minuto em Opus tem uns
	 * 200 KB, o teto de inline é 20 MB, e a File API seriam duas chamadas e um
	 * arquivo guardado no Google por 48 horas.
	 */
	public static function corpo_voz( $instrucao, $turno, $audio, $mime ) {
		return array(
			'contents'           => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $turno ),
						array(
							'inline_data' => array(
								'mime_type' => $mime,
								'data'      => base64_encode( $audio ),
							),
						),
					),
				),
			),
			'system_instruction' => array( 'parts' => array( array( 'text' => $instrucao ) ) ),
			'generationConfig'   => array(
				'temperature'      => self::TEMPERATURA_VOZ,
				'maxOutputTokens'  => self::MAX_TOKENS_VOZ,
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::schema_voz(),
			),
		);
	}

	/**
	 * Manda o áudio e devolve o texto cru da resposta, no mesmo formato de
	 * gerar().
	 *
	 * @return array|WP_Error
	 */
	public static function ouvir( $instrucao, $turno, $audio, $mime ) {
		// O mesmo atalho de gerar(), com nome próprio: a suíte simula a
		// transcrição sem precisar de um áudio de verdade nem de rede.
		$curto = apply_filters( 'leticia_pre_ouvir', null, $instrucao, $turno, $mime );
		if ( null !== $curto ) {
			return $curto;
		}
		return self::mandar( self::corpo_voz( $instrucao, $turno, $audio, $mime ), self::PRAZO_VOZ );
	}

	/**
	 * Uma chamada mínima a um modelo, fora da cadeia e do disjuntor.
	 *
	 * É a checagem diária: modelo desativado continua aparecendo na listagem
	 * da API — foi assim que a reserva morreu sem ninguém ver —, então só uma
	 * geração de verdade diz se ele atende.
	 *
	 * @return array array( 'ok' => bool, 'codigo' => string, 'mensagem' => string, 'ms' => int )
	 */
	public static function sondar( $modelo ) {
		$curto = apply_filters( 'leticia_pre_sondar', null, $modelo );
		if ( null !== $curto ) {
			return $curto;
		}

		$chave = Leticia_Config::api_key();
		if ( '' === $chave ) {
			return array( 'ok' => false, 'codigo' => 'sem_chave', 'mensagem' => 'A chave da API não está configurada.', 'ms' => 0 );
		}

		$corpo = array(
			'contents'         => array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'Responda só: ok' ) ) ) ),
			'generationConfig' => array( 'temperature' => 0, 'maxOutputTokens' => 16 ),
		);

		// Duas tentativas para o que é de um instante (tempo, rede, 5xx). Na
		// medição, o mesmo modelo respondeu em 2,3 s e, logo depois, esgotou
		// seis segundos: uma tentativa só mandaria alarme falso.
		for ( $tentativa = 1; $tentativa <= 2; $tentativa++ ) {
			$comeco   = microtime( true );
			$resposta = self::tentar( $modelo, $corpo, $chave, $comeco + self::TIMEOUT_SONDA + 1, self::TIMEOUT_SONDA );
			$ms       = (int) round( ( microtime( true ) - $comeco ) * 1000 );
			if ( ! is_wp_error( $resposta ) || ! in_array( $resposta->get_error_code(), array( 'tempo', 'rede', 'http' ), true ) ) {
				break;
			}
		}

		// Resposta cortada no teto de 16 tokens ainda é o modelo atendendo.
		if ( is_wp_error( $resposta ) && 'resposta_vazia' !== $resposta->get_error_code() ) {
			return array( 'ok' => false, 'codigo' => $resposta->get_error_code(), 'mensagem' => $resposta->get_error_message(), 'ms' => $ms );
		}
		return array( 'ok' => true, 'codigo' => '', 'mensagem' => '', 'ms' => $ms );
	}

	/**
	 * A cadeia de modelos e as tentativas, para qualquer corpo.
	 *
	 * Tempo esgotado não se repete no mesmo modelo: se ele não respondeu em
	 * seis segundos, a segunda tentativa só gasta o resto do prazo esperando o
	 * mesmo. Vai direto para a reserva. Erro de rede e 5xx, que costumam ser
	 * de um instante, ganham mais uma tentativa se ainda houver tempo.
	 *
	 * @return array|WP_Error
	 */
	private static function mandar( array $corpo_array, $prazo = self::PRAZO_TOTAL ) {
		$chave = Leticia_Config::api_key();
		if ( '' === $chave ) {
			return new WP_Error( 'sem_chave', 'A chave da API não está configurada.' );
		}

		$cadeia = Leticia_Modelos::disponiveis( self::cadeia() );
		if ( ! $cadeia ) {
			// Todos pausados: a resposta é instantânea. É o ponto do disjuntor —
			// o cliente não espera um tempo esgotado que já se sabe que vem.
			return new WP_Error( 'modelos_pausados', 'Todos os modelos estão pausados depois de falhas seguidas.' );
		}

		$limite = microtime( true ) + $prazo;
		$ultimo = null;

		foreach ( $cadeia as $modelo ) {
			for ( $tentativa = 1; $tentativa <= self::TENTATIVAS; $tentativa++ ) {
				if ( microtime( true ) >= $limite - 1 ) {
					return $ultimo ? $ultimo : new WP_Error( 'sem_tempo', 'A API não respondeu dentro do prazo.' );
				}

				$resposta = self::tentar( $modelo, $corpo_array, $chave, $limite );

				if ( ! is_wp_error( $resposta ) ) {
					Leticia_Modelos::sucesso( $modelo );
					$resposta['modelo'] = $modelo;
					return $resposta;
				}

				$ultimo = $resposta;
				$codigo = $resposta->get_error_code();
				Leticia_Modelos::falha( $modelo, $codigo, $resposta->get_error_message() );

				// Chave recusada vale para todos os modelos: trocar não resolve.
				if ( 'chave' === $codigo ) {
					return $resposta;
				}
				if ( ! self::vale_repetir( $codigo ) ) {
					break;   // trocar de modelo, ou desistir
				}
			}
		}

		return $ultimo ? $ultimo : new WP_Error( 'sem_modelo', 'Nenhum modelo configurado.' );
	}

	/**
	 * Uma tentativa num modelo, com o raciocínio no mínimo.
	 *
	 * Se o modelo recusar o parâmetro de raciocínio (400 falando dele), a
	 * recusa é lembrada por um dia e a mesma tentativa sai de novo sem ele —
	 * um parâmetro de economia não pode derrubar a conversa.
	 */
	private static function tentar( $modelo, array $corpo_array, $chave, $limite, $timeout = self::TIMEOUT ) {
		$pensar = self::pensamento( $modelo );
		if ( $pensar ) {
			$corpo_array['generationConfig']['thinkingConfig'] = $pensar;
		}

		$resposta = self::pedir( $modelo, wp_json_encode( $corpo_array ), $chave, $limite, $timeout );

		if ( $pensar && is_wp_error( $resposta ) && 'recusado' === $resposta->get_error_code() && preg_match( '/think/i', $resposta->get_error_message() ) ) {
			set_transient( self::PREFIXO_SEM_PENSAMENTO . md5( $modelo ), 1, DAY_IN_SECONDS );
			unset( $corpo_array['generationConfig']['thinkingConfig'] );
			$resposta = self::pedir( $modelo, wp_json_encode( $corpo_array ), $chave, $limite, $timeout );
		}

		return $resposta;
	}

	private static function pedir( $modelo, $corpo, $chave, $limite, $timeout = self::TIMEOUT ) {
		$restante = max( 1, (int) floor( $limite - microtime( true ) ) );

		$resposta = wp_remote_post(
			self::ENDPOINT . '/models/' . rawurlencode( $modelo ) . ':generateContent',
			array(
				'timeout' => min( $timeout, $restante ),
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $chave,
				),
				'body'    => $corpo,
			)
		);

		if ( is_wp_error( $resposta ) ) {
			// O WordPress devolve o tempo esgotado como erro de rede genérico; é
			// a mensagem do cURL que diferencia os dois.
			$mensagem = $resposta->get_error_message();
			return new WP_Error( preg_match( '/timed out|cURL error 28/i', $mensagem ) ? 'tempo' : 'rede', $mensagem );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resposta );
		$cru    = wp_remote_retrieve_body( $resposta );

		if ( 200 !== $codigo ) {
			return self::erro_http( $codigo, $cru );
		}

		return self::interpretar( $cru );
	}

	private static function erro_http( $codigo, $cru ) {
		$dados    = json_decode( $cru, true );
		$mensagem = isset( $dados['error']['message'] ) ? $dados['error']['message'] : '';

		if ( 429 === $codigo ) {
			// A mensagem inteira vai junto: é nela que se descobre se a cota
			// estourada é a do minuto ou a do dia.
			return new WP_Error( 'cota', 'Cota da API esgotada. ' . $mensagem . ' ' . ( is_string( $cru ) ? substr( $cru, 0, 2000 ) : '' ) );
		}
		if ( 404 === $codigo ) {
			return new WP_Error( 'modelo_sumiu', 'O modelo não existe ou não está disponível. ' . $mensagem );
		}
		if ( 401 === $codigo || 403 === $codigo ) {
			return new WP_Error( 'chave', 'A chave da API foi recusada. ' . $mensagem );
		}
		// Chave inválida vem como 400, não 401: tratada como 400 genérico, o
		// painel mandava trocar o modelo, e a cadeia gastava uma chamada na
		// reserva com a mesma chave.
		if ( 400 === $codigo && preg_match( '/API key not valid|API_KEY_INVALID|API key expired/i', (string) $cru ) ) {
			return new WP_Error( 'chave', 'A chave da API foi recusada. ' . $mensagem );
		}
		if ( 400 === $codigo ) {
			return new WP_Error( 'recusado', 'A API recusou o pedido. ' . $mensagem );
		}
		return new WP_Error( 'http', sprintf( 'A API respondeu HTTP %d. %s', $codigo, $mensagem ) );
	}

	/** Códigos que valem tentar de novo no mesmo modelo: são de um instante. */
	private static function vale_repetir( $codigo ) {
		return in_array( $codigo, array( 'rede', 'http' ), true );
	}

	/**
	 * Tira o texto da resposta da API.
	 *
	 * @return array|WP_Error
	 */
	public static function interpretar( $cru ) {
		$dados = json_decode( $cru, true );

		if ( ! is_array( $dados ) ) {
			return new WP_Error( 'resposta_ilegivel', 'A API devolveu algo que não é JSON.' );
		}

		if ( isset( $dados['promptFeedback']['blockReason'] ) ) {
			return new WP_Error( 'bloqueado_na_api', 'A própria API recusou o pedido: ' . $dados['promptFeedback']['blockReason'] );
		}

		$texto = '';
		if ( isset( $dados['candidates'][0]['content']['parts'] ) ) {
			foreach ( $dados['candidates'][0]['content']['parts'] as $parte ) {
				if ( isset( $parte['text'] ) ) {
					$texto .= $parte['text'];
				}
			}
		}

		if ( '' === trim( $texto ) ) {
			return new WP_Error( 'resposta_vazia', 'A API respondeu sem texto.' );
		}

		return array(
			'texto'  => $texto,
			'modelo' => '',
			'uso'    => array(
				'entrada' => isset( $dados['usageMetadata']['promptTokenCount'] ) ? (int) $dados['usageMetadata']['promptTokenCount'] : 0,
				'saida'   => isset( $dados['usageMetadata']['candidatesTokenCount'] ) ? (int) $dados['usageMetadata']['candidatesTokenCount'] : 0,
			),
			'finish' => isset( $dados['candidates'][0]['finishReason'] ) ? $dados['candidates'][0]['finishReason'] : '',
		);
	}
}
