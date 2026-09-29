<?php
/**
 * A suíte offline da LetícIA.
 *
 *   php leticia/tests/rodar.php
 *
 * Sem composer, sem vendor/, sem banco, sem HTTP e sem gastar cota. É para
 * rodar a cada mudança — se custar mais que um segundo ou exigir setup, ninguém
 * roda, e um teste que ninguém roda não protege nada.
 *
 * Sai com código 1 se algo falhar, para o CI e o empacotador reprovarem.
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Este arquivo roda só por linha de comando.' );
}

require_once __DIR__ . '/stubs-wp.php';
require_once LETICIA_DIR . 'includes/class-leticia-config.php';
require_once LETICIA_DIR . 'includes/class-leticia-base.php';
require_once LETICIA_DIR . 'includes/class-leticia-campos.php';
require_once LETICIA_DIR . 'includes/class-leticia-validacao.php';
require_once LETICIA_DIR . 'includes/class-leticia-roteiro.php';
require_once LETICIA_DIR . 'includes/class-leticia-trava.php';
require_once LETICIA_DIR . 'includes/class-leticia-prompt.php';
require_once LETICIA_DIR . 'includes/class-leticia-gemini.php';
require_once LETICIA_DIR . 'includes/class-leticia-modelos.php';
require_once LETICIA_DIR . 'includes/class-leticia-modelo.php';
require_once LETICIA_DIR . 'includes/class-leticia-voz.php';
require_once LETICIA_DIR . 'includes/class-leticia-limites.php';
require_once LETICIA_DIR . 'includes/class-leticia-arquivos.php';
require_once LETICIA_DIR . 'includes/class-leticia-armazem.php';
require_once LETICIA_DIR . 'includes/class-leticia-armazem-json.php';
require_once LETICIA_DIR . 'includes/class-leticia-armazem-wpdb.php';
require_once LETICIA_DIR . 'includes/class-leticia-registro.php';
require_once LETICIA_DIR . 'includes/class-leticia-rascunho.php';
require_once LETICIA_DIR . 'includes/class-leticia-anexo.php';
require_once LETICIA_DIR . 'includes/class-leticia-retomada.php';
require_once LETICIA_DIR . 'includes/class-leticia-links.php';
require_once LETICIA_DIR . 'includes/class-leticia-email.php';
require_once LETICIA_DIR . 'includes/class-leticia-entrega.php';
require_once LETICIA_DIR . 'includes/class-leticia-rest.php';
require_once LETICIA_DIR . 'includes/class-leticia-download.php';
require_once LETICIA_DIR . 'includes/class-leticia-tela.php';
require_once LETICIA_DIR . 'includes/class-leticia-desinstalar.php';
require_once LETICIA_DIR . 'admin/class-leticia-admin.php';
require_once LETICIA_DIR . 'admin/class-leticia-painel.php';

$comeco = microtime( true );

$casos = array();
$casos = array_merge( $casos, require __DIR__ . '/casos-base.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-campos.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-validacao.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-roteiro.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-conversa.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-trava.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-modelo.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-modelos.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-economia.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-arquivos.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-registro.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-armazem.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-rascunho.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-entrega.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-rest.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-voz.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-proposta.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-painel.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-retomada.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-links.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-desinstalar.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-css.php' );

$falhas    = array();
$grupo_ant = null;

foreach ( $casos as $caso ) {
	if ( $caso['grupo'] !== $grupo_ant ) {
		echo PHP_EOL . strtoupper( $caso['grupo'] ) . PHP_EOL;
		$grupo_ant = $caso['grupo'];
	}

	try {
		$problema = call_user_func( $caso['executar'] );
	} catch ( Throwable $e ) {
		// Exceção é falha, não é interrupção da suíte: o resto dos casos
		// continua valendo e o relatório sai inteiro.
		$problema = 'explodiu: ' . $e->getMessage();
	}

	if ( null === $problema ) {
		printf( "  ok    %s\n", $caso['nome'] );
		continue;
	}

	printf( "  FALHA %s\n        %s\n", $caso['nome'], $problema );
	$falhas[] = $caso['nome'];
}

$ms = round( ( microtime( true ) - $comeco ) * 1000 );

echo PHP_EOL;
if ( $falhas ) {
	printf( "%d de %d falharam (%d ms)\n", count( $falhas ), count( $casos ), $ms );
	exit( 1 );
}
printf( "todos os %d casos passaram (%d ms)\n", count( $casos ), $ms );
exit( 0 );
