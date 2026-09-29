<?php
/**
 * Salvar e retomar.
 *
 * Os três comportamentos que precisam estar certos: retoma no campo certo,
 * expira, e "começar do zero" limpa. Mais um que não estava na lista e importa
 * tanto quanto: **briefing enviado não é rascunho** — retomar um enviado faria
 * a pessoa preencher de novo o que a equipe já recebeu.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	$caminho = sys_get_temp_dir() . '/leticia-teste-rascunho-' . getmypid() . '.json';
	if ( file_exists( $caminho ) ) {
		unlink( $caminho );
	}
	$armazem = new Leticia_Armazem_Json( $caminho );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	return $armazem;
};

$preencher = function ( $ate ) {
	$exemplos = array(
		'responsavel'    => 'Marina Alves',
		'empresa'        => 'Padaria Aurora',
		'whatsapp'       => '47999998888',
		'email'          => 'marina@padariaaurora.com.br',
		'dominio'        => 'padariaaurora.com.br',
		'endereco'       => 'Rua das Flores, 120',
		'ramo'           => 'padaria artesanal de bairro',
		'servicos'       => 'pães, bolos e café da manhã',
		'contatos_site'  => 'WhatsApp (47) 99999-8888',
		'redes_sociais'  => 'instagram.com/padariaaurora',
		'paginas_extras' => 'não',
		'imagens_ia'     => 'sim',
	);

	$estado = Leticia_Roteiro::novo( 3 );
	$n      = 0;

	foreach ( Leticia_Campos::todos() as $campo ) {
		if ( $n >= $ate ) {
			break;
		}
		$r = 'arquivo' === $campo['tipo']
			? Leticia_Roteiro::responder( $estado, $campo['chave'], '', array( 'valor' => 'arquivo.pdf' ) )
			: Leticia_Roteiro::responder( $estado, $campo['chave'], $exemplos[ $campo['chave'] ] );

		$estado = $r['estado'];
		$n++;
	}

	return $estado;
};

// ---------------------------------------------------------------- o token

$casos[] = array(
	'grupo'    => 'rascunho · token',
	'nome'     => 'o token assinado devolve a mesma sessão',
	'executar' => function () {
		$sessao = Leticia_Rascunho::nova_sessao();
		$volta  = Leticia_Rascunho::conferir( Leticia_Rascunho::assinar( $sessao ) );

		if ( is_wp_error( $volta ) ) {
			return 'não conferiu: ' . $volta->get_error_message();
		}
		return $volta === $sessao ? null : 'voltou outra sessão';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · token',
	'nome'     => 'token adulterado não abre o briefing de outra pessoa',
	'executar' => function () {
		// Quem editar o id na mão produz uma assinatura que não confere e
		// recebe um briefing novo — nunca o de outro.
		$token = Leticia_Rascunho::assinar( Leticia_Rascunho::nova_sessao() );
		$cru   = base64_decode( strtr( $token, '-_', '+/' ) );

		list( , $expira, $firma ) = explode( '|', $cru );
		$forjado = rtrim( strtr( base64_encode( str_repeat( 'a', 32 ) . '|' . $expira . '|' . $firma ), '+/', '-_' ), '=' );

		$r = Leticia_Rascunho::conferir( $forjado );
		return is_wp_error( $r ) ? null : 'aceitou um token forjado';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · token',
	'nome'     => 'token vencido é recusado',
	'executar' => function () {
		$token = Leticia_Rascunho::assinar( Leticia_Rascunho::nova_sessao(), time() - 60 );
		$r     = Leticia_Rascunho::conferir( $token );

		if ( ! is_wp_error( $r ) ) {
			return 'aceitou token vencido';
		}
		return 'token_expirado' === $r->get_error_code() ? null : 'recusou por ' . $r->get_error_code();
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · token',
	'nome'     => 'lixo no lugar do token não derruba nada',
	'executar' => function () {
		foreach ( array( '', 'abc', '....', str_repeat( 'x', 500 ) ) as $lixo ) {
			$r = Leticia_Rascunho::conferir( $lixo );
			if ( ! is_wp_error( $r ) ) {
				return 'aceitou "' . substr( $lixo, 0, 20 ) . '"';
			}
		}
		return null;
	},
);

// ---------------------------------------------------------------- retomada

$casos[] = array(
	'grupo'    => 'rascunho · retomada',
	'nome'     => 'retoma no campo em que a pessoa parou',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, $preencher( 8 ) );

		$estado = Leticia_Rascunho::carregar( $sessao );
		if ( ! $estado ) {
			return 'não achou o rascunho';
		}
		if ( 8 !== Leticia_Roteiro::quantos_resolvidos( $estado ) ) {
			return 'voltou com ' . Leticia_Roteiro::quantos_resolvidos( $estado ) . ' respostas';
		}

		$proximo = Leticia_Roteiro::proximo( $estado );
		return 'contatos_site' === $proximo['chave'] ? null : 'retomou em ' . $proximo['chave'];
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · retomada',
	'nome'     => 'a barra de retomada diz a empresa e a etapa',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, $preencher( 8 ) );

		$resumo = Leticia_Rascunho::resumo( $sessao );
		if ( ! $resumo ) {
			return 'não montou o resumo';
		}
		if ( false === strpos( $resumo['frase'], 'Padaria Aurora' ) ) {
			return 'a frase não traz a empresa: ' . $resumo['frase'];
		}
		if ( false === strpos( $resumo['frase'], 'etapa 2' ) ) {
			return 'a frase não traz a etapa: ' . $resumo['frase'];
		}
		return 8 === $resumo['respondidos'] ? null : 'contou errado as respostas';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · retomada',
	'nome'     => 'sem o nome da empresa, a frase continua fazendo sentido',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, $preencher( 1 ) );   // só o responsável

		$resumo = Leticia_Rascunho::resumo( $sessao );
		if ( ! $resumo ) {
			return 'não montou o resumo';
		}
		return false === strpos( $resumo['frase'], 'da .' ) ? null : 'sobrou buraco na frase: ' . $resumo['frase'];
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · retomada',
	'nome'     => 'briefing sem nenhuma resposta não oferece retomada',
	'executar' => function () use ( $zerar ) {
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, Leticia_Roteiro::novo( 1 ) );

		return null === Leticia_Rascunho::resumo( $sessao ) ? null : 'ofereceu retomar um briefing vazio';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · retomada',
	'nome'     => 'briefing já enviado não é oferecido como rascunho',
	'executar' => function () use ( $zerar, $preencher ) {
		// Retomar um enviado faria a pessoa preencher de novo o que a equipe
		// já recebeu — e provavelmente gerar um segundo briefing.
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, Leticia_Roteiro::marcar_enviado( $preencher( 12 ) ) );

		if ( null !== Leticia_Rascunho::carregar( $sessao ) ) {
			return 'ofereceu retomar um briefing enviado';
		}
		return null === Leticia_Rascunho::resumo( $sessao ) ? null : 'a barra apareceu mesmo assim';
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · retomada',
	'nome'     => 'o que está guardado é saneado ao voltar',
	'executar' => function () use ( $zerar ) {
		// O que está no banco pode ter sido escrito por uma versão anterior,
		// com campo que não existe mais. Confiar no banco é o mesmo erro que
		// confiar no navegador, só que mais difícil de perceber.
		$armazem = $zerar();
		$sessao  = Leticia_Rascunho::nova_sessao();

		$armazem->gravar_briefing(
			$sessao,
			array(
				'respostas' => array(
					'ramo'      => array( 'valor' => 'padaria' ),
					'orcamento' => array( 'valor' => 'R$ 500' ),
				),
			)
		);

		$estado = Leticia_Rascunho::carregar( $sessao );
		if ( ! $estado ) {
			return 'não carregou';
		}
		if ( isset( $estado['respostas']['orcamento'] ) ) {
			return 'deixou entrar um campo que não existe';
		}
		return Leticia_Roteiro::resolvido( $estado, 'ramo' ) ? null : 'descartou a resposta boa junto';
	},
);

// ------------------------------------------------------------ começar do zero

$casos[] = array(
	'grupo'    => 'rascunho · descarte',
	'nome'     => '"começar do zero" apaga o briefing e a conversa',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, $preencher( 6 ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'ramo', 'texto' => 'padaria' ) );

		Leticia_Rascunho::descartar( $sessao );

		if ( null !== Leticia_Rascunho::carregar( $sessao ) ) {
			return 'o briefing continuou lá';
		}
		return Leticia_Registro::conversa( $sessao ) ? 'a conversa ficou para trás' : null;
	},
);

$casos[] = array(
	'grupo'    => 'rascunho · descarte',
	'nome'     => 'sessão inválida não apaga nada',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$sessao = Leticia_Rascunho::nova_sessao();
		Leticia_Rascunho::salvar( $sessao, $preencher( 3 ) );

		Leticia_Rascunho::descartar( 'não-é-uma-sessão' );

		return null !== Leticia_Rascunho::carregar( $sessao ) ? null : 'apagou o briefing errado';
	},
);

return $casos;
