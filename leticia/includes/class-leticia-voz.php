<?php
/**
 * Responder falando.
 *
 * O áudio vai ao Gemini junto com o campo e volta como texto para a pessoa
 * **conferir**. Nada daqui grava resposta: o texto confirmado segue pela rota
 * de sempre, `/responder`, como se tivesse sido digitado — com a mesma
 * validação, a mesma condução e a mesma trava. A voz é um jeito de escrever,
 * não um segundo caminho pelo roteiro.
 *
 * **O áudio não fica em lugar nenhum.** Chega, vai ao modelo e acaba com a
 * requisição. Nem o disco nem o registro guardam a voz de ninguém.
 *
 * **O que economiza cota é a duração, não a compressão.** O Gemini cobra 32
 * tokens por segundo de áudio, seja WAV ou Opus. Opus a 24 kbps só deixa o
 * envio leve no 4G; quem segura o gasto é o teto de segundos, o navegador não
 * mandar gravação sem voz, e o limite por sessão.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Voz {

	/** Menos que isso não é uma gravação, é um clique. */
	const MIN_BYTES = 600;

	/**
	 * Teto de bytes por formato.
	 *
	 * Folgado para o teto de 120 s — o navegador grava Opus a 24 kbps, e o
	 * Safari grava AAC bem mais gordo. Não mede duração; impede que alguém
	 * mande dez minutos de áudio para gastar a cota.
	 */
	const TETO_OPUS  = 1572864;   // 1,5 MB
	const TETO_OUTRO = 4194304;   // 4 MB

	/** Os tipos de campo em que falar faz sentido. Botão e arquivo, não. */
	const TIPOS = array( 'texto', 'telefone', 'email', 'dominio' );

	/** O teto do campo de texto da tela: o que volta cabe na caixa. */
	const TETO_TEXTO = 1200;

	public static function aceita_campo( array $campo ) {
		return in_array( $campo['tipo'], self::TIPOS, true );
	}

	/**
	 * O formato pelos primeiros bytes, e não pelo Content-Type.
	 *
	 * O cabeçalho é o navegador quem diz, e nada que o navegador diz decide
	 * coisa alguma. Os bytes dizem o que o arquivo é — e a resposta é o nome
	 * que o Gemini aceita, que nem sempre é o do navegador: o `audio/mp4` do
	 * Safari é, para a API, `audio/m4a`.
	 *
	 * @return string|null
	 */
	public static function formato( $bytes ) {
		$bytes = (string) $bytes;
		if ( strlen( $bytes ) < 12 ) {
			return null;
		}
		if ( "\x1A\x45\xDF\xA3" === substr( $bytes, 0, 4 ) ) {
			return 'audio/webm';
		}
		if ( 'OggS' === substr( $bytes, 0, 4 ) ) {
			return 'audio/ogg';
		}
		if ( 'ftyp' === substr( $bytes, 4, 4 ) ) {
			return 'audio/m4a';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WAVE' === substr( $bytes, 8, 4 ) ) {
			return 'audio/wav';
		}
		if ( 'ID3' === substr( $bytes, 0, 3 ) || ( "\xFF" === $bytes[0] && ( ord( $bytes[1] ) & 0xE0 ) === 0xE0 ) ) {
			return 'audio/mp3';
		}
		return null;
	}

	public static function teto_bytes( $mime ) {
		return in_array( $mime, array( 'audio/webm', 'audio/ogg' ), true ) ? self::TETO_OPUS : self::TETO_OUTRO;
	}

	/**
	 * Ouve e interpreta.
	 *
	 * @return array|WP_Error array(
	 *   'ouviu'           => bool,    houve fala que deu para entender
	 *   'texto'           => string,  o que vai para a pessoa conferir
	 *   'confianca_baixa' => bool,
	 *   'motivo'          => string,  nenhum|ruido|cortado|ambiguo|soletrar|fora_do_campo
	 *   'aviso'           => string,  o motivo em linguagem de gente
	 *   'nota'            => string,  lembrete de conferir, mesmo com certeza
	 *   'confirmacao'     => string,  "Entendi que o seu WhatsApp é"
	 * )
	 */
	public static function interpretar( array $campo, $audio, array $contexto = array(), $sessao = '' ) {
		$audio = (string) $audio;

		if ( strlen( $audio ) < self::MIN_BYTES ) {
			return new WP_Error( 'audio_vazio', 'A gravação chegou vazia. Pode gravar de novo?', array( 'status' => 400 ) );
		}

		$mime = self::formato( $audio );
		if ( null === $mime ) {
			return new WP_Error( 'formato_audio', 'Não reconheci o formato desta gravação. Pode escrever a resposta?', array( 'status' => 415 ) );
		}

		if ( strlen( $audio ) > self::teto_bytes( $mime ) ) {
			return new WP_Error( 'audio_grande', 'A gravação ficou longa demais. Consegue resumir em menos tempo?', array( 'status' => 413 ) );
		}

		$resposta = Leticia_Gemini::ouvir(
			Leticia_Prompt::instrucao_voz( $campo ),
			Leticia_Prompt::turno_voz( $campo, $contexto ),
			$audio,
			$mime
		);
		Leticia_Limites::registrar_audio( $sessao );

		$lido = is_wp_error( $resposta ) ? null : self::ler_json( $resposta['texto'] );

		if ( null === $lido ) {
			do_action(
				'leticia_voz_falhou',
				$campo['chave'],
				is_wp_error( $resposta ) ? $resposta->get_error_code() : 'json_quebrado'
			);
			// Uma tentativa só, sem repetir: quem está esperando é uma pessoa
			// que acabou de falar, e a saída boa para ela é escrever — não
			// esperar mais quinze segundos pela mesma falha.
			return new WP_Error( 'voz_falhou', 'Não consegui entender o áudio agora. Pode escrever a resposta?', array( 'status' => 502 ) );
		}

		return self::higienizar( $lido, $campo );
	}

	/** @return array|null */
	public static function ler_json( $texto ) {
		$texto = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( (string) $texto ) ) );
		$dados = json_decode( $texto, true );
		if ( ! is_array( $dados ) && preg_match( '/\{.*\}/s', $texto, $achado ) ) {
			$dados = json_decode( $achado[0], true );
		}
		return is_array( $dados ) && array_key_exists( 'houve_fala', $dados ) ? $dados : null;
	}

	/**
	 * Põe em forma o que voltou, sem confiar em nada.
	 *
	 * O modelo diz se tem certeza; o servidor ainda confere o formato. Um
	 * e-mail sem arroba volta marcado como dúvida mesmo que o modelo tenha dito
	 * que ouviu muito bem — é o tipo de erro que a pessoa confirma sem ler.
	 */
	private static function higienizar( array $lido, array $campo ) {
		$motivos = array_keys( self::avisos() );

		$texto = isset( $lido['texto_interpretado'] ) ? (string) $lido['texto_interpretado'] : '';
		if ( '' === trim( $texto ) && isset( $lido['transcricao'] ) ) {
			$texto = (string) $lido['transcricao'];
		}
		$texto = self::limpar( $texto );

		$saida = array(
			'ouviu'           => ! empty( $lido['houve_fala'] ) && '' !== $texto,
			'texto'           => '',
			'confianca_baixa' => ! empty( $lido['confianca_baixa'] ),
			'motivo'          => isset( $lido['motivo'] ) && in_array( $lido['motivo'], $motivos, true ) ? $lido['motivo'] : 'nenhum',
			'aviso'           => '',
			'nota'            => '',
			'confirmacao'     => isset( $campo['ouvi'] ) ? $campo['ouvi'] : 'Entendi assim:',
		);

		if ( ! $saida['ouviu'] ) {
			$saida['confianca_baixa'] = true;
			$saida['motivo']          = 'nenhum';
			$saida['aviso']           = 'Não consegui ouvir nada. Tente de novo com o celular mais perto da boca.';
			return $saida;
		}

		$saida['texto'] = $texto;

		$validado = Leticia_Validacao::checar( $campo, $texto );
		if ( ! $validado['ok'] && empty( $validado['conduzir'] ) ) {
			$saida['confianca_baixa'] = true;
			$saida['motivo']          = in_array( $campo['tipo'], array( 'telefone', 'email', 'dominio' ), true ) ? 'soletrar' : 'ambiguo';
		}

		if ( $saida['confianca_baixa'] && 'nenhum' === $saida['motivo'] ) {
			$saida['motivo'] = 'ambiguo';
		}
		if ( ! $saida['confianca_baixa'] ) {
			$saida['motivo'] = 'nenhum';
		}

		$saida['aviso'] = self::aviso( $saida['motivo'], $campo );

		// Dado exato pede conferência mesmo quando o modelo tem certeza: no
		// teste, "Alves" soletrado virou "alvis" com confiança alta, e o
		// formato do e-mail estava perfeito. É o erro que ninguém lê antes de
		// confirmar.
		if ( ! $saida['confianca_baixa'] && in_array( $campo['tipo'], array( 'telefone', 'email', 'dominio' ), true ) ) {
			$saida['nota'] = 'telefone' === $campo['tipo']
				? 'Confira número por número antes de confirmar.'
				: 'Confira letra por letra antes de confirmar.';
		}

		return $saida;
	}

	/**
	 * O motivo, dito para quem não sabe o que é "confiança baixa".
	 *
	 * Texto fixo, e não do modelo: o que aparece na tela vindo do modelo é só
	 * o que a própria pessoa falou.
	 */
	public static function avisos() {
		return array(
			'nenhum'        => '',
			'ruido'         => 'Tinha barulho na gravação, então posso ter ouvido errado. Confira com calma.',
			'cortado'       => 'Parece que a gravação cortou no meio. Confira se está tudo aí.',
			'ambiguo'       => 'Fiquei em dúvida em algumas palavras. Confira com calma.',
			'soletrar'      => 'Este precisa estar exato. Confira letra por letra.',
			'fora_do_campo' => 'Não sei se isso responde à pergunta. Confira se era isso mesmo.',
		);
	}

	private static function aviso( $motivo, array $campo ) {
		$avisos = self::avisos();
		if ( 'soletrar' === $motivo && 'telefone' === $campo['tipo'] ) {
			return 'Confira número por número: com um dígito errado, a equipe não consegue falar com você.';
		}
		return isset( $avisos[ $motivo ] ) ? $avisos[ $motivo ] : '';
	}

	/** Sem marcação, sem espaço sobrando, e cabendo na caixa de texto. */
	private static function limpar( $texto ) {
		$texto  = wp_strip_all_tags( (string) $texto );
		$linhas = array();
		foreach ( preg_split( '/\R/u', $texto ) as $linha ) {
			$linha = trim( preg_replace( '/[ \t]+/u', ' ', $linha ) );
			if ( '' !== $linha ) {
				$linhas[] = $linha;
			}
		}
		$texto = implode( "\n", $linhas );
		if ( mb_strlen( $texto, 'UTF-8' ) > self::TETO_TEXTO ) {
			$texto = rtrim( mb_substr( $texto, 0, self::TETO_TEXTO, 'UTF-8' ) );
		}
		return $texto;
	}
}
