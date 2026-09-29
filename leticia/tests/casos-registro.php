<?php
/**
 * O registro e o rascunho.
 *
 * Estes casos gravam e leem de verdade, no armazém em arquivo. É o que o
 * `Leticia_Armazem_Json` existe para permitir: um teste que só confere se um
 * insert foi tentado não pega a coluna que ficou de fora, e coluna que fica de
 * fora é exatamente como um registro some sem ninguém notar.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

/** Um armazém limpo para cada caso: teste que herda estado mente. */
$zerar = function () {
	$caminho = sys_get_temp_dir() . '/leticia-teste-registro-' . getmypid() . '.json';
	if ( file_exists( $caminho ) ) {
		unlink( $caminho );
	}
	$armazem = new Leticia_Armazem_Json( $caminho );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	return $armazem;
};

/** Preenche N campos de um briefing. */
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

// ------------------------------------------------------------------ gravação

$casos[] = array(
	'grupo'    => 'registro · gravação',
	'nome'     => 'o briefing é gravado com o que o painel precisa ler',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$estado = $preencher( 5 );
		Leticia_Registro::salvar( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $estado );

		$linha = Leticia_Registro::briefing( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
		if ( ! $linha ) {
			return 'não gravou nada';
		}
		if ( 'Padaria Aurora' !== $linha['empresa'] ) {
			return 'a empresa não foi extraída: ' . $linha['empresa'];
		}
		if ( '(47) 99999-8888' !== $linha['whatsapp'] ) {
			return 'o WhatsApp não foi extraído: ' . $linha['whatsapp'];
		}
		if ( 5 !== (int) $linha['respondidos'] ) {
			return 'contou ' . $linha['respondidos'] . ' respostas';
		}
		// A coluna existe para a pergunta "onde as pessoas param" ser um
		// agrupamento, e não uma varredura de JSON linha a linha.
		return 'ramo' === $linha['campo_parado'] ? null : 'campo_parado veio "' . $linha['campo_parado'] . '"';
	},
);

$casos[] = array(
	'grupo'    => 'registro · gravação',
	'nome'     => 'um briefing por sessão, mesmo salvando muitas vezes',
	'executar' => function () use ( $zerar, $preencher ) {
		$armazem = $zerar();
		$sessao  = str_repeat( 'b', 32 );

		for ( $n = 1; $n <= 5; $n++ ) {
			Leticia_Registro::salvar( $sessao, $preencher( $n ) );
		}

		$todos = $armazem->listar_briefings();
		if ( 1 !== count( $todos ) ) {
			return 'gravou ' . count( $todos ) . ' briefings para a mesma sessão';
		}
		return 5 === (int) $todos[0]['respondidos'] ? null : 'o último salvamento não valeu';
	},
);

$casos[] = array(
	'grupo'    => 'registro · gravação',
	'nome'     => 'clicar duas vezes em enviar não reescreve a hora do envio',
	'executar' => function () use ( $zerar, $preencher ) {
		// É por esse carimbo que a equipe conta as 72 horas.
		$armazem = $zerar();
		$sessao  = str_repeat( 'c', 32 );
		$estado  = Leticia_Roteiro::marcar_enviado( $preencher( 12 ) );

		Leticia_Registro::salvar( $sessao, $estado );
		if ( Leticia_Registro::briefing( $sessao )['enviado_em'] < 1 ) {
			return 'não carimbou o envio';
		}

		// Envelhecer o carimbo direto no arquivo torna o caso determinístico:
		// com um sleep de um segundo ele custaria mais que a suíte inteira, e
		// suíte lenta é suíte que ninguém roda.
		$caminho = $armazem->caminho();
		$dados   = json_decode( file_get_contents( $caminho ), true );
		$dados['briefings'][ $sessao ]['enviado_em'] = 1000000000;
		file_put_contents( $caminho, json_encode( $dados ) );
		$armazem->esquecer();

		Leticia_Registro::salvar( $sessao, $estado );

		return 1000000000 === (int) Leticia_Registro::briefing( $sessao )['enviado_em']
			? null
			: 'o carimbo mudou no segundo clique';
	},
);

