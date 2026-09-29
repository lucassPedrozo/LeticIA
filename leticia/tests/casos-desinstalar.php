<?php
/**
 * Excluir o plugin.
 *
 * Os dois erros que importam aqui são opostos: deixar lixo para trás
 * (agendamento que dispara para um plugin que não existe, transient de sessão
 * para sempre na wp_options) e apagar demais (os briefings de quem só estava
 * reinstalando, ou arquivo de outra coisa numa pasta configurada por engano).
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

/** Um $wpdb que só anota o que pediram, com a wp_options num array. */
class Leticia_Wpdb_Espiao {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $sql     = array();
	public $linhas  = array();

	public function esc_like( $texto ) {
		return addcslashes( $texto, '_%\\' );
	}

	public function prepare( $sql ) {
		$args = array_slice( func_get_args(), 1 );
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . addslashes( $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}

	public function query( $sql ) {
		$this->sql[] = $sql;
		if ( 0 !== strpos( $sql, 'DELETE FROM wp_options' ) ) {
			return true;
		}
		$antes = count( $this->linhas );
		$this->linhas = array_values( array_filter( $this->linhas, function ( $nome ) {
			return 0 !== strpos( $nome, '_transient_leticia_' ) && 0 !== strpos( $nome, '_transient_timeout_leticia_' );
		} ) );
		return $antes - count( $this->linhas );
	}
}

$preparar = function ( $apagar_dados ) {
	update_option( 'leticia_config', array( 'GEMINI_API_KEY' => 'x', 'APAGAR_AO_DESINSTALAR' => $apagar_dados ? '1' : '0' ) );
	update_option( 'leticia_saude_modelos', array( 'gemini-3.5-flash-lite' => array( 'ok' => true ) ) );
	update_option( 'leticia_db_versao', '3' );
	update_option( 'outro_plugin_config', 'fica' );
	$GLOBALS['leticia_desagendados'] = array();

	$wpdb         = new Leticia_Wpdb_Espiao();
	$wpdb->linhas = array(
		'_transient_leticia_lim_dia_20260916', '_transient_timeout_leticia_lim_dia_20260916',
		'_transient_leticia_gaveta_abc', '_transient_leticia_mod_p_123',
		'_transient_outro_plugin', '_transient_timeout_outro_plugin',
	);
	return $wpdb;
};

$pasta_teste = function () {
	$pasta = sys_get_temp_dir() . '/leticia-desinstalar-' . getmypid() . '-' . wp_rand( 1, 99999 );
	Leticia_Arquivos::proteger( $pasta );
	@mkdir( $pasta . '/arquivos/sessao1', 0777, true );
	@mkdir( $pasta . '/parciais/sessao1/envio1', 0777, true );
	file_put_contents( $pasta . '/arquivos/sessao1/a1b2c3.pdf', 'pdf' );
	file_put_contents( $pasta . '/parciais/sessao1/envio1/0', 'pedaço' );
	return $pasta;
};

$casos[] = array(
	'grupo'    => 'desinstalar',
	'nome'     => 'sai configuração, transients e agendamentos; os briefings ficam por padrão',
	'executar' => function () use ( $preparar ) {
		$wpdb = $preparar( false );
		add_filter( 'leticia_pasta_base', function () {
			return '';
		} );
		$feito = Leticia_Desinstalar::executar( $wpdb )[0];
		remove_all_filters( 'leticia_pasta_base' );

		if ( false !== get_option( 'leticia_config' ) || false !== get_option( 'leticia_saude_modelos' ) ) {
			return 'a configuração ficou';
		}
		if ( 'fica' !== get_option( 'outro_plugin_config' ) ) {
			return 'apagou opção de outro plugin';
		}
		if ( array( '_transient_outro_plugin', '_transient_timeout_outro_plugin' ) !== $wpdb->linhas ) {
			return 'transients que sobraram: ' . implode( ', ', $wpdb->linhas );
		}
		if ( array( 'leticia_diario', 'leticia_reentrega' ) !== $GLOBALS['leticia_desagendados'] ) {
			return 'agendamentos desfeitos: ' . implode( ', ', $GLOBALS['leticia_desagendados'] );
		}
		foreach ( $wpdb->sql as $sql ) {
			if ( false !== stripos( $sql, 'DROP TABLE' ) ) {
				return 'apagou tabela sem a caixa marcada';
			}
		}
		if ( '3' !== get_option( 'leticia_db_versao' ) ) {
			return 'tirou a versão do banco com as tabelas ficando';
		}
		delete_option( 'leticia_db_versao' );
		delete_option( 'outro_plugin_config' );
		return 4 === $feito['transients'] ? null : 'contou ' . $feito['transients'] . ' transients';
	},
);

$casos[] = array(
	'grupo'    => 'desinstalar',
	'nome'     => 'com a caixa marcada, as duas tabelas saem junto',
	'executar' => function () use ( $preparar ) {
		$wpdb = $preparar( true );
		add_filter( 'leticia_pasta_base', function () {
			return '';
		} );
		$feito = Leticia_Desinstalar::executar( $wpdb )[0];
		remove_all_filters( 'leticia_pasta_base' );
		delete_option( 'outro_plugin_config' );

		$drops = array_values( array_filter( $wpdb->sql, function ( $s ) {
			return false !== stripos( $s, 'DROP TABLE' );
		} ) );
		if ( array( 'DROP TABLE IF EXISTS wp_leticia_briefings', 'DROP TABLE IF EXISTS wp_leticia_turnos' ) !== $drops ) {
			return 'tabelas: ' . implode( ' | ', $drops );
		}
		return false === get_option( 'leticia_db_versao' ) ? null : 'a versão do banco ficou sem as tabelas';
	},
);

$casos[] = array(
	'grupo'    => 'desinstalar',
	'nome'     => 'a pasta temporária sai inteira, com a proteção',
	'executar' => function () use ( $pasta_teste ) {
		$pasta = $pasta_teste();
		$n     = Leticia_Desinstalar::apagar_pasta( $pasta );
		if ( is_dir( $pasta ) ) {
			return 'a pasta ficou (' . $n . ' arquivos apagados)';
		}
		return 5 === $n ? null : 'apagou ' . $n . ' arquivos, esperava 5';
	},
);

$casos[] = array(
	'grupo'    => 'desinstalar',
	'nome'     => 'pasta configurada por engano: o que não é da LetícIA fica, e a pasta também',
	'executar' => function () use ( $pasta_teste ) {
		$pasta = $pasta_teste();
		file_put_contents( $pasta . '/backup-do-site.zip', 'importante' );
		file_put_contents( $pasta . '/.htaccess', "# regra de outro sistema\n" );
		@mkdir( $pasta . '/outra-coisa' );
		file_put_contents( $pasta . '/outra-coisa/dado.txt', 'importante' );

		Leticia_Desinstalar::apagar_pasta( $pasta );

		$ficou = is_file( $pasta . '/backup-do-site.zip' ) && is_file( $pasta . '/outra-coisa/dado.txt' ) && is_file( $pasta . '/.htaccess' );
		$saiu  = ! file_exists( $pasta . '/arquivos' ) && ! file_exists( $pasta . '/parciais' );

		foreach ( array( '/backup-do-site.zip', '/outra-coisa/dado.txt', '/.htaccess' ) as $f ) {
			@unlink( $pasta . $f );
		}
		@rmdir( $pasta . '/outra-coisa' );
		@rmdir( $pasta );

		if ( ! $ficou ) {
			return 'apagou arquivo que não era do plugin';
		}
		return $saiu ? null : 'não limpou o que era do plugin';
	},
);

$casos[] = array(
	'grupo'    => 'desinstalar',
	'nome'     => 'os nomes escritos por extenso batem com as constantes das outras classes',
	'executar' => function () {
		$opcoes = array( Leticia_Config::OPCAO, Leticia_Modelos::OPCAO_SAUDE, Leticia_Armazem_Wpdb::OPCAO_VERSAO );
		if ( array_diff( $opcoes, Leticia_Desinstalar::OPCOES ) ) {
			return 'opção esquecida: ' . implode( ', ', array_diff( $opcoes, Leticia_Desinstalar::OPCOES ) );
		}
		$agenda = array( Leticia_Registro::CRON, Leticia_Entrega::CRON_REENTREGA );
		if ( array_diff( $agenda, Leticia_Desinstalar::AGENDAMENTOS ) ) {
			return 'agendamento esquecido';
		}
		$prefixos = array( Leticia_Limites::PREFIXO, Leticia_Modelos::PREFIXO, Leticia_Rest::PREFIXO_GAVETA, Leticia_Arquivos::PREFIXO_PARCIAL, Leticia_Gemini::PREFIXO_SEM_PENSAMENTO, Leticia_Base::CACHE, Leticia_Config::DIAG, Leticia_Admin::AVISO );
		foreach ( $prefixos as $p ) {
			if ( 0 !== strpos( $p, Leticia_Desinstalar::PREFIXO_TRANSIENT ) ) {
				return 'transient fora do prefixo: ' . $p;
			}
		}
		$wpdb = new Leticia_Wpdb_Falso();
		foreach ( Leticia_Desinstalar::TABELAS as $t ) {
			if ( false === strpos( $wpdb->prefix . $t, 'leticia_' ) ) {
				return 'tabela estranha';
			}
		}
		if ( ! file_exists( LETICIA_DIR . 'uninstall.php' ) || false === strpos( file_get_contents( LETICIA_DIR . 'uninstall.php' ), 'WP_UNINSTALL_PLUGIN' ) ) {
			return 'uninstall.php sumiu ou roda sem a guarda do WordPress';
		}
		return 'APAGAR_AO_DESINSTALAR' === Leticia_Desinstalar::APAGAR_DADOS && array_key_exists( 'APAGAR_AO_DESINSTALAR', Leticia_Config::padroes() ) ? null : 'a chave da caixa mudou de nome';
	},
);

return $casos;
