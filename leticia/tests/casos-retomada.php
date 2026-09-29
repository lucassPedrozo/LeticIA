<?php
/**
 * "Continuar depois": o link que leva o rascunho para outro aparelho, o
 * lembrete por e-mail e o botão da equipe no painel.
 *
 * O que se protege aqui é a chave: ela só reabre o próprio briefing, não
 * serve para o que o link de anexo serve (nem o contrário), não reabre
 * briefing enviado, e o e-mail nunca sai para um endereço que veio de fora.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_emails();
	update_option(
		Leticia_Config::OPCAO,
		array(
			'GEMINI_API_KEY' => '',
			'REMETENTE'      => 'formulario@example.com',
			'DESTINO'        => 'briefing@example.com',
		)
	);
	return $armazem;
};

$pedir = function ( array $params = array() ) {
	return new WP_REST_Request( $params );
};

$dados = function ( $r ) {
	return $r instanceof WP_REST_Response ? $r->get_data() : ( is_wp_error( $r ) ? array( 'erro_wp' => $r->get_error_message() ) : $r );
};

/** Um briefing parado no endereço, com ou sem e-mail. */
$parado = function ( $com_email = true ) use ( $pedir, $dados ) {
	$t = $dados( Leticia_Rest::abrir( $pedir() ) );
	$respostas = array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'email' => $com_email ? 'marina@padariaaurora.com.br' : 'não tenho' );
	foreach ( $respostas as $campo => $texto ) {
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $campo, 'texto' => $texto, 'pagina' => 'https://joinvix.com.br/briefing/' ) ) ) );
	}
	return $t;
};

/** Faz o briefing parecer parado há N segundos. */
$envelhecer = function ( $armazem, $sessao, $segundos ) {
	$linha                          = $armazem->ler_briefing( $sessao );
	$linha['roteiro']['atividade'] = time() - $segundos;
	$armazem->gravar_briefing( $sessao, array( 'roteiro' => $linha['roteiro'] ) );
};

// ------------------------------------------------------------- a chave

