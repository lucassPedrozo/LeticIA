<?php
/**
 * A LetícIA no terminal.
 *
 *   php briefing.php              começa um briefing
 *   php briefing.php --retomar    continua o último
 *   php briefing.php --painel     mostra o que o registro sabe
 *
 * Roda o produto inteiro — roteiro, validação, condução pelo modelo, trava,
 * upload em pedaços, rascunho e registro — **sem WordPress nenhum**. É a mesma
 * ideia do protótipo de terminal que calibrou a LivIA: ver a coisa funcionando
 * antes de ela ter tela, e ajustar o tom conversando de verdade.
 *
 * Sem chave no `.env`, ele roda em modo degradado: as 15 perguntas estáticas,
 * validação de formato e envio normal, sem comentário nenhum. Isso não é uma
 * limitação do terminal — é o comportamento que o produto tem quando a API cai,
 * e poder vê-lo de perto é metade do motivo deste arquivo existir.
 */

require_once __DIR__ . '/local/wp-falso.php';

// ----------------------------------------------------------------- pintura

$cores = array(
	'apaga'  => "\033[90m",
	'forte'  => "\033[1m",
	'dela'   => "\033[36m",
	'boa'    => "\033[32m",
	'ruim'   => "\033[33m",
	'fim'    => "\033[0m",
);

function pinta( $texto, $cor ) {
	global $cores;
	return isset( $cores[ $cor ] ) ? $cores[ $cor ] . $texto . $cores['fim'] : $texto;
}

function fala( $texto ) {
	// Quebra em 76 colunas: a LetícIA escreve para ser lida, não para caber.
	foreach ( explode( "\n", wordwrap( trim( $texto ), 76, "\n", false ) ) as $linha ) {
		echo '  ' . pinta( $linha, 'dela' ) . PHP_EOL;
	}
}

function nota( $texto ) {
	echo '  ' . pinta( $texto, 'apaga' ) . PHP_EOL;
}

function erro( $texto ) {
	echo '  ' . pinta( $texto, 'ruim' ) . PHP_EOL;
}

function pergunta_na_tela( $texto ) {
	echo PHP_EOL . '  ' . pinta( $texto, 'forte' ) . PHP_EOL;
}

function ler() {
	echo PHP_EOL . '  > ';
	$linha = fgets( STDIN );
	return false === $linha ? ':sair' : trim( $linha );
}

function barra( array $estado ) {
	$p      = Leticia_Roteiro::progresso( $estado );
	$largura = 28;
	$cheio  = (int) round( $p['fracao'] * $largura );

	return sprintf(
		'  %s%s  etapa %d de %d · %d de %d',
		pinta( str_repeat( '━', $cheio ), 'boa' ),
		pinta( str_repeat( '━', $largura - $cheio ), 'apaga' ),
		$p['secao'],
		$p['secoes'],
		$p['respondidos'],
		$p['total']
	);
}

// ------------------------------------------------------------------ painel

if ( in_array( '--painel', $argv, true ) ) {
	echo PHP_EOL . pinta( '  REGISTRO', 'forte' ) . PHP_EOL . PHP_EOL;

	$resumo = Leticia_Registro::resumo();
	foreach ( $resumo as $chave => $valor ) {
		printf( "  %-16s %s\n", $chave, $valor );
	}

	$abandono = Leticia_Registro::abandono_por_campo();
	if ( $abandono ) {
		echo PHP_EOL . pinta( '  ONDE AS PESSOAS PARAM', 'forte' ) . PHP_EOL . PHP_EOL;
		foreach ( $abandono as $campo => $quantos ) {
			printf( "  %-16s %s\n", $campo, str_repeat( '▌', min( 40, $quantos ) ) . ' ' . $quantos );
		}
	}

	$duvidas = Leticia_Registro::duvidas_por_campo();
	if ( $duvidas ) {
		echo PHP_EOL . pinta( '  DÚVIDAS POR CAMPO', 'forte' ) . PHP_EOL;
		nota( '  campo com muita pergunta é campo mal escrito' );
		echo PHP_EOL;
		foreach ( $duvidas as $campo => $quantos ) {
			printf( "  %-16s %d\n", $campo, $quantos );
		}
	}

	echo PHP_EOL;
	exit( 0 );
}

// ----------------------------------------------------------------- abertura

