<?php
/**
 * O roteiro — os casos que existem para nunca acontecer de novo.
 *
 * Cada um destes corresponde a um critério de aceite. Se um deles ficar
 * vermelho, o defeito que ele descreve voltou a ser possível:
 *
 *   nunca pula obrigatório · nunca repete campo concluído · voltar não corrompe
 *   nada · a barra não anda para trás · a barra não chega a 100% antes do envio
 *   · imagens_ia = não muda a fala de materiais · campo fora da lista não entra
 */

defined( 'ABSPATH' ) || exit;

/** Preenche um briefing inteiro com respostas de gente. */
$preencher = function ( $ate = null ) {
	$respostas = array(
		'responsavel'    => 'Marina Alves',
		'empresa'        => 'Padaria Aurora',
		'whatsapp'       => '47999998888',
		'email'          => 'marina@padariaaurora.com.br',
		'dominio'        => 'padariaaurora.com.br',
		'endereco'       => 'Rua das Flores, 120 — Centro, Joinville',
		'ramo'           => 'padaria artesanal, pães de fermentação natural e bolos de festa',
		'servicos'       => "pães de fermentação natural\nbolos de festa por encomenda\ncafé da manhã",
		'contatos_site'  => 'WhatsApp (47) 99999-8888',
		'redes_sociais'  => 'instagram.com/padariaaurora',
		'paginas_extras' => 'não',
		'imagens_ia'     => 'sim',
		'logo'           => '',
		'textos'         => '',
		'materiais'      => '',
	);

	$estado = Leticia_Roteiro::novo( 7 );
	$n      = 0;

	foreach ( Leticia_Campos::todos() as $campo ) {
		if ( null !== $ate && $n >= $ate ) {
			break;
		}
		$chave = $campo['chave'];
		if ( 'arquivo' === $campo['tipo'] ) {
			$r = Leticia_Roteiro::responder( $estado, $chave, '', array(
				'valor'    => 'logo' === $chave ? 'logo-aurora.pdf' : '',
				'arquivos' => 'logo' === $chave ? array( array( 'nome' => 'logo-aurora.pdf' ) ) : array(),
			) );
		} else {
			$r = Leticia_Roteiro::responder( $estado, $chave, $respostas[ $chave ] );
		}
		if ( '' !== $r['erro'] ) {
			throw new RuntimeException( sprintf( 'o preenchimento travou em %s: %s', $chave, $r['erro'] ) );
		}
		$estado = $r['estado'];
		$n++;
	}

	return $estado;
};

/**
 * Responde um campo com algo plausível, para percorrer o roteiro inteiro.
 * Existe só aqui: produção nunca "responde sozinha".
 */
function leticia_teste_responder( array $estado, array $campo ) {
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

	if ( 'arquivo' === $campo['tipo'] ) {
		$r = Leticia_Roteiro::responder( $estado, $campo['chave'], '', array( 'valor' => 'arquivo.pdf' ) );
	} else {
		$bruto = isset( $exemplos[ $campo['chave'] ] ) ? $exemplos[ $campo['chave'] ] : 'resposta';
		$r     = Leticia_Roteiro::responder( $estado, $campo['chave'], $bruto );
	}

	if ( '' !== $r['erro'] ) {
		throw new RuntimeException( $campo['chave'] . ': ' . $r['erro'] );
	}
	return $r['estado'];
}

$casos = array();

// ----------------------------------------------------------------- estrutura

