<?php
/**
 * A entrega.
 *
 * Duas coisas estão sendo protegidas aqui, e a segunda é a que custa caro
 * quando falha:
 *
 *   1. O formato — assunto, rótulos e ordem — é o que a equipe já lê hoje.
 *      Quem abre esse e-mail abre dezenas deles e sabe onde cada informação
 *      fica; diagramação nova custa atenção em cima da hora.
 *   2. **E-mail que falha não pode perder briefing.** Ele já está gravado. A
 *      falha vira fila e vira linha no painel, nunca um cliente que preencheu
 *      quinze campos para ninguém.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$zerar = function () {
	$caminho = sys_get_temp_dir() . '/leticia-teste-entrega-' . getmypid() . '.json';
	if ( file_exists( $caminho ) ) {
		unlink( $caminho );
	}
	$armazem = new Leticia_Armazem_Json( $caminho );
	$armazem->instalar();
	Leticia_Registro::usar_armazem( $armazem );
	leticia_zerar_emails();
	leticia_zerar_acoes();

	update_option(
		Leticia_Config::OPCAO,
		array(
			'REMETENTE'      => 'formulario@example.com',
			'REMETENTE_NOME' => 'Formulário JoinVix',
			'DESTINO'        => "briefing@example.com
equipe@example.com
direcao@example.com",
			'ASSUNTO'        => '[SITE EXPRESS] Novo briefing - [DOMINIO]',
		)
	);

	return $armazem;
};

/** Um briefing completo, com as respostas de um cliente de verdade. */
$briefing = function ( array $troca = array() ) {
	$respostas = array_merge(
		array(
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
			'imagens_ia'     => 'nao',
		),
		$troca
	);

	$estado = Leticia_Roteiro::novo( 5 );

	foreach ( Leticia_Campos::todos() as $campo ) {
		$chave = $campo['chave'];

		if ( 'arquivo' === $campo['tipo'] ) {
			$extra = 'logo' === $chave
				? array( 'valor' => 'logo-aurora.pdf', 'arquivos' => array( array( 'id' => 'abc123', 'nome' => 'logo-aurora.pdf' ) ) )
				: array();
			if ( 'logo' === $chave && isset( $troca['logo_pendente'] ) ) {
				$extra = array( 'pendente' => true );
			}
			$estado = Leticia_Roteiro::responder( $estado, $chave, '', $extra )['estado'];
			continue;
		}

		if ( ! isset( $respostas[ $chave ] ) ) {
			continue;
		}
		$estado = Leticia_Roteiro::responder( $estado, $chave, $respostas[ $chave ] )['estado'];
	}

	return Leticia_Roteiro::marcar_enviado( $estado );
};

// ----------------------------------------------------------------- assunto

$casos[] = array(
	'grupo'    => 'entrega · assunto',
	'nome'     => 'o assunto é o de hoje, com o domínio do cliente',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$assunto = Leticia_Entrega::assunto( $briefing() );

		return '[SITE EXPRESS] Novo briefing - padariaaurora.com.br' === $assunto
			? null
			: 'saiu "' . $assunto . '"';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · assunto',
	'nome'     => 'sem domínio, o assunto traz a empresa e avisa da pendência',
	'executar' => function () use ( $zerar, $briefing ) {
		// "Novo briefing - ainda não tenho" não ajuda ninguém a achar o e-mail
		// depois — e a pendência de domínio é o que segura o prazo.
		$zerar();
		$assunto = Leticia_Entrega::assunto( $briefing( array( 'dominio' => 'ainda não tenho' ) ) );

		if ( 0 !== strpos( $assunto, '[SITE EXPRESS] Novo briefing - ' ) ) {
			return 'mudou o prefixo: ' . $assunto;
		}
		if ( false === strpos( $assunto, 'Padaria Aurora' ) ) {
			return 'não trouxe a empresa: ' . $assunto;
		}
		return false !== strpos( $assunto, 'sem domínio' ) ? null : 'não avisou da pendência: ' . $assunto;
	},
);