$arquivo_sessao = LETICIA_DADOS . '/ultima-sessao.txt';
$retomando      = false;

if ( in_array( '--retomar', $argv, true ) && is_readable( $arquivo_sessao ) ) {
	$sessao = trim( file_get_contents( $arquivo_sessao ) );
	$estado = Leticia_Rascunho::carregar( $sessao );
	if ( $estado ) {
		$retomando = true;
	}
}

if ( ! $retomando ) {
	$sessao = Leticia_Rascunho::nova_sessao();
	$estado = Leticia_Roteiro::novo( Leticia_Rascunho::semente( $sessao ) );
}

file_put_contents( $arquivo_sessao, $sessao );

echo PHP_EOL;
echo pinta( '  LetícIA', 'forte' ) . pinta( ' · briefing do Site Express', 'apaga' ) . PHP_EOL;

if ( Leticia_Config::pode_comentar() ) {
	$restantes = Leticia_Limites::restantes_hoje();
	nota( '  modelo: ' . Leticia_Config::modelo() . ' · ' . ( null === $restantes ? 'sem teto diário' : 'teto de hoje: ' . $restantes . ' chamadas' ) );
} else {
	nota( '  sem chave no .env — rodando em modo degradado, sem comentários' );
}
nota( '  comandos: :voltar  :pular  :revisar  :sair' );

if ( $retomando ) {
	$resumo = Leticia_Rascunho::resumo( $sessao );
	if ( $resumo ) {
		echo PHP_EOL;
		fala( $resumo['frase'] . ' Se não for você, é só rodar sem --retomar.' );
	}
}

// ----------------------------------------------------------------- o laço

$secoes_ditas = array();

