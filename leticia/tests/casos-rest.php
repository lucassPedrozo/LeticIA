<?php
/**
 * As rotas.
 *
 * Os handlers são chamados direto, sem subir o WordPress: testar o handler é
 * testar a regra, e testar o roteador seria testar o WordPress.
 *
 * O que está sendo protegido aqui é a fronteira. Tudo que chega nestas funções
 * veio de fora, e o defeito clássico de uma API pública não é o caminho feliz
 * — é o caminho em que alguém manda um campo que não existe, um token de outra
 * pessoa, ou clica em enviar duas vezes.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	// Em memória: estes casos percorrem briefings inteiros pelas rotas e não
	// leem o arquivo. Quem cobre a gravação em disco são os casos do armazém.
	$armazem = new Leticia_Armazem_Json( Leticia_Armazem_Json::MEMORIA );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	Leticia_Limites::zerar();
	leticia_zerar_emails();
	leticia_zerar_acoes();

	update_option(
		Leticia_Config::OPCAO,
		array(
			'GEMINI_API_KEY' => '',   // modo degradado: nenhum caso aqui gasta cota
			'REMETENTE'      => 'formulario@example.com',
			'DESTINO'        => 'briefing@example.com',
			'ASSUNTO'        => '[SITE EXPRESS] Novo briefing - [DOMINIO]',
		)
	);

	return $armazem;
};

$pedir = function ( array $params = array(), $corpo = '' ) {
	return new WP_REST_Request( $params, $corpo );
};

$dados = function ( $r ) {
	return $r instanceof WP_REST_Response ? $r->get_data() : $r;
};

/** Abre uma sessão e devolve o token. */
$abrir = function () use ( $pedir, $dados ) {
	return $dados( Leticia_Rest::abrir( $pedir() ) );
};

/** Responde um campo pela rota. */
$responder = function ( $token, $campo, $texto ) use ( $pedir, $dados ) {
	return $dados( Leticia_Rest::responder( $pedir( array( 'token' => $token, 'campo' => $campo, 'texto' => $texto ) ) ) );
};

// ------------------------------------------------------------- as rotas

$casos[] = array(
	'grupo'    => 'rest · registro',
	'nome'     => 'as rotas combinadas estão todas registradas',
	'executar' => function () {
		Leticia_Rest::registrar();
		$rotas = array_keys( leticia_rotas() );

		$esperadas = array(
			'leticia/v1/sessao', 'leticia/v1/responder', 'leticia/v1/pular', 'leticia/v1/voltar',
			'leticia/v1/arquivo/iniciar', 'leticia/v1/arquivo/pedaco', 'leticia/v1/arquivo/concluir',
			'leticia/v1/arquivo/remover', 'leticia/v1/enviar', 'leticia/v1/descartar', 'leticia/v1/saude',
			'leticia/v1/anexo/abrir', 'leticia/v1/anexo/concluir', 'leticia/v1/voz', 'leticia/v1/proposta',
		);

		$faltam = array_diff( $esperadas, $rotas );
		return $faltam ? 'faltando: ' . implode( ', ', $faltam ) : null;
	},
);

// ------------------------------------------------------------- abertura

$casos[] = array(
	'grupo'    => 'rest · abertura',
	'nome'     => 'abrir devolve token, primeiro campo e progresso zerado',
	'executar' => function () use ( $zerar, $abrir ) {
		$zerar();
		$t = $abrir();

		if ( empty( $t['token'] ) ) {
			return 'não devolveu token';
		}
		if ( ! $t['campo'] || 'responsavel' !== $t['campo']['chave'] ) {
			return 'não começou pelo responsável';
		}
		if ( 0 !== $t['progresso']['respondidos'] ) {
			return 'começou com progresso';
		}
		// A pergunta vai pronta: o modelo nunca é chamado para escrevê-la.
		return '' !== $t['campo']['pergunta'] ? null : 'o campo veio sem pergunta';
	},
);

$casos[] = array(
	'grupo'    => 'rest · abertura',
	'nome'     => 'a apresentação vem só antes da primeira resposta, com o nome dela',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		if ( empty( $t['apresentacao']['titulo'] ) || false === strpos( $t['apresentacao']['titulo'], Leticia_Config::nome() ) ) {
			return 'a primeira tela veio sem apresentação: ' . wp_json_encode( $t['apresentacao'] );
		}
		if ( empty( $t['apresentacao']['detalhe'] ) || false !== strpos( $t['apresentacao']['titulo'] . $t['apresentacao']['detalhe'], '|' ) ) {
			return 'título e detalhe não se separaram';
		}
		$depois = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		return null === $depois['apresentacao'] ? null : 'a apresentação voltou depois da primeira resposta';
	},
);

$casos[] = array(
	'grupo'    => 'rest · abertura',
	'nome'     => 'a ajuda do campo viaja junto, sem ida ao servidor',
	'executar' => function () use ( $zerar, $abrir ) {
		// Abrir o "por que perguntamos" não pode custar uma requisição, e muito
		// menos uma chamada de modelo.
		$zerar();
		$t = $abrir();

		return '' !== $t['campo']['ajuda'] ? null : 'o campo veio sem ajuda';
	},
);

$casos[] = array(
	'grupo'    => 'rest · abertura',
	'nome'     => 'token de outra pessoa não abre o briefing dela',
	'executar' => function () use ( $zerar, $pedir, $dados ) {
		$zerar();
		$t = $dados( Leticia_Rest::abrir( $pedir( array( 'token' => 'lixo.forjado.aqui' ) ) ) );

		// Token ruim não é erro na abertura: vira briefing novo. Recusar faria
		// um localStorage antigo travar a pessoa fora do formulário.
		if ( ! $t['campo'] || 'responsavel' !== $t['campo']['chave'] ) {
			return 'não abriu briefing novo';
		}
		return null === $t['retomada'] ? null : 'ofereceu retomar algo';
	},
);

$casos[] = array(
	'grupo'    => 'rest · abertura',
	'nome'     => 'com rascunho, a abertura já traz a barra de retomada',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir, $dados ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		$t = $responder( $t['token'], 'empresa', 'Padaria Aurora' );

		$volta = $dados( Leticia_Rest::abrir( $pedir( array( 'token' => $t['token'] ) ) ) );

		if ( empty( $volta['retomada'] ) ) {
			return 'não ofereceu retomada';
		}
		if ( false === strpos( $volta['retomada']['frase'], 'Padaria Aurora' ) ) {
			return 'a frase não traz a empresa';
		}
		return 'whatsapp' === $volta['campo']['chave'] ? null : 'retomou em ' . $volta['campo']['chave'];
	},
);

// ------------------------------------------------------------- resposta

$casos[] = array(
	'grupo'    => 'rest · resposta',
	'nome'     => 'responder grava e devolve o próximo campo',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );

		if ( 1 !== $t['progresso']['respondidos'] ) {
			return 'não contou a resposta';
		}
		if ( 'empresa' !== $t['campo']['chave'] ) {
			return 'seguiu para ' . $t['campo']['chave'];
		}
		return isset( $t['respostas']['responsavel'] ) ? null : 'a resposta não voltou na tela';
	},
);

