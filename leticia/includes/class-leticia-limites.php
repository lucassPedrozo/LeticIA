<?php
/**
 * Teto de entrada, ritmo e disjuntor — os da LetícIA, não os da LivIA.
 *
 * Os números vêm medidos da LivIA; as **chaves** não podem ser as mesmas. Se as
 * duas contassem no mesmo transient, um dia movimentado de uma abriria o
 * disjuntor da outra, e quem cai primeiro é sempre a que o cliente estava
 * usando naquela hora. Por isso aqui tem prefixo próprio e teto próprio.
 *
 * A diferença de forma em relação à LivIA: **nada aqui impede o briefing de
 * continuar**. Estourou o teto do dia, a LetícIA para de comentar e segue
 * perguntando. As funções devolvem "pode chamar o modelo?", nunca "pode usar o
 * formulário?".
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Limites {

	/** Teto de entrada por mensagem. Acima disso não vai ao modelo. */
	const TETO_ENTRADA = 1200;

	/** Chamadas por janela, por briefing. Um campo bem conversado dá 3 ou 4. */
	const POR_JANELA_SESSAO = 10;

	/** Chamadas por janela, por IP. Cobre um escritório inteiro preenchendo junto. */
	const POR_JANELA_IP = 40;

	/**
	 * Gravações por janela, por briefing. Contador próprio: quem responde tudo
	 * falando gasta uma chamada por campo antes mesmo do comentário, e somar as
	 * duas no mesmo teto calava a LetícIA no meio da seção 2.
	 */
	const POR_JANELA_VOZ = 12;

	/** Cinco minutos. */
	const JANELA = 300;

	const PREFIXO = 'leticia_lim_';

	public static function ip() {
		$bruto = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip    = filter_var( $bruto, FILTER_VALIDATE_IP );
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * A mensagem cabe num pedido ao modelo?
	 *
	 * Diferente da LivIA: aqui uma mensagem gigante **não** é recusada ao
	 * cliente. Ela é gravada como resposta do campo e só não vai ao modelo —
	 * um cliente prolixo é um cliente detalhista, e recusar o texto dele seria
	 * perder informação que a equipe quer ter.
	 */
	public static function cabe_no_pedido( $texto ) {
		$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $texto, 'UTF-8' ) : strlen( (string) $texto );
		return $tamanho <= self::TETO_ENTRADA;
	}

	/** O ritmo: quantas chamadas esta sessão e este IP já fizeram na janela. */
	public static function pode_chamar( $sessao = '' ) {
		if ( ! self::sob_o_teto( 'ip_' . md5( self::ip() ), self::POR_JANELA_IP ) ) {
			return false;
		}
		if ( '' !== $sessao && ! self::sob_o_teto( 's_' . $sessao, self::POR_JANELA_SESSAO ) ) {
			return false;
		}
		return true;
	}

	private static function sob_o_teto( $sufixo, $teto ) {
		$chave = self::PREFIXO . $sufixo;
		$conta = (int) get_transient( $chave );
		return $conta < $teto;
	}

	/** Conta uma chamada em todos os contadores de uma vez. */
	public static function registrar_chamada( $sessao = '' ) {
		self::incrementar( 'ip_' . md5( self::ip() ), self::JANELA );
		if ( '' !== $sessao ) {
			self::incrementar( 's_' . $sessao, self::JANELA );
		}
		self::incrementar( 'dia_' . self::hoje(), DAY_IN_SECONDS + HOUR_IN_SECONDS );
	}

	/**
	 * Vale gastar uma chamada ouvindo um áudio agora?
	 *
	 * @return string '' quando pode; 'desligada' ou 'ritmo' quando não
	 */
	public static function pode_ouvir( $sessao ) {
		if ( ! Leticia_Config::voz_ligada() || self::disjuntor_aberto() ) {
			return 'desligada';
		}
		if ( ! self::sob_o_teto( 'ip_' . md5( self::ip() ), self::POR_JANELA_IP ) ) {
			return 'ritmo';
		}
		if ( '' !== $sessao && ! self::sob_o_teto( 'v_' . $sessao, self::POR_JANELA_VOZ ) ) {
			return 'ritmo';
		}
		return '';
	}

	/** Conta uma gravação: no IP e no dia, como qualquer chamada, e na janela de voz. */
	public static function registrar_audio( $sessao ) {
		self::incrementar( 'ip_' . md5( self::ip() ), self::JANELA );
		if ( '' !== $sessao ) {
			self::incrementar( 'v_' . $sessao, self::JANELA );
		}
		self::incrementar( 'dia_' . self::hoje(), DAY_IN_SECONDS + HOUR_IN_SECONDS );
	}

	private static function incrementar( $sufixo, $validade ) {
		$chave = self::PREFIXO . $sufixo;
		$conta = (int) get_transient( $chave );
		set_transient( $chave, $conta + 1, $validade );
	}

	private static function hoje() {
		return gmdate( 'Ymd' );
	}

	/**
	 * Uma chamada que não precisou acontecer. Só para o painel mostrar quanto a
	 * economia rende — não entra em teto nenhum.
	 */
	public static function registrar_poupada() {
		self::incrementar( 'poupadas_' . self::hoje(), DAY_IN_SECONDS + HOUR_IN_SECONDS );
	}

	public static function poupadas_hoje() {
		return (int) get_transient( self::PREFIXO . 'poupadas_' . self::hoje() );
	}

	public static function usadas_hoje() {
		return (int) get_transient( self::PREFIXO . 'dia_' . self::hoje() );
	}

	/** @return int|null null quando não há teto */
	public static function restantes_hoje() {
		if ( ! Leticia_Config::tem_teto_diario() ) {
			return null;
		}
		return max( 0, Leticia_Config::teto_diario() - self::usadas_hoje() );
	}

	/**
	 * O disjuntor.
	 *
	 * Aberto, a LetícIA vira o formulário guiado: perguntas estáticas, validação
	 * de formato, envio normal. O cliente não vê erro nenhum — ele vê uma
	 * atendente mais calada.
	 */
	public static function disjuntor_aberto() {
		// Sem teto, não há disjuntor: quem segura o gasto é o ritmo por IP e
		// por sessão, que continua valendo.
		return Leticia_Config::tem_teto_diario() && self::usadas_hoje() >= Leticia_Config::teto_diario();
	}

	/**
	 * A pergunta que o resto do plugin faz: vale gastar uma chamada agora?
	 *
	 * Uma função só, para não acontecer de um caminho checar o disjuntor e
	 * esquecer o ritmo — que foi como a LivIA descobriu que tinha dois lugares
	 * decidindo a mesma coisa.
	 */
	public static function pode_consultar_modelo( $sessao, $texto ) {
		if ( ! Leticia_Config::pode_comentar() ) {
			return false;
		}
		if ( ! self::cabe_no_pedido( $texto ) ) {
			return false;
		}
		if ( self::disjuntor_aberto() ) {
			return false;
		}
		return self::pode_chamar( $sessao );
	}

	/** Só para a tela de configuração e a suíte. */
	public static function zerar() {
		delete_transient( self::PREFIXO . 'dia_' . self::hoje() );
		delete_transient( self::PREFIXO . 'poupadas_' . self::hoje() );
		delete_transient( self::PREFIXO . 'ip_' . md5( self::ip() ) );
	}
}