$casos[] = array(
	'grupo'    => 'retomada · link',
	'nome'     => 'o link reabre o próprio briefing, em outro aparelho, e sai com token novo',
	'executar' => function () use ( $zerar, $parado, $pedir, $dados ) {
		$zerar();
		$t      = $parado();
		$sessao = Leticia_Rascunho::conferir( $t['token'] );

		$c = $dados( Leticia_Rest::continuar( $pedir( array( 'token' => $t['token'], 'pagina' => 'https://joinvix.com.br/briefing/' ) ) ) );
		if ( 0 !== strpos( $c['link'], 'https://joinvix.com.br/briefing/?leticia_retomar=' ) ) {
			return 'o link saiu assim: ' . $c['link'];
		}
		if ( '5547999998888' !== $c['whatsapp'] || 'm•••@padariaaurora.com.br' !== $c['email'] ) {
			return 'os contatos vieram errados: ' . wp_json_encode( array( $c['whatsapp'], $c['email'] ) );
		}

		// Outro aparelho: sem token nenhum, só o link.
		parse_str( (string) parse_url( $c['link'], PHP_URL_QUERY ), $q );
		$volta = $dados( Leticia_Rest::abrir( $pedir( array( 'retomar' => $q['leticia_retomar'] ) ) ) );
		if ( Leticia_Rascunho::conferir( $volta['token'] ) !== $sessao ) {
			return 'o link abriu outro briefing';
		}
		if ( 'dominio' !== $volta['campo']['chave'] || empty( $volta['retomada'] ) || '' !== $volta['aviso_link'] ) {
			return 'não retomou de onde parou: ' . wp_json_encode( array( $volta['campo']['chave'], $volta['aviso_link'] ) );
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'retomada · link',
	'nome'     => 'o link vale mais que o token deste aparelho',
	'executar' => function () use ( $zerar, $parado, $pedir, $dados ) {
		$zerar();
		$meu   = $parado();
		$outro = $dados( Leticia_Rest::abrir( $pedir() ) );   // este aparelho tinha um vazio
		$link  = Leticia_Retomada::assinar( Leticia_Rascunho::conferir( $meu['token'] ) );

		$volta = $dados( Leticia_Rest::abrir( $pedir( array( 'token' => $outro['token'], 'retomar' => $link ) ) ) );
		return Leticia_Rascunho::conferir( $volta['token'] ) === Leticia_Rascunho::conferir( $meu['token'] ) ? null : 'ficou com o briefing do aparelho';
	},
);

$casos[] = array(
	'grupo'    => 'retomada · link',
	'nome'     => 'chave de anexo não abre retomada, nem o contrário; forjada e vencida também não',
	'executar' => function () use ( $zerar, $parado ) {
		$zerar();
		$sessao = Leticia_Rascunho::conferir( $parado()['token'] );

		if ( ! is_wp_error( Leticia_Retomada::conferir( Leticia_Anexo::assinar( $sessao ) ) ) ) {
			return 'o link de anexo abriu a retomada';
		}
		if ( ! is_wp_error( Leticia_Anexo::conferir( Leticia_Retomada::assinar( $sessao ) ) ) ) {
			return 'o link de retomada abriu o anexo';
		}
		if ( ! is_wp_error( Leticia_Retomada::conferir( Leticia_Rascunho::assinar( $sessao ) ) ) ) {
			return 'o token do navegador serviu de link';
		}
		$vencido = Leticia_Retomada::conferir( Leticia_Retomada::assinar( $sessao, time() - 10 ) );
		if ( ! is_wp_error( $vencido ) || 'retomada_expirada' !== $vencido->get_error_code() ) {
			return 'o link vencido abriu';
		}
		return is_wp_error( Leticia_Retomada::conferir( 'lixo' ) ) ? null : 'aceitou lixo';
	},
);

$casos[] = array(
	'grupo'    => 'retomada · link',
	'nome'     => 'briefing enviado não reabre pelo link: vira aviso e briefing novo',
	'executar' => function () use ( $zerar, $parado, $pedir, $dados ) {
		$armazem = $zerar();
		$sessao  = Leticia_Rascunho::conferir( $parado()['token'] );
		$link    = Leticia_Retomada::assinar( $sessao );
		$armazem->gravar_briefing( $sessao, array( 'enviado_em' => time() ) );

		$volta = $dados( Leticia_Rest::abrir( $pedir( array( 'retomar' => $link ) ) ) );
		if ( Leticia_Rascunho::conferir( $volta['token'] ) === $sessao ) {
			return 'reabriu o briefing enviado';
		}
		return false !== strpos( $volta['aviso_link'], 'já foi enviado' ) ? null : 'sem aviso: ' . $volta['aviso_link'];
	},
);

$casos[] = array(
	'grupo'    => 'retomada · link',
	'nome'     => 'sem nenhuma resposta não há o que guardar',
	'executar' => function () use ( $zerar, $pedir, $dados ) {
		$zerar();
		$t = $dados( Leticia_Rest::abrir( $pedir() ) );
		$r = Leticia_Rest::continuar( $pedir( array( 'token' => $t['token'] ) ) );
		return is_wp_error( $r ) || ( $r instanceof WP_REST_Response && $r->get_status() >= 400 ) ? null : 'gerou link de briefing vazio';
	},
);

$casos[] = array(
	'grupo'    => 'retomada · link',
	'nome'     => 'o link da página tira os parâmetros velhos dos dois links',
	'executar' => function () {
		$sessao = str_repeat( 'a', 32 );
		$link   = Leticia_Retomada::link( $sessao, 'https://joinvix.com.br/briefing/?leticia_retomar=velho&utm=x&leticia_anexo=outro' );
		if ( false !== strpos( $link, 'velho' ) || false !== strpos( $link, 'outro' ) ) {
			return 'ficou parâmetro velho: ' . $link;
		}
		return false !== strpos( $link, 'utm=x' ) ? null : 'levou junto o que não era dele: ' . $link;
	},
);

// ------------------------------------------------------------- e-mail

$casos[] = array(
	'grupo'    => 'retomada · e-mail',
	'nome'     => 'o link vai para o e-mail do briefing — nunca para um que veio na requisição',
	'executar' => function () use ( $zerar, $parado, $pedir, $dados ) {
		$zerar();
		$t = $parado();
		leticia_zerar_emails();

		$r = $dados( Leticia_Rest::continuar( $pedir( array( 'token' => $t['token'], 'enviar' => 'email', 'email' => 'alguem@de-fora.com' ) ) ) );
		$e = leticia_emails_enviados();
		if ( ! $r['enviado'] || 1 !== count( $e ) ) {
			return 'não mandou: ' . wp_json_encode( $r );
		}
		if ( 'marina@padariaaurora.com.br' !== $e[0]['para'] ) {
			return 'mandou para ' . $e[0]['para'];
		}
		return false !== strpos( $e[0]['corpo'], 'leticia_retomar=' ) ? null : 'o e-mail saiu sem o link';
	},
);

$casos[] = array(
	'grupo'    => 'retomada · e-mail',
	'nome'     => 'sem e-mail no briefing não manda; e pedir sem parar esbarra no limite',
	'executar' => function () use ( $zerar, $parado, $pedir, $dados ) {
		$zerar();
		$sem = $parado( false );
		leticia_zerar_emails();
		$r = $dados( Leticia_Rest::continuar( $pedir( array( 'token' => $sem['token'], 'enviar' => 'email' ) ) ) );
		if ( $r['enviado'] || '' !== $r['email'] || leticia_emails_enviados() ) {
			return 'mandou para quem não deu e-mail';
		}

		$zerar();
		$com = $parado();
		leticia_zerar_emails();
		for ( $i = 0; $i < Leticia_Retomada::EMAILS_POR_HORA + 2; $i++ ) {
			Leticia_Limites::zerar();   // o ritmo das rotas não é o que está em teste
			$r = $dados( Leticia_Rest::continuar( $pedir( array( 'token' => $com['token'], 'enviar' => 'email' ) ) ) );
		}
		if ( Leticia_Retomada::EMAILS_POR_HORA !== count( leticia_emails_enviados() ) ) {
			return 'saíram ' . count( leticia_emails_enviados() ) . ' e-mails';
		}
		return '' !== $r['erro'] ? null : 'passou do limite sem dizer nada';
	},
);

// ------------------------------------------------------------- lembrete

$casos[] = array(
	'grupo'    => 'retomada · lembrete',
	'nome'     => 'o lembrete sai uma vez por parada, entre um dia e uma semana',
	'executar' => function () use ( $zerar, $parado, $envelhecer, $pedir, $dados ) {
		$armazem = $zerar();
		$t       = $parado();
		$sessao  = Leticia_Rascunho::conferir( $t['token'] );

		leticia_zerar_emails();
		$envelhecer( $armazem, $sessao, 3600 );   // parado há uma hora: cedo
		if ( 0 !== Leticia_Retomada::lembrar() ) {
			return 'lembrou quem parou há uma hora';
		}

		$envelhecer( $armazem, $sessao, 2 * DAY_IN_SECONDS );
		if ( 1 !== Leticia_Retomada::lembrar() ) {
			return 'não lembrou quem parou há dois dias';
		}
		$e = leticia_emails_enviados();
		if ( 'marina@padariaaurora.com.br' !== $e[0]['para'] || false === strpos( $e[0]['corpo'], 'leticia_retomar=' ) || false === strpos( $e[0]['corpo'], 'Marina' ) ) {
			return 'o lembrete saiu errado';
		}
		if ( 0 !== Leticia_Retomada::lembrar() ) {
			return 'lembrou duas vezes a mesma parada';
		}

		// Voltou, respondeu e parou de novo: cabe outro.
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'dominio', 'texto' => 'padariaaurora.com.br' ) ) ) );
		$envelhecer( $armazem, $sessao, 2 * DAY_IN_SECONDS );
		if ( 1 !== Leticia_Retomada::lembrar() ) {
			return 'não lembrou a segunda parada';
		}

		// Parado há mais de uma semana: quem chama é a equipe.
		$zerar();
		$velho = Leticia_Rascunho::conferir( $parado()['token'] );
		$envelhecer( Leticia_Registro::armazem(), $velho, 9 * DAY_IN_SECONDS );
		return 0 === Leticia_Retomada::lembrar() ? null : 'lembrou quem parou há nove dias';
	},
);

