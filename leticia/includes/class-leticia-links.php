<?php
/**
 * Os links com os dados já preenchidos: o shortcode `[leticia_links]`.
 *
 *   [leticia_links]                                  numa página só da equipe
 *   [leticia_links pagina="https://…/briefing/"]     a página que tem o [leticia]
 *
 * Quem vendeu o Site Express já sabe o nome, a empresa, o WhatsApp, o e-mail
 * e, às vezes, o domínio. Perguntar de novo é o que faz o cliente sem tempo
 * desistir antes de chegar no que importa. Esta página monta o briefing já
 * com esses dados e devolve um link para mandar ao cliente: ele abre, vê a
 * apresentação e começa direto em "O que a sua empresa faz?".
 *
 * **Os dados não vão na URL.** O rascunho é criado aqui, no servidor, e o link
 * leva só uma chave assinada — a mesma do "continuar depois", que vale 30 dias
 * e não reabre briefing enviado. Nome e telefone numa URL acabariam em
 * histórico de navegador, em log de servidor e em qualquer lugar para onde o
 * link fosse encaminhado.
 *
 * **Só para a equipe.** A página pode ser pública, mas o formulário só aparece
 * para quem está logado e pode `edit_posts` (filtro
 * `leticia_links_capacidade`). O envio confere nonce e permissão de novo.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Links {

	const SHORTCODE = 'leticia_links';
	const NONCE     = 'leticia_links';

	/** Os campos que a venda costuma saber. O resto é conversa com o cliente. */
	const CAMPOS = array( 'responsavel', 'empresa', 'whatsapp', 'email', 'dominio' );

	/** Quantos links recentes a página lista. */
	const RECENTES = 20;

	public static function iniciar() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	public static function capacidade() {
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'leticia_links_capacidade', 'edit_posts' ) : 'edit_posts';
	}

	// ------------------------------------------------------------- criar

	/**
	 * Cria o briefing preenchido e devolve o link.
	 *
	 * Cada valor passa pela mesma validação de quando o cliente digita: o
	 * WhatsApp sai normalizado, e um e-mail torto volta como erro aqui, na mão
	 * de quem vendeu — não na tela do cliente.
	 *
	 * @param array  $dados  chave do campo => valor (os vazios são ignorados)
	 * @param string $pagina a página que tem o [leticia]
	 * @param string $autor  quem gerou, para a lista
	 * @return array|WP_Error array( 'sessao', 'link', 'respostas' )
	 */
	public static function criar( array $dados, $pagina, $autor = '' ) {
		$sessao = Leticia_Rascunho::nova_sessao();
		$estado = Leticia_Roteiro::novo( Leticia_Rascunho::semente( $sessao ) );

		$erros       = array();
		$preenchidos = array();
		foreach ( self::CAMPOS as $chave ) {
			$valor = isset( $dados[ $chave ] ) ? trim( (string) $dados[ $chave ] ) : '';
			if ( '' === $valor ) {
				continue;
			}
			$r = Leticia_Roteiro::responder( $estado, $chave, $valor );
			if ( '' !== $r['erro'] ) {
				$campo           = Leticia_Campos::por_chave( $chave );
				$erros[ $chave ] = ( $campo ? $campo['rotulo'] : $chave ) . ': ' . $r['erro'];
				continue;
			}
			$estado        = $r['estado'];
			$preenchidos[] = $chave;
		}

		if ( $erros ) {
			return new WP_Error( 'dados_invalidos', implode( ' ', $erros ), array( 'campos' => $erros ) );
		}
		if ( ! $preenchidos ) {
			return new WP_Error( 'sem_dados', 'Preencha pelo menos um campo — o nome da empresa, de preferência.' );
		}

		Leticia_Rascunho::salvar( $sessao, $estado, array( 'pagina' => $pagina ) );

		$linha   = Leticia_Registro::briefing( $sessao );
		$roteiro = $linha && is_array( $linha['roteiro'] ) ? $linha['roteiro'] : array();

		// Ninguém mexeu ainda: o relógio de "parado há quanto tempo" começa
		// quando o cliente abrir, não quando a equipe gerou o link.
		unset( $roteiro['atividade'] );
		$roteiro['preenchidos'] = $preenchidos;
		$roteiro['criado_por']  = (string) $autor;
		$roteiro['link_em']     = time();
		Leticia_Registro::armazem()->gravar_briefing( $sessao, array( 'roteiro' => $roteiro ) );

		return array(
			'sessao'    => $sessao,
			'link'      => Leticia_Retomada::link( $sessao, $pagina ),
			'respostas' => $estado['respostas'],
		);
	}

	/** Os campos que a equipe preencheu — vazio quando o briefing não veio de link. */
	public static function preenchidos( $linha ) {
		return $linha && ! empty( $linha['roteiro']['preenchidos'] ) ? (array) $linha['roteiro']['preenchidos'] : array();
	}

	/** Veio de link e o cliente ainda não abriu. */
	public static function nao_aberto( $linha ) {
		return (bool) self::preenchidos( $linha ) && empty( $linha['roteiro']['aberto_em'] );
	}

	/**
	 * O cliente só tem nas respostas o que a equipe preencheu — nada dele
	 * ainda. É quando a tela ainda mostra a apresentação, e não a barra de
	 * retomada.
	 */
	public static function so_preenchido( $sessao, array $estado ) {
		$preenchidos = self::preenchidos( Leticia_Registro::briefing( $sessao ) );
		if ( ! $preenchidos ) {
			return false;
		}
		foreach ( array_keys( $estado['respostas'] ) as $chave ) {
			if ( ! in_array( $chave, $preenchidos, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** A mensagem que vai pelo WhatsApp da equipe, com o link. */
	public static function mensagem( array $respostas, $link ) {
		$nome    = Leticia_Base::primeiro_nome( isset( $respostas['responsavel']['valor'] ) ? $respostas['responsavel']['valor'] : '' );
		$estado  = Leticia_Roteiro::sanear( array( 'respostas' => $respostas ) );
		$minutos = max( 1, Leticia_Roteiro::minutos_restantes( $estado ) );
		return sprintf(
			'Oi%s! Aqui é da JoinVix. Para começarmos o seu site, deixei o briefing pronto com os dados que você já passou. Leva uns %d minutos, e dá para parar e continuar depois: %s',
			'' !== $nome ? ', ' . $nome : '',
			$minutos,
			$link
		);
	}

	public static function link_whatsapp( array $respostas, $link ) {
		$numero = Leticia_Retomada::numero_whatsapp( $respostas );
		return '' === $numero ? '' : 'https://wa.me/' . $numero . '?text=' . rawurlencode( self::mensagem( $respostas, $link ) );
	}

	// ------------------------------------------------------------- a página

	/**
	 * A página do briefing, para onde o link aponta.
	 *
	 * O atributo `pagina` do shortcode, se houver; senão, a primeira página
	 * publicada que tem o [leticia]; senão, a inicial do site.
	 */
	public static function pagina_do_briefing( $atributo = '' ) {
		$atributo = trim( (string) $atributo );
		if ( '' !== $atributo ) {
			return esc_url_raw( $atributo );
		}
		if ( function_exists( 'get_posts' ) ) {
			$paginas = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					's'              => '[leticia',
					'posts_per_page' => 20,
				)
			);
			foreach ( (array) $paginas as $p ) {
				$conteudo = isset( $p->post_content ) ? (string) $p->post_content : '';
				if ( preg_match( '/\[leticia(\s[^\]]*)?\]/', $conteudo ) ) {
					return get_permalink( $p );
				}
			}
		}
		return function_exists( 'home_url' ) ? home_url( '/' ) : '';
	}

	public static function render( $atributos = array() ) {
		$attrs = shortcode_atts( array( 'pagina' => '' ), $atributos, self::SHORTCODE );

		if ( ! is_user_logged_in() ) {
			return '<div class="leticia-links"><p>Esta página é da equipe. <a href="' . esc_url( wp_login_url( self::aqui() ) ) . '">Entre com a sua conta</a> para gerar links de briefing.</p></div>';
		}
		if ( ! current_user_can( self::capacidade() ) ) {
			return '<div class="leticia-links"><p>A sua conta não tem permissão para gerar links de briefing.</p></div>';
		}

		wp_enqueue_style( 'leticia-links', LETICIA_URL . 'public/leticia-links.css', array(), LETICIA_VERSAO );

		$pagina    = self::pagina_do_briefing( $attrs['pagina'] );
		$resultado = null;
		$enviado   = array();

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && isset( $_POST['leticia_links'] ) ) {
			$nonce = isset( $_POST['_leticia_links'] ) ? sanitize_text_field( wp_unslash( $_POST['_leticia_links'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
				$resultado = new WP_Error( 'nonce', 'A página ficou aberta tempo demais. Recarregue e tente de novo.' );
			} else {
				foreach ( self::CAMPOS as $chave ) {
					$enviado[ $chave ] = isset( $_POST['leticia_links'][ $chave ] ) ? sanitize_text_field( wp_unslash( $_POST['leticia_links'][ $chave ] ) ) : '';
				}
				$usuario   = wp_get_current_user();
				$resultado = self::criar( $enviado, $pagina, $usuario && isset( $usuario->display_name ) ? $usuario->display_name : '' );
				if ( ! is_wp_error( $resultado ) ) {
					$enviado = array();   // link gerado: o formulário volta limpo
				}
			}
		}

		ob_start();
		echo '<div class="leticia-links">';
		echo '<h2>Link de briefing com os dados preenchidos</h2>';
		echo '<p class="leticia-links-nota">Preencha o que você já sabe do cliente. Ele abre o link, vê a apresentação e começa direto no que só ele pode contar. O link vale 30 dias.</p>';

		if ( is_wp_error( $resultado ) ) {
			echo '<div class="leticia-links-aviso e-ruim">' . esc_html( $resultado->get_error_message() ) . '</div>';
		} elseif ( $resultado ) {
			self::mostrar_link( $resultado['link'], $resultado['respostas'], true );
		}

		echo '<form method="post" class="leticia-links-form" autocomplete="off">';
		wp_nonce_field( self::NONCE, '_leticia_links' );
		$rotulos = array(
			'responsavel' => array( 'Nome do responsável', 'Marina Alves' ),
			'empresa'     => array( 'Empresa', 'Padaria Aurora' ),
			'whatsapp'    => array( 'WhatsApp', '(47) 99999-9999' ),
			'email'       => array( 'E-mail', 'marina@padariaaurora.com.br' ),
			'dominio'     => array( 'Domínio (se já tiver)', 'padariaaurora.com.br' ),
		);
		foreach ( $rotulos as $chave => $r ) {
			printf(
				'<label><span>%s</span><input type="text" name="leticia_links[%s]" value="%s" placeholder="%s"></label>',
				esc_html( $r[0] ),
				esc_attr( $chave ),
				esc_attr( isset( $enviado[ $chave ] ) ? $enviado[ $chave ] : '' ),
				esc_attr( $r[1] )
			);
		}
		echo '<button type="submit">Gerar link</button>';
		printf( '<p class="leticia-links-nota">O link abre em <a href="%1$s" target="_blank" rel="noopener">%1$s</a>.</p>', esc_url( $pagina ) );
		echo '</form>';

		self::recentes( $pagina );
		echo '</div>';
		self::script_copiar();

		return ob_get_clean();
	}

	private static function mostrar_link( $link, array $respostas, $novo = false ) {
		$zap = self::link_whatsapp( $respostas, $link );
		echo '<div class="leticia-links-resultado' . ( $novo ? ' e-bom' : '' ) . '">';
		if ( $novo ) {
			echo '<strong>Link pronto.</strong> Mande para o cliente:';
		}
		printf( '<div class="leticia-links-linha"><input type="text" readonly value="%s"><button type="button" data-leticia-copiar>Copiar</button></div>', esc_attr( $link ) );
		if ( '' !== $zap ) {
			printf( '<a class="leticia-links-zap" href="%s" target="_blank" rel="noopener">Mandar pelo WhatsApp, com a mensagem pronta</a>', esc_url( $zap ) );
		}
		echo '</div>';
	}

	/** Os últimos links gerados, com a situação de cada um. */
	private static function recentes( $pagina ) {
		$linhas = array();
		foreach ( Leticia_Registro::armazem()->listar_briefings( array( 'limite' => 300 ) ) as $b ) {
			if ( self::preenchidos( $b ) ) {
				$linhas[] = $b;
			}
		}
		usort(
			$linhas,
			function ( $a, $b ) {
				return (int) $b['roteiro']['link_em'] - (int) $a['roteiro']['link_em'];
			}
		);
		$linhas = array_slice( $linhas, 0, self::RECENTES );

		echo '<h3>Links recentes</h3>';
		if ( ! $linhas ) {
			echo '<p class="leticia-links-nota">Nenhum link gerado ainda.</p>';
			return;
		}

		echo '<table class="leticia-links-tabela"><thead><tr><th>Cliente</th><th>Situação</th><th>Link</th></tr></thead><tbody>';
		foreach ( $linhas as $b ) {
			$respostas = (array) $b['respostas'];
			$nome      = isset( $respostas['responsavel']['valor'] ) ? $respostas['responsavel']['valor'] : '';
			$quem      = trim( ( '' !== $b['empresa'] ? $b['empresa'] : '(sem empresa)' ) . ( '' !== $nome ? ' · ' . $nome : '' ) );
			$por       = ! empty( $b['roteiro']['criado_por'] ) ? ' · por ' . $b['roteiro']['criado_por'] : '';

			echo '<tr><td><strong>' . esc_html( $quem ) . '</strong><br><small>' . esc_html( self::ha_quanto( (int) $b['roteiro']['link_em'] ) . $por ) . '</small></td>';
			echo '<td>' . esc_html( self::situacao( $b ) ) . '</td><td>';
			if ( (int) $b['enviado_em'] < 1 ) {
				// Link novo a cada abertura da página: 30 dias contados de agora.
				self::mostrar_link( Leticia_Retomada::link( $b['sessao'], (string) ( '' !== $b['pagina'] ? $b['pagina'] : $pagina ) ), $respostas );
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function situacao( array $b ) {
		if ( (int) $b['enviado_em'] > 0 ) {
			return 'Briefing enviado';
		}
		if ( self::nao_aberto( $b ) ) {
			return 'Ainda não abriu';
		}
		return sprintf( 'Preenchendo · %d de %d', (int) $b['respondidos'], (int) Leticia_Campos::total() );
	}

	private static function ha_quanto( $quando ) {
		$s = max( 0, time() - (int) $quando );
		if ( $s < 3600 ) {
			return 'há ' . max( 1, (int) round( $s / 60 ) ) . ' min';
		}
		if ( $s < 86400 ) {
			return 'há ' . (int) round( $s / 3600 ) . ' h';
		}
		return 'há ' . (int) round( $s / 86400 ) . ' dia(s)';
	}

	private static function aqui() {
		if ( function_exists( 'get_permalink' ) && get_permalink() ) {
			return get_permalink();
		}
		return function_exists( 'home_url' ) ? home_url( '/' ) : '';
	}

	/** Copiar sem biblioteca: seleciona o campo e usa a área de transferência. */
	private static function script_copiar() {
		?>
<script>
(function () {
  document.querySelectorAll('.leticia-links [data-leticia-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var campo = b.parentNode.querySelector('input');
      var feito = function () { b.textContent = 'Copiado'; setTimeout(function () { b.textContent = 'Copiar'; }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(campo.value).then(feito, function () { campo.select(); });
      } else { campo.select(); try { document.execCommand('copy'); feito(); } catch (e) {} }
    });
  });
})();
</script>
		<?php
	}
}
