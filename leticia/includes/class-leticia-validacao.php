<?php
/**
 * Formato — e só formato.
 *
 * Esta classe diz se um telefone tem DDD, se um e-mail tem arroba e se um
 * domínio parece um domínio. Ela nunca julga se a resposta é boa: quem acha que
 * "faço bolo" está magro é o modelo, e nem ele bloqueia — no máximo reperguntá
 * uma vez. Bloquear o avanço por resposta fraca é como se perde um briefing.
 *
 * Roda no servidor porque validação no navegador é enfeite: qualquer um
 * contorna abrindo o console.
 *
 * Toda função devolve o mesmo formato:
 *
 *   array(
 *     'ok'        => bool,
 *     'valor'     => string     valor normalizado, pronto para gravar
 *     'erro'      => string     o que dizer ao cliente quando ok é false
 *     'conferido' => bool       formato de fato validado (vale a marca na tela)
 *     'pendente'  => bool       respondeu "ainda não tenho"
 *     'negado'    => bool       disse que não tem, num campo em que isso é resposta
 *     'conduzir'  => bool       disse que não tem num campo obrigatório: o erro
 *                               é uma fala dela, não um aviso de formato
 *   )
 *
 * Uma exceção à regra de "só formato": a resposta negativa. "Não tenho" no
 * endereço é uma resposta completa, e é gravada como "Sem endereço físico" —
 * não como "Nao tenho", que a equipe teria que interpretar. "Não sei" no nome
 * do responsável não é resposta nenhuma, e não passa.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Validacao {

	/** Teto de entrada por campo. Briefing não é redação de vestibular. */
	const TETO_CARACTERES = 1200;

	/** Reconhece "ainda não tenho" em domínio, com as variações que aparecem. */
	const RE_SEM_DOMINIO = '/^(ainda\s+)?(nao|não)\s*(tenho|possuo|registrei|sei)|^(nao|não)\s+tenho\s+ainda|^sem\s+dom|^nenhum|^ainda\s+(nao|não)$/u';

	/**
	 * Negativa inteira: a resposta toda é "não", "não tenho", "nenhum"...
	 *
	 * Inteira de propósito. "Não tenho loja, atendo em domicílio" é uma
	 * resposta com informação, e não pode virar "Sem endereço físico".
	 */
	const RE_NEGATIVA = '/^(nao|n|nada|nenhum|nenhuma|ninguem|nao sei|sei la|nao tenho|nao temos|nao possuo|nao possuimos|nao tem|nao ha|nao existe|nao uso|nao usamos|nao preciso|nao precisa|nao quero|ainda nao|ainda nao tenho|ainda nao temos|sem|nao obrigado|nao obrigada|n\/?a|x+|-+|\.+)( ainda| por enquanto| no momento| agora)?[.!]*$/u';

	/**
	 * Negativa curta que menciona a coisa: "não tenho endereço físico", "sem
	 * redes sociais", "nenhuma por enquanto". Só vale em campo opcional.
	 */
	const RE_NEGATIVA_CURTA = '/^(ainda )?(nao|sem|nenhum|nenhuma)\b/u';

	public static function e_negativa( $bruto, $so_inteira = true ) {
		// "Não sei o que colocar" começa como negativa e é o contrário: a
		// pessoa quer responder e está pedindo ajuda. Gravar "Sem redes
		// sociais" para quem pediu uma ideia é não ouvir.
		if ( self::pede_ajuda( $bruto ) ) {
			return false;
		}
		$plano = self::simplificar( $bruto );
		$plano = trim( preg_replace( '/[!.]+$/', '', $plano ) );
		if ( '' === $plano ) {
			return false;
		}
		if ( preg_match( self::RE_NEGATIVA, $plano ) ) {
			return true;
		}
		if ( $so_inteira ) {
			return false;
		}
		// Curta, sem número, arroba ou endereço de site: com qualquer um
		// desses, a pessoa está dando a informação, não negando.
		return preg_match( self::RE_NEGATIVA_CURTA, $plano )
			&& str_word_count( $plano ) <= 6
			&& ! preg_match( '/\d|@|https?:|www\.|\.com/', $plano )
			&& ! preg_match( '/\b(mas|porem|so que|atendo|atendemos)\b/u', $plano );
	}

	public static function checar( array $campo, $bruto ) {
		$bruto = (string) $bruto;

		if ( self::comprimento( $bruto ) > self::TETO_CARACTERES ) {
			return self::erro( 'Ficou um pouco longo para este campo. Consegue resumir em algumas linhas? O resto a gente conversa depois.' );
		}

		// "Não tenho" num campo em que isso é resposta: gravado por extenso.
		if ( array_key_exists( 'nega', $campo ) && self::e_negativa( $bruto, false ) ) {
			$saida           = self::ok( (string) $campo['nega'] );
			$saida['negado'] = true;
			return $saida;
		}

		// "Não sei" num campo obrigatório: não passa, e quem responde é ela.
		// Domínio fica de fora — lá "ainda não tenho" é pendência legítima.
		if ( ! empty( $campo['obrigatorio'] ) && in_array( $campo['tipo'], array( 'texto', 'telefone' ), true ) && self::e_negativa( $bruto ) ) {
			return self::conduzir( $campo['chave'] );
		}

		if ( ! empty( $campo['formato'] ) && 'nome' === $campo['formato'] ) {
			return self::nome( $bruto, $campo['chave'] );
		}

		switch ( $campo['tipo'] ) {
			case 'telefone':
				return self::telefone( $bruto );
			case 'email':
				return self::email( $bruto, empty( $campo['obrigatorio'] ) );
			case 'dominio':
				return self::dominio( $bruto );
			case 'escolha':
				return self::escolha( $bruto, $campo );
			default:
				return self::texto( $bruto, ! empty( $campo['obrigatorio'] ) );
		}
	}

	/**
	 * Telefone brasileiro com DDD, guardado como (47) 99999-9999.
	 *
	 * O 55 do começo é aceito e removido: quem copia o número do próprio
	 * WhatsApp costuma trazer o código do país junto, e recusar isso seria
	 * recusar a forma mais confiável de mandar o número certo.
	 */
	public static function telefone( $bruto ) {
		$digitos = preg_replace( '/\D+/', '', $bruto );
		$digitos = preg_replace( '/^55(?=\d{10,11}$)/', '', $digitos );

		if ( '' === $digitos ) {
			return self::erro( 'Preciso do número com DDD para a equipe conseguir falar com você, como (47) 99999-9999.' );
		}
		if ( strlen( $digitos ) < 10 || strlen( $digitos ) > 11 ) {
			return self::erro( 'Parece que faltou alguma coisa no número. Preciso do DDD e do telefone, como (47) 99999-9999.' );
		}

		$ddd = substr( $digitos, 0, 2 );
		if ( (int) $ddd < 11 ) {
			return self::erro( 'Esse DDD não existe. Pode conferir os dois primeiros números?' );
		}

		$resto = substr( $digitos, 2 );
		$meio  = 9 === strlen( $resto ) ? substr( $resto, 0, 5 ) : substr( $resto, 0, 4 );
		$fim   = substr( $resto, strlen( $meio ) );

		return self::ok( sprintf( '(%s) %s-%s', $ddd, $meio, $fim ), true );
	}

	public static function email( $bruto, $opcional = false ) {
		$texto = trim( $bruto );

		if ( '' === $texto ) {
			return $opcional ? self::ok( '' ) : self::erro( 'Para seguir, preciso de um e-mail aqui.' );
		}
		if ( ! is_email( $texto ) ) {
			return self::erro( 'Esse e-mail parece incompleto. Confira se não faltou um ponto ou o @.' );
		}
		return self::ok( strtolower( $texto ), true );
	}

	/**
	 * Domínio, com o "ainda não tenho" tratado como resposta legítima.
	 *
	 * Sem esse caminho, quem ainda não registrou domínio fica preso num campo
	 * obrigatório — e some. O pendente é anotado e vai em destaque para a
	 * equipe, porque segura o cronômetro das 72 horas.
	 */
	public static function dominio( $bruto ) {
		$texto = trim( $bruto );
		$plano = self::simplificar( $texto );

		if ( '' === $texto ) {
			return self::erro( 'Qual é o endereço do site? Se ainda não tiver, escreva "ainda não tenho", que também vale.' );
		}
		if ( preg_match( self::RE_SEM_DOMINIO, $plano ) ) {
			$saida = self::ok( 'ainda não tenho' );
			$saida['pendente'] = true;
			return $saida;
		}

		$limpo = preg_replace( '#^[a-z]+://#i', '', $texto );
		$limpo = preg_replace( '/^www\./i', '', $limpo );
		$limpo = preg_replace( '#[/?\#].*$#', '', $limpo );
		$limpo = strtolower( trim( $limpo ) );

		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $limpo ) ) {
			return self::erro( 'Não consegui entender isso como endereço de site. Seria algo como suaempresa.com.br? Se ainda não tiver, escreva "ainda não tenho".' );
		}

		return self::ok( $limpo, true );
	}

	public static function escolha( $bruto, array $campo ) {
		$valor = self::simplificar( $bruto );
		foreach ( (array) $campo['opcoes'] as $opcao ) {
			if ( $valor === self::simplificar( $opcao['valor'] ) || $valor === self::simplificar( $opcao['texto'] ) ) {
				return self::ok( $opcao['valor'], true );
			}
		}
		return self::erro( 'É só escolher uma das duas opções.' );
	}

	/**
	 * Nome de pessoa, sem o que veio junto.
	 *
	 * "Eu, lucas" é "Lucas". "Sou eu, Marina Alves" é "Marina Alves". "eu" sozinho
	 * não é nome — e o que a equipe recebe no e-mail é o nome, não a frase.
	 */
	public static function nome( $bruto, $chave = 'responsavel' ) {
		$texto = trim( preg_replace( '/\s+/u', ' ', (string) $bruto ) );

		$prefixo = '/^(eu mesm[oa]|eu propri[oa]|eu|sou eu|sou|me chamo|meu nome [eé]|o meu nome [eé]|aqui [eé]|quem aprova [eé]|quem aprova sou|[eé] (o|a)|o|a)(?=[\s,:;.\-–—]|$)[\s,:;.\-–—]*/iu';
		for ( $i = 0; $i < 3; $i++ ) {
			$texto = trim( preg_replace( $prefixo, '', $texto ) );
		}
		$texto = trim( preg_replace( '/[\s,;.\-–—]*(mesm[oa]|\(eu\))$/iu', '', $texto ) );
		$texto = trim( $texto, " \t,;.:-–—" );

		if ( '' === $texto || self::e_negativa( $texto ) || ! preg_match( '/\p{L}{2,}/u', $texto ) ) {
			return self::conduzir( $chave );
		}

		return self::ok( self::capitalizar( $texto ) );
	}

	/**
	 * "lucas pedrozo" e "LUCAS PEDROZO" viram "Lucas Pedrozo".
	 *
	 * Só quando está tudo minúsculo ou tudo maiúsculo: "McDonald" e "DiCaprio"
	 * escritos pela própria pessoa ficam como vieram.
	 */
	public static function capitalizar( $texto ) {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			return $texto;
		}
		$minusc = mb_strtolower( $texto, 'UTF-8' );
		if ( $texto !== $minusc && mb_strtoupper( $texto, 'UTF-8' ) !== $texto ) {
			return $texto;
		}
		$palavras = explode( ' ', $minusc );
		foreach ( $palavras as $i => $p ) {
			if ( $i > 0 && in_array( $p, array( 'da', 'de', 'do', 'das', 'dos', 'e' ), true ) ) {
				continue;
			}
			$palavras[ $i ] = mb_convert_case( $p, MB_CASE_TITLE, 'UTF-8' );
		}
		return implode( ' ', $palavras );
	}

	public static function texto( $bruto, $obrigatorio ) {
		$texto = trim( preg_replace( '/[ \t]+/', ' ', (string) $bruto ) );

		if ( '' === $texto ) {
			return $obrigatorio ? self::erro( 'Preciso de uma resposta aqui para seguir.' ) : self::ok( '' );
		}
		if ( $obrigatorio && self::comprimento( $texto ) < 2 ) {
			return self::erro( 'Ficou bem curto. Pode contar um pouco mais?' );
		}
		return self::ok( $texto );
	}

	/**
	 * A mensagem parece pergunta?
	 *
	 * Heurística barata, e de propósito: ela decide se vale gastar uma chamada
	 * de modelo num campo que não comenta. Erra para o lado de chamar — deixar
	 * uma dúvida sem resposta custa mais que uma chamada a mais.
	 */
	/**
	 * A pessoa está pedindo ajuda para escrever a resposta?
	 *
	 * "Me dá uma ideia", "o que eu coloco aqui?", "escreve pra mim". Não decide
	 * nada sozinho: só garante que a mensagem chegue ao modelo em vez de cair
	 * no "não sei" escrito, e que nunca seja gravada como resposta quando o
	 * modelo não estiver lá para ajudar.
	 */
	public static function pede_ajuda( $bruto ) {
		$plano = self::simplificar( $bruto );
		if ( '' === $plano ) {
			return false;
		}
		return (bool) preg_match(
			'/\b(me ajud\w*|ajuda (a|pra|para) (escrever|montar|criar|pensar)|preciso de ajuda|(me )?(da|de|dar|daria) (uma )?(ideia|sugestao|dica)|(alguma|uma) (ideia|sugestao|dica)|sugere|sugerir|sugestoes|o que (eu )?(coloco|escrevo|ponho|boto|falo)|o que (eu )?(devo|posso|poderia) (colocar|escrever|por|falar)|nao sei (o que|como) (colocar|escrever|por|falar|descrever|explicar|responder)|(escreve|escreva|cria|crie|faz|faca|monta|monte) (pra|para|por) mim|(pode|poderia|consegue|conseguiria) (escrever|criar|fazer|montar|sugerir|me ajudar)|nao tenho (nenhuma )?ideia|sem ideia)\b/u',
			$plano
		);
	}

	/** A fala escrita de quando falta a resposta: "sem-resposta-<campo>". */
	/** Palavras que não dizem nada sozinhas: artigo, preposição, pronome. */
	const PALAVRAS_VAZIAS = array( 'de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'os', 'as', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma', 'uns', 'umas', 'com', 'para', 'pra', 'por', 'que', 'eu', 'se', 'ou', 'nos', 'mais', 'muito', 'coisa', 'coisas', 'tudo', 'etc' );

	/**
	 * Quantas palavras diferentes, que digam alguma coisa, o texto tem.
	 *
	 * "Bolo" tem uma; "faço bolos de festa por encomenda" tem quatro. Palavra
	 * de uma ou duas letras e as de PALAVRAS_VAZIAS não contam.
	 */
	public static function palavras_de_conteudo( $texto ) {
		$plano    = self::simplificar( (string) $texto );
		$palavras = preg_split( '/[^\p{L}\p{N}]+/u', $plano, -1, PREG_SPLIT_NO_EMPTY );
		$contam   = array();
		foreach ( $palavras as $p ) {
			if ( mb_strlen( $p, 'UTF-8' ) < 3 || in_array( $p, self::PALAVRAS_VAZIAS, true ) ) {
				continue;
			}
			// "mapa" e depois "mapas" é a mesma palavra dita duas vezes.
			if ( mb_strlen( $p, 'UTF-8' ) > 3 && 's' === mb_substr( $p, -1, 1, 'UTF-8' ) ) {
				$p = mb_substr( $p, 0, -1, 'UTF-8' );
			}
			$contam[ $p ] = true;
		}
		return count( $contam );
	}

	/**
	 * A resposta é curta demais para o que o campo precisa?
	 *
	 * Só vale em campo com `minimo_palavras`. O que a pessoa escreveu nas
	 * tentativas anteriores conta junto: "bolo" e depois "de festa, por
	 * encomenda" são uma resposta só.
	 */
	public static function curto( array $campo, $bruto, $anterior = '' ) {
		if ( empty( $campo['minimo_palavras'] ) ) {
			return false;
		}
		return self::palavras_de_conteudo( trim( $anterior . ' ' . $bruto ) ) < (int) $campo['minimo_palavras'];
	}

	public static function fala_de_conducao( $chave ) {
		$saida = self::conduzir( $chave );
		return $saida['erro'];
	}

	public static function parece_duvida( $bruto ) {
		$texto = (string) $bruto;
		if ( false !== strpos( $texto, '?' ) ) {
			return true;
		}
		$plano = self::simplificar( $texto );
		return (bool) preg_match(
			'/^(o que|oq|pq|por que|porque|por quê|pra que|para que|como|quem|posso|preciso|tem que|e se|nao sei|não sei|nao entendi|onde|qual a diferenca|serve pra)\b/u',
			$plano
		);
	}

	/** Minúsculas, sem acento, sem espaço sobrando. Só para comparar. */
	public static function simplificar( $texto ) {
		$texto = (string) $texto;
		$de    = array( 'á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ' );
		$para  = array( 'a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n' );
		$texto = function_exists( 'mb_strtolower' ) ? mb_strtolower( $texto, 'UTF-8' ) : strtolower( $texto );
		$texto = str_replace( $de, $para, $texto );
		return trim( preg_replace( '/\s+/', ' ', $texto ) );
	}

	private static function comprimento( $texto ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $texto, 'UTF-8' ) : strlen( $texto );
	}

	private static function ok( $valor, $conferido = false ) {
		return array( 'ok' => true, 'valor' => $valor, 'erro' => '', 'conferido' => $conferido, 'pendente' => false );
	}

	private static function erro( $mensagem ) {
		return array( 'ok' => false, 'valor' => '', 'erro' => $mensagem, 'conferido' => false, 'pendente' => false );
	}

	/** O "não sei" num obrigatório: a resposta é uma fala dela, da base. */
	private static function conduzir( $chave ) {
		$fala = class_exists( 'Leticia_Base' ) ? Leticia_Base::escolher( 'sem-resposta-' . $chave, 0 ) : null;
		if ( ! $fala && class_exists( 'Leticia_Base' ) ) {
			$fala = Leticia_Base::escolher( 'sem-resposta', 0 );
		}
		$saida             = self::erro( $fala ? trim( $fala['titulo'] . ' ' . $fala['detalhe'] ) : 'Preciso desta resposta para montar o site. Pode contar do jeito que conseguir.' );
		$saida['conduzir'] = true;
		return $saida;
	}
}
