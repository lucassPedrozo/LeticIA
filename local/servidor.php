<?php
/**
 * A tela da LetícIA no navegador, sem WordPress.
 *
 *   php -S localhost:8765 local/servidor.php
 *
 * e abra http://localhost:8765
 *
 * Serve a página com o shortcode, os arquivos de `leticia/public/` e as rotas
 * `/wp-json/leticia/v1/*` — respondidas pelas **mesmas** classes do plugin, as
 * que vão para o servidor. Não é um back-end falso como o do protótipo: é o
 * produto, com o `wp-falso.php` fingindo só o que as classes pedem ao
 * WordPress.
 *
 * Os e-mails que sairiam para a equipe não saem: vão para
 * `local/dados/emails/`, um arquivo por mensagem, para dar para conferir o que
 * a equipe receberia.
 */

if ( 'cli-server' !== php_sapi_name() ) {
	exit( "Este arquivo roda pelo servidor embutido do PHP:\n\n  php -S localhost:8765 local/servidor.php\n" );
}

$caminho = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$raiz    = dirname( __DIR__ );

// ------------------------------------------------------ arquivos estáticos

/**
 * Só CSS e JS de `leticia/public/` e `leticia/admin/` saem como arquivo. Nada
 * mais do projeto — nem a base, nem o `.env`, nem os dados locais — é servido
 * por este caminho.
 */
$servidas = array( '/leticia/public/', '/leticia/admin/' );
$pasta_servida = '';
foreach ( $servidas as $prefixo ) {
	if ( 0 === strpos( $caminho, $prefixo ) ) {
		$pasta_servida = rtrim( $prefixo, '/' );
	}
}

if ( '' !== $pasta_servida ) {
	$arquivo = realpath( $raiz . $caminho );
	$publico = realpath( $raiz . $pasta_servida );

	if ( ! $arquivo || 0 !== strpos( $arquivo, $publico ) || ! is_file( $arquivo ) ) {
		http_response_code( 404 );
		exit;
	}

	$tipos = array( 'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8' );
	$ext   = pathinfo( $arquivo, PATHINFO_EXTENSION );
	// Da pasta do admin só sai o CSS: o PHP de lá não é para ser lido.
	if ( ! isset( $tipos[ $ext ] ) ) {
		http_response_code( 404 );
		exit;
	}

	header( 'Content-Type: ' . ( isset( $tipos[ $ext ] ) ? $tipos[ $ext ] : 'application/octet-stream' ) );
	header( 'Cache-Control: no-store' );   // editou o CSS, recarregou, viu
	readfile( $arquivo );
	exit;
}