$casos[] = array(
	'grupo'    => 'rest · resposta',
	'nome'     => 'formato inválido não avança e não gasta chamada',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina' );
		$t = $responder( $t['token'], 'empresa', 'Padaria Aurora' );
		$t = $responder( $t['token'], 'whatsapp', 'meu zap' );

		if ( '' === $t['erro'] ) {
			return 'aceitou um telefone que não é telefone';
		}
		if ( ! $t['permanece'] ) {
			return 'avançou mesmo assim';
		}
		return 'whatsapp' === $t['campo']['chave'] ? null : 'saiu do campo';
	},
);

$casos[] = array(
	'grupo'    => 'rest · resposta',
	'nome'     => 'o telefone volta normalizado na tela',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina' );
		$t = $responder( $t['token'], 'empresa', 'Padaria Aurora' );
		$t = $responder( $t['token'], 'whatsapp', '47999998888' );

		if ( '(47) 99999-8888' !== $t['respostas']['whatsapp']['valor'] ) {
			return 'gravou ' . $t['respostas']['whatsapp']['valor'];
		}
		// A marca de conferido só aparece em campo com formato validado.
		return $t['conferido'] ? null : 'não marcou como conferido';
	},
);

$casos[] = array(
	'grupo'    => 'rest · resposta',
	'nome'     => 'campo que não existe é recusado',
	'executar' => function () use ( $zerar, $abrir, $pedir ) {
		$zerar();
		$t = $abrir();
		$r = Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'orcamento', 'texto' => 'R$ 500' ) ) );

		return is_wp_error( $r ) ? null : 'aceitou um campo inventado';
	},
);

$casos[] = array(
	'grupo'    => 'rest · resposta',
	'nome'     => 'sem token, nenhuma rota de sessão responde',
	'executar' => function () use ( $zerar, $pedir ) {
		$zerar();

		foreach ( array( 'responder', 'pular', 'voltar', 'enviar', 'descartar' ) as $rota ) {
			$r = call_user_func( array( 'Leticia_Rest', $rota ), $pedir( array( 'campo' => 'ramo' ) ) );
			if ( ! is_wp_error( $r ) ) {
				return $rota . ' respondeu sem token';
			}
		}
		return null;
	},
);

// ------------------------------------------------------------ pular e voltar

$casos[] = array(
	'grupo'    => 'rest · pular',
	'nome'     => 'pular opcional avança; pular obrigatório é recusado',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir, $dados ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina' );
		$t = $responder( $t['token'], 'empresa', 'Padaria Aurora' );
		$t = $responder( $t['token'], 'whatsapp', '47999998888' );

		$t = $dados( Leticia_Rest::pular( $pedir( array( 'token' => $t['token'], 'campo' => 'email' ) ) ) );
		if ( 'dominio' !== $t['campo']['chave'] ) {
			return 'pular e-mail levou para ' . $t['campo']['chave'];
		}

		$r = Leticia_Rest::pular( $pedir( array( 'token' => $t['token'], 'campo' => 'dominio' ) ) );
		return is_wp_error( $r ) ? null : 'deixou pular o domínio';
	},
);

$casos[] = array(
	'grupo'    => 'rest · voltar',
	'nome'     => 'voltar devolve o texto anterior e não derruba o progresso',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir, $dados ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		$t = $responder( $t['token'], 'empresa', 'Padaria Aurora' );
		$antes = $t['progresso']['fracao'];

		$t = $dados( Leticia_Rest::voltar( $pedir( array( 'token' => $t['token'], 'campo' => 'responsavel' ) ) ) );

		if ( 'responsavel' !== $t['campo']['chave'] ) {
			return 'não reabriu o campo';
		}
		if ( 'Marina Alves' !== $t['eco'] ) {
			return 'não devolveu o texto anterior: "' . $t['eco'] . '"';
		}
		if ( $t['progresso']['fracao'] < $antes ) {
			return 'a barra andou para trás';
		}
		return isset( $t['respostas']['empresa'] ) ? null : 'apagou o que veio depois';
	},
);

// ------------------------------------------------------------- arquivos

$casos[] = array(
	'grupo'    => 'rest · arquivos',
	'nome'     => 'o envio em pedaços fecha o campo pela rota',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir, $dados ) {
		$zerar();
		$t = $abrir();
		foreach ( array(
			array( 'responsavel', 'Marina Alves' ),
			array( 'empresa', 'Padaria Aurora' ),
			array( 'whatsapp', '47999998888' ),
			array( 'email', 'marina@padariaaurora.com.br' ),
			array( 'dominio', 'padariaaurora.com.br' ),
			array( 'endereco', 'Rua das Flores, 120' ),
			array( 'ramo', 'padaria artesanal de bairro' ),
			array( 'servicos', 'pães e bolos' ),
			array( 'contatos_site', 'WhatsApp (47) 99999-8888' ),
			array( 'redes_sociais', 'instagram.com/padariaaurora' ),
			array( 'paginas_extras', 'não' ),
			array( 'imagens_ia', 'sim' ),
		) as $par ) {
			$t = $responder( $t['token'], $par[0], $par[1] );
		}

		if ( 'logo' !== $t['campo']['chave'] ) {
			return 'não chegou na logomarca, e sim em ' . $t['campo']['chave'];
		}
		// O campo de arquivo diz ao navegador o tamanho do pedaço.
		if ( empty( $t['campo']['pedaco'] ) ) {
			return 'o campo não informou o tamanho do pedaço';
		}

		$conteudo = "%PDF-1.4\n" . str_repeat( "% linha\n", 300 );
		$partes   = str_split( $conteudo, 1024 );

		$ini = $dados(
			Leticia_Rest::arquivo_iniciar(
				$pedir( array( 'token' => $t['token'], 'campo' => 'logo', 'nome' => 'logo.pdf', 'tamanho' => strlen( $conteudo ), 'pedacos' => count( $partes ) ) )
			)
		);
		if ( is_wp_error( $ini ) ) {
			return 'não abriu o envio: ' . $ini->get_error_message();
		}

		foreach ( $partes as $i => $parte ) {
			$r = Leticia_Rest::arquivo_pedaco( $pedir( array( 'token' => $t['token'], 'id' => $ini['id'], 'indice' => $i ), $parte ) );
			if ( is_wp_error( $r ) ) {
				return 'pedaço ' . $i . ': ' . $r->get_error_message();
			}
		}

		$fim = $dados( Leticia_Rest::arquivo_concluir( $pedir( array( 'token' => $t['token'], 'id' => $ini['id'] ) ) ) );
		if ( is_wp_error( $fim ) ) {
			return 'não concluiu: ' . $fim->get_error_message();
		}
		// O metadado que volta não pode carregar o caminho em disco.
		if ( isset( $fim['arquivo']['disco'] ) || isset( $fim['arquivo']['pasta'] ) ) {
			return 'vazou o caminho do arquivo para o navegador';
		}

		$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'logo' ) ) ) );

		if ( empty( $t['respostas']['logo']['arquivos'] ) ) {
			return 'o arquivo não entrou na resposta';
		}
		return 'textos' === $t['campo']['chave'] ? null : 'seguiu para ' . $t['campo']['chave'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · arquivos',
	'nome'     => 'logomarca sem arquivo é recusada, a menos que fique pendente',
	'executar' => function () use ( $zerar, $abrir, $pedir ) {
		$zerar();
		$t = $abrir();

		$r = Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'logo' ) ) );
		if ( ! is_wp_error( $r ) ) {
			return 'fechou a logomarca sem arquivo nenhum';
		}

		$com_pendencia = Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'logo', 'pendente' => true ) ) );
		return is_wp_error( $com_pendencia ) ? 'recusou a logo pendente' : null;
	},
);