$casos[] = array(
	'grupo'    => 'registro · gravação',
	'nome'     => 'a data de criação não é reescrita a cada salvamento',
	'executar' => function () use ( $zerar, $preencher ) {
		$armazem = $zerar();
		$sessao  = str_repeat( 'd', 32 );

		Leticia_Registro::salvar( $sessao, $preencher( 2 ) );

		$caminho = $armazem->caminho();
		$dados   = json_decode( file_get_contents( $caminho ), true );
		$dados['briefings'][ $sessao ]['criado_em'] = 1000000000;
		file_put_contents( $caminho, json_encode( $dados ) );
		$armazem->esquecer();

		Leticia_Registro::salvar( $sessao, $preencher( 4 ) );

		return 1000000000 === (int) Leticia_Registro::briefing( $sessao )['criado_em']
			? null
			: 'o briefing rejuvenesceu';
	},
);

// ------------------------------------------------------------- onde param

$casos[] = array(
	'grupo'    => 'registro · abandono',
	'nome'     => 'o abandono é agrupado pelo campo em que a pessoa parou',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();

		// Três pessoas param no ramo de atividade, uma no domínio.
		foreach ( array( 5, 5, 5, 4 ) as $i => $quantos ) {
			Leticia_Registro::salvar( str_repeat( (string) $i, 32 ), $preencher( $quantos ) );
		}

		$conta = Leticia_Registro::abandono_por_campo();

		if ( empty( $conta['ramo'] ) || 3 !== $conta['ramo'] ) {
			return 'contou ' . ( isset( $conta['ramo'] ) ? $conta['ramo'] : 0 ) . ' paradas no ramo';
		}
		// E a ordem importa: o painel mostra o pior primeiro.
		return 'ramo' === array_key_first( $conta ) ? null : 'o campo que mais mata não veio primeiro';
	},
);

$casos[] = array(
	'grupo'    => 'registro · abandono',
	'nome'     => 'quem enviou não conta como abandono',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		Leticia_Registro::salvar( str_repeat( 'e', 32 ), Leticia_Roteiro::marcar_enviado( $preencher( 12 ) ) );

		return Leticia_Registro::abandono_por_campo() ? 'contou um briefing enviado' : null;
	},
);

$casos[] = array(
	'grupo'    => 'registro · abandono',
	'nome'     => 'quem abriu e fechou sem digitar nada não entra na conta',
	'executar' => function () use ( $zerar ) {
		// Contá-lo empilharia ruído no primeiro campo, que é justamente onde
		// ele mais atrapalha a leitura do relatório.
		$zerar();
		Leticia_Registro::salvar( str_repeat( 'f', 32 ), Leticia_Roteiro::novo( 1 ) );

		return Leticia_Registro::abandono_por_campo() ? 'contou quem nem começou' : null;
	},
);

// -------------------------------------------------------------- dúvidas

$casos[] = array(
	'grupo'    => 'registro · dúvidas',
	'nome'     => 'dúvida e fora de escopo contam, resposta não',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();
		$sessao = str_repeat( '9', 32 );
		Leticia_Registro::salvar( $sessao, $preencher( 5 ) );

		Leticia_Registro::turno( $sessao, array( 'campo' => 'dominio', 'tipo' => 'duvida', 'texto' => 'o que é domínio?' ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'dominio', 'tipo' => 'duvida', 'texto' => 'e se eu não tiver?' ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'ramo', 'tipo' => 'fora_de_escopo', 'texto' => 'quanto custa?' ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'ramo', 'tipo' => 'resposta', 'texto' => 'padaria' ) );

		$conta = Leticia_Registro::duvidas_por_campo();

		if ( empty( $conta['dominio'] ) || 2 !== $conta['dominio'] ) {
			return 'domínio contou ' . ( isset( $conta['dominio'] ) ? $conta['dominio'] : 0 );
		}
		return ( isset( $conta['ramo'] ) && 1 === $conta['ramo'] ) ? null : 'fora de escopo não contou';
	},
);

