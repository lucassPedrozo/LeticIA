<?php
/**
 * O armazém em arquivo — para o terminal e para a suíte.
 *
 * Existe por um motivo prático: dá para preencher um briefing inteiro, do
 * começo ao envio, **sem instalar nada num WordPress**. É a mesma ideia do
 * protótipo de terminal que calibrou a LivIA, e serve para o mesmo: ver o
 * produto funcionando antes de ele ter tela.
 *
 * Não tenta ser banco. Lê o arquivo, mexe no array, grava de volta, com trava
 * de arquivo para duas escritas não se atropelarem. Para um punhado de
 * briefings de desenvolvimento isso basta e sobra; para produção existe o
 * Leticia_Armazem_Wpdb, que é quem roda no servidor.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Armazem_Json extends Leticia_Armazem {

	/** Caminho especial: guarda só em memória, sem arquivo nenhum. */
	const MEMORIA = ':memoria:';

	/** @var string */
	private $caminho;

	/**
	 * O conteúdo em memória.
	 *
	 * Sem isto, cada campo respondido custava uma leitura e uma escrita do
	 * arquivo inteiro — e um briefing tem quinze campos. A suíte passava de
	 * dois segundos, que é mais do que uma suíte rodada a cada mudança aguenta.
	 *
	 * Vale porque este armazém é de um processo só: o terminal e a suíte. O de
	 * produção é o de banco, e lá quem cuida de concorrência é o MySQL.
	 *
	 * @var array|null
	 */
	private $memo = null;

	public function __construct( $caminho = '' ) {
		$this->caminho = $caminho ? $caminho : trailingslashit( sys_get_temp_dir() ) . 'leticia-registro.json';
	}

	public function caminho() {
		return $this->caminho;
	}

	public function instalar() {
		if ( self::MEMORIA === $this->caminho || ! file_exists( $this->caminho ) ) {
			$this->gravar_tudo( array( 'briefings' => array(), 'turnos' => array() ) );
		}
		return true;
	}

	// ----------------------------------------------------------- briefings

	public function gravar_briefing( $sessao, array $dados ) {
		$tudo = $this->ler_tudo();
		$agora = time();

		$antes = isset( $tudo['briefings'][ $sessao ] ) ? $tudo['briefings'][ $sessao ] : array();
		$linha = self::encaixar( array_merge( $antes, $dados ), self::molde_briefing() );

		$linha['sessao']        = $sessao;
		// Nascimento não se reescreve, nem se quem grava mandar outro.
		$linha['criado_em']     = ! empty( $antes['criado_em'] ) ? (int) $antes['criado_em'] : ( $linha['criado_em'] ? $linha['criado_em'] : $agora );
		$linha['atualizado_em'] = $agora;

		$tudo['briefings'][ $sessao ] = $linha;
		$this->gravar_tudo( $tudo );

		return $sessao;
	}

	public function ler_briefing( $sessao ) {
		$tudo = $this->ler_tudo();
		return isset( $tudo['briefings'][ $sessao ] ) ? $tudo['briefings'][ $sessao ] : null;
	}

	public function apagar_briefing( $sessao ) {
		$tudo = $this->ler_tudo();
		unset( $tudo['briefings'][ $sessao ] );
		$tudo['turnos'] = array_values(
			array_filter(
				$tudo['turnos'],
				function ( $t ) use ( $sessao ) {
					return $t['sessao'] !== $sessao;
				}
			)
		);
		$this->gravar_tudo( $tudo );
		return true;
	}

	public function listar_briefings( array $filtros = array() ) {
		$tudo   = $this->ler_tudo();
		$linhas = array_values( $tudo['briefings'] );

		if ( isset( $filtros['enviados'] ) && null !== $filtros['enviados'] ) {
			$quer = (bool) $filtros['enviados'];
			$linhas = array_values(
				array_filter(
					$linhas,
					function ( $b ) use ( $quer ) {
						return $quer ? $b['enviado_em'] > 0 : 0 === (int) $b['enviado_em'];
					}
				)
			);
		}

		if ( ! empty( $filtros['desde'] ) ) {
			$desde  = (int) $filtros['desde'];
			$linhas = array_values(
				array_filter(
					$linhas,
					function ( $b ) use ( $desde ) {
						return $b['atualizado_em'] >= $desde;
					}
				)
			);
		}

		usort(
			$linhas,
			function ( $a, $b ) {
				return $b['atualizado_em'] <=> $a['atualizado_em'];
			}
		);

		if ( ! empty( $filtros['limite'] ) ) {
			$linhas = array_slice( $linhas, 0, (int) $filtros['limite'] );
		}

		return $linhas;
	}

	// -------------------------------------------------------------- turnos

	public function gravar_turno( $sessao, array $dados ) {
		$tudo  = $this->ler_tudo();
		$linha = self::encaixar( $dados, self::molde_turno() );

		$linha['sessao']    = $sessao;
		$linha['criado_em'] = $linha['criado_em'] ? $linha['criado_em'] : time();

		$tudo['turnos'][] = $linha;
		$this->gravar_tudo( $tudo );

		return count( $tudo['turnos'] );
	}

	public function turnos( $sessao ) {
		$tudo = $this->ler_tudo();
		return array_values(
			array_filter(
				$tudo['turnos'],
				function ( $t ) use ( $sessao ) {
					return $t['sessao'] === $sessao;
				}
			)
		);
	}

	// ------------------------------------------------------------- expurgo

	public function expurgar_turnos( $dias ) {
		$corte = time() - ( (int) $dias * DAY_IN_SECONDS );
		$tudo  = $this->ler_tudo();
		$antes = count( $tudo['turnos'] );

		$tudo['turnos'] = array_values(
			array_filter(
				$tudo['turnos'],
				function ( $t ) use ( $corte ) {
					return $t['criado_em'] >= $corte;
				}
			)
		);

		$this->gravar_tudo( $tudo );
		return $antes - count( $tudo['turnos'] );
	}

	public function expurgar_briefings( $dias ) {
		$corte = time() - ( (int) $dias * DAY_IN_SECONDS );
		$tudo  = $this->ler_tudo();
		$saiu  = 0;

		foreach ( $tudo['briefings'] as $sessao => $linha ) {
			// Só abandonado. Briefing enviado é entrega feita: apagar sozinho
			// seria apagar o trabalho que a equipe já recebeu.
			if ( $linha['enviado_em'] > 0 || $linha['atualizado_em'] >= $corte ) {
				continue;
			}
			unset( $tudo['briefings'][ $sessao ] );
			$saiu++;
		}

		$this->gravar_tudo( $tudo );
		return $saiu;
	}

	// ------------------------------------------------------------- arquivo

	/** Esquece o que está em memória. Para quem mexeu no arquivo por fora. */
	public function esquecer() {
		$this->memo = null;
	}

	private function ler_tudo() {
		if ( null !== $this->memo ) {
			return $this->memo;
		}
		if ( self::MEMORIA === $this->caminho || ! file_exists( $this->caminho ) ) {
			return $this->memo = array( 'briefings' => array(), 'turnos' => array() );
		}
		$cru   = file_get_contents( $this->caminho ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$dados = json_decode( (string) $cru, true );

		if ( ! is_array( $dados ) || ! isset( $dados['briefings'] ) ) {
			// Arquivo corrompido não pode derrubar o produto: ele volta vazio, e
			// o que existia fica ao lado, com outro nome, para quem quiser ver.
			if ( '' !== trim( (string) $cru ) ) {
				@rename( $this->caminho, $this->caminho . '.quebrado' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			return $this->memo = array( 'briefings' => array(), 'turnos' => array() );
		}

		$dados['turnos'] = isset( $dados['turnos'] ) && is_array( $dados['turnos'] ) ? $dados['turnos'] : array();
		return $this->memo = $dados;
	}

	private function gravar_tudo( array $dados ) {
		$this->memo = $dados;

		// Só memória: para os casos da suíte que percorrem briefings inteiros
		// pelas rotas. Gravar o arquivo a cada campo, quinze vezes por caso, é
		// o que mais pesa no tempo da suíte, e esses casos não leem o arquivo.
		if ( self::MEMORIA === $this->caminho ) {
			return;
		}

		$pasta = dirname( $this->caminho );
		if ( ! is_dir( $pasta ) ) {
			wp_mkdir_p( $pasta );
		}
		$json = json_encode( $dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		file_put_contents( $this->caminho, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