$casos[] = array(
	'grupo'    => 'rest · arquivos',
	'nome'     => 'um envio não escreve na sessão de outra pessoa',
	'executar' => function () use ( $zerar, $abrir, $pedir, $dados ) {
		$zerar();
		$dona   = $abrir();
		$outra  = $abrir();

		$ini = $dados(
			Leticia_Rest::arquivo_iniciar(
				$pedir( array( 'token' => $dona['token'], 'campo' => 'logo', 'nome' => 'logo.pdf', 'tamanho' => 100, 'pedacos' => 1 ) )
			)
		);

		$r = Leticia_Rest::arquivo_pedaco( $pedir( array( 'token' => $outra['token'], 'id' => $ini['id'], 'indice' => 0 ), 'qualquer coisa' ) );

		return is_wp_error( $r ) ? null : 'gravou pedaço no envio alheio';
	},
);

// ---------------------------------------------------------------- envio

/** Preenche tudo pela rota e devolve a tela da revisão. */
$ate_a_revisao = function ( $token ) use ( $responder, $pedir, $dados ) {
	foreach ( array(
		array( 'responsavel', 'Marina Alves' ),
		array( 'empresa', 'Padaria Aurora' ),
		array( 'whatsapp', '47999998888' ),
		array( 'email', 'marina@padariaaurora.com.br' ),
		array( 'dominio', 'padariaaurora.com.br' ),
		array( 'endereco', 'Rua das Flores, 120' ),
		array( 'ramo', 'padaria artesanal de bairro' ),
		array( 'servicos', 'pães e bolos' ),
		array( 'contatos_site', 'WhatsApp (47) 99999-8888' ),
		array( 'redes_sociais', 'instagram.com/padariaaurora' ),
		array( 'paginas_extras', 'não' ),
		array( 'imagens_ia', 'sim' ),
	) as $par ) {
		$t = $responder( $token, $par[0], $par[1] );
	}

	$t = $dados( Leticia_Rest::responder( $pedir( array( 'token' => $token, 'campo' => 'logo', 'pendente' => true ) ) ) );
	$t = $dados( Leticia_Rest::pular( $pedir( array( 'token' => $token, 'campo' => 'textos' ) ) ) );
	$t = $dados( Leticia_Rest::pular( $pedir( array( 'token' => $token, 'campo' => 'materiais' ) ) ) );

	return $t;
};

$casos[] = array(
	'grupo'    => 'rest · envio',
	'nome'     => 'com 15 respondidos a fase vira revisão, e a barra não está cheia',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao ) {
		$zerar();
		$t = $abrir();
		$t = $ate_a_revisao( $t['token'] );

		if ( 'revisao' !== $t['fase'] ) {
			return 'a fase é ' . $t['fase'];
		}
		if ( 15 !== $t['progresso']['respondidos'] ) {
			return 'respondidos: ' . $t['progresso']['respondidos'];
		}
		if ( $t['progresso']['fracao'] >= 1.0 ) {
			return 'a barra encheu antes do envio';
		}
		// A revisão precisa do texto do aceite para poder mostrá-lo.
		return ! empty( $t['consentimento'] ) ? null : 'a revisão veio sem o texto do aceite';
	},
);

$casos[] = array(
	'grupo'    => 'rest · envio',
	'nome'     => 'sem o aceite marcado, não envia',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao, $pedir ) {
		$zerar();
		$t = $abrir();
		$ate_a_revisao( $t['token'] );

		$r = Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'] ) ) );
		return is_wp_error( $r ) ? null : 'enviou sem o aceite';
	},
);

$casos[] = array(
	'grupo'    => 'rest · envio',
	'nome'     => 'briefing incompleto não envia, e diz o que falta',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir ) {
		$zerar();
		$t = $abrir();
		$responder( $t['token'], 'responsavel', 'Marina Alves' );

		$r = Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'], 'consentimento' => true ) ) );
		if ( ! is_wp_error( $r ) ) {
			return 'enviou pela metade';
		}
		return false !== strpos( $r->get_error_message(), 'Empresa' ) ? null : 'não disse o que falta: ' . $r->get_error_message();
	},
);

$casos[] = array(
	'grupo'    => 'rest · envio',
	'nome'     => 'enviar fecha o briefing, avisa a equipe e enche a barra',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao, $pedir, $dados ) {
		$zerar();
		$t = $abrir();
		$ate_a_revisao( $t['token'] );

		$fim = $dados( Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'], 'consentimento' => true ) ) ) );

		if ( is_wp_error( $fim ) ) {
			return 'não enviou: ' . $fim->get_error_message();
		}
		if ( 'fim' !== $fim['fase'] ) {
			return 'a fase é ' . $fim['fase'];
		}
		if ( $fim['progresso']['fracao'] < 1.0 ) {
			return 'a barra não completou depois do envio';
		}
		if ( ! in_array( 'logo', $fim['pendencias'], true ) ) {
			return 'a tela final não mostra a pendência da logo';
		}
		return leticia_emails_enviados() ? null : 'nenhum e-mail saiu';
	},
);

$casos[] = array(
	'grupo'    => 'rest · envio',
	'nome'     => 'clicar duas vezes em enviar manda um e-mail, não dois',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao, $pedir, $dados ) {
		$zerar();
		$t = $abrir();
		$ate_a_revisao( $t['token'] );

		$pedido = $pedir( array( 'token' => $t['token'], 'consentimento' => true ) );
		Leticia_Rest::enviar( $pedido );
		$quantos = count( leticia_emails_enviados() );

		$segundo = $dados( Leticia_Rest::enviar( $pedido ) );

		if ( is_wp_error( $segundo ) ) {
			return 'o segundo clique deu erro na cara do cliente';
		}
		if ( 'fim' !== $segundo['fase'] ) {
			return 'o segundo clique tirou a pessoa da tela de sucesso';
		}
		return count( leticia_emails_enviados() ) === $quantos ? null : 'mandou o briefing duas vezes';
	},
);

