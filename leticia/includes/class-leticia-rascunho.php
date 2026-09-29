<?php
/**
 * Salvar e retomar.
 *
 * Um briefing leva de dez a quinze minutos. Abas fecham, o celular toca, a
 * pessoa vai procurar o arquivo da logomarca e não volta. Sem retomada, tudo
 * isso é briefing perdido — e a maior parte dele já estava respondida.
 *
 * **Fica no servidor, não no navegador.** O que o navegador guarda é um token
 * assinado, e só. Duas razões: o estado precisa aparecer no painel enquanto
 * está abandonado, e o que está no `localStorage` do cliente não pode decidir
 * o que o servidor aceita como respondido.
 *
 * **`localStorage`, e não `sessionStorage`.** A LivIA escolheu `sessionStorage`
 * de propósito, porque a conversa dela não deve aparecer para a próxima pessoa
 * num computador compartilhado. Aqui o caso é o oposto: retomar dias depois é o
 * comportamento desejado. O computador compartilhado continua existindo, e a
 * resposta para ele não é apagar tudo — é a saída explícita: "não é você?
 * começar do zero".
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Rascunho {

	/** Sete dias. Passou disso, o token não abre mais nada. */
	const VALIDADE = 604800;

	public static function nova_sessao() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * A semente das variantes de pergunta, derivada do id da sessão.
	 *
	 * Derivada e não sorteada-e-gravada: assim ela é a mesma desde a primeira
	 * requisição, antes de existir rascunho. Gravar só para guardá-la criaria
	 * uma linha no banco para cada visita à página — robô de busca incluído.
	 */
	public static function semente( $sessao ) {
		return self::sessao_valida( $sessao ) ? (int) hexdec( substr( $sessao, 0, 7 ) ) : 0;
	}

	public static function sessao_valida( $sessao ) {
		return is_string( $sessao ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $sessao );
	}

	/**
	 * O token que o navegador guarda.
	 *
	 * Assinado, não criptografado: ele não esconde nada, só prova que a sessão
	 * saiu daqui. Quem editar o id na mão produz uma assinatura que não confere
	 * e recebe um briefing novo — não o de outra pessoa.
	 */
	public static function assinar( $sessao, $expira = 0 ) {
		$expira = $expira ? (int) $expira : time() + self::VALIDADE;
		$carga  = $sessao . '|' . $expira;
		$firma  = hash_hmac( 'sha256', $carga, self::segredo() );

		return rtrim( strtr( base64_encode( $carga . '|' . $firma ), '+/', '-_' ), '=' );
	}

	/** @return string|WP_Error o id da sessão */
	public static function conferir( $token ) {
		$cru = base64_decode( strtr( (string) $token, '-_', '+/' ), true );
		if ( false === $cru ) {
			return new WP_Error( 'token_invalido', 'Token ilegível.' );
		}

		$partes = explode( '|', $cru );
		if ( 3 !== count( $partes ) ) {
			return new WP_Error( 'token_invalido', 'Token malformado.' );
		}

		list( $sessao, $expira, $firma ) = $partes;

		$esperada = hash_hmac( 'sha256', $sessao . '|' . $expira, self::segredo() );
		if ( ! hash_equals( $esperada, $firma ) ) {
			return new WP_Error( 'token_invalido', 'Assinatura não confere.' );
		}
		if ( (int) $expira < time() ) {
			return new WP_Error( 'token_expirado', 'Esse briefing expirou.' );
		}
		if ( ! self::sessao_valida( $sessao ) ) {
			return new WP_Error( 'token_invalido', 'Sessão inválida.' );
		}

		return $sessao;
	}

	private static function segredo() {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'leticia' );
		}
		// Fora do WordPress — terminal e suíte — a chave sai do ambiente, e
		// tem um padrão para o caso de ninguém ter definido nada. O padrão só
		// vale fora de produção: dentro do WordPress o wp_salt sempre existe.
		$do_ambiente = getenv( 'LETICIA_SALT' );
		return $do_ambiente ? $do_ambiente : 'leticia-desenvolvimento';
	}

	// ------------------------------------------------------------- estado

	/**
	 * Guarda o andamento. Chamado a cada campo resolvido.
	 *
	 * Grava na mesma tabela do briefing enviado, de propósito: um rascunho é um
	 * briefing que ainda não saiu. Tabelas separadas fariam o painel precisar
	 * somar duas fontes para responder "quantos briefings começaram hoje".
	 */
	public static function salvar( $sessao, array $estado, array $extra = array() ) {
		if ( ! self::sessao_valida( $sessao ) ) {
			return new WP_Error( 'sessao_invalida', 'Sessão inválida.' );
		}
		return Leticia_Registro::salvar( $sessao, $estado, $extra );
	}

	/**
	 * Devolve o estado de onde a pessoa parou.
	 *
	 * Sempre passa pelo saneamento do roteiro: o que está guardado pode ter
	 * sido escrito por uma versão anterior do plugin, com campo que não existe
	 * mais. Confiar no que está no banco é o mesmo erro que confiar no que vem
	 * do navegador, só que mais difícil de perceber.
	 *
	 * @return array|null
	 */
	public static function carregar( $sessao ) {
		if ( ! self::sessao_valida( $sessao ) ) {
			return null;
		}

		$linha = Leticia_Registro::briefing( $sessao );
		if ( ! $linha ) {
			return null;
		}
		// Briefing já enviado não é rascunho: retomar um enviado faria a pessoa
		// preencher de novo o que a equipe já recebeu.
		if ( (int) $linha['enviado_em'] > 0 ) {
			return null;
		}

		$roteiro = isset( $linha['roteiro'] ) && is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();

		// A contagem de reperguntas precisa voltar junto. Sem ela, cada
		// requisição reconstrói o estado com o limite zerado — e a LetícIA
		// insiste para sempre com quem já respondeu.
		$estado = Leticia_Roteiro::sanear(
			array(
				'respostas'     => $linha['respostas'],
				'reperguntados' => isset( $roteiro['reperguntados'] ) ? $roteiro['reperguntados'] : array(),
				'ultima_ponte'  => isset( $roteiro['ultima_ponte'] ) ? $roteiro['ultima_ponte'] : '',
				// O rascunho esperando decisão volta junto: recarregar a página
				// no meio da escolha não pode sumir com o texto.
				'propostas'     => isset( $roteiro['propostas'] ) ? $roteiro['propostas'] : array(),
				'anteriores'    => isset( $roteiro['anteriores'] ) ? $roteiro['anteriores'] : array(),
				'semente'       => self::semente( $sessao ),
				'indice'        => 0,
				'enviado'       => false,
			)
		);

		// O ponteiro sai das respostas, não do zero. Com zero, toda tela que
		// mantém a pessoa no campo — dúvida, repergunta, pedido de ajuda —
		// dizia "Etapa 1 de 3" no meio da etapa 2.
		$proximo          = Leticia_Roteiro::proximo( $estado );
		$estado['indice'] = $proximo ? Leticia_Campos::indice( $proximo['chave'] ) : Leticia_Campos::total();

		return $estado;
	}

	/**
	 * O que a barra de retomada mostra.
	 *
	 * "Retomando o briefing da Padaria Aurora, na etapa 2. Não é você? Começar
	 * do zero." A saída existe por causa do computador compartilhado, e por
	 * causa da pessoa que quer recomeçar mesmo.
	 *
	 * @return array|null
	 */
	public static function resumo( $sessao ) {
		$estado = self::carregar( $sessao );
		if ( ! $estado ) {
			return null;
		}
		// Só com o que a equipe preencheu não há o que retomar: é a primeira
		// vez do cliente, e quem recebe ele é a apresentação.
		if ( Leticia_Links::so_preenchido( $sessao, $estado ) ) {
			return null;
		}

		$resolvidos = Leticia_Roteiro::quantos_resolvidos( $estado );
		if ( $resolvidos < 1 ) {
			// Sem nenhuma resposta não há o que retomar, e oferecer retomada de
			// um briefing vazio só confunde.
			return null;
		}

		$proximo = Leticia_Roteiro::proximo( $estado );
		$empresa = isset( $estado['respostas']['empresa']['valor'] ) ? $estado['respostas']['empresa']['valor'] : '';

		return array(
			'empresa'     => $empresa,
			'respondidos' => $resolvidos,
			'total'       => Leticia_Campos::total(),
			'campo'       => $proximo ? $proximo['chave'] : '',
			'secao'       => $proximo ? $proximo['secao'] : count( Leticia_Campos::SECOES ),
			'frase'       => self::frase( $empresa, $proximo ),
		);
	}

	private static function frase( $empresa, $proximo ) {
		$onde = $proximo
			? sprintf( 'na etapa %d', $proximo['secao'] )
			: 'na revisão final';

		return $empresa
			? sprintf( 'Retomando o briefing da %s, %s.', $empresa, $onde )
			: sprintf( 'Retomando o briefing que você começou, %s.', $onde );
	}

	/** "Não é você? Começar do zero." */
	public static function descartar( $sessao ) {
		if ( ! self::sessao_valida( $sessao ) ) {
			return false;
		}
		return Leticia_Registro::apagar( $sessao );
	}
}
