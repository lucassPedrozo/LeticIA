<?php
/**
 * O armazém de produção: duas tabelas.
 *
 *   wp_leticia_briefings  um por sessão, enviado ou não
 *   wp_leticia_turnos     a conversa, para o ciclo de melhoria
 *
 * A separação importa porque os dois têm prazos de vida diferentes. O briefing
 * é a entrega — é o que a equipe usa para montar o site, e some quando alguém
 * mandar sumir. A conversa é diagnóstico: serve para descobrir em que campo as
 * pessoas param e qual pergunta está mal escrita, e depois de noventa dias não
 * serve mais para nada além de guardar dado pessoal de cliente.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Armazem_Wpdb extends Leticia_Armazem {

	const VERSAO_TABELA = 2;
	const OPCAO_VERSAO  = 'leticia_db_versao';

	public function tabela_briefings() {
		global $wpdb;
		return $wpdb->prefix . 'leticia_briefings';
	}

	public function tabela_turnos() {
		global $wpdb;
		return $wpdb->prefix . 'leticia_turnos';
	}

	public function instalar() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();

		// Formatação exigida pelo dbDelta: dois espaços depois de PRIMARY KEY,
		// uma coluna por linha, KEY escrito por extenso.
		$sql = "CREATE TABLE {$this->tabela_briefings()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sessao varchar(64) NOT NULL,
			criado_em bigint(20) unsigned NOT NULL DEFAULT 0,
			atualizado_em bigint(20) unsigned NOT NULL DEFAULT 0,
			enviado_em bigint(20) unsigned NOT NULL DEFAULT 0,
			campo_parado varchar(40) NOT NULL DEFAULT '',
			respondidos smallint(5) unsigned NOT NULL DEFAULT 0,
			empresa varchar(190) NOT NULL DEFAULT '',
			whatsapp varchar(40) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			respostas longtext NULL,
			arquivos longtext NULL,
			roteiro longtext NULL,
			pendencias varchar(190) NOT NULL DEFAULT '',
			pagina varchar(255) NOT NULL DEFAULT '',
			entregue tinyint(1) NOT NULL DEFAULT 0,
			tentativas smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY sessao (sessao),
			KEY enviado_em (enviado_em),
			KEY campo_parado (campo_parado)
		) {$collate};";

		$sql .= "CREATE TABLE {$this->tabela_turnos()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sessao varchar(64) NOT NULL,
			campo varchar(40) NOT NULL DEFAULT '',
			papel varchar(10) NOT NULL DEFAULT 'cliente',
			texto longtext NULL,
			tipo varchar(20) NOT NULL DEFAULT '',
			suficiente tinyint(1) NOT NULL DEFAULT 1,
			bloqueio varchar(120) NOT NULL DEFAULT '',
			degradado tinyint(1) NOT NULL DEFAULT 0,
			modelo varchar(60) NOT NULL DEFAULT '',
			tokens_entrada int(10) unsigned NOT NULL DEFAULT 0,
			tokens_saida int(10) unsigned NOT NULL DEFAULT 0,
			ms int(10) unsigned NOT NULL DEFAULT 0,
			criado_em bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY sessao (sessao),
			KEY campo (campo),
			KEY criado_em (criado_em)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::OPCAO_VERSAO, self::VERSAO_TABELA, false );

		return true;
	}

	/**
	 * Migra quando o plugin é atualizado sem passar pela ativação.
	 *
	 * Subir arquivos novos por cima — que é como uma atualização acontece na
	 * prática — não dispara o hook de ativação. Sem esta checagem, a tabela
	 * ficaria na versão antiga enquanto o código já grava colunas novas, e cada
	 * insert falharia calado: o cliente veria a tela normalmente e o briefing
	 * simplesmente não existiria.
	 */
	public function conferir_tabela() {
		if ( (int) get_option( self::OPCAO_VERSAO, 0 ) < self::VERSAO_TABELA ) {
			$this->instalar();
		}
	}

	// ----------------------------------------------------------- briefings

	/**
	 * Grava só as colunas que vieram.
	 *
	 * O contrato de `gravar_briefing` é "cria ou atualiza", e atualizar quer
	 * dizer mexer no que foi pedido. A primeira versão completava `$dados` com o
	 * molde também na atualização — e `marcar_entregue()`, que manda só
	 * `entregue` e `tentativas`, zerava as respostas e o carimbo de envio do
	 * briefing que tinha acabado de chegar. O armazém em arquivo sempre mesclou;
	 * era por isso que a suíte não via.
	 */
	public function gravar_briefing( $sessao, array $dados ) {
		global $wpdb;

		$agora    = time();
		$completa = self::encaixar( $dados, self::molde_briefing() );

		$existe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->tabela_briefings()} WHERE sessao = %s", $sessao ) ); // phpcs:ignore

		if ( $existe ) {
			$linha = array_intersect_key( $completa, $dados );
			unset( $linha['criado_em'], $linha['sessao'] );   // não se reescreve nascimento
			$linha['atualizado_em'] = $agora;
			$wpdb->update( $this->tabela_briefings(), $this->empacotar( $linha ), array( 'sessao' => $sessao ) );
			return (int) $existe;
		}

		$linha                  = $completa;
		$linha['sessao']        = $sessao;
		$linha['atualizado_em'] = $agora;
		$linha['criado_em']     = $linha['criado_em'] ? $linha['criado_em'] : $agora;
		$wpdb->insert( $this->tabela_briefings(), $this->empacotar( $linha ) );

		return (int) $wpdb->insert_id;
	}

	/** As colunas de lista viram texto, só as que estiverem na linha. */
	private function empacotar( array $linha ) {
		foreach ( array( 'respostas', 'arquivos', 'roteiro' ) as $coluna ) {
			if ( array_key_exists( $coluna, $linha ) ) {
				$linha[ $coluna ] = wp_json_encode( $linha[ $coluna ] );
			}
		}
		if ( array_key_exists( 'pendencias', $linha ) ) {
			$linha['pendencias'] = implode( ',', (array) $linha['pendencias'] );
		}
		return $linha;
	}

	public function ler_briefing( $sessao ) {
		global $wpdb;

		$linha = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tabela_briefings()} WHERE sessao = %s", $sessao ), ARRAY_A ); // phpcs:ignore
		return $linha ? $this->desempacotar( $linha ) : null;
	}

	public function apagar_briefing( $sessao ) {
		global $wpdb;
		$wpdb->delete( $this->tabela_briefings(), array( 'sessao' => $sessao ) );
		$wpdb->delete( $this->tabela_turnos(), array( 'sessao' => $sessao ) );
		return true;
	}

	public function listar_briefings( array $filtros = array() ) {
		global $wpdb;

		$onde   = array( '1=1' );
		$valores = array();

		if ( isset( $filtros['enviados'] ) && null !== $filtros['enviados'] ) {
			$onde[] = $filtros['enviados'] ? 'enviado_em > 0' : 'enviado_em = 0';
		}
		if ( ! empty( $filtros['desde'] ) ) {
			$onde[]    = 'atualizado_em >= %d';
			$valores[] = (int) $filtros['desde'];
		}

		$limite    = isset( $filtros['limite'] ) ? max( 1, (int) $filtros['limite'] ) : 50;
		$valores[] = $limite;

		$sql = 'SELECT * FROM ' . $this->tabela_briefings()
			. ' WHERE ' . implode( ' AND ', $onde )
			. ' ORDER BY atualizado_em DESC LIMIT %d';

		$linhas = $wpdb->get_results( $wpdb->prepare( $sql, $valores ), ARRAY_A ); // phpcs:ignore

		return array_map( array( $this, 'desempacotar' ), (array) $linhas );
	}

	private function desempacotar( array $linha ) {
		$linha['respostas']  = json_decode( (string) $linha['respostas'], true );
		$linha['arquivos']   = json_decode( (string) $linha['arquivos'], true );
		$linha['roteiro']    = isset( $linha['roteiro'] ) ? json_decode( (string) $linha['roteiro'], true ) : array();
		$linha['roteiro']    = is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();
		$linha['pendencias'] = array_filter( explode( ',', (string) $linha['pendencias'] ) );

		$linha['respostas'] = is_array( $linha['respostas'] ) ? $linha['respostas'] : array();
		$linha['arquivos']  = is_array( $linha['arquivos'] ) ? $linha['arquivos'] : array();

		return $linha;
	}

	// -------------------------------------------------------------- turnos

	public function gravar_turno( $sessao, array $dados ) {
		global $wpdb;

		$linha              = self::encaixar( $dados, self::molde_turno() );
		$linha['sessao']    = $sessao;
		$linha['criado_em'] = $linha['criado_em'] ? $linha['criado_em'] : time();

		$wpdb->insert( $this->tabela_turnos(), $linha );
		return (int) $wpdb->insert_id;
	}

	public function turnos( $sessao ) {
		global $wpdb;
		$linhas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->tabela_turnos()} WHERE sessao = %s ORDER BY id ASC", $sessao ), ARRAY_A ); // phpcs:ignore
		return (array) $linhas;
	}

	// ------------------------------------------------------------- expurgo

	public function expurgar_turnos( $dias ) {
		global $wpdb;
		$corte = time() - ( (int) $dias * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tabela_turnos()} WHERE criado_em < %d", $corte ) ); // phpcs:ignore
	}

	public function expurgar_briefings( $dias ) {
		global $wpdb;
		$corte = time() - ( (int) $dias * DAY_IN_SECONDS );
		// enviado_em = 0 e nada mais: briefing entregue não se apaga sozinho.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tabela_briefings()} WHERE enviado_em = 0 AND atualizado_em < %d", $corte ) ); // phpcs:ignore
	}
}