$casos[] = array(
	'grupo'    => 'rest · envio',
	'nome'     => 'e-mail que falha não impede a tela de dizer "recebido"',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao, $pedir, $dados ) {
		// O briefing já está gravado. O que falhou foi o aviso.
		$zerar();
		$t = $abrir();
		$ate_a_revisao( $t['token'] );

		$GLOBALS['leticia_email_falha'] = true;
		$fim = $dados( Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'], 'consentimento' => true ) ) ) );
		$GLOBALS['leticia_email_falha'] = false;

		if ( is_wp_error( $fim ) ) {
			return 'devolveu erro para o cliente';
		}
		if ( 'fim' !== $fim['fase'] ) {
			return 'não chegou na tela de sucesso';
		}
		if ( ! empty( $fim['avisada'] ) ) {
			return 'disse que avisou a equipe';
		}
		return Leticia_Registro::nao_entregues() ? null : 'o painel não viu a falha';
	},
);

// ------------------------------------------------------------- descartar

$casos[] = array(
	'grupo'    => 'rest · descartar',
	'nome'     => '"começar do zero" apaga e devolve um briefing novo',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir, $dados ) {
		$zerar();
		$t      = $abrir();
		$antigo = $t['token'];
		$responder( $antigo, 'responsavel', 'Marina Alves' );

		$novo = $dados( Leticia_Rest::descartar( $pedir( array( 'token' => $antigo ) ) ) );

		if ( 'responsavel' !== $novo['campo']['chave'] || 0 !== $novo['progresso']['respondidos'] ) {
			return 'não começou do zero';
		}
		if ( $novo['token'] === $antigo ) {
			return 'devolveu o mesmo token';
		}
		$sessao = Leticia_Rascunho::conferir( $antigo );
		return null === Leticia_Rascunho::carregar( $sessao ) ? null : 'o rascunho antigo continuou lá';
	},
);

// ----------------------------------------------------------------- saúde

$casos[] = array(
	'grupo'    => 'rest · saúde',
	'nome'     => 'sem chave, a saúde diz que coleta mas não conversa',
	'executar' => function () use ( $zerar, $pedir, $dados ) {
		// São estados diferentes, e é a diferença que importa para quem
		// monitora: o briefing continua sendo coletado com o modelo fora do ar.
		$zerar();
		$s = $dados( Leticia_Rest::saude( $pedir() ) );

		if ( ! $s['coletando'] ) {
			return 'disse que nem coleta';
		}
		return $s['conversando'] ? 'disse que conversa sem chave' : null;
	},
);

// ------------------------------------------------- o estado entre requisições

$casos[] = array(
	'grupo'    => 'rest · estado',
	'nome'     => 'a pergunta não troca de texto quando a pessoa volta ao campo',
	'executar' => function () use ( $zerar, $abrir, $responder, $pedir, $dados ) {
		// Ver a pergunta com outra redação ao voltar faz parecer que a pessoa
		// mudou de campo. A variante é sorteada uma vez por sessão.
		$zerar();
		$t        = $abrir();
		$primeira = $t['campo']['pergunta'];

		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		$t = $dados( Leticia_Rest::voltar( $pedir( array( 'token' => $t['token'], 'campo' => 'responsavel' ) ) ) );

		return $t['campo']['pergunta'] === $primeira
			? null
			: 'mudou de "' . $primeira . '" para "' . $t['campo']['pergunta'] . '"';
	},
);

$casos[] = array(
	'grupo'    => 'rest · estado',
	'nome'     => 'o limite de uma repergunta sobrevive entre requisições',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		// Cada chamada da rota reconstrói o estado a partir do que está
		// gravado. Se a contagem de reperguntas não estiver gravada, ela zera a
		// cada requisição — e a LetícIA insiste para sempre com quem já
		// respondeu, que é exatamente como se perde um briefing.
		$zerar();
		update_option(
			Leticia_Config::OPCAO,
			array( 'GEMINI_API_KEY' => 'teste', 'GEMINI_MODEL' => 'gemini-3.5-flash-lite', 'ATIVA' => '1', 'TETO_DIARIO' => '200' )
		);
		remove_all_filters( 'leticia_pre_gerar' );
		add_filter(
			'leticia_pre_gerar',
			function () {
				// Um modelo teimoso: acha toda resposta insuficiente.
				return array(
					'texto'  => json_encode( array( 'tipo' => 'resposta', 'suficiente' => false, 'repergunta' => 'São telhas ou telas de proteção?' ) ),
					'modelo' => 'teste',
					'uso'    => array(),
				);
			}
		);

		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		$t = $responder( $t['token'], 'empresa', 'Padaria Aurora' );
		$t = $responder( $t['token'], 'whatsapp', '47999998888' );
		$t = $responder( $t['token'], 'email', 'marina@padariaaurora.com.br' );
		// O domínio também comenta, então o modelo teimoso o reperguntaria:
		// responde duas vezes — a segunda já tem que passar.
		$t = $responder( $t['token'], 'dominio', 'padariaaurora.com.br' );
		$t = $responder( $t['token'], 'dominio', 'padariaaurora.com.br' );
		$t = $responder( $t['token'], 'endereco', 'Rua das Flores, 120' );

		// O ramo aceita três reperguntas — curtas demais ou com o modelo
		// achando pouco. A quarta resposta passa, seja qual for.
		$vezes = array();
		$r     = $t;
		foreach ( array( 'tela', 'tela de proteção', 'telas de proteção para janelas', 'telas de proteção para janelas e sacadas' ) as $texto ) {
			$r       = $responder( $r['token'], 'ramo', $texto );
			$vezes[] = $r['permanece'];
		}

		remove_all_filters( 'leticia_pre_gerar' );

		if ( array( true, true, true ) !== array_slice( $vezes, 0, 3 ) ) {
			return 'as três primeiras não ficaram no campo: ' . wp_json_encode( $vezes );
		}
		if ( $vezes[3] ) {
			return 'reperguntou de novo — o limite zerou entre as requisições';
		}
		return 'servicos' === $r['campo']['chave'] ? null : 'seguiu para ' . $r['campo']['chave'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · estado',
	'nome'     => 'ramo curto ("bolo", "mapa") não avança, mesmo sem IA; três tentativas e ele passa',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();   // sem chave: a regra é do PHP, e vale em modo degradado
		foreach ( array( 'bolo' => 1, 'mapa' => 1, 'vendo bolo' => 2, 'faço bolos de festa por encomenda' => 4, 'padaria artesanal de bairro' => 3, "mapa
mapas para empresas" => 2 ) as $texto => $esperado ) {
			$achou = Leticia_Validacao::palavras_de_conteudo( $texto );
			if ( $achou !== $esperado ) {
				return '"' . $texto . '" contou ' . $achou . ', esperava ' . $esperado;
			}
		}

		$t = $abrir();
		foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'email' => 'marina@padariaaurora.com.br', 'dominio' => 'padariaaurora.com.br', 'endereco' => 'não tenho' ) as $c => $v ) {
			$t = $responder( $t['token'], $c, $v );
		}

		$r = $responder( $t['token'], 'ramo', 'bolo' );
		if ( empty( $r['permanece'] ) || 'ramo' !== $r['campo']['chave'] || isset( $r['respostas']['ramo'] ) ) {
			return '"bolo" avançou';
		}
		if ( empty( $r['dela']['repergunta'] ) || 'bolo' !== $r['eco'] ) {
			return 'a resposta curta voltou sem pedido ou sem o texto: ' . wp_json_encode( array( $r['dela'], $r['eco'] ) );
		}
		$pedidos = array( $r['dela']['repergunta'] );

		// "de festa" completa a primeira: bolo + festa ainda são duas.
		$r = $responder( $r['token'], 'ramo', 'de festa' );
		if ( empty( $r['permanece'] ) ) {
			return '"bolo" e "de festa" passaram juntos';
		}
		$pedidos[] = $r['dela']['repergunta'];
		if ( $pedidos[0] === $pedidos[1] ) {
			return 'a segunda vez repetiu o mesmo pedido';
		}

		$r = $responder( $r['token'], 'ramo', 'bolo' );
		$r = $responder( $r['token'], 'ramo', 'bolo' );
		if ( ! empty( $r['permanece'] ) || 'servicos' !== $r['campo']['chave'] ) {
			return 'depois de três pedidos ainda não deixou seguir';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'rest · estado',
	'nome'     => 'ramo com o bastante passa de primeira',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'email' => 'marina@padariaaurora.com.br', 'dominio' => 'padariaaurora.com.br', 'endereco' => 'não tenho' ) as $c => $v ) {
			$t = $responder( $t['token'], $c, $v );
		}
		$r = $responder( $t['token'], 'ramo', 'faço bolos de festa por encomenda' );
		return empty( $r['permanece'] ) && 'servicos' === $r['campo']['chave'] ? null : 'uma resposta boa ficou presa';
	},
);

