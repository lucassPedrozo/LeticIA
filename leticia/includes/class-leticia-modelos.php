<?php
/**
 * A saúde dos modelos: pausa por falha e checagem diária.
 *
 * Não confundir com o disjuntor de `Leticia_Limites`, que é o teto de chamadas
 * do dia e vale para a LetícIA inteira. Este aqui é **por modelo** e reage a
 * falha, não a volume:
 *
 * **A pausa.** Três falhas seguidas (tempo esgotado, rede, 5xx) em dois
 * minutos tiram o modelo da cadeia por dois minutos. Cota do dia estourada tira
 * até a meia-noite do Pacífico, que é quando o Google zera a cota. Modelo que
 * sumiu (404) sai por seis horas. Enquanto está pausado, as chamadas vão direto
 * para a reserva — ou para o modo sem IA, na hora —, em vez de cada cliente
 * esperar seis segundos pelo mesmo tempo esgotado.
 *
 * **A checagem.** Uma vez por dia, uma geração mínima em cada modelo
 * configurado. A listagem de modelos da API não serve para isso: a reserva
 * `gemini-2.5-flash-lite` continuava listada e respondia 404 para chaves novas.
 * Modelo que passa a falhar gera um aviso no painel e um e-mail — um só, na
 * mudança, e não um por dia.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Modelos {

	const PREFIXO = 'leticia_mod_';

	/** Onde fica o resultado da última checagem. */
	const OPCAO_SAUDE = 'leticia_saude_modelos';

	const FALHAS_PARA_PAUSAR = 3;
	const JANELA_FALHAS      = 120;
	const PAUSA_FALHAS       = 120;
	const PAUSA_COTA_MINUTO  = 60;
	const PAUSA_SUMIU        = 21600;   // seis horas

	// ---------------------------------------------------------------- pausa

	/** @return array|null array( 'ate' => timestamp, 'motivo' => string ) */
	public static function pausa( $modelo ) {
		$pausa = get_transient( self::PREFIXO . 'p_' . md5( $modelo ) );
		if ( ! is_array( $pausa ) || empty( $pausa['ate'] ) || $pausa['ate'] <= time() ) {
			return null;
		}
		return $pausa;
	}

	public static function pausado( $modelo ) {
		return null !== self::pausa( $modelo );
	}

	/** A cadeia sem os modelos pausados, na mesma ordem. */
	public static function disponiveis( array $cadeia ) {
		return array_values(
			array_filter(
				$cadeia,
				function ( $modelo ) {
					return ! Leticia_Modelos::pausado( $modelo );
				}
			)
		);
	}

	/** Deu certo: as falhas acumuladas deixam de contar. */
	public static function sucesso( $modelo ) {
		$chave = self::PREFIXO . 'f_' . md5( $modelo );
		// Lê antes de apagar: sucesso é o caso comum, e apagar o que não existe
		// seria uma escrita no banco a cada chamada.
		if ( false !== get_transient( $chave ) ) {
			delete_transient( $chave );
		}
	}

	/**
	 * Uma falha. Decide se o modelo sai da cadeia, e por quanto tempo.
	 *
	 * @param string $codigo o código de erro de Leticia_Gemini
	 */
	public static function falha( $modelo, $codigo, $mensagem = '' ) {
		switch ( $codigo ) {
			case 'cota':
				if ( self::cota_do_dia( $mensagem ) ) {
					self::pausar( $modelo, self::segundos_ate_virada(), 'a cota do dia acabou' );
				} else {
					self::pausar( $modelo, self::PAUSA_COTA_MINUTO, 'limite por minuto' );
				}
				return;

			case 'modelo_sumiu':
				self::pausar( $modelo, self::PAUSA_SUMIU, 'o modelo não está disponível (404)' );
				return;

			case 'tempo':
			case 'rede':
			case 'http':
				$chave  = self::PREFIXO . 'f_' . md5( $modelo );
				$falhas = (int) get_transient( $chave ) + 1;
				if ( $falhas >= self::FALHAS_PARA_PAUSAR ) {
					delete_transient( $chave );
					self::pausar( $modelo, self::PAUSA_FALHAS, $falhas . ' falhas seguidas (' . $codigo . ')' );
					return;
				}
				set_transient( $chave, $falhas, self::JANELA_FALHAS );
				return;
		}
		// 'chave', 'recusado', 'bloqueado_na_api': o problema não é o modelo
		// estar fora, e pausar não resolveria nada.
	}

	public static function pausar( $modelo, $segundos, $motivo ) {
		$segundos = max( 1, (int) $segundos );
		set_transient(
			self::PREFIXO . 'p_' . md5( $modelo ),
			array( 'ate' => time() + $segundos, 'motivo' => $motivo, 'modelo' => $modelo ),
			$segundos
		);
		do_action( 'leticia_modelo_pausado', $modelo, $segundos, $motivo );
	}

	public static function despausar( $modelo ) {
		delete_transient( self::PREFIXO . 'p_' . md5( $modelo ) );
		delete_transient( self::PREFIXO . 'f_' . md5( $modelo ) );
	}

	/**
	 * A cota estourada é a do dia?
	 *
	 * O 429 não diz em campo próprio qual cota foi; diz no nome da métrica
	 * (`...PerDay...`) e às vezes no texto. Na dúvida, é a do minuto: pausar
	 * um minuto por engano custa pouco, pausar até meia-noite custa o dia.
	 */
	public static function cota_do_dia( $mensagem ) {
		return (bool) preg_match( '/per\s?day|perday|daily|requests? per day/i', (string) $mensagem );
	}

	/** Segundos até a meia-noite do Pacífico, quando o Google zera a cota diária. */
	public static function segundos_ate_virada( $agora = null ) {
		$fuso  = new DateTimeZone( 'America/Los_Angeles' );
		$data  = new DateTime( '@' . ( null === $agora ? time() : (int) $agora ) );
		$data->setTimezone( $fuso );
		$virada = clone $data;
		$virada->setTime( 0, 0, 0 );
		$virada->modify( '+1 day' );
		return max( 60, $virada->getTimestamp() - $data->getTimestamp() );
	}

	// ------------------------------------------------------------- checagem

	/**
	 * Testa cada modelo configurado com uma geração mínima.
	 *
	 * Roda no tique diário e pelo botão do painel. Duas chamadas por dia, no
	 * máximo — contam no teto como qualquer outra.
	 *
	 * @param bool $avisar mandar e-mail quando um modelo passar a falhar
	 * @return array modelo => array( 'papel', 'ok', 'codigo', 'mensagem', 'ms', 'quando' )
	 */
	public static function checar( $avisar = true ) {
		if ( ! Leticia_Config::esta_configurado() ) {
			return array();
		}

		$anterior = self::saude();
		$papeis   = array( Leticia_Config::modelo() => 'principal' );
		if ( '' !== Leticia_Config::modelo_reserva() ) {
			$papeis[ Leticia_Config::modelo_reserva() ] = 'reserva';
		}

		$resultado  = array();
		$passaram_a = array();

		$chave_recusada = null;

		foreach ( $papeis as $modelo => $papel ) {
			// Chave recusada no primeiro vale para o segundo: é a mesma chave.
			// Testar de novo só dobraria a espera e diria a mesma coisa.
			if ( null !== $chave_recusada ) {
				$r = $chave_recusada;
			} else {
				$r = Leticia_Gemini::sondar( $modelo );
				if ( 'chave' === $r['codigo'] ) {
					$chave_recusada = $r;
				} else {
					Leticia_Limites::registrar_chamada();
				}
			}

			$resultado[ $modelo ] = array(
				'papel'    => $papel,
				'ok'       => (bool) $r['ok'],
				'codigo'   => (string) $r['codigo'],
				'mensagem' => mb_substr( (string) $r['mensagem'], 0, 300, 'UTF-8' ),
				'ms'       => (int) $r['ms'],
				'quando'   => time(),
			);

			if ( $r['ok'] ) {
				self::despausar( $modelo );
				continue;
			}

			// A falha da checagem vale como falha de uso: modelo que sumiu sai
			// da cadeia já, sem o primeiro cliente do dia descobrir.
			self::falha( $modelo, $r['codigo'], $r['mensagem'] );

			$antes_ok = ! isset( $anterior[ $modelo ] ) || ! empty( $anterior[ $modelo ]['ok'] );
			if ( $antes_ok ) {
				$passaram_a[ $modelo ] = $resultado[ $modelo ];
			}
		}

		update_option( self::OPCAO_SAUDE, $resultado, false );

		if ( $avisar && $passaram_a ) {
			self::avisar( $passaram_a );
		}

		return $resultado;
	}

	/** O resultado da última checagem. */
	public static function saude() {
		$salvo = get_option( self::OPCAO_SAUDE, array() );
		return is_array( $salvo ) ? $salvo : array();
	}

	/**
	 * O estado para o painel e para a /saude: checagem e pausa de cada modelo.
	 *
	 * @return array modelo => array( 'papel', 'checado_ok', 'checado_em', 'pausado_ate', 'motivo' )
	 */
	public static function estado() {
		$saude  = self::saude();
		$saida  = array();
		$papeis = array( Leticia_Config::modelo() => 'principal' );
		if ( '' !== Leticia_Config::modelo_reserva() ) {
			$papeis[ Leticia_Config::modelo_reserva() ] = 'reserva';
		}
		foreach ( $papeis as $modelo => $papel ) {
			$pausa            = self::pausa( $modelo );
			$saida[ $modelo ] = array(
				'papel'       => $papel,
				'checado_ok'  => isset( $saude[ $modelo ] ) ? (bool) $saude[ $modelo ]['ok'] : null,
				'checado_em'  => isset( $saude[ $modelo ] ) ? (int) $saude[ $modelo ]['quando'] : 0,
				'erro'        => isset( $saude[ $modelo ] ) && ! $saude[ $modelo ]['ok'] ? $saude[ $modelo ]['mensagem'] : '',
				'codigo'      => isset( $saude[ $modelo ] ) ? (string) $saude[ $modelo ]['codigo'] : '',
				'pausado_ate' => $pausa ? (int) $pausa['ate'] : 0,
				'motivo'      => $pausa ? $pausa['motivo'] : '',
			);
		}
		return $saida;
	}

	/** Há pelo menos um modelo que pode atender agora? */
	public static function algum_disponivel() {
		return (bool) self::disponiveis( Leticia_Gemini::cadeia() );
	}

	/** O e-mail de quando um modelo passa a falhar. Um por mudança, não um por dia. */
	private static function avisar( array $falharam ) {
		$destino = Leticia_Config::destino();
		if ( ! $destino ) {
			return;
		}

		$linhas      = array();
		$sem_reserva = false;
		$chave       = false;
		foreach ( $falharam as $modelo => $r ) {
			$chave = $chave || 'chave' === $r['codigo'];
			$linhas[] = sprintf( '%s (%s): %s', $modelo, $r['papel'], '' !== $r['mensagem'] ? $r['mensagem'] : $r['codigo'] );
			if ( 'principal' === $r['papel'] ) {
				$sem_reserva = true;
			}
		}

		if ( $chave ) {
			$titulo       = 'Chave da API recusada';
			$consequencia = 'Nenhum modelo atende com esta chave: os briefings seguem sem comentário da IA — mas continuam sendo coletados e enviados.';
			$solucao      = 'Para resolver, confira a chave em Configurações → ' . Leticia_Config::nome() . ' (ou a constante LETICIA_GEMINI_API_KEY no wp-config.php) e use o botão "Testar os modelos". O .env só vale para o ambiente local.';
		} else {
			$titulo       = 'Modelo de IA fora do ar';
			$consequencia = $sem_reserva
				? 'Enquanto o modelo principal estiver fora, os briefings usam a reserva. Sem reserva que funcione, seguem sem comentário da IA — mas continuam sendo coletados e enviados.'
				: 'O principal está respondendo. Só que, se ele cair, não há reserva para assumir: os briefings seguiriam sem comentário da IA.';
			$solucao      = 'Para resolver, troque o modelo em Configurações → ' . Leticia_Config::nome() . ' e use o botão "Testar os modelos".';
		}

		$corpo = Leticia_Email::documento(
			$titulo,
			'A checagem diária da ' . Leticia_Config::nome() . ' encontrou um problema.',
			array(
				Leticia_Email::aviso( 'atencao', 'O que falhou', $linhas, esc_html( $consequencia ) ),
				Leticia_Email::paragrafo( $solucao ),
			)
		);

		$cabecalhos = array( 'Content-Type: text/html; charset=UTF-8' );
		$remetente  = Leticia_Config::remetente();
		if ( '' !== $remetente ) {
			$cabecalhos[] = 'From: ' . $remetente;
		}

		$assunto = $chave
			? '[' . Leticia_Config::nome() . '] Chave da API recusada'
			: '[' . Leticia_Config::nome() . '] Modelo de IA fora do ar: ' . implode( ', ', array_keys( $falharam ) );
		wp_mail( $destino, $assunto, $corpo, $cabecalhos );
	}

	/** Só para a suíte e para o botão do painel. */
	public static function zerar() {
		foreach ( Leticia_Gemini::cadeia() as $modelo ) {
			self::despausar( $modelo );
		}
		delete_option( self::OPCAO_SAUDE );
	}
}
