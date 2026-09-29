<?php
/**
 * A trava.
 *
 * Três casos aqui valem por todos os outros, e são a razão de a classe existir:
 *
 *   contato inventado é barrado · contato digitado pelo cliente passa ·
 *   preço ecoado não passa
 *
 * O segundo é o que diferencia a LetícIA de um filtro burro: o briefing existe
 * para coletar telefone, e-mail e domínio, e confirmá-los de volta é o
 * comportamento certo. O terceiro é o que impede a trava de ficar frouxa junto.
 */

defined( 'ABSPATH' ) || exit;

$casos = array();

$vazio = array( 'telefones' => array(), 'emails' => array(), 'sites' => array() );

// ------------------------------------------------------------------ inventado

$inventados = array(
	'Pode chamar a equipe no (47) 3333-4455 que eles resolvem.' => 'telefone',
	'Manda pra contato@naoexiste.com.br que eles veem.'          => 'e-mail',
	'Dá uma olhada em exemplo-que-nao-existe.com.br.'            => 'endereço de site',
);

foreach ( $inventados as $texto => $oque ) {
	$casos[] = array(
		'grupo'    => 'trava · invenção',
		'nome'     => sprintf( 'barra %s que ninguém digitou', $oque ),
		'executar' => function () use ( $texto, $vazio ) {
			$motivo = Leticia_Trava::verificar( $texto, $vazio );
			return null === $motivo ? 'passou: ' . $texto : null;
		},
	);
}

// ------------------------------------------------------- o que o cliente deu

$casos[] = array(
	'grupo'    => 'trava · eco do cliente',
	'nome'     => 'o telefone que o cliente acabou de digitar passa',
	'executar' => function () use ( $vazio ) {
		$permitidos = Leticia_Trava::somar_do_cliente( $vazio, 'meu zap é 47 99999-8888' );
		$motivo     = Leticia_Trava::verificar( 'Anotei o WhatsApp (47) 99999-8888 pra equipe te chamar.', $permitidos );
		return null === $motivo ? null : 'barrou o próprio número do cliente: ' . $motivo;
	},
);

$casos[] = array(
	'grupo'    => 'trava · eco do cliente',
	'nome'     => 'o domínio que o cliente digitou passa, com ou sem www',
	'executar' => function () use ( $vazio ) {
		$permitidos = Leticia_Trava::somar_do_cliente( $vazio, 'o site é www.padariaaurora.com.br' );
		$motivo     = Leticia_Trava::verificar( 'Anotei padariaaurora.com.br como endereço do site.', $permitidos );
		return null === $motivo ? null : 'barrou o domínio do cliente: ' . $motivo;
	},
);

$casos[] = array(
	'grupo'    => 'trava · eco do cliente',
	'nome'     => 'e-mail no fim da frase não vira chave diferente',
	'executar' => function () use ( $vazio ) {
		// A expressão de e-mail é gulosa e engoliria o ponto final, fazendo de
		// "x@y.com.br" e "x@y.com.br." dois endereços distintos.
		$permitidos = Leticia_Trava::somar_do_cliente( $vazio, 'pode mandar pra marina@padariaaurora.com.br.' );
		$motivo     = Leticia_Trava::verificar( 'A cópia vai pro marina@padariaaurora.com.br.', $permitidos );
		return null === $motivo ? null : 'barrou o e-mail do cliente: ' . $motivo;
	},
);

$casos[] = array(
	'grupo'    => 'trava · eco do cliente',
	'nome'     => 'um número parecido, mas diferente, continua barrado',
	'executar' => function () use ( $vazio ) {
		$permitidos = Leticia_Trava::somar_do_cliente( $vazio, 'meu zap é 47 99999-8888' );
		$motivo     = Leticia_Trava::verificar( 'Liga no (47) 99999-8889 que eles atendem.', $permitidos );
		return null === $motivo ? 'deixou passar um número de um dígito de diferença' : null;
	},
);

// ------------------------------------------------------------------ dinheiro

$precos = array(
	'Fica R$ 1.500 no total.'                  => 'com R$',
	'Sai por 1.500,00 à vista.'                => 'sem R$, com máscara',
	'São mil e quinhentos reais.'              => 'por extenso',
	'Custa 500 reais.'                         => 'número mais "reais"',
);

foreach ( $precos as $texto => $forma ) {
	$casos[] = array(
		'grupo'    => 'trava · preço',
		'nome'     => sprintf( 'barra preço %s', $forma ),
		'executar' => function () use ( $texto, $vazio ) {
			return null === Leticia_Trava::verificar( $texto, $vazio ) ? 'passou: ' . $texto : null;
		},
	);
}

