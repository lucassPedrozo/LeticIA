<?php
/**
 * A rota autenticada de download.
 *
 * É o outro lado do link que vai no e-mail. Os anexos moram numa pasta fechada
 * para a web — sem isso, o endereço de um arquivo é o arquivo, e material de
 * cliente fica acessível para quem descobrir a URL.
 *
 * Roda em `admin_init`, e não numa rota REST, por um motivo prático: o link
 * chega por e-mail e é clicado num navegador com sessão do WordPress aberta.
 * A REST com autenticação por cookie exige nonce, que um link de e-mail não
 * tem como carregar — a pessoa clicaria e receberia "não autorizado" estando
 * logada.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Download {

	/** A página do painel. O download mora no endereço dela. */
	const PAGINA = 'leticia';

	/** Quem pode baixar anexo de cliente. */
	const PERMISSAO = 'edit_pages';

	public static function iniciar() {
		add_action( 'admin_init', array( __CLASS__, 'talvez_entregar' ) );
	}

	public static function talvez_entregar() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['acao'], $_GET['arquivo'] ) ) {
			return;
		}
		if ( self::PAGINA !== $_GET['page'] || 'baixar' !== $_GET['acao'] ) {
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_GET['arquivo'] ) );
		// phpcs:enable

		if ( ! current_user_can( self::PERMISSAO ) ) {
			// Mesma resposta para "não pode" e "não existe": dizer qual dos
			// dois é ensina quem está tentando descobrir ids.
			wp_die( esc_html( 'Arquivo não encontrado.' ), '', array( 'response' => 404 ) );
		}

		$meta = self::achar( $id );
		if ( ! $meta ) {
			wp_die( esc_html( 'Arquivo não encontrado.' ), '', array( 'response' => 404 ) );
		}

		$r = Leticia_Arquivos::entregar( $meta );
		if ( is_wp_error( $r ) ) {
			wp_die( esc_html( $r->get_error_message() ), '', array( 'response' => 404 ) );
		}

		exit;
	}

	/**
	 * Acha o arquivo pelo id, varrendo os briefings.
	 *
	 * O id é aleatório e não diz nada sobre onde o arquivo está: quem tem o id
	 * não consegue montar o caminho, e quem tem o caminho não chega nele pela
	 * web. A varredura é o preço disso, e é barata — são dezenas de briefings,
	 * não milhões, e só quem está logado chega aqui.
	 *
	 * @return array|null
	 */
	public static function achar( $id ) {
		if ( ! preg_match( '/^[a-f0-9]{24}$/', (string) $id ) ) {
			return null;
		}

		foreach ( Leticia_Registro::armazem()->listar_briefings( array( 'limite' => 500 ) ) as $briefing ) {
			foreach ( (array) $briefing['respostas'] as $resposta ) {
				if ( empty( $resposta['arquivos'] ) ) {
					continue;
				}
				foreach ( (array) $resposta['arquivos'] as $arquivo ) {
					if ( isset( $arquivo['id'] ) && $arquivo['id'] === $id ) {
						return $arquivo;
					}
				}
			}
		}

		return null;
	}
}
