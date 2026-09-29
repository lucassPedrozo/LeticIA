<?php
/**
 * "Continuar depois" — o link que leva o rascunho para outro aparelho.
 *
 * O rascunho mora no navegador em que foi começado: o token fica no
 * localStorage. Quem começa no celular, no intervalo do almoço, e quer terminar
 * no computador — onde está a logomarca — perdia tudo. Este link resolve isso,
 * e é o mesmo que a equipe manda pelo WhatsApp e o lembrete manda por e-mail
 * para quem parou no meio.
 *
 * **Uma chave com propósito próprio.** Assinado como o link de anexo, mas com
 * outro propósito: um não abre o que o outro abre. Este só serve para briefing
 * que ainda não foi enviado — enviado, quem abre recebe um aviso, não o
 * briefing de volta.
 *
 * **Vale 30 dias**, contados de quando foi gerado. Cada lembrete e cada clique
 * no painel gera um novo.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Retomada {

	const VALIDADE  = 2592000;   // 30 dias
	const PARAMETRO = 'leticia_retomar';
	const PROPOSITO = 'retomada';

	/** O lembrete sai para quem está parado há mais de um dia… */
	const LEMBRETE_DEPOIS = 86400;

	/** …e só até uma semana: depois disso, quem chama é a equipe. */
	const LEMBRETE_ATE = 604800;

	/** Quantos lembretes por rodada do agendamento diário. */
	const LEMBRETES_POR_RODADA = 30;

	/** Quantos e-mails de "continuar depois" a pessoa pode pedir por hora. */
	const EMAILS_POR_HORA = 3;

	// ------------------------------------------------------------- o token

	public static function assinar( $sessao, $expira = 0 ) {
		$expira = $expira ? (int) $expira : time() + self::VALIDADE;
		$firma  = hash_hmac( 'sha256', self::PROPOSITO . '|' . $sessao . '|' . $expira, self::segredo() );

		return rtrim( strtr( base64_encode( $sessao . '|' . $expira . '|' . $firma ), '+/', '-_' ), '=' );
	}

	/** @return string|WP_Error o id da sessão */
	public static function conferir( $token ) {
		$cru    = base64_decode( strtr( (string) $token, '-_', '+/' ), true );
		$partes = false === $cru ? array() : explode( '|', $cru );

		if ( 3 !== count( $partes ) ) {
			return new WP_Error( 'retomada_invalida', 'Esse link não está completo. Confira se copiou o endereço inteiro.' );
		}

		list( $sessao, $expira, $firma ) = $partes;

		$esperada = hash_hmac( 'sha256', self::PROPOSITO . '|' . $sessao . '|' . $expira, self::segredo() );
		if ( ! hash_equals( $esperada, $firma ) || ! Leticia_Rascunho::sessao_valida( $sessao ) ) {
			return new WP_Error( 'retomada_invalida', 'Esse link não está completo. Confira se copiou o endereço inteiro.' );
		}
		if ( (int) $expira < time() ) {
			return new WP_Error( 'retomada_expirada', 'Esse link expirou, mas dá para começar de novo por aqui.' );
		}

		return $sessao;
	}

	/**
	 * Confere o link e devolve a sessão que ele reabre.
	 *
	 * @return string|WP_Error
	 */
	public static function abrir( $token ) {
		$sessao = self::conferir( $token );
		if ( is_wp_error( $sessao ) ) {
			return $sessao;
		}

		$linha = Leticia_Registro::briefing( $sessao );
		if ( ! $linha ) {
			return new WP_Error( 'retomada_invalida', 'Não encontrei o briefing deste link, mas dá para começar de novo por aqui.' );
		}
		if ( (int) $linha['enviado_em'] > 0 ) {
			return new WP_Error( 'retomada_enviada', 'Este briefing já foi enviado e está com a equipe da JoinVix. Se quiser mudar alguma coisa, é só falar com eles pelo WhatsApp.' );
		}

		return $sessao;
	}

	/**
	 * O link, pronto para copiar, mandar ou pôr no e-mail.
	 *
	 * Abre na página em que o briefing foi preenchido — ela tem o shortcode, e
	 * é onde a pessoa já esteve. Sem página guardada, a inicial do site.
	 */
	public static function link( $sessao, $pagina = '' ) {
		$base = '' !== $pagina ? $pagina : ( function_exists( 'home_url' ) ? home_url( '/' ) : '' );

		// Fora os parâmetros dos dois links, se a página já veio com eles.
		foreach ( array( self::PARAMETRO, Leticia_Anexo::PARAMETRO ) as $parametro ) {
			$base = preg_replace( '/([?&])' . $parametro . '=[^&#]*&?/', '$1', $base );
		}
		$base = rtrim( $base, '?&' );

		return add_query_arg( array( self::PARAMETRO => self::assinar( $sessao ) ), $base );
	}

	// ------------------------------------------------------------- contatos

	/** O WhatsApp do briefing só com dígitos e o 55 na frente, para o wa.me. */
	public static function numero_whatsapp( array $respostas ) {
		$digitos = preg_replace( '/\D+/', '', isset( $respostas['whatsapp']['valor'] ) ? (string) $respostas['whatsapp']['valor'] : '' );
		if ( strlen( $digitos ) < 10 ) {
			return '';
		}
		return 0 === strpos( $digitos, '55' ) && strlen( $digitos ) > 11 ? $digitos : '55' . $digitos;
	}

	/** O e-mail do briefing, se for mesmo um e-mail — "Sem e-mail" não é. */
	public static function email( array $respostas ) {
		$email = trim( isset( $respostas['email']['valor'] ) ? (string) $respostas['email']['valor'] : '' );
		return '' !== $email && is_email( $email ) ? $email : '';
	}

	/** "marina@padariaaurora.com.br" vira "m•••@padariaaurora.com.br". */
	public static function mascarar( $email ) {
		$partes = explode( '@', (string) $email );
		if ( 2 !== count( $partes ) || '' === $partes[0] ) {
			return '';
		}
		return mb_substr( $partes[0], 0, 1, 'UTF-8' ) . '•••@' . $partes[1];
	}

	/**
	 * O link do wa.me para a equipe chamar quem parou, com a mensagem pronta.
	 *
	 * @return string '' quando o briefing não tem WhatsApp
	 */
	public static function link_whatsapp_equipe( array $linha ) {
		$numero = self::numero_whatsapp( (array) $linha['respostas'] );
		if ( '' === $numero ) {
			return '';
		}
		return 'https://wa.me/' . $numero . '?text=' . rawurlencode( self::mensagem_equipe( $linha ) );
	}

	public static function mensagem_equipe( array $linha ) {
		$respostas = (array) $linha['respostas'];
		$nome      = Leticia_Base::primeiro_nome( isset( $respostas['responsavel']['valor'] ) ? $respostas['responsavel']['valor'] : '' );

		return sprintf(
			'Oi%s! Aqui é da JoinVix. Vi que você começou o briefing do seu site e respondeu %d de %d perguntas. Está tudo guardado, e dá para continuar de onde parou por este link: %s',
			'' !== $nome ? ', ' . $nome : '',
			(int) $linha['respondidos'],
			(int) Leticia_Campos::total(),
			self::link( $linha['sessao'], (string) $linha['pagina'] )
		);
	}

	// ------------------------------------------------------------- e-mails

	/**
	 * Manda o link para o e-mail que a pessoa deu no briefing.
	 *
	 * O endereço é sempre o do briefing, nunca um que venha na requisição: a
	 * rota é pública, e aceitar destinatário de fora faria dela um disparador
	 * de e-mail com a marca da JoinVix.
	 *
	 * @return true|WP_Error
	 */
	public static function mandar_link( $sessao, array $estado, $pagina = '' ) {
		$email = self::email( isset( $estado['respostas'] ) ? $estado['respostas'] : array() );
		if ( '' === $email ) {
			return new WP_Error( 'sem_email', 'Este briefing não tem e-mail. Copie o link ou mande para o seu WhatsApp.' );
		}

		$chave  = 'leticia_retomada_' . substr( $sessao, 0, 16 );
		$vezes  = (int) get_transient( $chave );
		if ( $vezes >= self::EMAILS_POR_HORA ) {
			return new WP_Error( 'muitos_emails', 'O link já foi para o seu e-mail. Confira também a caixa de spam.' );
		}
		set_transient( $chave, $vezes + 1, HOUR_IN_SECONDS );

		$nome = Leticia_Base::primeiro_nome( isset( $estado['respostas']['responsavel']['valor'] ) ? $estado['respostas']['responsavel']['valor'] : '' );
		$foi  = wp_mail(
			$email,
			'Link para continuar o seu briefing — JoinVix',
			self::corpo( $nome, Leticia_Roteiro::quantos_resolvidos( $estado ), self::link( $sessao, $pagina ), false ),
			self::cabecalhos()
		);

		return $foi ? true : new WP_Error( 'email_falhou', 'Não consegui mandar o e-mail agora. Copie o link, por enquanto.' );
	}

	/**
	 * O lembrete: um e-mail para quem parou no meio.
	 *
	 * Roda no agendamento diário. Sai uma vez por parada: se a pessoa voltar,
	 * responder e parar de novo, ganha outro — mas nunca dois seguidos para
	 * a mesma parada. Só para quem deixou e-mail, respondeu alguma coisa e está
	 * parado entre um dia e uma semana.
	 *
	 * @return int quantos lembretes saíram
	 */
	public static function lembrar() {
		if ( ! Leticia_Config::lembrete_ligado() ) {
			return 0;
		}

		$agora  = time();
		$sairam = 0;

		foreach ( Leticia_Registro::armazem()->listar_briefings( array( 'enviados' => false, 'limite' => 300 ) ) as $b ) {
			if ( $sairam >= self::LEMBRETES_POR_RODADA ) {
				break;
			}
			if ( (int) $b['respondidos'] < 1 || ! empty( $b['roteiro']['lembrete'] ) ) {
				continue;
			}
			// Link da equipe que o cliente nem abriu: ele não "parou no meio",
			// não começou. Quem chama é a equipe, pelo painel.
			if ( Leticia_Links::nao_aberto( $b ) ) {
				continue;
			}
			$parado = $agora - Leticia_Registro::ultima_atividade( $b );
			if ( $parado < self::LEMBRETE_DEPOIS || $parado > self::LEMBRETE_ATE ) {
				continue;
			}
			$email = self::email( (array) $b['respostas'] );
			if ( '' === $email ) {
				continue;
			}

			$nome = Leticia_Base::primeiro_nome( isset( $b['respostas']['responsavel']['valor'] ) ? $b['respostas']['responsavel']['valor'] : '' );
			$foi  = wp_mail(
				$email,
				'Seu briefing está guardado — JoinVix',
				self::corpo( $nome, (int) $b['respondidos'], self::link( $b['sessao'], (string) $b['pagina'] ), true ),
				self::cabecalhos()
			);

			// Marca mesmo se o e-mail falhou: tentar de novo todo dia um
			// endereço que recusa é spam do nosso lado.
			Leticia_Registro::marcar_lembrete( $b['sessao'] );
			if ( $foi ) {
				$sairam++;
			}
		}

		return $sairam;
	}

	private static function corpo( $nome, $respondidos, $link, $lembrete ) {
		$ola   = '' !== $nome ? 'Oi, ' . $nome . '!' : 'Oi!';
		$total = Leticia_Campos::total();

		$abertura = $lembrete
			? sprintf( '%s Você começou o briefing do seu site e respondeu %d de %d perguntas. Está tudo guardado: dá para continuar de onde parou, no celular ou no computador.', $ola, $respondidos, $total )
			: sprintf( '%s Aqui está o link para continuar o briefing do seu site de onde parou, no celular ou no computador. Você já respondeu %d de %d perguntas.', $ola, $respondidos, $total );

		$blocos = array(
			Leticia_Email::paragrafo( $abertura ),
			Leticia_Email::botao( 'Continuar o briefing', $link ),
			Leticia_Email::paragrafo( 'O link vale por 30 dias e abre o seu briefing — guarde-o só para você.' . ( $lembrete ? ' Se já falou com a equipe, pode ignorar este e-mail.' : '' ), true ),
			Leticia_Email::paragrafo( 'Equipe JoinVix', true ),
		);

		return Leticia_Email::documento(
			$lembrete ? 'Seu briefing está guardado' : 'Continue de onde parou',
			'Site Express · JoinVix',
			$blocos
		);
	}

	private static function cabecalhos() {
		$cabecalhos = array( 'Content-Type: text/html; charset=UTF-8' );
		$remetente  = Leticia_Config::remetente();
		if ( '' !== $remetente ) {
			$cabecalhos[] = 'From: ' . $remetente;
		}
		return $cabecalhos;
	}

	private static function segredo() {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'leticia' );
		}
		$do_ambiente = getenv( 'LETICIA_SALT' );
		return $do_ambiente ? $do_ambiente : 'leticia-desenvolvimento';
	}
}