// ------------------------------------------------------------- a conversa

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => 'sem IA, a resposta volta com reação e a pergunta com detalhe',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		if ( '' === $t['campo']['detalhe'] ) {
			return 'a primeira pergunta veio sem detalhe';
		}
		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		if ( '' === $t['ponte'] ) {
			return 'a resposta não teve reação nenhuma';
		}
		return false === strpos( $t['campo']['pergunta'], '|' ) ? null : 'a barra chegou na tela';
	},
);

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => 'o comentário do modelo vira a ponte, no lugar da reação pronta',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'GEMINI_API_KEY' => 'chave-de-teste' ) ) );
		$t = $abrir();
		// Até o ramo: é campo que sempre vai ao modelo. Domínio válido não vai
		// mais — a reação escrita dá conta, e há caso próprio para isso.
		foreach ( array( array( 'responsavel', 'Marina Alves' ), array( 'empresa', 'Padaria Aurora' ), array( 'whatsapp', '47999998888' ), array( 'email', 'marina@padariaaurora.com.br' ), array( 'dominio', 'padariaaurora.com.br' ), array( 'endereco', 'não tenho' ) ) as $par ) {
			$t = $responder( $t['token'], $par[0], $par[1] );
		}

		add_filter(
			'leticia_pre_gerar',
			function () {
				return array(
					'texto' => json_encode( array( 'tipo' => 'resposta', 'suficiente' => true, 'comentario' => 'Fermentação natural costuma trazer cliente de longe.' ) ),
				);
			}
		);
		$t = $responder( $t['token'], 'ramo', 'padaria artesanal de fermentação natural' );
		remove_all_filters( 'leticia_pre_gerar' );

		return 'Fermentação natural costuma trazer cliente de longe.' === $t['ponte'] ? null : 'a ponte foi: ' . $t['ponte'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => 'a reação anterior vai ao modelo na requisição seguinte',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		// Sem isso ela abria duas respostas seguidas com "o tom acolhedor".
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'GEMINI_API_KEY' => 'chave-de-teste' ) ) );
		$t = $abrir();
		foreach ( array( array( 'responsavel', 'Marina Alves' ), array( 'empresa', 'Padaria Aurora' ), array( 'whatsapp', '47999998888' ), array( 'email', 'marina@padariaaurora.com.br' ), array( 'dominio', 'padariaaurora.com.br' ) ) as $par ) {
			$t = $responder( $t['token'], $par[0], $par[1] );
		}

		$turnos = array();
		add_filter(
			'leticia_pre_gerar',
			function ( $nada, $instrucao, $turno ) use ( &$turnos ) {
				$turnos[] = $turno;
				return array( 'texto' => json_encode( array( 'tipo' => 'resposta', 'suficiente' => true, 'comentario' => 'Reação número ' . count( $turnos ) . '.' ) ) );
			},
			10,
			3
		);
		$t = $responder( $t['token'], 'endereco', 'Rua das Flores, 10' );
		$t = $responder( $t['token'], 'ramo', 'padaria artesanal de fermentação natural' );
		remove_all_filters( 'leticia_pre_gerar' );

		if ( 1 !== count( $turnos ) ) {
			return 'o modelo foi chamado ' . count( $turnos ) . ' vezes';
		}
		// A resposta de endereço não chama o modelo: a reação dela foi a da
		// base. É essa que precisa ter chegado ao turno do ramo.
		$endereco = Leticia_Campos::por_chave( 'endereco' );
		foreach ( $endereco['depois'] as $variante ) {
			if ( false !== strpos( $turnos[0], $variante ) ) {
				return 'Reação número 1.' === $t['ponte'] ? null : 'a ponte foi ' . $t['ponte'];
			}
		}
		return 'a reação anterior não estava no turno: ' . $turnos[0];
	},
);

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => 'a segunda aba não apaga o briefing que a primeira enviou',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao, $pedir, $dados, $responder ) {
		$zerar();
		$t = $abrir();
		$ate_a_revisao( $t['token'] );
		Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'], 'consentimento' => true ) ) );

		// A outra aba, parada num campo, com o mesmo token.
		$r = Leticia_Rest::responder( $pedir( array( 'token' => $t['token'], 'campo' => 'ramo', 'texto' => 'outra coisa' ) ) );
		if ( ! is_wp_error( $r ) ) {
			return 'aceitou resposta num briefing enviado';
		}
		if ( 409 !== $r->get_error_data()['status'] ) {
			return 'status ' . $r->get_error_data()['status'];
		}
		$linha = Leticia_Registro::briefing( Leticia_Rascunho::conferir( $t['token'] ) );
		if ( 15 !== count( $linha['respostas'] ) ) {
			return 'o briefing enviado ficou com ' . count( $linha['respostas'] ) . ' respostas';
		}
		return (int) $linha['enviado_em'] > 0 ? null : 'o briefing voltou a ser rascunho';
	},
);

// ------------------------------------------------------------------ anexo

/** Envia um briefing com a logo pendente e devolve a tela final. */
$enviado_sem_logo = function () use ( $zerar, $abrir, $ate_a_revisao, $pedir, $dados ) {
	$zerar();
	$t = $abrir();
	$ate_a_revisao( $t['token'] );
	return $dados( Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'], 'consentimento' => true, 'pagina' => 'https://joinvix.com.br/briefing/' ) ) ) );
};

$token_do_link = function ( $link ) {
	parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $q );
	return isset( $q[ Leticia_Anexo::PARAMETRO ] ) ? $q[ Leticia_Anexo::PARAMETRO ] : '';
};