$casos[] = array(
	'grupo'    => 'entrega · assunto',
	'nome'     => 'o prefixo é byte a byte o de hoje',
	'executar' => function () use ( $zerar, $briefing ) {
		// Se alguém tiver filtro ou marcador no Gmail apontando para ele,
		// continua funcionando.
		$zerar();
		return 0 === strpos( Leticia_Entrega::assunto( $briefing() ), '[SITE EXPRESS] Novo briefing - ' )
			? null
			: 'o prefixo mudou';
	},
);

// -------------------------------------------------------------------- corpo

/** O corpo em texto, para os casos lerem sem marcação. */
$texto = function ( $html ) {
	return Leticia_Email::texto( $html );
};

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'as respostas saem em blocos, com rótulo e valor',
	'executar' => function () use ( $zerar, $briefing, $texto ) {
		$zerar();
		$corpo = $texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing() ) );

		foreach ( array( '1. Contato e aprovação', '2. O negócio', '3. Arquivos' ) as $bloco ) {
			if ( false === stripos( $corpo, $bloco ) ) {
				return 'faltou o bloco ' . $bloco;
			}
		}
		$esperados = array(
			'Responsável  Marina Alves',
			'WhatsApp do responsável  (47) 99999-8888',
			'Domínio do site  padariaaurora.com.br',
			'Serviços  pães, bolos e café da manhã',
			'Contatos exibidos no site  WhatsApp (47) 99999-8888',
		);
		foreach ( $esperados as $linha ) {
			if ( false === strpos( $corpo, $linha ) ) {
				return 'faltou a linha: ' . $linha;
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'a ordem dos campos é a do briefing',
	'executar' => function () use ( $zerar, $briefing, $texto ) {
		$zerar();
		$corpo    = $texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing() ) );
		$anterior = -1;
		foreach ( Leticia_Campos::todos() as $campo ) {
			$onde = strpos( $corpo, $campo['rotulo'] );
			if ( false === $onde ) {
				return 'sumiu o rótulo: ' . $campo['rotulo'];
			}
			if ( $onde < $anterior ) {
				return 'fora de ordem: ' . $campo['rotulo'];
			}
			$anterior = $onde;
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'o que veio do cliente sai escapado no HTML',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$corpo = Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing( array( 'ramo' => '<script>alert(1)</script> padaria' ) ) );
		return false === strpos( $corpo, '<script>' ) ? null : 'o script do cliente entrou cru no e-mail';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'a escolha de imagens sai por extenso, não como "nao"',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$corpo = Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing() );
		return false !== strpos( $corpo, 'Não, só as minhas' ) ? null : 'saiu o valor cru';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'as pendências vêm no topo, antes de tudo',
	'executar' => function () use ( $zerar, $briefing, $texto ) {
		$zerar();
		$estado = $briefing( array( 'dominio' => 'ainda não tenho', 'logo_pendente' => true ) );
		$corpo  = $texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $estado ) );

		$atencao = strpos( $corpo, 'Atenção: o prazo de 72 horas ainda não começou' );
		if ( false === $atencao ) {
			return 'não destacou nada';
		}
		if ( $atencao > strpos( $corpo, '1. Contato e aprovação' ) ) {
			return 'o destaque veio depois das respostas';
		}
		if ( false === strpos( $corpo, '- Logomarca: não veio' ) || false === strpos( $corpo, '- Domínio: ainda não existe' ) ) {
			return 'faltou uma das duas pendências';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'sem pendência, o e-mail diz que o prazo começou',
	'executar' => function () use ( $zerar, $briefing, $texto ) {
		$zerar();
		$corpo = $texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing() ) );
		return false !== strpos( $corpo, 'Material completo: as 72 horas começam agora' ) ? null : 'não disse que o prazo começou';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'o técnico vai no rodapé, depois das respostas',
	'executar' => function () use ( $zerar, $briefing, $texto ) {
		$zerar();
		$corpo = $texto( Leticia_Entrega::corpo_equipe(
			str_repeat( 'a', 32 ),
			$briefing(),
			array( 'pagina' => 'https://formularios.joinvix.com.br/site-express/', 'ip' => '203.0.113.42', 'agente' => 'Mozilla/5.0' )
		) );

		foreach ( array( 'Data:', 'Horário:', 'URL da página:', 'Agente de usuário:', 'IP remoto:', 'Ver no painel:' ) as $rotulo ) {
			if ( false === strpos( $corpo, $rotulo ) ) {
				return 'faltou ' . $rotulo;
			}
		}
		return strpos( $corpo, 'IP remoto: 203.0.113.42' ) > strpos( $corpo, '3. Arquivos' ) ? null : 'o técnico veio antes das respostas';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'o arquivo sai com o nome, e nada de link público',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$corpo = Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing() );
		if ( false === strpos( $corpo, 'logo-aurora.pdf' ) ) {
			return 'o nome do arquivo não apareceu';
		}
		return false === strpos( $corpo, 'wp-content/uploads' ) ? null : 'vazou link público';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'arquivo pendente aparece como pendente, não como vazio',
	'executar' => function () use ( $zerar, $briefing, $texto ) {
		$zerar();
		$corpo = $texto( Leticia_Entrega::corpo_equipe( str_repeat( 'a', 32 ), $briefing( array( 'logo_pendente' => true ) ) ) );
		return false !== strpos( $corpo, 'Logomarca  Pendente — o cliente manda depois pelo link' ) ? null : 'a logo pendente saiu como campo em branco';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · corpo',
	'nome'     => 'o e-mail vai como HTML',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'b', 32 );
		$estado = $briefing();
		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );
		$emails = leticia_emails_enviados();
		return in_array( 'Content-Type: text/html; charset=UTF-8', $emails[0]['cabecalhos'], true ) ? null : 'saiu sem o cabeçalho de HTML';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · envio',
	'nome'     => 'sai de sites@ para os três da equipe, e marca como entregue',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();

		Leticia_Registro::salvar( $sessao, $estado );
		$r = Leticia_Entrega::enviar( $sessao, $estado );

		if ( ! $r['ok'] ) {
			return 'não enviou: ' . $r['erro'];
		}

		$emails = leticia_emails_enviados();
		if ( ! $emails ) {
			return 'nenhum e-mail saiu';
		}
		$para = (array) $emails[0]['para'];
		$esperados = array( 'briefing@example.com', 'equipe@example.com', 'direcao@example.com' );
		if ( $para !== $esperados ) {
			return 'foi para ' . implode( ', ', $para );
		}

		// O remetente é a caixa do formulário. Sem o cabeçalho From, o
		// WordPress manda como wordpress@dominio.
		$cabecalhos = implode( "
", $emails[0]['cabecalhos'] );
		if ( false === strpos( $cabecalhos, 'From: Formulário JoinVix <formulario@example.com>' ) ) {
			return 'o remetente não é a caixa do formulário: ' . $cabecalhos;
		}

		return Leticia_Registro::briefing( $sessao )['entregue'] ? null : 'não marcou como entregue';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · envio',
	'nome'     => 'o cliente que deixou e-mail recebe cópia, sem IP nem link',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();

		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado, array( 'ip' => '203.0.113.42' ) );

		$emails = leticia_emails_enviados();
		if ( 2 !== count( $emails ) ) {
			return 'saíram ' . count( $emails ) . ' e-mails';
		}
		$copia = $emails[1];
		if ( 'marina@padariaaurora.com.br' !== $copia['para'] ) {
			return 'a cópia foi para ' . $copia['para'];
		}
		// Mandar de volta o IP da pessoa para a própria pessoa só assusta.
		if ( false !== strpos( $copia['corpo'], '203.0.113.42' ) ) {
			return 'a cópia levou o IP do cliente';
		}
		return false === strpos( $copia['corpo'], 'wp-admin' ) ? null : 'a cópia levou link do painel';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · envio',
	'nome'     => 'sem e-mail do cliente, só o da equipe sai',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();
		unset( $estado['respostas']['email'] );

		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );

		return 1 === count( leticia_emails_enviados() ) ? null : 'mandou cópia para ninguém';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · envio',
	'nome'     => 'responder o e-mail cai no cliente',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();

		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );

		$cabecalhos = implode( "\n", leticia_emails_enviados()[0]['cabecalhos'] );
		return false !== strpos( $cabecalhos, 'Reply-To: Marina Alves <marina@padariaaurora.com.br>' )
			? null
			: 'sem Reply-To: ' . $cabecalhos;
	},
);

