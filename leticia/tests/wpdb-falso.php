<?php
/**
 * Um $wpdb de mentira, em memória, que entende só o SQL que o armazém escreve.
 *
 * Existe por causa de um defeito que a suíte não via: todos os casos rodavam
 * no armazém em arquivo, e o de banco — o que vai para produção — zerava as
 * respostas de um briefing a cada gravação parcial. Com isto, os casos de
 * contrato rodam nos dois, e um armazém não pode mais divergir do outro calado.
 *
 * Devolve tudo como texto, igual ao $wpdb de verdade: é o tipo de diferença
 * ("0" em vez de 0) que só aparece em produção.
 */

class Leticia_Wpdb_Falso {

	public $prefix    = 'wp_';
	public $insert_id = 0;

	private $tabelas = array();
	private $proximo = array();

	public function get_charset_collate() {
		return '';
	}

	public function prepare( $sql ) {
		$args = array_slice( func_get_args(), 1 );
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$valor = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $valor : "'" . addslashes( (string) $valor ) . "'";
			},
			$sql
		);
	}

	public function insert( $tabela, array $dados ) {
		$this->proximo[ $tabela ] = isset( $this->proximo[ $tabela ] ) ? $this->proximo[ $tabela ] + 1 : 1;
		$dados['id']              = $this->proximo[ $tabela ];
		$this->tabelas[ $tabela ][ $dados['id'] ] = array_map( 'strval', $dados );
		$this->insert_id          = $dados['id'];
		return 1;
	}

	public function update( $tabela, array $dados, array $onde ) {
		$n = 0;
		foreach ( $this->linhas( $tabela ) as $id => $linha ) {
			if ( array_intersect_assoc( array_map( 'strval', $onde ), $linha ) == array_map( 'strval', $onde ) ) {
				$this->tabelas[ $tabela ][ $id ] = array_merge( $linha, array_map( 'strval', $dados ) );
				$n++;
			}
		}
		return $n;
	}

	public function delete( $tabela, array $onde ) {
		$n = 0;
		foreach ( $this->linhas( $tabela ) as $id => $linha ) {
			if ( array_intersect_assoc( array_map( 'strval', $onde ), $linha ) == array_map( 'strval', $onde ) ) {
				unset( $this->tabelas[ $tabela ][ $id ] );
				$n++;
			}
		}
		return $n;
	}

	public function get_var( $sql ) {
		$linhas = $this->selecionar( $sql );
		if ( ! $linhas ) {
			return null;
		}
		$primeira = reset( $linhas );
		preg_match( '/SELECT\s+(\w+)/i', $sql, $m );
		return isset( $primeira[ $m[1] ] ) ? $primeira[ $m[1] ] : null;
	}

	public function get_row( $sql ) {
		$linhas = $this->selecionar( $sql );
		return $linhas ? reset( $linhas ) : null;
	}

	public function get_results( $sql ) {
		return array_values( $this->selecionar( $sql ) );
	}

	public function query( $sql ) {
		if ( ! preg_match( '/DELETE FROM\s+(\w+)\s+WHERE\s+(.+)$/is', $sql, $m ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->linhas( $m[1] ) as $id => $linha ) {
			if ( $this->atende( $linha, $m[2] ) ) {
				unset( $this->tabelas[ $m[1] ][ $id ] );
				$n++;
			}
		}
		return $n;
	}

	private function linhas( $tabela ) {
		return isset( $this->tabelas[ $tabela ] ) ? $this->tabelas[ $tabela ] : array();
	}

	private function selecionar( $sql ) {
		if ( ! preg_match( '/FROM\s+(\w+)\s+WHERE\s+(.+?)(?:\s+ORDER BY\s+(\w+)\s+(ASC|DESC))?(?:\s+LIMIT\s+(\d+))?\s*$/is', $sql, $m ) ) {
			return array();
		}
		$saida = array();
		foreach ( $this->linhas( $m[1] ) as $linha ) {
			if ( $this->atende( $linha, $m[2] ) ) {
				$saida[] = $linha;
			}
		}
		if ( ! empty( $m[3] ) ) {
			$coluna = $m[3];
			$desc   = 'DESC' === strtoupper( $m[4] );
			usort(
				$saida,
				function ( $a, $b ) use ( $coluna, $desc ) {
					$c = (float) $a[ $coluna ] <=> (float) $b[ $coluna ];
					if ( 0 === $c ) {
						$c = (int) $a['id'] <=> (int) $b['id'];
					}
					return $desc ? -$c : $c;
				}
			);
		}
		if ( ! empty( $m[5] ) ) {
			$saida = array_slice( $saida, 0, (int) $m[5] );
		}
		return $saida;
	}

	private function atende( array $linha, $onde ) {
		foreach ( preg_split( '/\s+AND\s+/i', trim( $onde ) ) as $cond ) {
			if ( '1=1' === $cond ) {
				continue;
			}
			if ( ! preg_match( "/^(\w+)\s*(=|>=|<=|>|<)\s*'?(.*?)'?$/", $cond, $c ) ) {
				return false;
			}
			$valor = isset( $linha[ $c[1] ] ) ? $linha[ $c[1] ] : '';
			$alvo  = stripslashes( $c[3] );
			$ok    = array(
				'='  => (string) $valor === (string) $alvo,
				'>'  => (float) $valor > (float) $alvo,
				'<'  => (float) $valor < (float) $alvo,
				'>=' => (float) $valor >= (float) $alvo,
				'<=' => (float) $valor <= (float) $alvo,
			);
			if ( ! $ok[ $c[2] ] ) {
				return false;
			}
		}
		return true;
	}
}
