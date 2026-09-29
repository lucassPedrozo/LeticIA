<?php
/**
 * Onde os briefings ficam guardados.
 *
 * Há duas implementações, e a segunda não é luxo de arquitetura: é o que
 * permite rodar o produto inteiro sem WordPress.
 *
 *   Leticia_Armazem_Wpdb  duas tabelas, em produção
 *   Leticia_Armazem_Json  um arquivo, para o terminal e para a suíte
 *
 * A troca é por filtro, então nada no resto do plugin sabe qual dos dois está
 * respondendo. O ganho prático: os casos de teste do registro e do rascunho são
 * de verdade — gravam, leem e conferem — em vez de espiar se um insert foi
 * tentado. Teste que só observa a intenção não pega o campo que ficou de fora.
 *
 * O contrato é pequeno de propósito. Cada método aqui é uma pergunta que o
 * produto realmente faz; consulta que existe "porque um dia pode ser útil" é
 * consulta que ninguém mantém.
 */

defined( 'ABSPATH' ) || exit;

abstract class Leticia_Armazem {

	/** Cria o que precisa existir. Idempotente. */
	abstract public function instalar();

	/**
	 * Grava o briefing de uma sessão. Cria se não existir, atualiza se existir.
	 *
	 * Um briefing por sessão, sempre: é isto que faz clicar duas vezes em
	 * enviar gravar um briefing, e não dois.
	 */
	abstract public function gravar_briefing( $sessao, array $dados );

	/** @return array|null */
	abstract public function ler_briefing( $sessao );

	abstract public function apagar_briefing( $sessao );

	/**
	 * @param array $filtros 'enviados' => true|false|null, 'limite', 'desde'
	 * @return array
	 */
	abstract public function listar_briefings( array $filtros = array() );

	abstract public function gravar_turno( $sessao, array $dados );

	abstract public function turnos( $sessao );

	/** Apaga conversas mais velhas que N dias. Devolve quantas linhas saíram. */
	abstract public function expurgar_turnos( $dias );

	/** Apaga briefings **abandonados** mais velhos que N dias. */
	abstract public function expurgar_briefings( $dias );

	/** As colunas de um briefing, com os padrões. Uma fonte só para as duas. */
	public static function molde_briefing() {
		return array(
			'sessao'        => '',
			'criado_em'     => 0,
			'atualizado_em' => 0,
			'enviado_em'    => 0,      // zero = ainda não enviado, ou seja, abandonado
			'campo_parado'  => '',
			'respondidos'   => 0,
			'empresa'       => '',
			'whatsapp'      => '',
			'email'         => '',
			'respostas'     => array(),
			'arquivos'      => array(),
			'pendencias'    => array(),
			// O que o roteiro precisa lembrar entre requisições e não é
			// resposta: hoje, quantas vezes cada campo já foi reperguntado.
			'roteiro'       => array(),
			'pagina'        => '',
			'entregue'      => 0,
			'tentativas'    => 0,
		);
	}

	public static function molde_turno() {
		return array(
			'sessao'         => '',
			'campo'          => '',
			'papel'          => 'cliente',   // cliente | leticia
			'texto'          => '',
			'tipo'           => '',          // resposta | duvida | fora_de_escopo
			'suficiente'     => 1,
			'bloqueio'       => '',
			'degradado'      => 0,
			'modelo'         => '',
			'tokens_entrada' => 0,
			'tokens_saida'   => 0,
			'ms'             => 0,
			'criado_em'      => 0,
		);
	}

	/** Completa e põe nos tipos certos o que veio de fora. */
	public static function encaixar( array $dados, array $molde ) {
		$saida = array();
		foreach ( $molde as $coluna => $padrao ) {
			$valor = isset( $dados[ $coluna ] ) ? $dados[ $coluna ] : $padrao;
			if ( is_int( $padrao ) ) {
				$valor = (int) $valor;
			} elseif ( is_string( $padrao ) && ! is_string( $valor ) ) {
				$valor = (string) $valor;
			} elseif ( is_array( $padrao ) && ! is_array( $valor ) ) {
				$valor = $padrao;
			}
			$saida[ $coluna ] = $valor;
		}
		return $saida;
	}
}