// ------------------------------------------------------------ quando falha

$casos[] = array(
	'grupo'    => 'entrega · falha',
	'nome'     => 'e-mail que falha não perde o briefing',
	'executar' => function () use ( $zerar, $briefing ) {
		// Ele já está gravado. O que falhou foi o aviso.
		$zerar();
		$GLOBALS['leticia_email_falha'] = true;

		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();

		Leticia_Registro::salvar( $sessao, $estado );
		$r = Leticia_Entrega::enviar( $sessao, $estado );

		$GLOBALS['leticia_email_falha'] = false;

		if ( $r['ok'] ) {
			return 'disse que enviou';
		}

		$linha = Leticia_Registro::briefing( $sessao );
		if ( ! $linha ) {
			return 'o briefing sumiu junto com o e-mail';
		}
		if ( $linha['entregue'] ) {
			return 'marcou como entregue mesmo tendo falhado';
		}
		return 1 === (int) $linha['tentativas'] ? null : 'não contou a tentativa';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · falha',
	'nome'     => 'a falha entra na fila de retentativa',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$GLOBALS['leticia_email_falha'] = true;

		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();
		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );

		$GLOBALS['leticia_email_falha'] = false;

		$fila = leticia_agendamentos();
		if ( ! $fila ) {
			return 'não agendou nada';
		}
		return Leticia_Entrega::CRON_REENTREGA === $fila[0]['tag'] ? null : 'agendou outra coisa';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · falha',
	'nome'     => 'o briefing não entregue aparece no painel',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$GLOBALS['leticia_email_falha'] = true;

		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();
		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );

		$GLOBALS['leticia_email_falha'] = false;

		$parados = Leticia_Registro::nao_entregues();
		return 1 === count( $parados ) ? null : 'o painel não viu o trabalho parado';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · falha',
	'nome'     => 'a retentativa reenvia a partir do que está gravado',
	'executar' => function () use ( $zerar, $briefing ) {
		$zerar();
		$GLOBALS['leticia_email_falha'] = true;

		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();
		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );

		// O servidor de e-mail voltou.
		$GLOBALS['leticia_email_falha'] = false;
		leticia_zerar_emails();

		$r = Leticia_Entrega::retentar( $sessao );

		if ( ! $r || ! $r['ok'] ) {
			return 'a retentativa não entregou';
		}
		$emails = leticia_emails_enviados();
		if ( ! $emails ) {
			return 'nenhum e-mail saiu na retentativa';
		}
		// E o conteúdo veio do banco, não de uma sessão que já acabou.
		return false !== strpos( $emails[0]['corpo'], 'Padaria Aurora' ) ? null : 'o e-mail saiu vazio';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · falha',
	'nome'     => 'briefing já entregue não é reenviado pela fila',
	'executar' => function () use ( $zerar, $briefing ) {
		// É assim que uma fila de retry vira spam na caixa da equipe.
		$zerar();
		$sessao = str_repeat( 'a', 32 );
		$estado = $briefing();

		Leticia_Registro::salvar( $sessao, $estado );
		Leticia_Entrega::enviar( $sessao, $estado );
		leticia_zerar_emails();

		Leticia_Entrega::retentar( $sessao );

		return leticia_emails_enviados() ? 'mandou o mesmo briefing duas vezes' : null;
	},
);