$casos[] = array(
	'grupo'    => 'roteiro · estrutura',
	'nome'     => 'são 15 campos, em 3 seções, na ordem publicada',
	'executar' => function () {
		if ( 15 !== Leticia_Campos::total() ) {
			return 'há ' . Leticia_Campos::total() . ' campos';
		}
		$esperado = array(
			'responsavel', 'empresa', 'whatsapp', 'email', 'dominio',
			'ramo', 'servicos', 'endereco', 'contatos_site', 'redes_sociais', 'paginas_extras', 'imagens_ia',
			'logo', 'textos', 'materiais',
		);
		$achado = Leticia_Campos::chaves();
		if ( $achado !== $esperado ) {
			return 'a ordem mudou: ' . implode( ', ', $achado );
		}
		$por_secao = Leticia_Campos::por_secao();
		// O endereço mora na etapa do negócio, junto dos contatos do site.
		if ( array( 1 => 5, 2 => 7, 3 => 3 ) !== $por_secao ) {
			return 'as seções têm ' . implode( '/', $por_secao ) . ' campos';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · estrutura',
	'nome'     => 'só a logomarca é upload obrigatório',
	'executar' => function () {
		$obrigatorios = array();
		foreach ( Leticia_Campos::todos() as $campo ) {
			if ( 'arquivo' === $campo['tipo'] && $campo['obrigatorio'] ) {
				$obrigatorios[] = $campo['chave'];
			}
		}
		return array( 'logo' ) === $obrigatorios ? null : 'obrigatórios: ' . implode( ', ', $obrigatorios );
	},
);

// -------------------------------------------------------------- não pula nada

$casos[] = array(
	'grupo'    => 'roteiro · avanço',
	'nome'     => 'perguntando na ordem, nenhum campo é pulado nem repetido',
	'executar' => function () use ( $preencher ) {
		$estado  = Leticia_Roteiro::novo( 1 );
		$vistos  = array();
		$guarda  = 0;

		while ( ( $campo = Leticia_Roteiro::proximo( $estado ) ) && $guarda++ < 100 ) {
			if ( isset( $vistos[ $campo['chave'] ] ) ) {
				return 'perguntou ' . $campo['chave'] . ' duas vezes';
			}
			$vistos[ $campo['chave'] ] = true;
			$estado = leticia_teste_responder( $estado, $campo );
		}

		if ( count( $vistos ) !== Leticia_Campos::total() ) {
			$faltaram = array_diff( Leticia_Campos::chaves(), array_keys( $vistos ) );
			return 'não perguntou: ' . implode( ', ', $faltaram );
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · avanço',
	'nome'     => 'campo fora da lista é recusado',
	'executar' => function () {
		$estado = Leticia_Roteiro::novo( 1 );
		$r      = Leticia_Roteiro::responder( $estado, 'orcamento', 'R$ 500' );
		if ( '' === $r['erro'] ) {
			return 'aceitou um campo inventado';
		}
		return isset( $r['estado']['respostas']['orcamento'] ) ? 'gravou mesmo assim' : null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · avanço',
	'nome'     => 'obrigatório não pode ser pulado',
	'executar' => function () {
		$estado = Leticia_Roteiro::novo( 1 );
		$r      = Leticia_Roteiro::pular( $estado, 'ramo' );
		if ( '' === $r['erro'] ) {
			return 'deixou pular o ramo de atividade';
		}
		return Leticia_Roteiro::resolvido( $r['estado'], 'ramo' ) ? 'marcou como resolvido' : null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · avanço',
	'nome'     => 'opcional pulado conta como resolvido e não volta a ser perguntado',
	'executar' => function () {
		$estado = Leticia_Roteiro::novo( 1 );
		$r      = Leticia_Roteiro::pular( $estado, 'email' );
		if ( '' !== $r['erro'] ) {
			return 'recusou pular um opcional: ' . $r['erro'];
		}
		$estado = $r['estado'];
		if ( ! Leticia_Roteiro::resolvido( $estado, 'email' ) ) {
			return 'não marcou como resolvido';
		}
		$guarda = 0;
		while ( ( $campo = Leticia_Roteiro::proximo( $estado ) ) && $guarda++ < 100 ) {
			if ( 'email' === $campo['chave'] ) {
				return 'voltou a perguntar o e-mail';
			}
			$estado = leticia_teste_responder( $estado, $campo );
		}
		return null;
	},
);

// ---------------------------------------------------------------- voltar

$casos[] = array(
	'grupo'    => 'roteiro · voltar',
	'nome'     => 'voltar reabre o campo sem apagar o que veio depois',
	'executar' => function () use ( $preencher ) {
		$estado = $preencher();
		$antes  = Leticia_Roteiro::quantos_resolvidos( $estado );

		$r = Leticia_Roteiro::voltar_para( $estado, 'ramo' );
		if ( '' !== $r['erro'] ) {
			return 'recusou voltar: ' . $r['erro'];
		}
		$estado = $r['estado'];

		if ( Leticia_Campos::indice( 'ramo' ) !== $estado['indice'] ) {
			return 'o ponteiro não foi para o ramo';
		}
		if ( Leticia_Roteiro::quantos_resolvidos( $estado ) !== $antes ) {
			return 'perdeu respostas ao voltar';
		}
		if ( ! Leticia_Roteiro::resolvido( $estado, 'materiais' ) ) {
			return 'apagou o que vinha depois';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · voltar',
	'nome'     => 'corrigir no meio devolve ao primeiro pendente, não ao campo seguinte',
	'executar' => function () use ( $preencher ) {
		// Oito campos respondidos, o nono em aberto. A pessoa volta ao segundo
		// para corrigir o nome da empresa.
		$estado = $preencher( 8 );
		$estado = Leticia_Roteiro::voltar_para( $estado, 'empresa' )['estado'];
		$r      = Leticia_Roteiro::responder( $estado, 'empresa', 'Padaria Aurora Ltda' );
		if ( '' !== $r['erro'] ) {
			return 'a correção falhou: ' . $r['erro'];
		}
		$estado  = $r['estado'];
		$proximo = Leticia_Roteiro::proximo( $estado );

		if ( ! $proximo || 'contatos_site' !== $proximo['chave'] ) {
			return 'seguiu para ' . ( $proximo ? $proximo['chave'] : 'a revisão' ) . ' em vez de contatos_site';
		}
		if ( 'Padaria Aurora Ltda' !== $estado['respostas']['empresa']['valor'] ) {
			return 'não gravou a correção';
		}
		return null;
	},
);

// -------------------------------------------------------------- progresso

$casos[] = array(
	'grupo'    => 'roteiro · progresso',
	'nome'     => 'a barra nunca anda para trás, nem ao voltar e corrigir',
	'executar' => function () use ( $preencher ) {
		$estado = Leticia_Roteiro::novo( 1 );
		$ultima = -1;
		$guarda = 0;

		while ( ( $campo = Leticia_Roteiro::proximo( $estado ) ) && $guarda++ < 100 ) {
			$estado  = leticia_teste_responder( $estado, $campo );
			$fracao  = Leticia_Roteiro::progresso( $estado )['fracao'];
			if ( $fracao < $ultima ) {
				return sprintf( 'caiu de %.4f para %.4f em %s', $ultima, $fracao, $campo['chave'] );
			}
			$ultima = $fracao;
		}

		// E agora o caso que importa: voltar para corrigir.
		$estado = Leticia_Roteiro::voltar_para( $estado, 'empresa' )['estado'];
		$fracao = Leticia_Roteiro::progresso( $estado )['fracao'];
		if ( $fracao < $ultima ) {
			return sprintf( 'voltar derrubou a barra de %.4f para %.4f', $ultima, $fracao );
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · progresso',
	'nome'     => 'com 15 de 15 respondidos a barra ainda não está cheia',
	'executar' => function () use ( $preencher ) {
		$estado = $preencher();
		$p      = Leticia_Roteiro::progresso( $estado );

		if ( 15 !== $p['respondidos'] ) {
			return 'respondidos: ' . $p['respondidos'];
		}
		if ( $p['fracao'] >= 1.0 ) {
			return 'a barra chegou a 100% antes do envio';
		}
		$estado = Leticia_Roteiro::marcar_enviado( $estado );
		if ( Leticia_Roteiro::progresso( $estado )['fracao'] < 1.0 ) {
			return 'não completou nem depois do envio';
		}
		return null;
	},
);

// ------------------------------------------------------------ envio e falas

$casos[] = array(
	'grupo'    => 'roteiro · envio',
	'nome'     => 'logomarca pendente não impede o envio, e vira pendência',
	'executar' => function () use ( $preencher ) {
		$estado = $preencher( 12 );   // as duas seções de texto, sem os arquivos
		$r      = Leticia_Roteiro::responder( $estado, 'logo', '', array( 'pendente' => true ) );
		if ( '' !== $r['erro'] ) {
			return 'recusou a logo pendente: ' . $r['erro'];
		}
		$estado = $r['estado'];
		$estado = Leticia_Roteiro::pular( $estado, 'textos' )['estado'];
		$estado = Leticia_Roteiro::pular( $estado, 'materiais' )['estado'];

		if ( ! Leticia_Roteiro::pode_enviar( $estado ) ) {
			return 'o briefing não fecha com a logo pendente';
		}
		if ( ! in_array( 'logo', Leticia_Roteiro::pendencias( $estado ), true ) ) {
			return 'não registrou a pendência';
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · envio',
	'nome'     => 'faltando um obrigatório, não fecha',
	'executar' => function () use ( $preencher ) {
		$estado = $preencher( 6 );
		return Leticia_Roteiro::pode_enviar( $estado ) ? 'deixou fechar sem a seção 2' : null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · falas',
	'nome'     => 'imagens_ia = não muda a fala de materiais',
	'executar' => function () use ( $preencher ) {
		$materiais = Leticia_Campos::por_chave( 'materiais' );

		$com = $preencher( 12 );
		$sem = Leticia_Roteiro::responder( $com, 'imagens_ia', 'nao' )['estado'];

		$falas_com = Leticia_Roteiro::falas_antes( $com, $materiais );
		$falas_sem = Leticia_Roteiro::falas_antes( $sem, $materiais );

		if ( count( $falas_sem ) <= count( $falas_com ) ) {
			return 'a fala não mudou com "só as minhas"';
		}
		$junto = implode( ' ', $falas_sem );
		return false !== strpos( $junto, Leticia_Base::texto( 'sem-imagens-de-banco' ) ) ? null : 'o aviso saiu com outro texto: ' . $junto;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · falas',
	'nome'     => 'a abertura de uma seção aparece uma vez só',
	'executar' => function () use ( $preencher ) {
		$estado = $preencher( 6 );   // seção 1 inteira
		$ramo   = Leticia_Campos::por_chave( 'ramo' );

		$primeira = Leticia_Roteiro::falas_antes( $estado, $ramo );
		if ( ! $primeira ) {
			return 'a seção 2 abriu sem a fala';
		}

		$depois = Leticia_Roteiro::responder( $estado, 'ramo', 'padaria artesanal de bairro' )['estado'];
		$segunda = Leticia_Roteiro::falas_antes( $depois, Leticia_Campos::por_chave( 'servicos' ) );

		return $segunda ? 'repetiu a abertura em servicos' : null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · falas',
	'nome'     => 'contatos_site chega com a resposta já sugerida',
	'executar' => function () use ( $preencher ) {
		$estado    = $preencher( 6 );
		$sugestao  = Leticia_Roteiro::sugestao( $estado, Leticia_Campos::por_chave( 'contatos_site' ) );

		if ( '' === $sugestao ) {
			return 'não sugeriu nada tendo WhatsApp e e-mail na mão';
		}
		if ( false === strpos( $sugestao, '(47) 99999-8888' ) ) {
			return 'a sugestão não traz o WhatsApp: ' . $sugestao;
		}
		if ( false === strpos( $sugestao, 'marina@padariaaurora.com.br' ) ) {
			return 'a sugestão não traz o e-mail: ' . $sugestao;
		}
		return null;
	},
);

$casos[] = array(
	'grupo'    => 'roteiro · falas',
	'nome'     => 'sem WhatsApp respondido, não há o que sugerir',
	'executar' => function () {
		$estado = Leticia_Roteiro::novo( 1 );
		return '' === Leticia_Roteiro::sugestao( $estado, Leticia_Campos::por_chave( 'contatos_site' ) )
			? null
			: 'sugeriu contato do nada';
	},
);

// ------------------------------------------------------------- repergunta

$casos[] = array(
	'grupo'    => 'roteiro · repergunta',
	'nome'     => 'uma repergunta por campo; o ramo, que vira o texto do site, aceita três',
	'executar' => function () {
		$estado = Leticia_Roteiro::novo( 1 );
		if ( ! Leticia_Roteiro::pode_reperguntar( $estado, 'servicos' ) ) {
			return 'não deixou reperguntar nem a primeira vez';
		}
		$estado = Leticia_Roteiro::marcar_repergunta( $estado, 'servicos' );
		if ( Leticia_Roteiro::pode_reperguntar( $estado, 'servicos' ) ) {
			return 'deixaria insistir duas vezes nos serviços';
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( ! Leticia_Roteiro::pode_reperguntar( $estado, 'ramo' ) ) {
				return 'o ramo parou na repergunta ' . ( $i + 1 );
			}
			$estado = Leticia_Roteiro::marcar_repergunta( $estado, 'ramo' );
		}
		return Leticia_Roteiro::pode_reperguntar( $estado, 'ramo' ) ? 'o ramo insistiria uma quarta vez' : null;
	},
);

// ---------------------------------------------------------------- saneamento

$casos[] = array(
	'grupo'    => 'roteiro · saneamento',
	'nome'     => 'estado vindo de fora é saneado, não confiado',
	'executar' => function () {
		$sujo = array(
			'respostas' => array(
				'ramo'      => array( 'valor' => 'padaria' ),
				'orcamento' => array( 'valor' => 'R$ 500' ),   // campo inventado
				'empresa'   => 'não é array',
			),
			'indice'    => 999,
			'enviado'   => 'sim',
			'semente'   => 3,
		);
		$estado = Leticia_Roteiro::sanear( $sujo );

		if ( isset( $estado['respostas']['orcamento'] ) ) {
			return 'deixou entrar campo fora da lista';
		}
		if ( isset( $estado['respostas']['empresa'] ) ) {
			return 'aceitou resposta malformada';
		}
		if ( $estado['indice'] > Leticia_Campos::total() ) {
			return 'o ponteiro ficou fora do roteiro';
		}
		if ( ! Leticia_Roteiro::resolvido( $estado, 'ramo' ) ) {
			return 'descartou a resposta boa junto com as ruins';
		}
		return null;
	},
);

return $casos;
