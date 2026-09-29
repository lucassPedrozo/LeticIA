<?php
/**
 * "Manda a logo depois" — o endereço que fecha a pendência.
 *
 * A pessoa que não estava com a logomarca na mão enviou o briefing assim mesmo,
 * como a LetícIA sugeriu. Até aqui, o que acontecia depois era "a equipe te
 * manda um endereço para anexar" — um endereço que não existia. Agora existe:
 * um link pessoal, gerado no envio, que abre a mesma página do briefing só com
 * o campo que ficou faltando.
 *
 * **O link é uma chave, não um formulário aberto.** Ele carrega um token
 * assinado com um propósito diferente do token de rascunho: um não abre o que
 * o outro abre. O de rascunho continua sem conseguir mexer num briefing
 * enviado, e o de anexo só consegue uma coisa — pôr arquivo no campo pendente.
 *
 * **Vale 30 dias.** Logomarca que não chegou em um mês não é mais pendência de
 * link: é conversa de WhatsApp com a equipe.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Anexo {

	const VALIDADE  = 2592000;   // 30 dias
	const PARAMETRO = 'leticia_anexo';

	/** O que distingue esta assinatura da do rascunho. */
	const PROPOSITO = 'anexo';

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
			return new WP_Error( 'anexo_invalido', 'Esse link não está completo. Confira se copiou o endereço inteiro.' );
		}

		list( $sessao, $expira, $firma ) = $partes;

		$esperada = hash_hmac( 'sha256', self::PROPOSITO . '|' . $sessao . '|' . $expira, self::segredo() );
		if ( ! hash_equals( $esperada, $firma ) || ! Leticia_Rascunho::sessao_valida( $sessao ) ) {
			return new WP_Error( 'anexo_invalido', 'Esse link não está completo. Confira se copiou o endereço inteiro.' );
		}
		if ( (int) $expira < time() ) {
			return new WP_Error( 'anexo_expirado', 'Esse link expirou. Mande o arquivo pelo WhatsApp da equipe, que eles anexam para você.' );
		}

		return $sessao;
	}

	/**
	 * O link, pronto para ir no e-mail e na tela final.
	 *
	 * A página é a configurada; sem configuração, a página em que o briefing
	 * foi preenchido — que tem o shortcode e é onde a pessoa já esteve.
	 */
	public static function link( $sessao, $pagina = '' ) {
		$base = Leticia_Config::link_anexo_depois();
		if ( '' === $base ) {
			$base = '' !== $pagina ? $pagina : ( function_exists( 'home_url' ) ? home_url( '/' ) : '' );
		}
		// Sem o parâmetro velho, se a página já veio com um.
		$base = preg_replace( '/([?&])' . self::PARAMETRO . '=[^&#]*&?/', '$1', $base );
		$base = rtrim( $base, '?&' );

		return add_query_arg( array( self::PARAMETRO => self::assinar( $sessao ) ), $base );
	}

	/**
	 * Os campos de arquivo que ficaram pendentes num briefing enviado.
	 *
	 * @return string[] chaves
	 */
	public static function pendentes( array $linha ) {
		$saida = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( 'arquivo' !== $campo['tipo'] ) {
				continue;
			}
			if ( ! empty( $linha['respostas'][ $campo['chave'] ]['pendente'] ) ) {
				$saida[] = $campo['chave'];
			}
		}
		return $saida;
	}

	/**
	 * Confere o token e devolve o briefing a que ele dá acesso.
	 *
	 * @return array|WP_Error array( 'sessao', 'linha', 'pendentes' )
	 */
	public static function abrir( $token ) {
		$sessao = self::conferir( $token );
		if ( is_wp_error( $sessao ) ) {
			return $sessao;
		}

		$linha = Leticia_Registro::briefing( $sessao );
		if ( ! $linha || (int) $linha['enviado_em'] < 1 ) {
			// Link de anexo para briefing que não existe mais — expurgado, ou
			// apagado pela equipe. A mensagem é a mesma de link quebrado.
			return new WP_Error( 'anexo_invalido', 'Não encontrei o briefing deste link. Fale com a equipe pelo WhatsApp, que eles resolvem.' );
		}

		return array(
			'sessao'    => $sessao,
			'linha'     => $linha,
			'pendentes' => self::pendentes( $linha ),
		);
	}

	/**
	 * Fecha a pendência com os arquivos que subiram.
	 *
	 * Reescreve só o campo pendente; o resto do briefing enviado fica como
	 * estava, inclusive o carimbo de envio.
	 *
	 * @return array|WP_Error o estado do briefing atualizado
	 */
	public static function fechar( $sessao, array $linha, $chave, array $arquivos ) {
		if ( ! in_array( $chave, self::pendentes( $linha ), true ) ) {
			return new WP_Error( 'anexo_nada', 'Esse arquivo já chegou na equipe.' );
		}
		if ( ! $arquivos ) {
			return new WP_Error( 'sem_arquivo', 'Preciso do arquivo aqui para seguir.' );
		}

		$estado = Leticia_Roteiro::sanear( array( 'respostas' => $linha['respostas'], 'enviado' => true ) );

		$r = Leticia_Roteiro::responder(
			$estado,
			$chave,
			'',
			array(
				'valor'    => implode( ', ', wp_list_pluck( $arquivos, 'nome' ) ),
				'arquivos' => $arquivos,
			)
		);
		if ( '' !== $r['erro'] ) {
			return new WP_Error( 'recusado', $r['erro'] );
		}

		$estado = Leticia_Roteiro::marcar_enviado( $r['estado'] );
		Leticia_Registro::salvar( $sessao, $estado, array( 'arquivos' => $arquivos, 'pagina' => $linha['pagina'] ) );

		return $estado;
	}

	private static function segredo() {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'leticia' );
		}
		$do_ambiente = getenv( 'LETICIA_SALT' );
		return $do_ambiente ? $do_ambiente : 'leticia-desenvolvimento';
	}
}
