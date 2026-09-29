<?php
/**
 * Os anexos do briefing.
 *
 * **Por que em pedaços.** `post_max_size` costuma vir em 64 MB e um vídeo de
 * celular estoura sozinho. O modo de falhar é cruel: o POST é cortado antes de
 * o PHP rodar, `$_FILES` chega vazio, e a página parece simplesmente não fazer
 * nada — não há erro para mostrar porque não houve requisição. Fatiando no
 * navegador, cada pedaço é um POST pequeno, o progresso é real, a configuração
 * do servidor deixa de importar e um envio interrompido pode ser retomado.
 *
 * **A validação acontece no fim, sobre o arquivo remontado.** Validar pedaço a
 * pedaço não diz nada: os primeiros 4 MB de um .exe renomeado para .png podem
 * ser qualquer coisa. Só o arquivo inteiro tem assinatura para o `finfo` ler.
 *
 * **Nada aqui confia no nome do arquivo.** A extensão é conferida contra a
 * lista do campo, o tipo real é conferido contra a extensão, e o nome com que o
 * arquivo é gravado em disco é aleatório — o nome original vira metadado, nunca
 * caminho.
 */

defined( 'ABSPATH' ) || exit;

class Leticia_Arquivos {

	/** O tamanho do pedaço que o navegador manda. Aceita alguma folga. */
	const PEDACO = 4194304;          // 4 MiB
	const PEDACO_TETO = 8388608;     // 8 MiB: acima disso, não foi o nosso JS

	/** Quanto tempo um envio pela metade pode esperar antes de virar lixo. */
	const VALIDADE_PARCIAL = 21600;  // 6 horas

	/**
	 * Quanto tempo um arquivo pode ficar no servidor sem ter sido entregue.
	 *
	 * Os arquivos não moram aqui: vão por e-mail e saem na entrega. O que sobra
	 * é de rascunho abandonado, ou de briefing cujo e-mail nunca saiu — e trinta
	 * dias é tempo de sobra para alguém reenviar pelo painel.
	 */
	const VALIDADE_ARQUIVO = 2592000;  // 30 dias

	const PREFIXO_PARCIAL = 'leticia_up_';