while ( true ) {

	$campo = Leticia_Roteiro::proximo( $estado );

	if ( ! $campo ) {
		break;   // acabaram os campos: hora da revisão
	}

	echo PHP_EOL . barra( $estado ) . PHP_EOL;

	// As falas que não custam chamada de API: abertura de seção e avisos.
	foreach ( Leticia_Roteiro::falas_antes( $estado, $campo ) as $antes ) {
		if ( isset( $secoes_ditas[ $antes ] ) ) {
			continue;
		}
		$secoes_ditas[ $antes ] = true;
		echo PHP_EOL;
		fala( $antes );
	}

	// Título e detalhe, como na tela: a pergunta em destaque, a explicação embaixo.
	$partes = Leticia_Campos::pergunta_partes( $campo['chave'], $estado['semente'], Leticia_Roteiro::valores( $estado ) );
	pergunta_na_tela( $partes['titulo'] );
	if ( '' !== $partes['detalhe'] ) {
		nota( '  ' . $partes['detalhe'] );
	}

	if ( 'escolha' === $campo['tipo'] ) {
		foreach ( $campo['opcoes'] as $i => $opcao ) {
			nota( sprintf( '  [%d] %s', $i + 1, $opcao['texto'] ) );
		}
	} elseif ( 'arquivo' === $campo['tipo'] ) {
		nota( '  caminho de um arquivo ' . $campo['aceita_texto'] );
		if ( ! empty( $campo['pode_ficar_pendente'] ) ) {
			nota( '  ou :depois para mandar o briefing sem ele' );
		}
	} elseif ( ! empty( $campo['dica'] ) ) {
		nota( '  ' . $campo['dica'] );
	}

	$sugestao = Leticia_Roteiro::sugestao( $estado, $campo );
	if ( '' !== $sugestao ) {
		nota( '  sugestão pronta (:sim aceita): ' . $sugestao );
	}

	$bruto = ler();

	// ------------------------------------------------------------- comandos

	if ( ':sair' === $bruto ) {
		Leticia_Rascunho::salvar( $sessao, $estado );
		echo PHP_EOL;
		nota( '  guardado. volte com: php briefing.php --retomar' );
		echo PHP_EOL;
		exit( 0 );
	}

	if ( ':voltar' === $bruto ) {
		$anterior = null;
		foreach ( Leticia_Campos::todos() as $outro ) {
			if ( $outro['chave'] === $campo['chave'] ) {
				break;
			}
			if ( Leticia_Roteiro::resolvido( $estado, $outro['chave'] ) ) {
				$anterior = $outro['chave'];
			}
		}
		if ( ! $anterior ) {
			erro( 'não há campo anterior.' );
			continue;
		}
		// Voltar reabre o campo e não reprocessa nada com o modelo.
		unset( $estado['respostas'][ $anterior ] );
		nota( '  reabri: ' . $anterior );
		continue;
	}

	if ( ':revisar' === $bruto ) {
		break;
	}

	if ( ':pular' === $bruto ) {
		$r = Leticia_Roteiro::pular( $estado, $campo['chave'] );
		if ( '' !== $r['erro'] ) {
			erro( $r['erro'] );
			continue;
		}
		$estado = $r['estado'];
		echo PHP_EOL;
		fala( Leticia_Roteiro::ponte( $estado, $campo['chave'] ) );
		Leticia_Rascunho::salvar( $sessao, $estado );
		continue;
	}

	if ( ':sim' === $bruto && '' !== $sugestao ) {
		// Botão não gasta cota: a confirmação não vai ao modelo.
		$estado = Leticia_Roteiro::responder( $estado, $campo['chave'], $sugestao )['estado'];
		Leticia_Rascunho::salvar( $sessao, $estado );
		continue;
	}

	if ( ':depois' === $bruto && ! empty( $campo['pode_ficar_pendente'] ) ) {
		$estado = Leticia_Roteiro::responder( $estado, $campo['chave'], '', array( 'pendente' => true ) )['estado'];
		echo PHP_EOL;
		fala( Leticia_Roteiro::ponte( $estado, $campo['chave'] ) );
		Leticia_Rascunho::salvar( $sessao, $estado );
		continue;
	}

	// -------------------------------------------------------------- arquivo

	if ( 'arquivo' === $campo['tipo'] ) {
		if ( ! is_readable( $bruto ) ) {
			erro( 'não achei esse arquivo aqui. Caminho completo, ou :pular.' );
			continue;
		}

		$meta = subir_arquivo( $sessao, $campo, $bruto );
		if ( is_wp_error( $meta ) ) {
			erro( $meta->get_error_message() );
			continue;
		}

		if ( '' !== $meta['aviso'] ) {
			echo PHP_EOL;
			fala( $meta['aviso'] );
		}

		$estado = Leticia_Roteiro::responder(
			$estado,
			$campo['chave'],
			'',
			array( 'valor' => $meta['nome'], 'arquivos' => array( $meta ) )
		)['estado'];

		echo PHP_EOL;
		fala( Leticia_Roteiro::ponte( $estado, $campo['chave'] ) );

		Leticia_Rascunho::salvar( $sessao, $estado, array( 'arquivos' => array( $meta ) ) );
		continue;
	}

	// ---------------------------------------------------------- escolha

	if ( 'escolha' === $campo['tipo'] && preg_match( '/^[12]$/', $bruto ) ) {
		$bruto = $campo['opcoes'][ (int) $bruto - 1 ]['valor'];
	}

	// ------------------------------------------------------- a resposta

	$validado   = Leticia_Validacao::checar( $campo, $bruto );
	$pede_ajuda = Leticia_Validacao::pede_ajuda( $bruto );
	// "Me dá uma ideia" e "o que é domínio?" vão ao modelo mesmo quando a
	// validação reprovaria: quem responde pergunta é ela.
	$pergunta_vai = ( $pede_ajuda || Leticia_Validacao::parece_duvida( $bruto ) ) && Leticia_Config::pode_comentar();
	if ( ! $validado['ok'] && ! $pergunta_vai ) {
		erro( $validado['erro'] );
		continue;
	}

	$consulta = null;
	$comeco   = microtime( true );

	// Curta demais para o campo (o ramo pede três palavras que digam algo).
	$anterior = isset( $estado['anteriores'][ $campo['chave'] ] ) ? (string) $estado['anteriores'][ $campo['chave'] ] : '';
	$curto    = $validado['ok'] && ! $pede_ajuda && Leticia_Validacao::curto( $campo, $bruto, $anterior )
		&& Leticia_Roteiro::pode_reperguntar( $estado, $campo['chave'] );

	if ( '' === Leticia_Modelo::motivo_para_poupar( $campo, $bruto, $estado, $validado ) ) {
		// Os pontinhos só quando há mesmo alguém pensando do outro lado: em
		// modo degradado a resposta é instantânea, e fingir espera seria
		// encenação.
		if ( Leticia_Config::pode_comentar() ) {
			nota( '  …' );
		}
		$consulta = Leticia_Modelo::consultar(
			$campo,
			$bruto,
			contexto_do( $estado ),
			array(
				'sessao'        => $sessao,
				'reperguntando' => ! Leticia_Roteiro::pode_reperguntar( $estado, $campo['chave'] ),
				'curto'         => $curto,
				'anterior'      => $anterior,
			)
		);
	}

	$ms = (int) round( ( microtime( true ) - $comeco ) * 1000 );

	Leticia_Registro::turno(
		$sessao,
		array(
			'campo'     => $campo['chave'],
			'papel'     => 'cliente',
			'texto'     => $bruto,
			'tipo'      => $consulta ? $consulta['tipo'] : '',
			'bloqueio'  => $consulta && $consulta['bloqueio'] ? $consulta['bloqueio'] : '',
			'degradado' => $consulta && $consulta['degradado'] ? 1 : 0,
			'ms'        => $ms,
		)
	);

	if ( $consulta && $consulta['bloqueio'] ) {
		nota( '  [trava barrou: ' . $consulta['bloqueio'] . ']' );
	}

	// Pedido de ajuda sem modelo: nunca vira resposta.
	if ( $pede_ajuda && ( ! $consulta || $consulta['degradado'] || ( 'resposta' === $consulta['tipo'] && ! $validado['ok'] ) ) ) {
		echo PHP_EOL;
		fala( Leticia_Validacao::fala_de_conducao( $campo['chave'] ) );
		continue;
	}

	// Parecia pergunta e não era: o formato segue reprovado.
	if ( ! $validado['ok'] && ( ! $consulta || $consulta['degradado'] || 'resposta' === $consulta['tipo'] ) ) {
		erro( $validado['erro'] );
		continue;
	}

	// Pedido de ajuda: rascunho para decidir, ou perguntas que destravam.
	if ( $consulta && 'ajuda' === $consulta['tipo'] ) {
		echo PHP_EOL;
		if ( $consulta['resposta_duvida'] ) {
			fala( $consulta['resposta_duvida'] );
		}
		if ( $consulta['proposta'] ) {
			$final = decidir_rascunho( $consulta['proposta'] );
			if ( '' !== $final ) {
				$r = Leticia_Roteiro::responder( $estado, $campo['chave'], $final );
				if ( '' === $r['erro'] ) {
					$estado = $r['estado'];
					$estado['respostas'][ $campo['chave'] ]['texto_site'] = $final;
					Leticia_Rascunho::salvar( $sessao, $estado );
				} else {
					erro( $r['erro'] );
				}
			}
		}
		continue;
	}

	// Dúvida e fora de escopo mantêm a pessoa no mesmo campo.
	if ( $consulta && in_array( $consulta['tipo'], array( 'duvida', 'fora_de_escopo' ), true ) ) {
		echo PHP_EOL;
		fala( $consulta['resposta_duvida'] );
		continue;
	}

	// Curta demais: fica no campo, com o pedido do modelo ou o da base.
	if ( $curto ) {
		$pedido = $consulta && ! $consulta['degradado'] && 'resposta' === $consulta['tipo'] && $consulta['repergunta']
			? $consulta['repergunta']
			: Leticia_Roteiro::fala_curta( $estado, $campo['chave'] );
		$estado = Leticia_Roteiro::marcar_repergunta( $estado, $campo['chave'] );
		$estado = Leticia_Roteiro::guardar_anterior( $estado, $campo['chave'], trim( $anterior . "
" . $bruto ) );
		echo PHP_EOL;
		fala( $pedido );
		continue;
	}

	// Repergunta: uma por campo, salvo quando o campo pede mais.
	if ( $consulta && false === $consulta['suficiente'] && Leticia_Roteiro::pode_reperguntar( $estado, $campo['chave'] ) ) {
		$estado = Leticia_Roteiro::marcar_repergunta( $estado, $campo['chave'] );
		$estado = Leticia_Roteiro::guardar_anterior( $estado, $campo['chave'], trim( $anterior . "
" . $bruto ) );
		echo PHP_EOL;
		fala( $consulta['repergunta'] );
		continue;
	}

	$r = Leticia_Roteiro::responder(
		$estado,
		$campo['chave'],
		$bruto,
		array( 'limpo' => $consulta ? $consulta['valor_limpo'] : '' )
	);

	if ( '' !== $r['erro'] ) {
		erro( $r['erro'] );
		continue;
	}

	$estado = $r['estado'];

	// O rascunho: a resposta já está gravada; o texto aprovado vai junto.
	if ( $consulta && 'resposta' === $consulta['tipo'] && $consulta['proposta'] ) {
		echo PHP_EOL;
		if ( $consulta['comentario'] ) {
			fala( $consulta['comentario'] );
		}
		$estado['respostas'][ $campo['chave'] ]['texto_site'] = decidir_rascunho( $consulta['proposta'] );
		Leticia_Rascunho::salvar( $sessao, $estado );
		continue;
	}

	// A ponte até a próxima pergunta: a reação do modelo, quando houve, ou a
	// da base. Pendência sempre com o texto da base, que diz o que muda no prazo.
	$ponte = $consulta && $consulta['comentario'] && empty( $estado['respostas'][ $campo['chave'] ]['pendente'] )
		? $consulta['comentario']
		: Leticia_Roteiro::ponte( $estado, $campo['chave'] );
	if ( '' !== $ponte ) {
		echo PHP_EOL;
		fala( $ponte );
	}

	Leticia_Rascunho::salvar( $sessao, $estado );
}

// ----------------------------------------------------------------- revisão

echo PHP_EOL . PHP_EOL . pinta( '  CONFERE ANTES DE ENVIAR', 'forte' ) . PHP_EOL . PHP_EOL;

foreach ( Leticia_Campos::todos() as $campo ) {
	$r     = isset( $estado['respostas'][ $campo['chave'] ] ) ? $estado['respostas'][ $campo['chave'] ] : null;
	$valor = $r ? trim( $r['valor'] ) : '';

	if ( $r && ! empty( $r['pendente'] ) ) {
		$valor = pinta( 'pendente', 'ruim' );
	} elseif ( '' === $valor ) {
		$valor = pinta( $campo['obrigatorio'] ? '—' : 'não informado', 'apaga' );
	}

	// O padding vai no texto puro: printf conta os códigos de cor como
	// caracteres, e a coluna sairia torta na proporção do que foi pintado.
	$rotulo = $campo['rotulo'];
	$folga  = max( 1, 28 - mb_strlen( $rotulo, 'UTF-8' ) );

	echo '  ' . pinta( $rotulo, 'apaga' ) . str_repeat( ' ', $folga ) . $valor . PHP_EOL;
}

foreach ( Leticia_Roteiro::pendencias( $estado ) as $pendencia ) {
	echo PHP_EOL;
	erro( 'pendência: ' . $pendencia . ' — segura o cronômetro das 72 horas.' );
}

if ( ! Leticia_Roteiro::pode_enviar( $estado ) ) {
	echo PHP_EOL;
	erro( 'ainda falta campo obrigatório. Rode de novo com --retomar.' );
	Leticia_Rascunho::salvar( $sessao, $estado );
	echo PHP_EOL;
	exit( 1 );
}

echo PHP_EOL;
nota( '  ' . Leticia_Base::texto( 'consentimento' ) );
echo PHP_EOL . '  aceita e envia? [s/n] ';

if ( 's' !== strtolower( trim( (string) fgets( STDIN ) ) ) ) {
	Leticia_Rascunho::salvar( $sessao, $estado );
	echo PHP_EOL;
	nota( '  guardado sem enviar.' );
	echo PHP_EOL;
	exit( 0 );
}

$estado = Leticia_Roteiro::marcar_enviado( $estado );
Leticia_Registro::salvar( $sessao, $estado );

$entrega = Leticia_Entrega::enviar(
	$sessao,
	$estado,
	array(
		'pagina' => 'terminal',
		'agente' => 'php briefing.php',
		'ip'     => '127.0.0.1',
	)
);

echo PHP_EOL;
fala( 'Chegou aqui. Está tudo com a equipe.' );

// A tela do cliente diz "recebido" mesmo quando o e-mail falha, porque foi
// recebido: o briefing já está gravado e o e-mail é notificação, não
// transporte. Quem precisa saber da falha é o painel — e, aqui, quem
// desenvolve.
if ( ! $entrega['ok'] ) {
	echo PHP_EOL;
	erro( '  [o e-mail não saiu: ' . $entrega['erro'] . ' — entrou na fila de retentativa]' );
}

foreach ( leticia_emails_enviados() as $email ) {
	echo PHP_EOL . pinta( '  ┌─ e-mail ─────────────────────────────────────────────', 'apaga' ) . PHP_EOL;
	echo '  para:    ' . $email['para'] . PHP_EOL;
	echo '  assunto: ' . pinta( $email['assunto'], 'forte' ) . PHP_EOL;
	if ( $email['anexos'] ) {
		echo '  anexos:  ' . count( $email['anexos'] ) . PHP_EOL;
	}
	echo pinta( '  ├──────────────────────────────────────────────────────', 'apaga' ) . PHP_EOL;
	// O e-mail sai em HTML; no terminal, a leitura dele em texto.
	foreach ( explode( "\n", Leticia_Email::texto( $email['corpo'] ) ) as $linha ) {
		echo '  ' . $linha . PHP_EOL;
	}
	echo pinta( '  └──────────────────────────────────────────────────────', 'apaga' ) . PHP_EOL;
}

nota( '  registro em ' . LETICIA_DADOS . '/briefings.json' );
echo PHP_EOL;

exit( 0 );

// ----------------------------------------------------------------- auxiliares

/**
 * Mostra o rascunho e pergunta o que fazer.
 *
 * @return string o texto aprovado, ou '' quando a pessoa não quis
 */
function decidir_rascunho( $rascunho ) {
	echo PHP_EOL . pinta( '  ┌ sugestão de texto', 'forte' ) . PHP_EOL;
	foreach ( explode( "\n", $rascunho ) as $linha ) {
		echo pinta( '  │ ', 'forte' ) . $linha . PHP_EOL;
	}
	echo pinta( '  └ [u] usar  [a] ajustar  [n] não usar', 'forte' ) . PHP_EOL;

	while ( true ) {
		$escolha = strtolower( ler() );
		if ( 'u' === $escolha ) {
			return $rascunho;
		}
		// Fim da entrada (pipe, Ctrl+D) também é "não usar": sem isso, o laço
		// não termina nunca.
		if ( 'n' === $escolha || ':sair' === $escolha ) {
			return '';
		}
		if ( 'a' === $escolha ) {
			nota( '  escreva o texto ajustado, numa linha:' );
			$ajustado = ler();
			if ( '' !== $ajustado ) {
				return $ajustado;
			}
		}
		nota( '  u, a ou n' );
	}
}

function contexto_do( array $estado ) {
	$pega = function ( $chave ) use ( $estado ) {
		return isset( $estado['respostas'][ $chave ]['valor'] ) ? $estado['respostas'][ $chave ]['valor'] : '';
	};
	return array(
		'empresa'  => $pega( 'empresa' ),
		'ramo'     => $pega( 'ramo' ),
		'servicos' => $pega( 'servicos' ),
	);
}

/**
 * Sobe um arquivo pelo mesmo caminho que o navegador usaria.
 *
 * Fatiado em pedaços de verdade, com a mesma validação de verdade. Se um .exe
 * renomeado passar aqui, passaria no site também.
 */
function subir_arquivo( $sessao, array $campo, $caminho ) {
	$tamanho = filesize( $caminho );
	$pedacos = max( 1, (int) ceil( $tamanho / Leticia_Arquivos::PEDACO ) );

	$abertura = Leticia_Arquivos::iniciar( $sessao, $campo['chave'], basename( $caminho ), $tamanho, $pedacos );
	if ( is_wp_error( $abertura ) ) {
		return $abertura;
	}

	$mao = fopen( $caminho, 'rb' );
	for ( $i = 0; $i < $pedacos; $i++ ) {
		$parte = fread( $mao, Leticia_Arquivos::PEDACO );
		$r     = Leticia_Arquivos::receber( $sessao, $abertura['id'], $i, $parte );
		if ( is_wp_error( $r ) ) {
			fclose( $mao );
			return $r;
		}
		printf( "\r  enviando… pedaço %d de %d", $i + 1, $pedacos );
	}
	fclose( $mao );
	echo "\r" . str_repeat( ' ', 44 ) . "\r";

	return Leticia_Arquivos::concluir( $sessao, $abertura['id'] );
}