$subir_logo = function ( $chave_token, $token ) use ( $pedir, $dados ) {
	$conteudo = "%PDF-1.4\n" . str_repeat( "% linha\n", 50 );
	$ini      = $dados( Leticia_Rest::arquivo_iniciar( $pedir( array( $chave_token => $token, 'campo' => 'logo', 'nome' => 'logo.pdf', 'tamanho' => strlen( $conteudo ), 'pedacos' => 1 ) ) ) );
	if ( is_wp_error( $ini ) ) {
		return $ini;
	}
	$r = Leticia_Rest::arquivo_pedaco( $pedir( array( $chave_token => $token, 'id' => $ini['id'], 'indice' => 0 ), $conteudo ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return $dados( Leticia_Rest::arquivo_concluir( $pedir( array( $chave_token => $token, 'id' => $ini['id'] ) ) ) );
};

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'logo pendente: a tela final entrega o link de mandar depois',
	'executar' => function () use ( $enviado_sem_logo, $token_do_link ) {
		$fim = $enviado_sem_logo();
		if ( empty( $fim['anexo'] ) ) {
			return 'a tela final veio sem link';
		}
		if ( 0 !== strpos( $fim['anexo'], 'https://joinvix.com.br/briefing/?' ) ) {
			return 'o link não abre na página do briefing: ' . $fim['anexo'];
		}
		return '' !== $token_do_link( $fim['anexo'] ) ? null : 'o link não tem token';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'o link vai no e-mail da equipe e na cópia do cliente',
	'executar' => function () use ( $enviado_sem_logo ) {
		$fim    = $enviado_sem_logo();
		$emails = leticia_emails_enviados();
		if ( count( $emails ) < 2 ) {
			return 'saíram ' . count( $emails ) . ' e-mails';
		}
		foreach ( $emails as $i => $email ) {
			if ( false === strpos( $email['corpo'], Leticia_Anexo::PARAMETRO . '=' ) ) {
				return 'o e-mail ' . $i . ' não tem o link';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'pelo link, a logo chega, a pendência sai e a equipe é avisada',
	'executar' => function () use ( $enviado_sem_logo, $token_do_link, $subir_logo, $pedir, $dados ) {
		$fim   = $enviado_sem_logo();
		$anexo = $token_do_link( $fim['anexo'] );

		$tela = $dados( Leticia_Rest::anexo_abrir( $pedir( array( 'anexo' => $anexo ) ) ) );
		if ( is_wp_error( $tela ) || 'anexo' !== $tela['fase'] || 'logo' !== $tela['campo']['chave'] ) {
			return 'o link não abriu no campo da logo';
		}
		// A variante é sorteada pela sessão: umas chamam pelo nome, outras não.
		// O que não pode é a marcação chegar crua na tela.
		if ( false !== strpos( $tela['campo']['pergunta'], '{' ) || 'Marina' !== $tela['nome'] ) {
			return 'a saudação veio errada: ' . $tela['campo']['pergunta'] . ' / ' . $tela['nome'];
		}
		if ( isset( $tela['respostas'] ) ) {
			return 'o link de anexo devolveu as respostas do briefing';
		}

		leticia_zerar_emails();
		$subiu = $subir_logo( 'anexo', $anexo );
		if ( is_wp_error( $subiu ) ) {
			return 'o envio pelo link falhou: ' . $subiu->get_error_message();
		}

		$depois = $dados( Leticia_Rest::anexo_concluir( $pedir( array( 'anexo' => $anexo, 'campo' => 'logo' ) ) ) );
		if ( is_wp_error( $depois ) || 'anexo-fim' !== $depois['fase'] ) {
			return 'não fechou a pendência';
		}

		$linha = Leticia_Registro::briefing( Leticia_Anexo::conferir( $anexo ) );
		if ( in_array( 'logo', (array) $linha['pendencias'], true ) ) {
			return 'a pendência continua no registro';
		}
		if ( empty( $linha['respostas']['logo']['arquivos'] ) ) {
			return 'o arquivo não entrou no briefing';
		}
		if ( 15 !== count( $linha['respostas'] ) || (int) $linha['enviado_em'] < 1 ) {
			return 'mexeu no resto do briefing';
		}

		$emails = leticia_emails_enviados();
		if ( 1 !== count( $emails ) ) {
			return 'saíram ' . count( $emails ) . ' e-mails';
		}
		return '[CONTINUAÇÃO] - padariaaurora.com.br' === $emails[0]['assunto'] ? null : 'assunto: ' . $emails[0]['assunto'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'o link de anexo só manda o que está pendente',
	'executar' => function () use ( $enviado_sem_logo, $token_do_link, $pedir ) {
		$fim   = $enviado_sem_logo();
		$anexo = $token_do_link( $fim['anexo'] );
		$r     = Leticia_Rest::arquivo_iniciar( $pedir( array( 'anexo' => $anexo, 'campo' => 'materiais', 'nome' => 'foto.jpg', 'tamanho' => 10, 'pedacos' => 1 ) ) );
		return is_wp_error( $r ) ? null : 'aceitou arquivo em campo que não estava pendente';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'um token não abre o que é do outro',
	'executar' => function () use ( $enviado_sem_logo, $token_do_link, $pedir, $abrir ) {
		$fim   = $enviado_sem_logo();
		$anexo = $token_do_link( $fim['anexo'] );

		// O de anexo não serve como rascunho...
		if ( ! is_wp_error( Leticia_Rascunho::conferir( $anexo ) ) ) {
			return 'o token de anexo abriu como rascunho';
		}
		// ...e o de rascunho não serve como anexo.
		if ( ! is_wp_error( Leticia_Rest::anexo_abrir( $pedir( array( 'anexo' => $fim['token'] ) ) ) ) ) {
			return 'o token de rascunho abriu o link de anexo';
		}
		// E briefing não enviado não tem link de anexo que funcione.
		$outro = $abrir();
		$falso = Leticia_Anexo::assinar( Leticia_Rascunho::conferir( $outro['token'] ) );
		return is_wp_error( Leticia_Rest::anexo_abrir( $pedir( array( 'anexo' => $falso ) ) ) ) ? null : 'abriu anexo de briefing não enviado';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'o link expira em 30 dias',
	'executar' => function () {
		$sessao = str_repeat( 'd', 32 );
		$velho  = Leticia_Anexo::assinar( $sessao, time() - 1 );
		$r      = Leticia_Anexo::conferir( $velho );
		return is_wp_error( $r ) && 'anexo_expirado' === $r->get_error_code() ? null : 'aceitou link vencido';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'concluir duas vezes avisa a equipe uma vez',
	'executar' => function () use ( $enviado_sem_logo, $token_do_link, $subir_logo, $pedir, $dados ) {
		$fim   = $enviado_sem_logo();
		$anexo = $token_do_link( $fim['anexo'] );
		$subir_logo( 'anexo', $anexo );
		leticia_zerar_emails();

		Leticia_Rest::anexo_concluir( $pedir( array( 'anexo' => $anexo, 'campo' => 'logo' ) ) );
		$segunda = $dados( Leticia_Rest::anexo_concluir( $pedir( array( 'anexo' => $anexo, 'campo' => 'logo' ) ) ) );

		if ( is_wp_error( $segunda ) || 'anexo-fim' !== $segunda['fase'] ) {
			return 'o segundo clique deu erro';
		}
		return 1 === count( leticia_emails_enviados() ) ? null : 'avisou ' . count( leticia_emails_enviados() ) . ' vezes';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'o aceite configurado no painel vale na tela e no e-mail',
	'executar' => function () use ( $zerar, $abrir, $ate_a_revisao, $pedir, $dados ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'CONSENTIMENTO' => 'Autorizo a JoinVix a usar este material' ) ) );
		$t   = $abrir();
		$rev = $ate_a_revisao( $t['token'] );
		if ( 'Autorizo a JoinVix a usar este material' !== $rev['consentimento'] ) {
			return 'a revisão mostrou: ' . $rev['consentimento'];
		}
		Leticia_Rest::enviar( $pedir( array( 'token' => $t['token'], 'consentimento' => true ) ) );
		$emails = leticia_emails_enviados();
		return false !== strpos( $emails[0]['corpo'], 'Autorizo a JoinVix a usar este material' ) ? null : 'o e-mail saiu com outro aceite';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'com o domínio pendente, a logo que chega não promete as 72 horas',
	'executar' => function () use ( $enviado_sem_logo, $token_do_link, $subir_logo, $pedir, $dados ) {
		// O briefing de teste tem domínio real; aqui o domínio fica pendente.
		$fim    = $enviado_sem_logo();
		$sessao = Leticia_Rascunho::conferir( $fim['token'] );
		$linha  = Leticia_Registro::briefing( $sessao );
		$linha['respostas']['dominio'] = array( 'valor' => 'ainda não tenho', 'bruto' => 'ainda não tenho', 'pulado' => false, 'pendente' => true, 'link' => '', 'arquivos' => array() );
		Leticia_Registro::armazem()->gravar_briefing( $sessao, array( 'respostas' => $linha['respostas'], 'pendencias' => array( 'dominio', 'logo' ) ) );

		$anexo = $token_do_link( $fim['anexo'] );
		$subir_logo( 'anexo', $anexo );
		$depois = $dados( Leticia_Rest::anexo_concluir( $pedir( array( 'anexo' => $anexo, 'campo' => 'logo' ) ) ) );

		if ( false !== strpos( $depois['mensagem'], 'começam a contar' ) ) {
			return 'prometeu o prazo com o domínio pendente: ' . $depois['mensagem'];
		}
		return false !== strpos( $depois['mensagem'], 'domínio' ) ? null : 'não disse o que ainda segura: ' . $depois['mensagem'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'a cópia do cliente fala com ele, não sobre ele',
	'executar' => function () use ( $enviado_sem_logo ) {
		$enviado_sem_logo();
		foreach ( leticia_emails_enviados() as $email ) {
			if ( false !== strpos( implode( ' ', (array) $email['para'] ), 'marina@' ) ) {
				if ( false !== stripos( $email['corpo'], 'o cliente' ) ) {
					return 'a cópia do cliente diz "o cliente"';
				}
				return false !== strpos( $email['corpo'], 'Logomarca: você manda depois pelo link' ) ? null : 'a pendência saiu com outro texto';
			}
		}
		return 'a cópia do cliente não saiu';
	},
);

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => '"não tenho" no endereço: ela anota por extenso, e sem gastar chamada',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'GEMINI_API_KEY' => 'chave-de-teste' ) ) );
		$chamadas = 0;
		add_filter(
			'leticia_pre_gerar',
			function () use ( &$chamadas ) {
				$chamadas++;
				return array( 'texto' => json_encode( array( 'tipo' => 'resposta', 'suficiente' => true ) ) );
			}
		);
		$t = $abrir();
		foreach ( array( array( 'responsavel', 'Eu, lucas' ), array( 'empresa', 'Padaria Aurora' ), array( 'whatsapp', '47999998888' ), array( 'email', 'não tenho' ), array( 'dominio', 'ainda não tenho' ) ) as $par ) {
			$t = $responder( $t['token'], $par[0], $par[1] );
		}
		$antes = $chamadas;
		$t     = $responder( $t['token'], 'endereco', 'Nao tenho' );
		remove_all_filters( 'leticia_pre_gerar' );

		if ( $chamadas !== $antes ) {
			return 'gastou chamada para entender "não tenho"';
		}
		if ( 'Sem endereço físico' !== $t['respostas']['endereco']['valor'] ) {
			return 'gravou: ' . $t['respostas']['endereco']['valor'];
		}
		if ( 'Lucas' !== $t['respostas']['responsavel']['valor'] ) {
			return 'o nome foi gravado como ' . $t['respostas']['responsavel']['valor'];
		}
		return false !== stripos( $t['ponte'], 'endereço' ) ? null : 'a reação não falou do endereço: ' . $t['ponte'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => '"não sei" no nome: ela orienta e a pessoa continua no campo',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		$t = $responder( $t['token'], 'responsavel', 'não sei' );
		if ( empty( $t['permanece'] ) || 'responsavel' !== $t['campo']['chave'] ) {
			return 'deixou passar';
		}
		if ( '' !== $t['erro'] || empty( $t['dela']['repergunta'] ) ) {
			return 'respondeu como erro de formato, não como fala';
		}
		return isset( $t['respostas']['responsavel'] ) ? 'gravou mesmo assim' : null;
	},
);

$casos[] = array(
	'grupo'    => 'rest · conversa',
	'nome'     => 'a etapa vem separada, só na primeira pergunta dela',
	'executar' => function () use ( $zerar, $abrir, $responder ) {
		$zerar();
		$t = $abrir();
		if ( empty( $t['etapa'] ) || 1 !== $t['etapa']['numero'] || 'Seus dados' !== $t['etapa']['nome'] ) {
			return 'a primeira pergunta veio sem a etapa';
		}
		$t = $responder( $t['token'], 'responsavel', 'Marina Alves' );
		return null === $t['etapa'] ? null : 'a etapa repetiu na segunda pergunta';
	},
);

$casos[] = array(
	'grupo'    => 'rest · anexo',
	'nome'     => 'dois arquivos para depois: o link pede um de cada vez, e cada um vira [CONTINUAÇÃO]',
	'executar' => function () use ( $zerar, $abrir, $pedir, $dados, $token_do_link, $subir_logo ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'CAMPOS' => array( 'textos' => array( 'obrigatorio' => '0', 'depois' => '1' ) ) ) ) );
		Leticia_Campos::limpar_cache();

		$t     = $abrir();
		$token = $t['token'];
		foreach ( array( 'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888', 'email' => 'não tenho', 'dominio' => 'padariaaurora.com.br', 'endereco' => 'não tenho', 'ramo' => 'padaria artesanal de bairro', 'servicos' => 'pães e bolos', 'contatos_site' => 'WhatsApp (47) 99999-8888', 'redes_sociais' => 'não tenho', 'paginas_extras' => 'não', 'imagens_ia' => 'sim' ) as $c => $v ) {
			$dados( Leticia_Rest::responder( $pedir( array( 'token' => $token, 'campo' => $c, 'texto' => $v ) ) ) );
		}
		$dados( Leticia_Rest::responder( $pedir( array( 'token' => $token, 'campo' => 'logo', 'pendente' => true ) ) ) );
		$dados( Leticia_Rest::responder( $pedir( array( 'token' => $token, 'campo' => 'textos', 'pendente' => true ) ) ) );
		Leticia_Rest::pular( $pedir( array( 'token' => $token, 'campo' => 'materiais' ) ) );
		$fim = $dados( Leticia_Rest::enviar( $pedir( array( 'token' => $token, 'consentimento' => true, 'pagina' => 'https://joinvix.com.br/briefing/' ) ) ) );

		$anexo = $token_do_link( $fim['anexo'] );
		$tela  = $dados( Leticia_Rest::anexo_abrir( $pedir( array( 'anexo' => $anexo ) ) ) );
		if ( 'logo' !== $tela['campo']['chave'] || false === strpos( $tela['campo']['pergunta'], 'a logomarca' ) ) {
			Leticia_Campos::limpar_cache();
			return 'o link não começou pela logo: ' . $tela['campo']['pergunta'];
		}

		leticia_zerar_emails();
		$subir_logo( 'anexo', $anexo );
		$depois = $dados( Leticia_Rest::anexo_concluir( $pedir( array( 'anexo' => $anexo, 'campo' => 'logo' ) ) ) );

		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'CAMPOS' => array() ) ) );
		Leticia_Campos::limpar_cache();

		if ( 'anexo' !== $depois['fase'] || 'textos' !== $depois['campo']['chave'] ) {
			return 'depois da logo, não pediu os textos';
		}
		if ( false === strpos( $depois['campo']['pergunta'], 'os textos do site' ) ) {
			return 'a pergunta não falou dos textos: ' . $depois['campo']['pergunta'];
		}
		$emails = leticia_emails_enviados();
		if ( 1 !== count( $emails ) || '[CONTINUAÇÃO] - padariaaurora.com.br' !== $emails[0]['assunto'] ) {
			return 'o aviso saiu com: ' . ( $emails ? $emails[0]['assunto'] : 'nada' );
		}
		return false !== strpos( Leticia_Email::texto( $emails[0]['corpo'] ), 'Textos do site: não veio' ) ? null : 'o aviso não disse que os textos ainda faltam';
	},
);

// ------------------------------------------------------- etapa enxuta

/** Responde sem IA até o campo pedido, com endereço ou sem. */
$ate_campo = function ( $parar, $endereco = 'Rua das Flores, 120, Joinville' ) use ( $abrir, $responder ) {
	$t = $abrir();
	$roteiro = array(
		'responsavel' => 'Marina Alves', 'empresa' => 'Padaria Aurora', 'whatsapp' => '47999998888',
		'email' => 'marina@padariaaurora.com.br', 'dominio' => 'padariaaurora.com.br',
		'ramo' => 'padaria artesanal de bairro', 'servicos' => "pães\nbolos", 'endereco' => $endereco,
		'contatos_site' => 'WhatsApp (47) 99999-8888',
	);
	foreach ( $roteiro as $c => $v ) {
		if ( $c === $parar ) {
			break;
		}
		$t = $responder( $t['token'], $c, $v );
	}
	return $t;
};

$casos[] = array(
	'grupo'    => 'rest · etapa enxuta',
	'nome'     => 'o endereço vem antes dos contatos, e entra na sugestão — "sem endereço" não entra',
	'executar' => function () use ( $zerar, $ate_campo ) {
		$zerar();
		$t = $ate_campo( 'contatos_site' );
		if ( 'contatos_site' !== $t['campo']['chave'] ) {
			return 'depois do endereço veio ' . $t['campo']['chave'];
		}
		if ( false === strpos( $t['sugestao'], 'Endereço Rua das Flores, 120' ) || false === strpos( $t['sugestao'], 'WhatsApp' ) ) {
			return 'a sugestão ficou sem o endereço: ' . $t['sugestao'];
		}
		$zerar();
		$sem = $ate_campo( 'contatos_site', 'não tenho' );
		return false === strpos( $sem['sugestao'], 'Sem endereço' ) ? null : 'o "sem endereço" virou contato: ' . $sem['sugestao'];
	},
);

$casos[] = array(
	'grupo'    => 'rest · etapa enxuta',
	'nome'     => 'redes sociais e páginas a mais vêm na mesma tela — mas só no caminho de ida',
	'executar' => function () use ( $zerar, $ate_campo, $responder, $pedir, $dados ) {
		$zerar();
		$t = $ate_campo( 'nada' );
		$t = $responder( $t['token'], 'contatos_site', 'WhatsApp (47) 99999-8888' );
		if ( 'redes_sociais' !== $t['campo']['chave'] || empty( $t['junto'] ) || 'paginas_extras' !== $t['junto']['chave'] ) {
			return 'as redes vieram sozinhas: ' . wp_json_encode( array( $t['campo']['chave'], $t['junto'] ) );
		}
		if ( 'Páginas a mais' !== $t['junto']['rotulo_curto'] || false === strpos( $t['campo']['pergunta'] . ' ' . $t['campo']['detalhe'], 'pular' ) ) {
			return 'a tela conjunta saiu sem rótulo curto ou sem a saída de pular';
		}

		// Pular as duas: dois pulos, e a próxima é imagens.
		$p = $dados( Leticia_Rest::pular( $pedir( array( 'token' => $t['token'], 'campo' => 'redes_sociais' ) ) ) );
		if ( 'paginas_extras' !== $p['campo']['chave'] || ! empty( $p['junto'] ) ) {
			return 'depois de pular as redes: ' . wp_json_encode( array( $p['campo']['chave'], $p['junto'] ) );
		}
		$p = $dados( Leticia_Rest::pular( $pedir( array( 'token' => $p['token'], 'campo' => 'paginas_extras' ) ) ) );
		if ( 'imagens_ia' !== $p['campo']['chave'] ) {
			return 'depois dos dois pulos veio ' . $p['campo']['chave'];
		}

		// Voltar para corrigir as redes: um campo só.
		$v = $dados( Leticia_Rest::voltar( $pedir( array( 'token' => $p['token'], 'campo' => 'redes_sociais' ) ) ) );
		return 'redes_sociais' === $v['campo']['chave'] && empty( $v['junto'] ) ? null : 'voltar abriu a tela conjunta';
	},
);

$casos[] = array(
	'grupo'    => 'rest · etapa enxuta',
	'nome'     => 'a logo oferece foto e print do Instagram',
	'executar' => function () {
		$logo = Leticia_Campos::por_chave( 'logo' );
		if ( empty( $logo['foto'] ) || false === stripos( $logo['foto'], 'instagram' ) ) {
			return 'a logo ficou sem a saída da foto';
		}
		return empty( Leticia_Campos::por_chave( 'textos' )['foto'] ) ? null : 'textos ganhou foto de logo';
	},
);

return $casos;