$casos[] = array(
	'grupo'    => 'retomada · lembrete',
	'nome'     => 'sem e-mail, enviado ou com o lembrete desligado, não sai nada',
	'executar' => function () use ( $zerar, $parado, $envelhecer ) {
		$armazem = $zerar();
		$sem     = Leticia_Rascunho::conferir( $parado( false )['token'] );
		$enviado = Leticia_Rascunho::conferir( $parado()['token'] );
		$armazem->gravar_briefing( $enviado, array( 'enviado_em' => time() ) );
		foreach ( array( $sem, $enviado ) as $s ) {
			$envelhecer( $armazem, $s, 2 * DAY_IN_SECONDS );
		}
		leticia_zerar_emails();
		if ( 0 !== Leticia_Retomada::lembrar() ) {
			return 'lembrou quem não devia';
		}

		$ligado = Leticia_Rascunho::conferir( $parado()['token'] );
		$envelhecer( $armazem, $ligado, 2 * DAY_IN_SECONDS );
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'LEMBRETE' => '0' ) ) );
		$saiu = Leticia_Retomada::lembrar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'LEMBRETE' => '1' ) ) );
		return 0 === $saiu ? null : 'lembrou com o lembrete desligado';
	},
);

$casos[] = array(
	'grupo'    => 'retomada · lembrete',
	'nome'     => 'marcar o lembrete não conta como a pessoa ter mexido',
	'executar' => function () use ( $zerar, $parado, $envelhecer ) {
		$armazem = $zerar();
		$sessao  = Leticia_Rascunho::conferir( $parado()['token'] );
		$envelhecer( $armazem, $sessao, 2 * DAY_IN_SECONDS );
		Leticia_Retomada::lembrar();
		$quando = Leticia_Registro::ultima_atividade( $armazem->ler_briefing( $sessao ) );
		return time() - $quando > DAY_IN_SECONDS ? null : 'o lembrete virou atividade';
	},
);