// O teste de fumaça e os arquivos minificados: só no servidor local.
if ( '/local/fumaca.js' === $caminho ) {
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	readfile( __DIR__ . '/fumaca.js' );
	exit;
}
if ( 0 === strpos( $caminho, '/local/min/' ) ) {
	$nome = basename( $caminho );
	$tipos = array( 'leticia.js' => 'application/javascript', 'leticia.css' => 'text/css' );
	$arquivo = __DIR__ . '/dados/min/' . $nome;
	if ( ! isset( $tipos[ $nome ] ) || ! is_file( $arquivo ) ) {
		http_response_code( 404 );
		exit;
	}
	header( 'Content-Type: ' . $tipos[ $nome ] . '; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	readfile( $arquivo );
	exit;
}

if ( '/favicon.ico' === $caminho ) {
	http_response_code( 204 );
	exit;
}

require_once __DIR__ . '/wp-falso.php';

// ------------------------------------------------------------------ rotas

if ( 0 === strpos( $caminho, '/wp-json/leticia/v1/' ) ) {
	$rota = substr( $caminho, strlen( '/wp-json/leticia/v1' ) );

	$mapa = array(
		'/sessao'           => 'abrir',
		'/responder'        => 'responder',
		'/pular'            => 'pular',
		'/voltar'           => 'voltar',
		'/arquivo/iniciar'  => 'arquivo_iniciar',
		'/arquivo/pedaco'   => 'arquivo_pedaco',
		'/arquivo/concluir' => 'arquivo_concluir',
		'/arquivo/remover'  => 'arquivo_remover',
		'/enviar'           => 'enviar',
		'/descartar'        => 'descartar',
		'/anexo/abrir'      => 'anexo_abrir',
		'/anexo/concluir'   => 'anexo_concluir',
		'/voz'              => 'ouvir',
		'/proposta'         => 'proposta',
		'/continuar'        => 'continuar',
		'/sugerir'          => 'sugerir',
		'/saude'            => 'saude',
	);

	header( 'Content-Type: application/json; charset=utf-8' );

	if ( ! isset( $mapa[ $rota ] ) ) {
		http_response_code( 404 );
		echo json_encode( array( 'code' => 'rest_no_route', 'message' => 'Rota desconhecida.' ) );
		exit;
	}

	$corpo  = file_get_contents( 'php://input' );
	$params = $_GET;

	// Como o WordPress faz: corpo JSON vira parâmetro; corpo cru (o pedaço do
	// upload) fica só como corpo.
	$tipo = isset( $_SERVER['CONTENT_TYPE'] ) ? $_SERVER['CONTENT_TYPE'] : '';
	if ( false !== strpos( $tipo, 'application/json' ) ) {
		$json = json_decode( $corpo, true );
		if ( is_array( $json ) ) {
			$params = array_merge( $params, $json );
		}
	}

	$resposta = call_user_func( array( 'Leticia_Rest', $mapa[ $rota ] ), new WP_REST_Request( $params, $corpo ) );

	if ( is_wp_error( $resposta ) ) {
		$dados = $resposta->get_error_data();
		http_response_code( is_array( $dados ) && isset( $dados['status'] ) ? (int) $dados['status'] : 400 );
		echo json_encode(
			array( 'code' => $resposta->get_error_code(), 'message' => $resposta->get_error_message() ),
			JSON_UNESCAPED_UNICODE
		);
	} else {
		echo json_encode( $resposta->get_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	leticia_guardar_emails();
	exit;
}

/**
 * Os e-mails que sairiam, em arquivo.
 *
 * Um por mensagem, com destinatário, assunto, cabeçalhos e corpo: é o que a
 * equipe receberia, e é mais fácil de conferir aqui do que num Gmail de teste.
 */
function leticia_guardar_emails() {
	$emails = leticia_emails_enviados();
	if ( ! $emails ) {
		return;
	}

	$pasta = LETICIA_DADOS . '/emails';
	if ( ! is_dir( $pasta ) ) {
		mkdir( $pasta, 0777, true );
	}

	foreach ( $emails as $i => $email ) {
		$cabeca = 'Para: ' . implode( ', ', (array) $email['para'] ) . "\n"
			. 'Assunto: ' . $email['assunto'] . "\n"
			. implode( "\n", $email['cabecalhos'] ) . "\n"
			. 'Anexos: ' . count( $email['anexos'] );

		// E-mail em HTML vira um .html que abre no navegador do jeito que a
		// equipe vê — com o envelope (para, assunto, cabeçalhos) em cima.
		if ( false !== stripos( implode( "\n", $email['cabecalhos'] ), 'text/html' ) ) {
			$envelope = '<pre style="margin:0;padding:12px 16px;background:#1d2327;color:#f0f0f1;font:12px/1.5 monospace;white-space:pre-wrap;">' . htmlspecialchars( $cabeca, ENT_QUOTES, 'UTF-8' ) . '</pre>';
			$corpo    = preg_replace( '/<body([^>]*)>/i', '<body$1>' . $envelope, $email['corpo'], 1 );
			file_put_contents( $pasta . '/' . gmdate( 'Ymd-His' ) . '-' . $i . '.html', $corpo );
			continue;
		}

		file_put_contents( $pasta . '/' . gmdate( 'Ymd-His' ) . '-' . $i . '.txt', $cabeca . "\n\n" . $email['corpo'] . "\n" );
	}
}

// ------------------------------------------------------------------ e-mails

/**
 * Os e-mails que teriam saído: /emails lista, /emails/<arquivo> abre.
 *
 * Os HTML abrem do jeito que a equipe vê no Gmail. Só nomes de arquivo com o
 * formato que o próprio servidor grava — nada de caminho vindo da URL.
 */
if ( '/emails' === $caminho || 0 === strpos( $caminho, '/emails/' ) ) {
	$pasta = LETICIA_DADOS . '/emails';
	$nome  = basename( substr( $caminho, strlen( '/emails/' ) ) );

	if ( '/emails' !== $caminho ) {
		if ( ! preg_match( '/^[\w\-]+\.(html|txt)$/', $nome ) || ! is_file( $pasta . '/' . $nome ) ) {
			http_response_code( 404 );
			exit;
		}
		header( 'Content-Type: ' . ( '.html' === substr( $nome, -5 ) ? 'text/html' : 'text/plain' ) . '; charset=utf-8' );
		readfile( $pasta . '/' . $nome );
		exit;
	}

	$arquivos = is_dir( $pasta ) ? array_reverse( glob( $pasta . '/*.{html,txt}', GLOB_BRACE ) ) : array();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>E-mails · local</title><body style="font:14px/1.6 system-ui,sans-serif;margin:24px;max-width:760px">';
	echo '<h1 style="font-size:20px">E-mails que teriam saído</h1><p style="color:#666">Localmente nada é enviado: cada e-mail vira um arquivo em <code>local/dados/emails/</code>. O mais novo primeiro.</p><ul>';
	foreach ( array_slice( $arquivos, 0, 100 ) as $arquivo ) {
		$base    = basename( $arquivo );
		$assunto = '';
		$inicio  = (string) file_get_contents( $arquivo, false, null, 0, 4000 );
		if ( preg_match( '/Assunto: ([^\n<]+)/', $inicio, $m ) ) {
			$assunto = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}
		printf( '<li><a href="/emails/%1$s">%2$s</a> <span style="color:#888">%1$s</span></li>', htmlspecialchars( $base ), htmlspecialchars( '' !== $assunto ? $assunto : $base ) );
	}
	echo $arquivos ? '</ul>' : '</ul><p>Nenhum ainda.</p>';
	exit;
}

// ------------------------------------------------------------------ links

/**
 * A página do shortcode [leticia_links]: /links. Localmente, quem abre é a
 * equipe, logada e com permissão; o link gerado aponta para esta mesma
 * origem, onde está o [leticia].
 */
if ( '/links' === $caminho ) {
	$conteudo = Leticia_Links::render( array( 'pagina' => leticia_origem() . '/' ) );
	?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Links de briefing · local</title>
<link rel="stylesheet" href="/leticia/public/leticia-links.css">
<style>body { margin: 0; padding: 24px 16px; background: #F6F7F9; }</style>
</head>
<body><?php echo $conteudo; // phpcs:ignore ?></body>
</html>
	<?php
	exit;
}

// ------------------------------------------------------------------ painel

/**
 * O painel de Configurações → LetícIA, sem WordPress.
 *
 *   /wp-admin/options-general.php?page=leticia        briefings e configuração
 *   /wp-admin/options.php                             salvar a configuração
 *   /wp-admin/admin-post.php                          testar chave, reenviar
 *   /wp-admin/admin.php?page=leticia&acao=baixar      download de anexo
 *
 * As mesmas classes de `leticia/admin/`. Localmente não há login: quem abre o
 * endereço é tratado como administrador.
 */
if ( 0 === strpos( $caminho, '/wp-admin/' ) ) {
	switch ( $caminho ) {
		case '/wp-admin/options.php':
			if ( isset( $_POST['leticia_config'] ) && is_array( $_POST['leticia_config'] ) ) {
				Leticia_Config::salvar( wp_unslash( $_POST['leticia_config'] ) );
				set_transient( Leticia_Admin::AVISO, array( 'nivel' => 'bom', 'texto' => 'Configuração salva.' ), 60 );
			}
			wp_safe_redirect( Leticia_Admin::url( array( 'aba' => 'config' ) ) );
			exit;

		case '/wp-admin/admin-post.php':
			$acoes = array(
				'leticia_testar'   => array( 'Leticia_Admin', 'testar' ),
				'leticia_reenviar' => array( 'Leticia_Admin', 'reenviar' ),
			);
			$acao = isset( $_POST['action'] ) ? (string) $_POST['action'] : '';
			if ( isset( $acoes[ $acao ] ) ) {
				// O handler redireciona e sai sozinho; o e-mail do reenvio é
				// guardado na saída.
				register_shutdown_function( 'leticia_guardar_emails' );
				call_user_func( $acoes[ $acao ] );
			}
			wp_safe_redirect( Leticia_Admin::url() );
			exit;

		case '/wp-admin/admin.php':
			Leticia_Download::talvez_entregar();
			http_response_code( 404 );
			exit( 'Arquivo não encontrado.' );

		case '/wp-admin/options-general.php':
			leticia_pagina_do_admin();
			exit;
	}

	http_response_code( 404 );
	exit;
}

function leticia_pagina_do_admin() {
	?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LetícIA · painel local</title>
<link rel="stylesheet" href="/leticia/admin/leticia-admin.css">
<style>
	/* O mínimo do visual do wp-admin, só para o painel local não parecer
	   quebrado. Em produção quem desenha isto é o próprio WordPress. */
	body { margin: 0; background: #f0f0f1; color: #1d2327; font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
	.barra-local { background: #1d2327; color: #f0f0f1; padding: 8px 20px; font-size: 13px; }
	.barra-local a { color: #72aee6; }
	.wrap { margin: 10px 20px 40px; }
	h1 { font-size: 23px; font-weight: 400; margin: 16px 0 8px; }
	h2 { font-size: 1.3em; margin: 1em 0; }
	h3 { font-size: 1.1em; margin: 1.5em 0 .5em; }
	a { color: #2271b1; }
	code { background: rgba(0,0,0,.07); padding: 1px 4px; font-size: 12px; }
	.description { color: #646970; }
	.nav-tab-wrapper { border-bottom: 1px solid #c3c4c7; }
	.nav-tab { display: inline-block; padding: 5px 12px; margin: 0 4px -1px 0; border: 1px solid #c3c4c7; background: #dcdcde; color: #50575e; text-decoration: none; font-size: 14px; font-weight: 600; }
	.nav-tab-active { background: #f0f0f1; border-bottom-color: #f0f0f1; color: #000; }
	.widefat { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #c3c4c7; }
	.widefat th, .widefat td { padding: 8px 10px; text-align: left; }
	.widefat thead th { border-bottom: 1px solid #c3c4c7; font-weight: 400; }
	.striped > tbody > tr:nth-child(odd) { background: #f6f7f7; }
	.form-table { border-collapse: collapse; width: 100%; }
	.form-table th { width: 200px; padding: 20px 10px 20px 0; text-align: left; vertical-align: top; font-weight: 600; }
	.form-table td { padding: 15px 10px; }
	.regular-text { width: 25em; } .large-text { width: 99%; } .small-text { width: 5em; }
	input[type=text], input[type=password], input[type=number], input[type=url], textarea { border: 1px solid #8c8f94; border-radius: 4px; padding: 4px 8px; font: inherit; box-sizing: border-box; max-width: 100%; }
	.button { display: inline-block; border: 1px solid #2271b1; color: #2271b1; background: #f6f7f7; border-radius: 3px; padding: 4px 10px; cursor: pointer; font: inherit; }
	.button-primary { background: #2271b1; color: #fff; }
	.button-small { padding: 0 8px; font-size: 12px; }
	.subsubsub { list-style: none; padding: 0; color: #646970; } .subsubsub li { display: inline; } .subsubsub a.current { color: #000; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
<div class="barra-local">Painel local — sem WordPress, sem login. <a href="/">Abrir o briefing</a></div>
<?php Leticia_Admin::render(); ?>
</body>
</html>
<?php
}

// ------------------------------------------------------------------ página

$config = array(
	'rotas'    => '/wp-json/leticia/v1',
	'nonce'    => '',
	'modo'     => 'pagina',
	'nome'     => Leticia_Config::nome(),
	'pagina'   => 'http://' . $_SERVER['HTTP_HOST'] . '/',
	'classico' => Leticia_Config::formulario_classico(),
	'pedaco'   => Leticia_Arquivos::PEDACO,
);

$modo = isset( $_GET['modo'] ) && 'bloco' === $_GET['modo'] ? 'bloco' : 'pagina';

// ?tema=hostil (e o modo bloco) carregam as regras de botão e campo do tema
// Hello Elementor, copiadas como são. Foram elas que deixaram os hovers
// vermelhos e brancos: se um botão da LetícIA mudar de cor no hover aqui, o
// território do CSS vazou de novo.
$hostil = 'bloco' === $modo || ( isset( $_GET['tema'] ) && 'hostil' === $_GET['tema'] );

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>LetícIA · local</title>
<?php
// ?min=1: os arquivos como vão no .zip. Gerados agora, pelo mesmo minificar.py
// que o empacotar.py usa.
$minificado = isset( $_GET['min'] );
if ( $minificado ) {
	shell_exec( 'python ' . escapeshellarg( dirname( __DIR__ ) . '/minificar.py' ) . ' --saida ' . escapeshellarg( __DIR__ . '/dados/min' ) );
}
$assets = $minificado ? '/local/min/' : '/leticia/public/';
?>
<link rel="stylesheet" href="<?php echo $assets; ?>leticia.css">
<style>
	/* Só aqui, fora do plugin: a página hospedeira sem margem, como um
	   template em branco do Elementor. */
	body { margin: 0; background: #FBFBFC; }
	<?php if ( $hostil ) : ?>
	/* Hello Elementor, reset.css */
	[type=button],[type=submit],button{display:inline-block;font-weight:400;color:#c36;text-align:center;white-space:nowrap;user-select:none;background-color:transparent;border:1px solid #c36;padding:.5rem 1rem;font-size:1rem;border-radius:3px;transition:all .3s}
	[type=button]:focus:not(:focus-visible),[type=submit]:focus:not(:focus-visible),button:focus:not(:focus-visible){outline:none}
	[type=button]:focus,[type=button]:hover,[type=submit]:focus,[type=submit]:hover,button:focus,button:hover{color:#fff;background-color:#c36;text-decoration:none}
	input[type=text],input[type=email],textarea{width:100%;border:1px solid #666;border-radius:3px;padding:.5rem 1rem;transition:all .3s}
	input[type=text]:focus,textarea:focus{border-color:#333}
	a{background-color:transparent;text-decoration:none;color:#c36}
	a:active,a:hover{color:#336}
	<?php endif; ?>
	<?php if ( 'bloco' === $modo ) : ?>
	/* Simula uma coluna de tema com texto em volta, para ver o modo bloco. */
	.tema { max-width: 760px; margin: 40px auto; padding: 0 20px; font-family: Georgia, serif; }
	.tema button { padding: 20px 36px; border-radius: 30px; background: #1d4ed8; color: #fff; }
	<?php endif; ?>
	.leticia-raiz { --lt-acento: <?php echo esc_html( Leticia_Config::cor() ); ?>; }
</style>
</head>
<body>
<?php if ( 'bloco' === $modo ) : ?>
<div class="tema">
	<h1>Página do tema</h1>
	<p>Um parágrafo de exemplo, com as regras agressivas que um kit de tema costuma ter —
	inclusive <code>.tema button</code> com padding de 20px 36px. Se a LetícIA abaixo
	aparecer com botões inchados, o território vazou.</p>
<?php endif; ?>
<?php

// O mesmo HTML do shortcode, sem a parte que enfileira arquivo — aqui quem
// carrega CSS e JS é o <head> desta página.
$html = Leticia_Tela::render( array( 'modo' => $modo ) );
echo $html; // phpcs:ignore

?>
<?php if ( 'bloco' === $modo ) : ?>
	<p>Texto do tema depois do briefing.</p>
</div>
<?php endif; ?>
<script>window.LETICIA = <?php echo json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>;</script>
<script src="<?php echo $assets; ?>leticia.js"></script>
<?php if ( isset( $_GET['fumaca'] ) ) : ?>
<script src="/local/fumaca.js"></script>
<?php endif; ?>
</body>
</html>