	/**
	 * Tipo real aceito para cada extensão.
	 *
	 * Mais de um por extensão porque o `finfo` responde conforme a base de
	 * magia do servidor: um .docx é um zip por dentro, e servidor com base
	 * antiga responde `application/zip` em vez do tipo do Office. Recusar isso
	 * recusaria metade dos documentos que os clientes mandam.
	 */
	public static function tipos_reais() {
		return array(
			'png'  => array( 'image/png' ),
			'jpg'  => array( 'image/jpeg' ),
			'jpeg' => array( 'image/jpeg' ),
			'webp' => array( 'image/webp' ),
			'heic' => array( 'image/heic', 'image/heif' ),
			'svg'  => array( 'image/svg+xml', 'text/xml', 'application/xml', 'text/plain' ),
			'pdf'  => array( 'application/pdf' ),
			'ai'   => array( 'application/pdf', 'application/postscript' ),
			'eps'  => array( 'application/postscript', 'image/x-eps' ),
			'zip'  => array( 'application/zip', 'application/x-zip-compressed' ),
			'doc'  => array( 'application/msword', 'application/vnd.ms-office', 'application/x-ole-storage' ),
			'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip' ),
			'odt'  => array( 'application/vnd.oasis.opendocument.text', 'application/zip' ),
			'txt'  => array( 'text/plain' ),
			'mp4'  => array( 'video/mp4' ),
			'mov'  => array( 'video/quicktime', 'video/mp4' ),
		);
	}

	// ------------------------------------------------------------- as pastas

	public static function pasta_base() {
		$padrao = Leticia_Config::pasta_definida();
		if ( '' === $padrao && function_exists( 'wp_upload_dir' ) ) {
			$envio  = wp_upload_dir();
			$padrao = trailingslashit( $envio['basedir'] ) . 'leticia';
		}
		return apply_filters( 'leticia_pasta_base', $padrao );
	}

	private static function pasta( $relativo ) {
		$caminho = trailingslashit( self::pasta_base() ) . $relativo;
		if ( ! is_dir( $caminho ) ) {
			wp_mkdir_p( $caminho );
		}
		self::proteger( self::pasta_base() );
		return $caminho;
	}

	/**
	 * Fecha a pasta para o mundo.
	 *
	 * Três arquivos, porque três servidores diferentes precisam de três
	 * respostas: `.htaccess` no Apache, `web.config` no IIS, e o `index.php`
	 * vazio para o caso de a listagem de diretório estar ligada em qualquer um
	 * deles. Nenhum substitui o outro, e nenhum substitui a rota autenticada:
	 * são camadas, não alternativas.
	 */
	public static function proteger( $pasta ) {
		if ( ! is_dir( $pasta ) ) {
			wp_mkdir_p( $pasta );
		}

		$htaccess = trailingslashit( $pasta ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$htaccess,
				"Options -Indexes\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
				. "php_flag engine off\n"
			);
		}

		$indice = trailingslashit( $pasta ) . 'index.php';
		if ( ! file_exists( $indice ) ) {
			file_put_contents( $indice, "<?php\n// Nada para ver aqui.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$iis = trailingslashit( $pasta ) . 'web.config';
		if ( ! file_exists( $iis ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$iis,
				"<configuration>\n\t<system.webServer>\n\t\t<authorization>\n"
				. "\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n"
			);
		}
	}

	private static function cofre( $sessao ) {
		return self::pasta( 'arquivos/' . self::pasta_da_sessao( $sessao ) );
	}

	private static function oficina( $sessao, $id ) {
		return self::pasta( 'parciais/' . self::pasta_da_sessao( $sessao ) . '/' . $id );
	}

	/**
	 * O nome da pasta nunca é o id da sessão.
	 *
	 * Quem descobrisse um id de sessão saberia o caminho da pasta do cliente. O
	 * hash quebra essa ponte, e não custa nada.
	 */
	private static function pasta_da_sessao( $sessao ) {
		return substr( hash( 'sha256', 'leticia|' . $sessao ), 0, 32 );
	}

	// ------------------------------------------------------------- o envio

	/**
	 * Abre um envio. Aqui só dá para conferir o que o navegador afirma.
	 *
	 * @return array|WP_Error
	 */
	public static function iniciar( $sessao, $chave_campo, $nome, $tamanho, $pedacos ) {
		$campo = Leticia_Campos::por_chave( $chave_campo );
		if ( ! $campo || 'arquivo' !== $campo['tipo'] ) {
			return new WP_Error( 'campo_invalido', 'Esse campo não recebe arquivo.' );
		}

		$nome_limpo = self::limpar_nome( $nome );
		$ext        = self::extensao( $nome_limpo );

		if ( ! in_array( $ext, $campo['aceita'], true ) ) {
			return new WP_Error(
				'extensao',
				sprintf( 'Não consigo aceitar esse tipo de arquivo aqui. Aceito %s.', $campo['aceita_texto'] )
			);
		}

		$tamanho = (int) $tamanho;
		$teto    = Leticia_Config::teto_arquivo();
		if ( $tamanho <= 0 ) {
			return new WP_Error( 'vazio', 'Esse arquivo chegou vazio. Pode tentar de novo?' );
		}
		if ( $tamanho > $teto ) {
			return new WP_Error(
				'grande',
				sprintf(
					'Esse arquivo tem %s, e o limite é %s, porque ele vai para a equipe por e-mail. Se for vídeo ou muitas fotos, compacte ou mande o link de uma pasta do Drive.',
					size_format( $tamanho ),
					size_format( $teto )
				)
			);
		}

		$pedacos = (int) $pedacos;
		if ( $pedacos < 1 || $pedacos > 10000 ) {
			return new WP_Error( 'pedacos', 'Não consegui preparar o envio. Pode tentar de novo?' );
		}

		$id = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::PREFIXO_PARCIAL . $id,
			array(
				'sessao'    => $sessao,
				'campo'     => $chave_campo,
				'nome'      => $nome_limpo,
				'ext'       => $ext,
				'tamanho'   => $tamanho,
				'pedacos'   => $pedacos,
				'recebidos' => array(),
				'aberto_em' => time(),
			),
			self::VALIDADE_PARCIAL
		);

		self::oficina( $sessao, $id );

		return array( 'id' => $id, 'pedacos' => $pedacos, 'pedaco' => self::PEDACO );
	}

	/**
	 * Recebe um pedaço.
	 *
	 * A ordem não importa: cada pedaço é gravado com o próprio índice no nome,
	 * e a remontagem lê em ordem numérica. Rede móvel entrega fora de ordem o
	 * tempo todo, e um remontador que dependesse da ordem de chegada produziria
	 * arquivos corrompidos de forma intermitente — o pior tipo de defeito.
	 *
	 * @return array|WP_Error
	 */
	public static function receber( $sessao, $id, $indice, $conteudo ) {
		$envio = self::envio( $sessao, $id );
		if ( is_wp_error( $envio ) ) {
			return $envio;
		}

		$indice = (int) $indice;
		if ( $indice < 0 || $indice >= $envio['pedacos'] ) {
			return new WP_Error( 'indice', 'Pedaço fora da conta.' );
		}

		$bytes = strlen( (string) $conteudo );
		if ( 0 === $bytes ) {
			return new WP_Error( 'pedaco_vazio', 'Pedaço vazio.' );
		}
		if ( $bytes > self::PEDACO_TETO ) {
			return new WP_Error( 'pedaco_grande', 'Pedaço maior do que o combinado.' );
		}

		$destino = self::oficina( $sessao, $id ) . '/' . $indice . '.parte';
		if ( false === file_put_contents( $destino, $conteudo ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'disco', 'Não consegui guardar essa parte do arquivo. Pode tentar de novo?' );
		}

		$envio['recebidos'][ $indice ] = $bytes;
		set_transient( self::PREFIXO_PARCIAL . $id, $envio, self::VALIDADE_PARCIAL );

		return array(
			'recebidos' => count( $envio['recebidos'] ),
			'faltam'    => $envio['pedacos'] - count( $envio['recebidos'] ),
		);
	}

	/**
	 * Remonta, valida e guarda.
	 *
	 * @return array|WP_Error metadados do arquivo
	 */
	public static function concluir( $sessao, $id ) {
		$envio = self::envio( $sessao, $id );
		if ( is_wp_error( $envio ) ) {
			return $envio;
		}

		if ( count( $envio['recebidos'] ) !== $envio['pedacos'] ) {
			return new WP_Error(
				'incompleto',
				sprintf( 'Faltaram %d pedaços desse arquivo.', $envio['pedacos'] - count( $envio['recebidos'] ) )
			);
		}

		$campo = Leticia_Campos::por_chave( $envio['campo'] );
		if ( ! $campo ) {
			return new WP_Error( 'campo_invalido', 'Esse campo não recebe arquivo.' );
		}

		// O nome em disco é aleatório. O nome que a pessoa deu é metadado, e
		// nunca vira caminho: é a defesa contra "../../wp-config.php".
		$nome_disco = bin2hex( random_bytes( 16 ) ) . '.' . $envio['ext'];
		$destino    = self::cofre( $sessao ) . '/' . $nome_disco;

		$remontou = self::remontar( self::oficina( $sessao, $id ), $envio['pedacos'], $destino );
		if ( is_wp_error( $remontou ) ) {
			self::varrer( self::oficina( $sessao, $id ) );
			return $remontou;
		}

		$problema = self::conferir( $destino, $envio['ext'], $campo );
		if ( is_wp_error( $problema ) ) {
			// Arquivo recusado não fica no disco nem por um minuto.
			@unlink( $destino ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::varrer( self::oficina( $sessao, $id ) );
			delete_transient( self::PREFIXO_PARCIAL . $id );
			return $problema;
		}

		self::varrer( self::oficina( $sessao, $id ) );
		delete_transient( self::PREFIXO_PARCIAL . $id );

		$meta = array(
			'id'         => bin2hex( random_bytes( 12 ) ),
			'campo'      => $envio['campo'],
			'nome'       => $envio['nome'],
			'disco'      => $nome_disco,
			'pasta'      => self::pasta_da_sessao( $sessao ),
			'ext'        => $envio['ext'],
			'tipo'       => self::tipo_real( $destino ),
			'tamanho'    => filesize( $destino ),
			'aviso'      => self::aviso( $destino, $envio['ext'], $campo ),
			'enviado_em' => time(),
		);

		return $meta;
	}

	private static function remontar( $oficina, $pedacos, $destino ) {
		$saida = fopen( $destino, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $saida ) {
			return new WP_Error( 'disco', 'Não consegui montar o arquivo aqui. Pode tentar de novo?' );
		}

		// Ordem numérica, não ordem de chegada.
		for ( $i = 0; $i < $pedacos; $i++ ) {
			$parte = $oficina . '/' . $i . '.parte';
			if ( ! is_readable( $parte ) ) {
				fclose( $saida ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $destino ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error( 'pedaco_sumiu', sprintf( 'O pedaço %d se perdeu no caminho.', $i + 1 ) );
			}
			$entrada = fopen( $parte, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			stream_copy_to_stream( $entrada, $saida );
			fclose( $entrada ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		fclose( $saida ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * A conferência que vale: sobre o arquivo inteiro, já montado.
	 *
	 * @return true|WP_Error
	 */
	public static function conferir( $caminho, $ext, array $campo ) {
		if ( ! is_readable( $caminho ) || filesize( $caminho ) < 1 ) {
			return new WP_Error( 'vazio', 'Esse arquivo chegou vazio. Pode tentar de novo?' );
		}
		if ( filesize( $caminho ) > Leticia_Config::teto_arquivo() ) {
			return new WP_Error( 'grande', 'Esse arquivo passou do limite de tamanho.' );
		}
		if ( ! in_array( $ext, $campo['aceita'], true ) ) {
			return new WP_Error( 'extensao', sprintf( 'Aqui eu aceito %s.', $campo['aceita_texto'] ) );
		}

		$tipos = self::tipos_reais();
		$real  = self::tipo_real( $caminho );

		if ( ! isset( $tipos[ $ext ] ) || ! in_array( $real, $tipos[ $ext ], true ) ) {
			// O nome dizia uma coisa e o conteúdo diz outra. Pode ser ataque e
			// pode ser engano — o e-mail que a pessoa vê é o mesmo, porque
			// dizer "detectamos uma tentativa" ensina quem está tentando.
			return new WP_Error(
				'tipo_divergente',
				'Esse arquivo não parece ser mesmo um .' . $ext . '. Consegue exportar de novo e mandar?'
			);
		}

		// SVG é o único formato aceito que pode carregar script dentro. Ele
		// sempre sai por download, nunca renderizado — mas um SVG com script
		// não tem por que existir num briefing.
		if ( 'svg' === $ext && self::svg_com_script( $caminho ) ) {
			return new WP_Error( 'svg_perigoso', 'Esse SVG tem código dentro, e não consigo aceitar. Pode mandar em PNG ou PDF?' );
		}

		return true;
	}

	public static function tipo_real( $caminho ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			// Sem finfo não há conferência possível, e fingir que houve seria
			// pior: o código de chamada trata tipo vazio como divergente.
			return '';
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$tipo  = finfo_file( $finfo, $caminho );
		finfo_close( $finfo );
		return is_string( $tipo ) ? strtolower( $tipo ) : '';
	}

	private static function svg_com_script( $caminho ) {
		$trecho = file_get_contents( $caminho, false, null, 0, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return (bool) preg_match( '/<script|javascript:|\son\w+\s*=/i', (string) $trecho );
	}

	/**
	 * O que a LetícIA avisa sem bloquear.
	 *
	 * Logo pequena continua sendo aceita: bloquear aqui faria a pessoa parar o
	 * briefing para procurar um arquivo que talvez nem exista. Avisar deixa a
	 * decisão com ela e a informação com a equipe.
	 */
	private static function aviso( $caminho, $ext, array $campo ) {
		if ( 'logo' !== $campo['chave'] || ! in_array( $ext, array( 'png', 'jpg', 'jpeg' ), true ) ) {
			return '';
		}
		$medida = @getimagesize( $caminho ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $medida || empty( $medida[0] ) || $medida[0] >= 500 ) {
			return '';
		}
		return sprintf(
			'Essa imagem tem %d pixels de largura. Aceito assim mesmo, mas no topo do site ela pode sair borrada — se você tiver o arquivo original, em PDF ou vetor, ele rende bem mais.',
			(int) $medida[0]
		);
	}

	// ------------------------------------------------------------- entrega

	public static function caminho_de( array $meta ) {
		if ( empty( $meta['pasta'] ) || empty( $meta['disco'] ) ) {
			return '';
		}
		// Nada do que vem de fora entra no caminho sem passar por aqui: as duas
		// partes têm formato fixo e são conferidas antes de virar caminho.
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $meta['pasta'] ) || ! preg_match( '/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $meta['disco'] ) ) {
			return '';
		}
		return trailingslashit( self::pasta_base() ) . 'arquivos/' . $meta['pasta'] . '/' . $meta['disco'];
	}

	/**
	 * Manda o arquivo para quem já provou que pode vê-lo.
	 *
	 * Quem confere a permissão é a rota; esta função só entrega. Sempre como
	 * anexo: um PDF ou um SVG aberto no navegador, servido do mesmo domínio do
	 * site, é execução de conteúdo de terceiro na origem do cliente.
	 */
	public static function entregar( array $meta ) {
		$caminho = self::caminho_de( $meta );
		if ( '' === $caminho || ! is_readable( $caminho ) ) {
			return new WP_Error( 'sumiu', 'Esse arquivo não está mais aqui.' );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $caminho ) );
		header( 'Content-Disposition: attachment; filename="' . self::limpar_nome( $meta['nome'] ) . '"' );
		readfile( $caminho ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	// ------------------------------------------------------------- limpeza

	public static function remover( $sessao, array $meta ) {
		if ( empty( $meta['pasta'] ) || $meta['pasta'] !== self::pasta_da_sessao( $sessao ) ) {
			return new WP_Error( 'nao_e_seu', 'Esse arquivo não é desta conversa.' );
		}
		$caminho = self::caminho_de( $meta );
		if ( '' !== $caminho && file_exists( $caminho ) ) {
			@unlink( $caminho ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return true;
	}

	/**
	 * Tira do servidor os arquivos de um briefing que já foi entregue.
	 *
	 * Eles já estão na caixa da equipe. Guardar uma segunda cópia aqui é
	 * guardar material de cliente num lugar que ninguém decidiu que existe.
	 *
	 * @return int quantos saíram
	 */
	public static function apagar_do_briefing( $sessao, array $estado ) {
		$apagados = 0;
		foreach ( $estado['respostas'] as $resposta ) {
			foreach ( (array) $resposta['arquivos'] as $meta ) {
				$caminho = self::caminho_de( (array) $meta );
				if ( '' === $caminho || ! file_exists( $caminho ) ) {
					continue;
				}
				if ( true === self::remover( $sessao, (array) $meta ) ) {
					$apagados++;
				}
			}
		}
		$pasta = trailingslashit( self::pasta_base() ) . 'arquivos/' . self::pasta_da_sessao( $sessao );
		if ( is_dir( $pasta ) && ! glob( $pasta . '/*' ) ) {
			@rmdir( $pasta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $apagados;
	}

	/** O arquivo ainda está no servidor? No painel, é o que decide se há download. */
	public static function existe( array $meta ) {
		$caminho = self::caminho_de( $meta );
		return '' !== $caminho && is_readable( $caminho );
	}

	/**
	 * Varre os envios pela metade que ninguém terminou.
	 *
	 * Sem isto, todo briefing abandonado no meio de um vídeo deixaria os
	 * pedaços em disco para sempre — e ninguém repara em disco enchendo até o
	 * dia em que o site para de gravar.
	 */
	public static function limpar_velhos() {
		$base     = trailingslashit( self::pasta_base() );
		$apagadas = 0;

		foreach ( (array) glob( $base . 'parciais/*/*', GLOB_ONLYDIR ) as $pasta ) {
			if ( filemtime( $pasta ) > time() - self::VALIDADE_PARCIAL ) {
				continue;
			}
			self::varrer( $pasta );
			$apagadas++;
		}

		// E os arquivos inteiros que nunca foram entregues: de rascunho
		// abandonado, ou de briefing cujo e-mail não saiu nem pelo painel.
		foreach ( (array) glob( $base . 'arquivos/*', GLOB_ONLYDIR ) as $pasta ) {
			foreach ( (array) glob( $pasta . '/*' ) as $arquivo ) {
				if ( is_file( $arquivo ) && filemtime( $arquivo ) < time() - self::VALIDADE_ARQUIVO ) {
					@unlink( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$apagadas++;
				}
			}
			if ( ! glob( $pasta . '/*' ) ) {
				@rmdir( $pasta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		return $apagadas;
	}

	private static function varrer( $pasta ) {
		if ( ! is_dir( $pasta ) ) {
			return;
		}
		foreach ( (array) glob( $pasta . '/*' ) as $arquivo ) {
			if ( is_file( $arquivo ) ) {
				@unlink( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $pasta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// ------------------------------------------------------------- auxiliares

	private static function envio( $sessao, $id ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $id ) ) {
			return new WP_Error( 'id_invalido', 'Envio desconhecido.' );
		}
		$envio = get_transient( self::PREFIXO_PARCIAL . $id );
		if ( ! is_array( $envio ) ) {
			return new WP_Error( 'envio_expirou', 'Esse envio expirou. Pode mandar o arquivo de novo?' );
		}
		// O envio pertence a quem o abriu. Sem esta linha, quem descobrisse um
		// id escreveria dentro do envio de outra pessoa.
		if ( $envio['sessao'] !== $sessao ) {
			return new WP_Error( 'nao_e_seu', 'Esse envio não é desta conversa.' );
		}
		return $envio;
	}

	public static function limpar_nome( $nome ) {
		$nome = (string) $nome;
		$nome = preg_replace( '/[\x00-\x1f\x7f]/', '', $nome );
		$nome = str_replace( array( '/', '\\' ), '-', $nome );
		$nome = sanitize_file_name( $nome );
		$nome = ltrim( $nome, '.' );
		if ( function_exists( 'mb_substr' ) ) {
			$nome = mb_substr( $nome, 0, 120, 'UTF-8' );
		}
		return '' !== $nome ? $nome : 'arquivo';
	}

	public static function extensao( $nome ) {
		$partes = explode( '.', (string) $nome );
		if ( count( $partes ) < 2 ) {
			return '';
		}
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', array_pop( $partes ) ) );
	}
}
