<?php
/**
 * Os links com dados preenchidos ([leticia_links]) e a medição de uso.
 *
 * Protegido aqui: só a equipe gera link; os dados passam pela mesma validação
 * do cliente; o link não leva dado nenhum na URL; o cliente que abre vê a
 * apresentação, não a barra de retomada, e começa no primeiro campo que só
 * ele sabe; link ainda fechado não conta como desistência nem ganha lembrete.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_emails();
	update_option( Leticia_Config::OPCAO, array( 'GEMINI_API_KEY' => '', 'REMETENTE' => 'formulario@example.com', 'DESTINO' => 'briefing@example.com' ) );
	$GLOBALS['leticia_logado'] = false;
	$GLOBALS['leticia_pode']   = array();
	$_POST                     = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $armazem;
};

$pedir = function ( array $params = array() ) {
	return new WP_REST_Request( $params );
};

$dados = function ( $r ) {
	return $r instanceof WP_REST_Response ? $r->get_data() : $r;
};

$marina = array(
	'responsavel' => 'marina alves',
	'empresa'     => 'Padaria Aurora',
	'whatsapp'    => '47 99999 8888',
	'email'       => 'marina@padariaaurora.com.br',
	'dominio'     => '',
);

// ------------------------------------------------------------- criar

$casos[] = array(
	'grupo'    => 'links · criar',
	'nome'     => 'o link cria o briefing preenchido, com os dados validados — e nenhum dado na URL',
	'executar' => function () use ( $zerar, $marina ) {
		$zerar();
		$r = Leticia_Links::criar( $marina, 'https://joinvix.com.br/briefing/', 'Lucas' );
		if ( is_wp_error( $r ) ) {
			return 'recusou dados bons: ' . $r->get_error_message();
		}
		foreach ( array( 'Marina', 'Aurora', '9999', 'marina@' ) as $vazamento ) {
			if ( false !== stripos( $r['link'], $vazamento ) ) {
				return 'a URL leva dado do cliente: ' . $r['link'];
			}
		}
		$linha = Leticia_Registro::briefing( $r['sessao'] );
		if ( '(47) 99999-8888' !== $linha['respostas']['whatsapp']['valor'] || 'Marina Alves' !== $linha['respostas']['responsavel']['valor'] ) {
			return 'os dados não passaram pela normalização: ' . wp_json_encode( $linha['respostas'] );
		}
		if ( array( 'responsavel', 'empresa', 'whatsapp', 'email' ) !== $linha['roteiro']['preenchidos'] || 'Lucas' !== $linha['roteiro']['criado_por'] ) {
			return 'não anotou o que a equipe preencheu';
		}
		return Leticia_Links::nao_aberto( $linha ) ? null : 'o link nasceu como aberto';
	},
);

$casos[] = array(
	'grupo'    => 'links · criar',
	'nome'     => 'dado torto volta para quem vendeu, não para a tela do cliente',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$r = Leticia_Links::criar( array( 'empresa' => 'Padaria Aurora', 'whatsapp' => '1234', 'email' => 'marina@' ), 'https://joinvix.com.br/briefing/' );
		if ( ! is_wp_error( $r ) ) {
			return 'aceitou WhatsApp e e-mail inválidos';
		}
		$campos = $r->get_error_data()['campos'];
		if ( ! isset( $campos['whatsapp'], $campos['email'] ) || isset( $campos['empresa'] ) ) {
			return 'os erros vieram assim: ' . wp_json_encode( $campos );
		}
		if ( Leticia_Registro::armazem()->listar_briefings() ) {
			return 'gravou briefing mesmo com erro';
		}
		$vazio = Leticia_Links::criar( array(), 'https://joinvix.com.br/briefing/' );
		return is_wp_error( $vazio ) ? null : 'gerou link sem dado nenhum';
	},
);

// ------------------------------------------------------------- o cliente abre

$casos[] = array(
	'grupo'    => 'links · o cliente abre',
	'nome'     => 'abre na apresentação própria, sem barra de retomada, e começa no ramo',
	'executar' => function () use ( $zerar, $marina, $pedir, $dados ) {
		$zerar();
		$marina['dominio'] = 'padariaaurora.com.br';
		$r = Leticia_Links::criar( $marina, 'https://joinvix.com.br/briefing/' );
		parse_str( (string) parse_url( $r['link'], PHP_URL_QUERY ), $q );

		$t = $dados( Leticia_Rest::abrir( $pedir( array( 'retomar' => $q['leticia_retomar'] ) ) ) );
		if ( 'ramo' !== $t['campo']['chave'] ) {
			return 'começou em ' . $t['campo']['chave'];
		}
		if ( ! empty( $t['retomada'] ) ) {
			return 'mostrou a barra de retomada para quem nunca abriu';
		}
		if ( empty( $t['apresentacao'] ) || false === strpos( $t['apresentacao']['detalhe'], 'minutos' ) || false === stripos( $t['apresentacao']['titulo'] . $t['apresentacao']['detalhe'], 'dados' ) ) {
			return 'a apresentação não é a do link: ' . wp_json_encode( $t['apresentacao'] );
		}
		$linha = Leticia_Registro::briefing( $r['sessao'] );
		if ( Leticia_Links::nao_aberto( $linha ) || empty( $linha['roteiro']['atividade'] ) ) {
			return 'abrir não foi anotado';
		}

		// Respondeu o ramo: daqui em diante é um briefing como outro qualquer.
		$depois = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'ramo', 'texto' => 'padaria artesanal de bairro' ) ) ) );
		$volta  = $dados( Leticia_Rest::abrir( $pedir( array( 'token' => $depois['token'] ) ) ) );
		return empty( $volta['apresentacao'] ) && ! empty( $volta['retomada'] ) ? null : 'depois de responder, ainda tratou como primeira vez';
	},
);

$casos[] = array(
	'grupo'    => 'links · o cliente abre',
	'nome'     => 'link fechado não conta como desistência nem ganha lembrete',
	'executar' => function () use ( $zerar, $marina ) {
		$armazem = $zerar();
		$r       = Leticia_Links::criar( $marina, 'https://joinvix.com.br/briefing/' );
		$linha   = $armazem->ler_briefing( $r['sessao'] );
		// Gerado há dois dias e parado desde então: é exatamente a janela do
		// lembrete — só a regra do link fechado segura o e-mail.
		$linha['roteiro']['link_em']   = time() - 2 * DAY_IN_SECONDS;
		$linha['roteiro']['atividade'] = time() - 2 * DAY_IN_SECONDS;
		$armazem->gravar_briefing( $r['sessao'], array( 'roteiro' => $linha['roteiro'] ) );

		if ( Leticia_Registro::abandono_por_campo() ) {
			return 'contou o link fechado como parada';
		}
		if ( Leticia_Registro::resumo()['abandonados'] ) {
			return 'o cartão de parados contou o link fechado';
		}
		if ( 0 !== Leticia_Retomada::lembrar() ) {
			return 'mandou lembrete para quem nem abriu';
		}
		$situacao = Leticia_Painel::situacao( $armazem->ler_briefing( $r['sessao'] ) );
		return false !== strpos( $situacao, 'ainda não aberto' ) ? null : 'o painel disse: ' . wp_strip_all_tags( $situacao );
	},
);

$casos[] = array(
	'grupo'    => 'links · o cliente abre',
	'nome'     => 'a mensagem do WhatsApp convida — não diz que a pessoa parou no meio',
	'executar' => function () use ( $zerar, $marina ) {
		$armazem = $zerar();
		$r       = Leticia_Links::criar( $marina, 'https://joinvix.com.br/briefing/' );
		$url     = Leticia_Painel::chamar( $armazem->ler_briefing( $r['sessao'] ) );
		$texto   = rawurldecode( $url );
		if ( false === strpos( $texto, 'wa.me/5547999998888' ) || false === strpos( $texto, 'Oi, Marina!' ) ) {
			return 'o botão saiu assim: ' . $texto;
		}
		if ( false !== strpos( $texto, 'começou o briefing' ) || false === strpos( $texto, 'deixei o briefing pronto' ) ) {
			return 'a mensagem é a de quem parou: ' . $texto;
		}
		return false !== strpos( $texto, 'leticia_retomar=' ) ? null : 'a mensagem saiu sem o link';
	},
);

// ------------------------------------------------------------- a página

$casos[] = array(
	'grupo'    => 'links · página',
	'nome'     => 'só a equipe logada, com permissão e nonce, gera link',
	'executar' => function () use ( $zerar, $marina ) {
		$zerar();
		if ( false === strpos( Leticia_Links::render(), 'Entre com a sua conta' ) ) {
			return 'quem não está logado viu o formulário';
		}
		$GLOBALS['leticia_logado'] = true;
		if ( false === strpos( Leticia_Links::render(), 'não tem permissão' ) ) {
			return 'logado sem permissão viu o formulário';
		}
		$GLOBALS['leticia_pode']['edit_posts'] = true;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'leticia_links' => $marina, '_leticia_links' => 'forjado' );
		$html                      = Leticia_Links::render( array( 'pagina' => 'https://joinvix.com.br/briefing/' ) );
		if ( false === strpos( $html, 'Recarregue' ) || Leticia_Registro::armazem()->listar_briefings() ) {
			return 'aceitou envio sem nonce válido';
		}

		$_POST = array( 'leticia_links' => $marina, '_leticia_links' => 'nonce-' . Leticia_Links::NONCE );
		$html  = Leticia_Links::render( array( 'pagina' => 'https://joinvix.com.br/briefing/' ) );
		$zerar_post = function () { $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET'; };
		$zerar_post();
		if ( false === strpos( $html, 'Link pronto' ) || false === strpos( $html, 'https://joinvix.com.br/briefing/?leticia_retomar=' ) ) {
			return 'o link não apareceu na página';
		}
		if ( false === strpos( $html, 'wa.me/5547999998888' ) || false === strpos( $html, 'Ainda não abriu' ) ) {
			return 'faltou o WhatsApp ou a lista de recentes';
		}
		return false === strpos( $html, 'value="Padaria Aurora"' ) ? null : 'o formulário não voltou limpo depois de gerar';
	},
);

// ------------------------------------------------------------- medição

$casos[] = array(
	'grupo'    => 'medição',
	'nome'     => 'tempo por campo, aparelho e usos entram nas métricas',
	'executar' => function () use ( $zerar, $pedir, $dados ) {
		$armazem = $zerar();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';
		$t = $dados( Leticia_Rest::abrir( $pedir() ) );
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'responsavel', 'texto' => 'Marina Alves' ) ) ) );
		$sessao = Leticia_Rascunho::conferir( $t['token'] );

		// Quarenta segundos na pergunta da empresa.
		$linha = $armazem->ler_briefing( $sessao );
		$linha['roteiro']['atividade'] = time() - 40;
		$armazem->gravar_briefing( $sessao, array( 'roteiro' => $linha['roteiro'] ) );
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'empresa', 'texto' => 'Padaria Aurora' ) ) ) );

		// Uma hora parado no WhatsApp: pausa, não conta.
		$linha = $armazem->ler_briefing( $sessao );
		$linha['roteiro']['atividade'] = time() - 3600;
		$armazem->gravar_briefing( $sessao, array( 'roteiro' => $linha['roteiro'] ) );
		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'whatsapp', 'texto' => '47999998888' ) ) ) );

		Leticia_Rest::continuar( $pedir( array( 'token' => $t['token'] ) ) );
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$m = Leticia_Registro::metricas();
		if ( 1 !== $m['tempos']['empresa']['amostras'] || abs( $m['tempos']['empresa']['mediana'] - 40 ) > 2 ) {
			return 'o tempo da empresa saiu ' . wp_json_encode( $m['tempos']['empresa'] );
		}
		if ( 0 !== $m['tempos']['whatsapp']['amostras'] ) {
			return 'a pausa de uma hora contou como tempo';
		}
		if ( array( 'celular' => 1 ) !== $m['aparelhos'] ) {
			return 'o aparelho saiu ' . wp_json_encode( $m['aparelhos'] );
		}
		return 1 === $m['usos']['continuar_link'] ? null : 'o "continuar depois" não foi contado';
	},
);

$casos[] = array(
	'grupo'    => 'medição',
	'nome'     => 'o lembrete que trouxe a pessoa de volta é contado',
	'executar' => function () use ( $zerar, $pedir, $dados ) {
		$armazem = $zerar();
		$t = $dados( Leticia_Rest::abrir( $pedir() ) );
		foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'email' => 'marina@padariaaurora.com.br' ) as $c => $v ) {
			$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => $c, 'texto' => $v ) ) ) );
		}
		$sessao = Leticia_Rascunho::conferir( $t['token'] );
		$linha  = $armazem->ler_briefing( $sessao );
		$linha['roteiro']['atividade'] = time() - 2 * DAY_IN_SECONDS;
		$armazem->gravar_briefing( $sessao, array( 'roteiro' => $linha['roteiro'] ) );
		Leticia_Retomada::lembrar();

		Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'dominio', 'texto' => 'padariaaurora.com.br' ) ) );
		$m = Leticia_Registro::metricas();
		return 1 === $m['lembretes']['receberam'] && 1 === $m['lembretes']['voltaram'] ? null : 'lembretes: ' . wp_json_encode( $m['lembretes'] );
	},
);

$casos[] = array(
	'grupo'    => 'medição',
	'nome'     => 'o painel mostra a seção de uso sem quebrar com o registro vazio',
	'executar' => function () use ( $zerar ) {
		$zerar();
		ob_start();
		Leticia_Painel::briefings();
		$html = ob_get_clean();
		foreach ( array( 'Como as pessoas preenchem', 'Tempo por pergunta', 'As novidades', 'Links com dados preenchidos' ) as $trecho ) {
			if ( false === strpos( $html, $trecho ) ) {
				return 'faltou "' . $trecho . '"';
			}
		}
		return null;
	},
);

return $casos;