$casos[] = array(
	'grupo'    => 'trava · preço',
	'nome'     => 'preço ecoado do cliente também não passa',
	'executar' => function () use ( $vazio ) {
		// Dinheiro nunca entra nos permitidos, venha de onde vier: "me cobraram
		// 500" repetido de volta é a LetícIA falando de preço.
		$permitidos = Leticia_Trava::somar_do_cliente( $vazio, 'me cobraram 500 reais no outro lugar' );
		$motivo     = Leticia_Trava::verificar( 'Entendi que te cobraram 500 reais.', $permitidos );
		return null === $motivo ? 'ecoou preço' : null;
	},
);

$casos[] = array(
	'grupo'    => 'trava · percentual',
	'nome'     => 'barra desconto em número e por extenso',
	'executar' => function () use ( $vazio ) {
		foreach ( array( 'Consigo 50% pra você.', 'Dá pra fazer cinquenta por cento.' ) as $texto ) {
			if ( null === Leticia_Trava::verificar( $texto, $vazio ) ) {
				return 'passou: ' . $texto;
			}
		}
		return null;
	},
);

// --------------------------------------------------------------------- prazo

$casos[] = array(
	'grupo'    => 'trava · prazo',
	'nome'     => 'barra prazo prometido que não é o publicado',
	'executar' => function () use ( $vazio ) {
		$motivo = Leticia_Trava::verificar( 'Seu site fica pronto em 2 dias.', $vazio );
		return null === $motivo ? 'prometeu dois dias' : null;
	},
);

$casos[] = array(
	'grupo'    => 'trava · prazo',
	'nome'     => 'as 72 horas publicadas passam',
	'executar' => function () use ( $vazio ) {
		$motivo = Leticia_Trava::verificar( 'Fica pronto em até 72 horas depois que o material completo chega.', $vazio );
		return null === $motivo ? null : 'barrou o prazo do produto: ' . $motivo;
	},
);

$casos[] = array(
	'grupo'    => 'trava · prazo',
	'nome'     => 'o cliente descrevendo o próprio prazo não é promessa',
	'executar' => function () use ( $vazio ) {
		// "bolo com três dias de antecedência" é a descrição de um serviço.
		// Barrar isso seria barrar o briefing.
		$motivo = Leticia_Trava::verificar( 'Anotei: bolos por encomenda com 3 dias de antecedência.', $vazio );
		return null === $motivo ? null : 'barrou a descrição do cliente: ' . $motivo;
	},
);

// ----------------------------------------------------------------- recitação

$casos[] = array(
	'grupo'    => 'trava · recitação',
	'nome'     => 'título de markdown é recitação',
	'executar' => function () use ( $vazio ) {
		$motivo = Leticia_Trava::verificar( "## Por que perguntamos\n\nA equipe precisa saber.", $vazio, 'base qualquer' );
		return null === $motivo ? 'deixou passar um título' : null;
	},
);

$casos[] = array(
	'grupo'    => 'trava · recitação',
	'nome'     => 'devolver um trecho longo da instrução é recitação',
	'executar' => function () use ( $vazio ) {
		$base   = Leticia_Base::bloco( 'ramo' );
		$trecho = $base['porque'] . ' ' . $base['serve'];
		$motivo = Leticia_Trava::verificar( $trecho, $vazio, $trecho );
		return null === $motivo ? 'deixou recitar o bloco do campo' : null;
	},
);

$casos[] = array(
	'grupo'    => 'trava · recitação',
	'nome'     => 'uma frase normal não é recitação',
	'executar' => function () use ( $vazio ) {
		$base   = Leticia_Base::bloco( 'ramo' );
		$motivo = Leticia_Trava::verificar(
			'Entendi. Com isso a equipe já consegue escrever a abertura da sua página.',
			$vazio,
			$base['porque']
		);
		return null === $motivo ? null : 'barrou uma frase comum: ' . $motivo;
	},
);

// -------------------------------------------------------- permitidos da base

$casos[] = array(
	'grupo'    => 'trava · permitidos',
	'nome'     => 'o exemplo didático da base é permitido',
	'executar' => function () {
		// A base usa (47) 99999-9999 de propósito ao explicar o campo de
		// telefone. Barrar o próprio exemplo faria a LetícIA não conseguir
		// ensinar o formato.
		$permitidos = Leticia_Trava::permitidos();
		$motivo     = Leticia_Trava::verificar( 'É com DDD, tipo (47) 99999-9999.', $permitidos );
		return null === $motivo ? null : 'barrou o exemplo da própria base: ' . $motivo;
	},
);

return $casos;