// ------------------------------------------------------------- painel

$casos[] = array(
	'grupo'    => 'retomada · painel',
	'nome'     => 'o painel chama no WhatsApp quem parou, com nome e link na mensagem',
	'executar' => function () use ( $zerar, $parado ) {
		$armazem = $zerar();
		$sessao  = Leticia_Rascunho::conferir( $parado()['token'] );
		$linha   = $armazem->ler_briefing( $sessao );

		$url = Leticia_Retomada::link_whatsapp_equipe( $linha );
		if ( 0 !== strpos( $url, 'https://wa.me/5547999998888?text=' ) ) {
			return 'o link do WhatsApp saiu assim: ' . $url;
		}
		$texto = rawurldecode( substr( $url, strpos( $url, '=' ) + 1 ) );
		if ( false === strpos( $texto, 'Oi, Marina!' ) || false === strpos( $texto, 'leticia_retomar=' ) ) {
			return 'a mensagem saiu assim: ' . $texto;
		}

		if ( false === strpos( Leticia_Painel::chamar( $linha ), 'Chamar no WhatsApp' ) ) {
			return 'o botão não apareceu';
		}
		$armazem->gravar_briefing( $sessao, array( 'enviado_em' => time() ) );
		return '' === Leticia_Painel::chamar( $armazem->ler_briefing( $sessao ) ) ? null : 'ofereceu chamar quem já enviou';
	},
);

return $casos;