$casos[] = array(
	'grupo'    => 'registro · resumo',
	'nome'     => 'a faixa de atenção separa o que exige decisão',
	'executar' => function () use ( $zerar, $preencher ) {
		$zerar();

		// Um enviado e não entregue: é a única linha que representa trabalho
		// parado de verdade.
		$enviado = Leticia_Roteiro::marcar_enviado( $preencher( 12 ) );
		Leticia_Registro::salvar( str_repeat( 'a', 32 ), $enviado );

		// Um abandonado no meio.
		Leticia_Registro::salvar( str_repeat( 'b', 32 ), $preencher( 5 ) );

		Leticia_Registro::turno( str_repeat( 'b', 32 ), array( 'campo' => 'ramo', 'bloqueio' => 'valor em dinheiro' ) );
		Leticia_Registro::turno( str_repeat( 'b', 32 ), array( 'campo' => 'ramo', 'degradado' => 1 ) );

		$r = Leticia_Registro::resumo();

		if ( 1 !== $r['enviados'] || 1 !== $r['abandonados'] ) {
			return sprintf( 'enviados %d, abandonados %d', $r['enviados'], $r['abandonados'] );
		}
		if ( 1 !== $r['nao_entregues'] ) {
			return 'não viu o briefing gravado cujo e-mail não saiu';
		}
		if ( 1 !== $r['bloqueios'] || 1 !== $r['degradados'] ) {
			return 'perdeu bloqueio ou degradado';
		}
		return null;
	},
);

// -------------------------------------------------------------- expurgo

$casos[] = array(
	'grupo'    => 'registro · expurgo',
	'nome'     => 'a conversa sai depois de noventa dias, o briefing enviado fica',
	'executar' => function () use ( $zerar, $preencher ) {
		// A conversa é diagnóstico e vira dado pessoal parado. O briefing
		// enviado é a entrega: apagar sozinho seria apagar o que a equipe
		// recebeu.
		$armazem = $zerar();
		$sessao  = str_repeat( '7', 32 );
		$velho   = time() - ( 100 * DAY_IN_SECONDS );

		Leticia_Registro::salvar( $sessao, Leticia_Roteiro::marcar_enviado( $preencher( 12 ) ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'ramo', 'texto' => 'antiga', 'criado_em' => $velho ) );
		Leticia_Registro::turno( $sessao, array( 'campo' => 'ramo', 'texto' => 'de hoje' ) );

		$saiu = Leticia_Registro::expurgar();

		if ( 1 !== $saiu['turnos'] ) {
			return 'apagou ' . $saiu['turnos'] . ' turnos';
		}
		if ( ! Leticia_Registro::briefing( $sessao ) ) {
			return 'apagou um briefing enviado';
		}
		$restantes = Leticia_Registro::conversa( $sessao );
		return 1 === count( $restantes ) && 'de hoje' === $restantes[0]['texto'] ? null : 'apagou o turno errado';
	},
);

$casos[] = array(
	'grupo'    => 'registro · expurgo',
	'nome'     => 'briefing abandonado antigo sai, o recente fica',
	'executar' => function () use ( $zerar, $preencher ) {
		$armazem = $zerar();

		$antigo  = str_repeat( '1', 32 );
		$recente = str_repeat( '2', 32 );

		Leticia_Registro::salvar( $antigo, $preencher( 4 ) );
		Leticia_Registro::salvar( $recente, $preencher( 4 ) );

		// gravar_briefing sempre carimba a hora de agora, e está certo que
		// carimbe: não existe caminho de produção que envelheça uma linha. Para
		// o caso fazer sentido, a idade é forjada direto no arquivo.
		$caminho = $armazem->caminho();
		$dados   = json_decode( file_get_contents( $caminho ), true );
		$dados['briefings'][ $antigo ]['atualizado_em'] = time() - ( 200 * DAY_IN_SECONDS );
		file_put_contents( $caminho, json_encode( $dados ) );
		$armazem->esquecer();

		$saiu = Leticia_Registro::expurgar();

		if ( 1 !== $saiu['briefings'] ) {
			return 'apagou ' . $saiu['briefings'] . ' briefings abandonados';
		}
		if ( Leticia_Registro::briefing( $antigo ) ) {
			return 'o antigo continuou lá';
		}
		return Leticia_Registro::briefing( $recente ) ? null : 'levou o recente junto';
	},
);

return $casos;
