<?php
/**
 * O que sai do WordPress quando o plugin é **excluído** (não desativado).
 *
 * Desativar só para o tique diário: quem desativa para testar um conflito de
 * tema não pode perder configuração nem fila de reenvio. Excluir é outra
 * coisa, e aqui sai tudo que o plugin criou:
 *
 *   - a configuração, a saúde dos modelos e a versão do banco
 *   - todo transient `leticia_*` (limites, pausas, envios pela metade, gaveta)
 *   - os agendamentos: o tique diário e a fila de reenvio de e-mail
 *   - a pasta temporária dos arquivos — só o que é dela, nada além
 *
 * **Os briefings ficam, a menos que o painel diga o contrário.** Estão no banco
 * e são o registro do que cada cliente mandou; excluir o plugin para
 * reinstalar uma versão nova não pode apagar isso. Com a caixa "apagar os
 * briefings ao excluir" marcada, as duas tabelas saem junto.
 *
 * Esta classe **não usa nenhuma outra do plugin**: o WordPress roda o
 * `uninstall.php` sem carregar o plugin. Os nomes estão escritos aqui por
 * extenso, e há caso de teste conferindo que batem com as constantes das
 * outras classes.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Desinstalar {

	const OPCOES = array( 'leticia_config', 'leticia_saude_modelos', 'leticia_db_versao' );

	const PREFIXO_TRANSIENT = 'leticia_';

	const AGENDAMENTOS = array( 'leticia_diario', 'leticia_reentrega' );

	const TABELAS = array( 'leticia_briefings', 'leticia_turnos' );

	/** A chave da configuração que pede para apagar os briefings junto. */
	const APAGAR_DADOS = 'APAGAR_AO_DESINSTALAR';

	/**
	 * Roda a limpeza em um site — ou em todos, numa rede multisite.
	 *
	 * @param object $wpdb o $wpdb do WordPress
	 * @return array o que foi feito, por site (para a suíte e para log)
	 */
	public static function executar( $wpdb ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			$feito = array();
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site ) {
				switch_to_blog( $site );
				$feito[ $site ] = self::site( $wpdb );
				restore_current_blog();
			}
			return $feito;
		}
		return array( 0 => self::site( $wpdb ) );
	}

	/** A limpeza de um site. */
	public static function site( $wpdb ) {
		// Lida antes de apagar: é a configuração que diz se os briefings saem.
		$config       = get_option( 'leticia_config', array() );
		$apagar_dados = is_array( $config ) && ! empty( $config[ self::APAGAR_DADOS ] ) && '1' === (string) $config[ self::APAGAR_DADOS ];

		$feito = array(
			'opcoes'       => 0,
			'transients'   => 0,
			'agendamentos' => 0,
			'arquivos'     => 0,
			'tabelas'      => array(),
		);

		foreach ( self::AGENDAMENTOS as $gancho ) {
			// wp_unschedule_hook e não wp_clear_scheduled_hook: o reenvio é
			// agendado com a sessão como argumento, e o clear só tira evento
			// sem argumento nenhum.
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				$feito['agendamentos'] += (int) wp_unschedule_hook( $gancho );
			} elseif ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				$feito['agendamentos'] += (int) wp_clear_scheduled_hook( $gancho );
			}
		}

		$feito['arquivos'] = self::apagar_pasta( self::pasta() );

		foreach ( self::OPCOES as $opcao ) {
			// A versão do banco só sai junto com as tabelas: sem ela e com as
			// tabelas no lugar, a reinstalação conferiria tudo de novo à toa —
			// sem estrago, mas sem motivo.
			if ( 'leticia_db_versao' === $opcao && ! $apagar_dados ) {
				continue;
			}
			if ( delete_option( $opcao ) ) {
				$feito['opcoes']++;
			}
		}

		// Transient com prazo mora em duas linhas: o valor e o vencimento.
		// Apagar direto no banco é o único jeito de pegar os que têm nome
		// variável (um por sessão, um por modelo).
		$like = $wpdb->esc_like( '_transient_' . self::PREFIXO_TRANSIENT ) . '%';
		$vence = $wpdb->esc_like( '_transient_timeout_' . self::PREFIXO_TRANSIENT ) . '%';
		$feito['transients'] = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $vence )
		);

		if ( $apagar_dados ) {
			foreach ( self::TABELAS as $tabela ) {
				$nome = $wpdb->prefix . $tabela;
				$wpdb->query( "DROP TABLE IF EXISTS {$nome}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- nome montado aqui, sem entrada de fora
				$feito['tabelas'][] = $nome;
			}
		}

		return $feito;
	}

	/** A mesma regra de Leticia_Arquivos::pasta_base(), sem depender dela. */
	public static function pasta() {
		$pasta = defined( 'LETICIA_PASTA_ARQUIVOS' ) ? trim( (string) constant( 'LETICIA_PASTA_ARQUIVOS' ) ) : '';
		if ( '' === $pasta && function_exists( 'wp_upload_dir' ) ) {
			$envio = wp_upload_dir( null, false );
			$pasta = rtrim( $envio['basedir'], '/\\' ) . '/leticia';
		}
		return apply_filters( 'leticia_pasta_base', $pasta );
	}

	/**
	 * Apaga só o que o plugin põe na pasta.
	 *
	 * Nada de apagar recursivo às cegas: se LETICIA_PASTA_ARQUIVOS apontar por
	 * engano para uma pasta com outras coisas, o que não é da LetícIA fica, e a
	 * pasta também.
	 *
	 * @return int quantos arquivos saíram
	 */
	public static function apagar_pasta( $pasta ) {
		$pasta = rtrim( (string) $pasta, '/\\' );
		if ( '' === $pasta || ! is_dir( $pasta ) ) {
			return 0;
		}

		$apagados = 0;

		// parciais/<sessão>/<envio>/pedaços e arquivos/<sessão>/arquivos:
		// os dois níveis que o plugin cria, e só eles.
		foreach ( array( 'parciais/*/*/*', 'parciais/*/*', 'arquivos/*/*' ) as $molde ) {
			foreach ( (array) glob( $pasta . '/' . $molde ) as $item ) {
				if ( is_file( $item ) && @unlink( $item ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$apagados++;
				}
			}
		}
		foreach ( array( 'parciais/*/*', 'parciais/*', 'arquivos/*', 'parciais', 'arquivos' ) as $molde ) {
			foreach ( (array) glob( $pasta . '/' . $molde, GLOB_ONLYDIR ) as $sub ) {
				@rmdir( $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- só sai se estiver vazia
			}
		}

		// A proteção sai por último, e só se for a nossa.
		foreach ( array( '.htaccess', 'index.php', 'web.config' ) as $nome ) {
			$caminho = $pasta . '/' . $nome;
			if ( is_file( $caminho ) && self::e_nossa_protecao( $nome, (string) file_get_contents( $caminho ) ) && @unlink( $caminho ) ) { // phpcs:ignore
				$apagados++;
			}
		}

		@rmdir( $pasta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- só sai se ficou vazia
		return $apagados;
	}

	/** O arquivo de proteção é o que Leticia_Arquivos::proteger() escreveu? */
	private static function e_nossa_protecao( $nome, $conteudo ) {
		switch ( $nome ) {
			case '.htaccess':
				return false !== strpos( $conteudo, 'Require all denied' ) && false !== strpos( $conteudo, 'php_flag engine off' );
			case 'index.php':
				return false !== strpos( $conteudo, 'Nada para ver aqui' );
			case 'web.config':
				return false !== strpos( $conteudo, '<deny users="*" />' );
		}
		return false;
	}
}
