<?php
/**
 * Os anexos.
 *
 * Quatro casos são os que a seção 16 pede nominalmente — extensão recusada,
 * MIME divergente da extensão, pedaços fora de ordem, nome saneado — e cada um
 * deles descreve um jeito conhecido de este tipo de código dar errado.
 *
 * Nenhum caso aqui toca a pasta do WordPress: os stubs apontam a base para uma
 * pasta temporária, que é varrida no fim da suíte.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

/** Sobe um arquivo inteiro, do jeito que o navegador faria. */
$subir = function ( $sessao, $campo, $nome, $conteudo, $ordem = null ) {
	$pedaco  = 1024;   // pedaços pequenos, para a suíte ser rápida
	$partes  = str_split( $conteudo, $pedaco );
	$abertura = Leticia_Arquivos::iniciar( $sessao, $campo, $nome, strlen( $conteudo ), count( $partes ) );

	if ( is_wp_error( $abertura ) ) {
		return $abertura;
	}

	$indices = null === $ordem ? array_keys( $partes ) : $ordem;
	foreach ( $indices as $i ) {
		$r = Leticia_Arquivos::receber( $sessao, $abertura['id'], $i, $partes[ $i ] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
	}

	return Leticia_Arquivos::concluir( $sessao, $abertura['id'] );
};

/** Um PNG de verdade, pequeno: 1x1 pixel. */
$png = function () {
	return base64_decode(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
	);
};

$pdf = function () {
	return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
};

// ------------------------------------------------------------- o bom caminho

$casos[] = array(
	'grupo'    => 'arquivos · envio',
	'nome'     => 'um PDF em vários pedaços chega inteiro e íntegro',
	'executar' => function () use ( $subir, $pdf ) {
		$conteudo = $pdf() . str_repeat( "% preenchimento\n", 500 );
		$meta     = $subir( 'sessao-a', 'logo', 'logo-aurora.pdf', $conteudo );

		if ( is_wp_error( $meta ) ) {
			return 'recusou: ' . $meta->get_error_message();
		}
		if ( (int) $meta['tamanho'] !== strlen( $conteudo ) ) {
			return sprintf( 'remontou %d bytes de %d', $meta['tamanho'], strlen( $conteudo ) );
		}

		$caminho = Leticia_Arquivos::caminho_de( $meta );
		if ( ! is_readable( $caminho ) ) {
			return 'o arquivo não está onde o metadado diz';
		}
		// Byte a byte: um remontador que troca a ordem passaria no teste de
		// tamanho e entregaria um arquivo corrompido.
		return file_get_contents( $caminho ) === $conteudo ? null : 'o conteúdo saiu diferente do que entrou';
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · envio',
	'nome'     => 'pedaços fora de ordem não corrompem o arquivo',
	'executar' => function () use ( $subir, $pdf ) {
		// Rede móvel entrega fora de ordem o tempo todo. Um remontador que
		// dependesse da ordem de chegada daria arquivo corrompido de forma
		// intermitente, que é o pior tipo de defeito para achar depois.
		$conteudo = $pdf() . str_repeat( "% linha de conteudo\n", 400 );
		$partes   = str_split( $conteudo, 1024 );
		$ordem    = array_keys( $partes );
		shuffle( $ordem );

		$meta = $subir( 'sessao-b', 'logo', 'logo.pdf', $conteudo, $ordem );
		if ( is_wp_error( $meta ) ) {
			return 'recusou: ' . $meta->get_error_message();
		}
		return file_get_contents( Leticia_Arquivos::caminho_de( $meta ) ) === $conteudo
			? null
			: 'o arquivo remontou embaralhado';
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · envio',
	'nome'     => 'faltando um pedaço, o arquivo não é aceito',
	'executar' => function () use ( $pdf ) {
		$conteudo = $pdf() . str_repeat( "% x\n", 1000 );
		$partes   = str_split( $conteudo, 1024 );
		$abertura = Leticia_Arquivos::iniciar( 'sessao-c', 'logo', 'logo.pdf', strlen( $conteudo ), count( $partes ) );

		foreach ( $partes as $i => $parte ) {
			if ( 1 === $i ) {
				continue;   // o pedaço que se perdeu na rede
			}
			Leticia_Arquivos::receber( 'sessao-c', $abertura['id'], $i, $parte );
		}

		$meta = Leticia_Arquivos::concluir( 'sessao-c', $abertura['id'] );
		return is_wp_error( $meta ) ? null : 'aceitou um arquivo incompleto';
	},
);

// ------------------------------------------------------------- recusas

$casos[] = array(
	'grupo'    => 'arquivos · recusa',
	'nome'     => 'extensão fora da lista do campo é recusada logo na abertura',
	'executar' => function () {
		// Antes do primeiro byte subir: não faz sentido gastar a rede da pessoa
		// para recusar no fim.
		$r = Leticia_Arquivos::iniciar( 'sessao-d', 'logo', 'virus.exe', 1000, 1 );
		if ( ! is_wp_error( $r ) ) {
			return 'aceitou .exe na logomarca';
		}
		return 'extensao' === $r->get_error_code() ? null : 'recusou pelo motivo errado: ' . $r->get_error_code();
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · recusa',
	'nome'     => 'extensão aceita num campo é recusada em outro',
	'executar' => function () {
		// .mp4 vale em materiais e não vale na logomarca.
		$logo      = Leticia_Arquivos::iniciar( 'sessao-d', 'logo', 'video.mp4', 1000, 1 );
		$materiais = Leticia_Arquivos::iniciar( 'sessao-d', 'materiais', 'video.mp4', 1000, 1 );

		if ( ! is_wp_error( $logo ) ) {
			return 'aceitou vídeo como logomarca';
		}
		return is_wp_error( $materiais ) ? 'recusou vídeo em materiais' : null;
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · recusa',
	'nome'     => 'nome dizendo uma coisa e conteúdo dizendo outra é recusado',
	'executar' => function () use ( $subir, $pdf ) {
		// O caso clássico: qualquer coisa renomeada para .png. A extensão passa
		// na abertura; o que pega é o finfo sobre o arquivo montado.
		if ( ! function_exists( 'finfo_open' ) ) {
			return null;   // sem finfo não há o que testar neste servidor
		}
		$meta = $subir( 'sessao-e', 'logo', 'logo.png', $pdf() );
		if ( ! is_wp_error( $meta ) ) {
			return 'aceitou um PDF chamado .png';
		}
		return 'tipo_divergente' === $meta->get_error_code() ? null : 'recusou por ' . $meta->get_error_code();
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · recusa',
	'nome'     => 'arquivo recusado não fica no disco',
	'executar' => function () use ( $subir, $pdf ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			return null;
		}
		$antes = glob( Leticia_Arquivos::pasta_base() . '/arquivos/*/*' );
		$subir( 'sessao-f', 'logo', 'falso.png', $pdf() );
		$depois = glob( Leticia_Arquivos::pasta_base() . '/arquivos/*/*' );

		return count( (array) $antes ) === count( (array) $depois ) ? null : 'o arquivo recusado sobrou em disco';
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · recusa',
	'nome'     => 'SVG com script dentro não entra',
	'executar' => function () use ( $subir ) {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$r   = $subir( 'sessao-g', 'logo', 'logo.svg', $svg );
		if ( ! is_wp_error( $r ) ) {
			return 'aceitou SVG com script';
		}
		return in_array( $r->get_error_code(), array( 'svg_perigoso', 'tipo_divergente' ), true )
			? null
			: 'recusou por ' . $r->get_error_code();
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · recusa',
	'nome'     => 'arquivo acima do teto é recusado antes de subir',
	'executar' => function () {
		$grande = Leticia_Config::teto_arquivo() + 1;
		$r      = Leticia_Arquivos::iniciar( 'sessao-h', 'materiais', 'filme.mp4', $grande, 200 );
		if ( ! is_wp_error( $r ) ) {
			return 'aceitou acima do teto';
		}
		// A mensagem tem que oferecer a saída, não só dizer não.
		$msg = $r->get_error_message();
		if ( false === strpos( $msg, 'e-mail' ) ) {
			return 'não explicou por que existe o limite: ' . $msg;
		}
		return false !== strpos( $msg, 'link de uma pasta' ) ? null : 'recusou sem oferecer saída';
	},
);

// ------------------------------------------------------------- nome e caminho

$nomes = array(
	'../../wp-config.php'      => 'travessia de diretório',
	'foto.png.php'             => 'extensão dupla',
	'  arquivo com espaço.pdf' => 'espaços nas pontas',
	'.htaccess'                => 'arquivo oculto',
);

foreach ( $nomes as $nome => $ataque ) {
	$casos[] = array(
		'grupo'    => 'arquivos · nome',
		'nome'     => sprintf( 'sanea %s', $ataque ),
		'executar' => function () use ( $nome ) {
			$limpo = Leticia_Arquivos::limpar_nome( $nome );
			if ( false !== strpos( $limpo, '/' ) || false !== strpos( $limpo, '\\' ) ) {
				return 'sobrou separador de caminho: ' . $limpo;
			}
			if ( false !== strpos( $limpo, '..' ) ) {
				return 'sobrou travessia: ' . $limpo;
			}
			if ( 0 === strpos( $limpo, '.' ) ) {
				return 'continuou oculto: ' . $limpo;
			}
			return '' !== $limpo ? null : 'sobrou nome vazio';
		},
	);
}

$casos[] = array(
	'grupo'    => 'arquivos · nome',
	'nome'     => 'o nome em disco é aleatório, não o que a pessoa mandou',
	'executar' => function () use ( $subir, $png ) {
		$meta = $subir( 'sessao-i', 'logo', 'A Minha Logo Linda.png', $png() );
		if ( is_wp_error( $meta ) ) {
			return 'recusou: ' . $meta->get_error_message();
		}
		if ( ! preg_match( '/^[a-f0-9]{32}\.png$/', $meta['disco'] ) ) {
			return 'o nome em disco é ' . $meta['disco'];
		}
		// O nome original sobrevive como metadado: é ele que a equipe vê.
		return false !== strpos( $meta['nome'], 'Logo' ) ? null : 'perdeu o nome original do cliente';
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · caminho',
	'nome'     => 'metadado adulterado não vira caminho',
	'executar' => function () {
		$forjado = array( 'pasta' => '../../../..', 'disco' => 'wp-config.php', 'nome' => 'x' );
		return '' === Leticia_Arquivos::caminho_de( $forjado ) ? null : 'montou um caminho a partir de lixo';
	},
);

// ------------------------------------------------------------- pertencimento

$casos[] = array(
	'grupo'    => 'arquivos · dono',
	'nome'     => 'ninguém escreve no envio de outra sessão',
	'executar' => function () {
		$abertura = Leticia_Arquivos::iniciar( 'sessao-j', 'logo', 'logo.png', 100, 1 );
		$r        = Leticia_Arquivos::receber( 'sessao-intruso', $abertura['id'], 0, 'qualquer coisa' );

		if ( ! is_wp_error( $r ) ) {
			return 'gravou pedaço na conversa alheia';
		}
		return 'nao_e_seu' === $r->get_error_code() ? null : 'recusou por ' . $r->get_error_code();
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · dono',
	'nome'     => 'ninguém apaga arquivo de outra sessão',
	'executar' => function () use ( $subir, $png ) {
		$meta = $subir( 'sessao-k', 'logo', 'logo.png', $png() );
		if ( is_wp_error( $meta ) ) {
			return 'o envio falhou: ' . $meta->get_error_message();
		}
		$r = Leticia_Arquivos::remover( 'sessao-intruso', $meta );

		if ( ! is_wp_error( $r ) ) {
			return 'apagou arquivo alheio';
		}
		return is_readable( Leticia_Arquivos::caminho_de( $meta ) ) ? null : 'apagou mesmo assim';
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · dono',
	'nome'     => 'a pasta não tem o id da sessão no nome',
	'executar' => function () use ( $subir, $png ) {
		// Quem descobrisse um id de sessão saberia o caminho da pasta.
		$meta = $subir( 'sessao-l-bem-conhecida', 'logo', 'logo.png', $png() );
		if ( is_wp_error( $meta ) ) {
			return 'o envio falhou';
		}
		return false === strpos( $meta['pasta'], 'sessao-l' ) ? null : 'a pasta é o próprio id';
	},
);

// ------------------------------------------------------------- proteção

$casos[] = array(
	'grupo'    => 'arquivos · proteção',
	'nome'     => 'a pasta nasce fechada para a web',
	'executar' => function () use ( $subir, $png ) {
		$subir( 'sessao-m', 'logo', 'logo.png', $png() );
		$raiz = Leticia_Arquivos::pasta_base();

		foreach ( array( '.htaccess', 'index.php', 'web.config' ) as $guarda ) {
			if ( ! file_exists( $raiz . '/' . $guarda ) ) {
				return 'faltou ' . $guarda;
			}
		}
		$htaccess = file_get_contents( $raiz . '/.htaccess' );
		if ( false === strpos( $htaccess, 'Options -Indexes' ) ) {
			return 'a listagem de diretório continua ligada';
		}
		return false !== strpos( $htaccess, 'engine off' ) ? null : 'o PHP continua ligado na pasta de anexos';
	},
);

// ------------------------------------------------------------- avisos

$casos[] = array(
	'grupo'    => 'arquivos · aviso',
	'nome'     => 'logo pequena é aceita, com aviso',
	'executar' => function () use ( $subir, $png ) {
		// Bloquear aqui faria a pessoa parar o briefing para procurar um
		// arquivo que talvez nem exista.
		$meta = $subir( 'sessao-n', 'logo', 'logo.png', $png() );
		if ( is_wp_error( $meta ) ) {
			return 'recusou uma logo pequena: ' . $meta->get_error_message();
		}
		return '' !== $meta['aviso'] ? null : 'aceitou 1 pixel sem avisar nada';
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · aviso',
	'nome'     => 'imagem pequena em materiais não vira aviso',
	'executar' => function () use ( $subir, $png ) {
		$meta = $subir( 'sessao-o', 'materiais', 'foto.png', $png() );
		if ( is_wp_error( $meta ) ) {
			return 'recusou: ' . $meta->get_error_message();
		}
		return '' === $meta['aviso'] ? null : 'avisou sobre uma foto qualquer';
	},
);

// ------------------------------------------------------------- limpeza

$casos[] = array(
	'grupo'    => 'arquivos · limpeza',
	'nome'     => 'envio abandonado é varrido depois do prazo',
	'executar' => function () {
		// Sem isto, todo briefing abandonado no meio de um vídeo deixa pedaços
		// em disco para sempre — e ninguém repara em disco enchendo.
		$abertura = Leticia_Arquivos::iniciar( 'sessao-p', 'logo', 'logo.pdf', 5000, 5 );
		Leticia_Arquivos::receber( 'sessao-p', $abertura['id'], 0, str_repeat( 'x', 500 ) );

		$pastas = glob( Leticia_Arquivos::pasta_base() . '/parciais/*/*', GLOB_ONLYDIR );
		if ( ! $pastas ) {
			return 'não achei a pasta do envio pela metade';
		}

		// Envelhece a pasta para além do prazo.
		touch( $pastas[0], time() - Leticia_Arquivos::VALIDADE_PARCIAL - 60 );
		Leticia_Arquivos::limpar_velhos();

		return is_dir( $pastas[0] ) ? 'a sobra continuou lá' : null;
	},
);

$casos[] = array(
	'grupo'    => 'arquivos · limpeza',
	'nome'     => 'envio recente não é varrido junto',
	'executar' => function () {
		$abertura = Leticia_Arquivos::iniciar( 'sessao-q', 'logo', 'logo.pdf', 5000, 5 );
		Leticia_Arquivos::receber( 'sessao-q', $abertura['id'], 0, str_repeat( 'y', 500 ) );

		Leticia_Arquivos::limpar_velhos();

		$sobrou = glob( Leticia_Arquivos::pasta_base() . '/parciais/*/*/0.parte' );
		return $sobrou ? null : 'varreu um envio que ainda estava acontecendo';
	},
);

return $casos;
