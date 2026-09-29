<?php
/**
 * A conversa — o que faz o briefing parecer guiado, e não um formulário.
 *
 * Nenhum destes casos chama o modelo. É de propósito: a personalidade que
 * sobrevive à IA fora do ar é a que mora na base — o nome da pessoa, a reação
 * a cada resposta, a abertura de cada etapa. Se ela depender do modelo, o modo
 * degradado volta a ser formulário.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$marcacoes = function ( $texto ) {
	preg_match_all( '/\{([a-z_]+)\}/', $texto, $m );
	return $m[1];
};

// ------------------------------------------------------------------- base

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'cada campo reage à resposta com pelo menos duas variantes',
	'executar' => function () {
		$magros = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( count( $campo['depois'] ) < 2 ) {
				$magros[] = $campo['chave'];
			}
		}
		return $magros ? 'sem reação suficiente: ' . implode( ', ', $magros ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'toda lista tem uma variante que não depende de nome',
	'executar' => function () use ( $marcacoes ) {
		// Sem isso, quem ainda não disse o nome — ou voltou e apagou — fica
		// sem pergunta nenhuma.
		$listas = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			$listas[ $campo['chave'] . '/pergunta' ] = $campo['perguntas'];
			$listas[ $campo['chave'] . '/depois' ]   = $campo['depois'];
		}
		foreach ( array( 'abertura-secao-1', 'abertura-secao-2', 'abertura-secao-3', 'anexo-pergunta', 'anexo-recebido' ) as $texto ) {
			$listas[ $texto ] = Leticia_Base::variantes( $texto );
		}

		$ruins = array();
		foreach ( $listas as $onde => $lista ) {
			$livre = false;
			foreach ( $lista as $variante ) {
				// {assistente}, {pendente} e {minutos} sempre existem onde são usados.
				if ( ! array_diff( $marcacoes( $variante ), array( 'assistente', 'pendente', 'minutos' ) ) ) {
					$livre = true;
				}
			}
			if ( ! $livre ) {
				$ruins[] = $onde;
			}
		}
		return $ruins ? 'só com {nome}/{empresa}: ' . implode( ', ', $ruins ) : null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'só existem as marcações que alguém sabe preencher',
	'executar' => function () use ( $marcacoes ) {
		$conhecidas = array_keys( Leticia_Roteiro::valores( Leticia_Roteiro::novo( 1 ) ) );
		$dados      = Leticia_Base::carregar();
		$tudo       = json_encode( $dados, JSON_UNESCAPED_UNICODE );
		$estranhas  = array_diff( array_unique( $marcacoes( $tudo ) ), $conhecidas );
		return $estranhas ? 'marcação sem valor: {' . implode( '}, {', $estranhas ) . '}' : null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'nenhum artigo antes do nome da empresa',
	'executar' => function () {
		// "a {empresa}" vira "a Rei do Pastel". Nome de empresa não tem
		// gênero garantido.
		// Só o que a LetícIA diz; o cabeçalho do arquivo cita o erro de exemplo.
		$tudo = json_encode( Leticia_Base::carregar(), JSON_UNESCAPED_UNICODE );
		return preg_match( '/\b(a|o|da|do|na|no)\s+\{empresa\}/iu', $tudo, $m ) ? 'achei "' . $m[0] . '"' : null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'reação nunca é elogio vazio',
	'executar' => function () {
		// "Ótima resposta!" depois de toda resposta vira mobília em três
		// campos — e é o que o prompt proíbe o modelo de dizer também.
		foreach ( Leticia_Campos::todos() as $campo ) {
			foreach ( $campo['depois'] as $variante ) {
				if ( preg_match( '/^(ótim|perfeit|show|excelente|que legal|entendi)/iu', $variante ) ) {
					return $campo['chave'] . ': "' . $variante . '"';
				}
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'os textos soltos da conversa existem',
	'executar' => function () {
		$faltam = array();
		foreach ( array( 'abertura-secao-1', 'depois-de-pular', 'depois-de-adiar-logo', 'depois-de-adiar-dominio', 'anexo-pergunta', 'anexo-recebido' ) as $chave ) {
			if ( ! Leticia_Base::variantes( $chave ) ) {
				$faltam[] = $chave;
			}
		}
		return $faltam ? 'faltando: ' . implode( ', ', $faltam ) : null;
	},
);

// ---------------------------------------------------------------- partes

$casos[] = array(
	'grupo'    => 'conversa · pergunta',
	'nome'     => 'a pergunta chega dividida em título e detalhe',
	'executar' => function () {
		foreach ( Leticia_Campos::todos() as $campo ) {
			for ( $semente = 0; $semente < 3; $semente++ ) {
				$p = Leticia_Campos::pergunta_partes( $campo['chave'], $semente, array( 'nome' => 'Marina', 'empresa' => 'Padaria Aurora' ) );
				if ( false !== strpos( $p['titulo'] . $p['detalhe'], '|' ) ) {
					return $campo['chave'] . ': a barra vazou para a tela';
				}
				if ( '' === $p['detalhe'] ) {
					return $campo['chave'] . ' tem variante sem detalhe: "' . $p['titulo'] . '"';
				}
				// O título é a pergunta. Explicação longa vai no detalhe — foi
				// o parágrafo inteiro no título grande que embolou a tela.
				if ( mb_strlen( $p['titulo'], 'UTF-8' ) > 90 ) {
					return $campo['chave'] . ' tem título longo demais: "' . $p['titulo'] . '"';
				}
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · pergunta',
	'nome'     => 'sem nome, a variante com {nome} dá lugar a outra',
	'executar' => function () {
		for ( $semente = 0; $semente < 6; $semente++ ) {
			$p = Leticia_Campos::pergunta_partes( 'ramo', $semente, array() );
			if ( false !== strpos( $p['titulo'], '{' ) || 0 === strpos( $p['titulo'], ',' ) ) {
				return 'saiu com buraco: "' . $p['titulo'] . '"';
			}
		}
		$com = Leticia_Base::escolher( array( '{nome}, me conta? | x', 'Me conta? | y' ), 0, array( 'nome' => 'Marina' ) );
		$sem = Leticia_Base::escolher( array( '{nome}, me conta? | x', 'Me conta? | y' ), 0, array() );
		if ( 'Marina, me conta?' !== $com['titulo'] ) {
			return 'não preencheu: ' . $com['titulo'];
		}
		return 'Me conta?' === $sem['titulo'] ? null : 'não pulou a variante com nome: ' . $sem['titulo'];
	},
);

$casos[] = array(
	'grupo'    => 'conversa · pergunta',
	'nome'     => 'o nome volta com inicial maiúscula e sem sobrenome',
	'executar' => function () {
		$casos = array( 'marina alves' => 'Marina', 'MARINA' => 'Marina', 'Ana Luíza Prado' => 'Ana', 'joão' => 'João', '' => '', 'eu' => '', 'Eu mesma' => '', 'Dra. Marina Alves' => 'Marina', '123' => '' );
		foreach ( $casos as $entrada => $esperado ) {
			$achado = Leticia_Base::primeiro_nome( $entrada );
			if ( $achado !== $esperado ) {
				return '"' . $entrada . '" virou "' . $achado . '"';
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · pergunta',
	'nome'     => 'a instrução do modelo não carrega a barra nem o {nome}',
	'executar' => function () {
		// A instrução é a mesma para todo cliente: é o que o cache reaproveita.
		foreach ( Leticia_Campos::todos() as $campo ) {
			$instrucao = Leticia_Prompt::instrucao( $campo );
			if ( preg_match( '/Pergunta feita: [^\n]*(\||\{)/u', $instrucao, $m ) ) {
				return $campo['chave'] . ': ' . $m[0];
			}
		}
		return null;
	},
);

// ------------------------------------------------------------------ ponte

$respondido = function () {
	$estado = Leticia_Roteiro::novo( 7 );
	$estado = Leticia_Roteiro::responder( $estado, 'responsavel', 'marina alves' )['estado'];
	$estado = Leticia_Roteiro::responder( $estado, 'empresa', 'Padaria Aurora' )['estado'];
	return $estado;
};

$casos[] = array(
	'grupo'    => 'conversa · ponte',
	'nome'     => 'toda resposta ganha uma reação, mesmo sem IA',
	'executar' => function () use ( $respondido ) {
		$estado = $respondido();
		foreach ( array( 'responsavel', 'empresa' ) as $chave ) {
			$ponte = Leticia_Roteiro::ponte( $estado, $chave );
			if ( '' === $ponte ) {
				return $chave . ' ficou sem reação';
			}
			if ( false !== strpos( $ponte, '{' ) ) {
				return $chave . ' saiu com marcação: ' . $ponte;
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · ponte',
	'nome'     => 'pular e adiar não recebem "anotado"',
	'executar' => function () use ( $respondido ) {
		$estado = Leticia_Roteiro::pular( $respondido(), 'email' )['estado'];
		$pulo   = Leticia_Roteiro::ponte( $estado, 'email' );
		if ( ! in_array( $pulo, Leticia_Base::variantes( 'depois-de-pular' ), true ) ) {
			return 'o pulo recebeu: ' . $pulo;
		}

		$estado = Leticia_Roteiro::responder( $estado, 'logo', '', array( 'pendente' => true ) )['estado'];
		$adiado = Leticia_Roteiro::ponte( $estado, 'logo' );
		if ( false === stripos( $adiado, 'depois' ) ) {
			return 'a logo adiada recebeu: ' . $adiado;
		}

		$estado  = Leticia_Roteiro::responder( $estado, 'dominio', 'ainda não tenho' )['estado'];
		$dominio = Leticia_Roteiro::ponte( $estado, 'dominio' );
		return false !== stripos( $dominio, 'registr' ) ? null : 'o domínio pendente recebeu: ' . $dominio;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · ponte',
	'nome'     => 'a reação usa o nome que a pessoa deu, arrumado',
	'executar' => function () use ( $respondido ) {
		$estado = $respondido();
		$vistas = '';
		for ( $s = 0; $s < 6; $s++ ) {
			$estado['semente'] = $s;
			$vistas           .= Leticia_Roteiro::ponte( $estado, 'responsavel' ) . ' ';
		}
		if ( false !== strpos( $vistas, 'marina' ) ) {
			return 'chamou pelo nome em minúscula: ' . $vistas;
		}
		return false !== strpos( $vistas, 'Marina' ) ? null : 'nenhuma variante usou o nome: ' . $vistas;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · ponte',
	'nome'     => 'a primeira pergunta vem com a apresentação dela',
	'executar' => function () {
		$falas = Leticia_Roteiro::falas_antes( Leticia_Roteiro::novo( 2 ), Leticia_Campos::por_chave( 'responsavel' ) );
		if ( ! $falas ) {
			return 'o briefing começou sem ninguém se apresentar';
		}
		return false !== strpos( $falas[0], Leticia_Config::nome() ) ? null : 'a apresentação não diz o nome dela: ' . $falas[0];
	},
);

$casos[] = array(
	'grupo'    => 'conversa · ponte',
	'nome'     => 'a reação ao botão combina com o que foi escolhido',
	'executar' => function () use ( $respondido ) {
		$sim = Leticia_Roteiro::responder( $respondido(), 'imagens_ia', 'sim' )['estado'];
		$nao = Leticia_Roteiro::responder( $respondido(), 'imagens_ia', 'nao' )['estado'];
		for ( $s = 0; $s < 4; $s++ ) {
			$sim['semente'] = $nao['semente'] = $s;
			$a = Leticia_Roteiro::ponte( $sim, 'imagens_ia' );
			$b = Leticia_Roteiro::ponte( $nao, 'imagens_ia' );
			if ( false !== strpos( $a, '(' ) || false !== strpos( $b, '(' ) ) {
				return 'a marcação da opção vazou: ' . $a . ' / ' . $b;
			}
			if ( false !== stripos( $b, 'banco' ) && false === stripos( $b, 'nada de' ) ) {
				return 'quem disse não ouviu sobre banco de imagem: ' . $b;
			}
			// Cada reação fala do que foi escolhido: o sim, de banco de imagem; o
			// não, das fotos.
			if ( false === stripos( $a, 'banco' ) || false === stripos( $b, 'foto' ) ) {
				return 'reações estranhas: ' . $a . ' / ' . $b;
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · base',
	'nome'     => 'nenhum texto supõe o gênero de quem responde',
	'executar' => function () {
		$tudo = json_encode( Leticia_Base::carregar(), JSON_UNESCAPED_UNICODE );
		if ( preg_match( '/(você mesm[oa]|obrigad[oa]|bem-vind[oa]|sozinh[oa]|preparad[oa]|pront[oa] pra)/iu', $tudo, $m ) ) {
			return 'achei "' . $m[0] . '"';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · ponte',
	'nome'     => 'na revisão ela fecha a conversa, e não reage ao último campo',
	'executar' => function () {
		$falas = Leticia_Base::variantes( 'abertura-revisao' );
		return count( $falas ) >= 2 ? null : 'a revisão tem ' . count( $falas ) . ' fechos';
	},
);

$casos[] = array(
	'grupo'    => 'conversa · pouco texto',
	'nome'     => 'detalhe, reação e abertura cabem em uma linha ou duas',
	'executar' => function () {
		$valores = array( 'nome' => 'Marina', 'empresa' => 'Padaria Aurora', 'assistente' => 'LetícIA' );
		foreach ( Leticia_Campos::todos() as $campo ) {
			foreach ( $campo['perguntas'] as $v ) {
				$p = Leticia_Base::partes( Leticia_Base::preencher( $v, $valores ) );
				if ( mb_strlen( $p['detalhe'], 'UTF-8' ) > 90 ) {
					return $campo['chave'] . ': detalhe com ' . mb_strlen( $p['detalhe'], 'UTF-8' ) . ' caracteres';
				}
				if ( mb_strlen( $p['titulo'], 'UTF-8' ) > 70 ) {
					return $campo['chave'] . ': título com ' . mb_strlen( $p['titulo'], 'UTF-8' ) . ' caracteres';
				}
			}
			foreach ( $campo['depois'] as $v ) {
				if ( mb_strlen( preg_replace( '/^\(\w+\)\s*/', '', $v ), 'UTF-8' ) > 75 ) {
					return $campo['chave'] . ': reação longa: ' . $v;
				}
			}
		}
		$dados = Leticia_Base::carregar();
		foreach ( $dados['textos'] as $chave => $texto ) {
			// Aceite e aviso de IA são texto jurídico, não fala: medem o que precisam dizer.
			if ( in_array( $chave, array( 'consentimento', 'aviso-ia', 'sem-imagens-de-banco' ), true ) ) {
				continue;
			}
			foreach ( Leticia_Base::variantes( $chave ) as $v ) {
				if ( mb_strlen( Leticia_Base::corrida( $v ), 'UTF-8' ) > 100 ) {
					return $chave . ': ' . mb_strlen( Leticia_Base::corrida( $v ), 'UTF-8' ) . ' caracteres';
				}
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'conversa · pouco texto',
	'nome'     => 'todo campo com "não tenho" tem a reação escrita',
	'executar' => function () {
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( array_key_exists( 'nega', $campo ) && ! Leticia_Base::variantes( 'depois-de-negar-' . $campo['chave'] ) ) {
				return $campo['chave'] . ' aceita "não tenho" sem ter o que dizer';
			}
			if ( $campo['obrigatorio'] && in_array( $campo['tipo'], array( 'texto', 'telefone' ), true ) && ! Leticia_Base::variantes( 'sem-resposta-' . $campo['chave'] ) ) {
				return $campo['chave'] . ' é obrigatório e não tem a fala de "não sei"';
			}
		}
		return null;
	},
);

return $casos;