$casos[] = array(
	'grupo'    => 'entrega · falha',
	'nome'     => 'depois de cinco tentativas, para de insistir',
	'executar' => function () use ( $zerar ) {
		leticia_zerar_emails();
		leticia_zerar_acoes();

		$agendou = Leticia_Entrega::agendar_retentativa( str_repeat( 'a', 32 ), 6 );
		if ( $agendou ) {
			return 'continuou agendando';
		}
		// E avisa, em vez de sumir calado: a partir daqui é decisão de gente.
		return leticia_acoes_disparadas( 'leticia_entrega_desistiu' ) ? null : 'desistiu sem avisar';
	},
);

// ------------------------------------------------------------------ anexos

/** Sobe um arquivo de verdade para a sessão, e devolve o metadado. */
$arquivo_real = function ( $sessao, $campo, $nome, $bytes ) {
	$conteudo = "PK\x03\x04" . str_repeat( 'x', $bytes - 4 );
	$abertura = Leticia_Arquivos::iniciar( $sessao, $campo, $nome, strlen( $conteudo ), 1 );
	Leticia_Arquivos::receber( $sessao, $abertura['id'], 0, $conteudo );
	return Leticia_Arquivos::concluir( $sessao, $abertura['id'] );
};

/** Um briefing enviado com três ZIPs de 600 KB nos materiais. */
$com_tres_arquivos = function ( $sessao ) use ( $briefing, $arquivo_real ) {
	$estado = $briefing();
	$metas  = array();
	foreach ( array( 'fotos-1.zip', 'fotos-2.zip', 'fotos-3.zip' ) as $nome ) {
		$metas[] = $arquivo_real( $sessao, 'materiais', $nome, 600 * 1024 );
	}
	$estado['respostas']['materiais']['arquivos'] = $metas;
	$estado = Leticia_Roteiro::marcar_enviado( $estado );
	Leticia_Registro::salvar( $sessao, $estado );
	return $estado;
};

$casos[] = array(
	'grupo'    => 'entrega · anexos',
	'nome'     => 'o que não cabe num e-mail vai no seguinte, numerado',
	'executar' => function () use ( $zerar, $com_tres_arquivos ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'TETO_EMAIL_MB' => '1' ) ) );
		$sessao = str_repeat( '4', 32 );
		$estado = $com_tres_arquivos( $sessao );

		Leticia_Entrega::enviar( $sessao, $estado );

		$equipe = array_values( array_filter( leticia_emails_enviados(), function ( $e ) {
			return false !== strpos( implode( ',', (array) $e['para'] ), 'briefing@' );
		} ) );
		if ( 3 !== count( $equipe ) ) {
			return 'saíram ' . count( $equipe ) . ' e-mails para a equipe';
		}
		foreach ( $equipe as $i => $email ) {
			if ( 1 !== count( $email['anexos'] ) ) {
				return 'o e-mail ' . ( $i + 1 ) . ' levou ' . count( $email['anexos'] ) . ' anexos';
			}
			if ( false === strpos( $email['assunto'], sprintf( '(e-mail %d de 3)', $i + 1 ) ) ) {
				return 'assunto sem numeração: ' . $email['assunto'];
			}
		}
		if ( false === strpos( $equipe[0]['corpo'], 'fotos-3.zip (anexado no e-mail 3 de 3)' ) ) {
			return 'o primeiro e-mail não diz onde está cada arquivo';
		}
		return false === strpos( $equipe[0]['corpo'], 'acao=baixar' ) ? null : 'o e-mail ainda aponta para download no servidor';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · anexos',
	'nome'     => 'entregue, os arquivos saem do servidor',
	'executar' => function () use ( $zerar, $com_tres_arquivos ) {
		$zerar();
		$sessao = str_repeat( '5', 32 );
		$estado = $com_tres_arquivos( $sessao );
		foreach ( $estado['respostas']['materiais']['arquivos'] as $meta ) {
			if ( ! Leticia_Arquivos::existe( $meta ) ) {
				return 'o arquivo nem chegou a existir';
			}
		}
		Leticia_Entrega::enviar( $sessao, $estado );
		foreach ( $estado['respostas']['materiais']['arquivos'] as $meta ) {
			if ( Leticia_Arquivos::existe( $meta ) ) {
				return 'ficou no servidor depois da entrega: ' . $meta['nome'];
			}
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'entrega · anexos',
	'nome'     => 'se um e-mail falha, os arquivos ficam e a tentativa seguinte não repete os que saíram',
	'executar' => function () use ( $zerar, $com_tres_arquivos ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'TETO_EMAIL_MB' => '1' ) ) );
		$sessao = str_repeat( '6', 32 );
		$estado = $com_tres_arquivos( $sessao );

		// O servidor aceita o primeiro e recusa o segundo.
		$GLOBALS['leticia_email_limite'] = 1;
		$r = Leticia_Entrega::enviar( $sessao, $estado );
		unset( $GLOBALS['leticia_email_limite'] );

		if ( $r['ok'] ) {
			return 'disse que entregou';
		}
		foreach ( $estado['respostas']['materiais']['arquivos'] as $meta ) {
			if ( ! Leticia_Arquivos::existe( $meta ) ) {
				return 'apagou arquivo de briefing não entregue';
			}
		}

		leticia_zerar_emails();
		$r = Leticia_Entrega::retentar( $sessao );
		if ( ! $r['ok'] ) {
			return 'a nova tentativa falhou: ' . $r['erro'];
		}
		$assuntos = wp_list_pluck( array_filter( leticia_emails_enviados(), function ( $e ) {
			return false !== strpos( implode( ',', (array) $e['para'] ), 'briefing@' );
		} ), 'assunto' );
		$assuntos = array_values( $assuntos );
		if ( 2 !== count( $assuntos ) || false === strpos( $assuntos[0], '(e-mail 2 de 3)' ) ) {
			return 'a nova tentativa mandou: ' . implode( ' | ', $assuntos );
		}
		return 1 === (int) Leticia_Registro::briefing( $sessao )['entregue'] ? null : 'não marcou como entregue';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · anexos',
	'nome'     => 'sem teto diário, o disjuntor nunca abre',
	'executar' => function () use ( $zerar ) {
		$zerar();
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'TETO_DIARIO' => '0' ) ) );
		Leticia_Limites::zerar();
		for ( $i = 0; $i < 5; $i++ ) {
			Leticia_Limites::registrar_chamada();
		}
		if ( Leticia_Limites::disjuntor_aberto() || null !== Leticia_Limites::restantes_hoje() ) {
			return 'sem teto, o disjuntor abriu ou contou restantes';
		}
		update_option( Leticia_Config::OPCAO, array_merge( get_option( Leticia_Config::OPCAO ), array( 'TETO_DIARIO' => '5' ) ) );
		$aberto = Leticia_Limites::disjuntor_aberto();
		Leticia_Limites::zerar();
		return $aberto ? null : 'com teto 5 e 5 chamadas, não abriu';
	},
);

$casos[] = array(
	'grupo'    => 'entrega · anexos',
	'nome'     => 'o teto diário padrão é nenhum',
	'executar' => function () {
		return '0' === Leticia_Config::padroes()['TETO_DIARIO'] ? null : 'o padrão é ' . Leticia_Config::padroes()['TETO_DIARIO'];
	},
);

return $casos;
