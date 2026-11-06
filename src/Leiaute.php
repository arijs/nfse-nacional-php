<?php declare(strict_types=1);

namespace Arijs\NfseNacional;

/**
 * Qual conjunto de esquemas a DPS segue.
 *
 * Os dois se chamam "1.01" no atributo `versao`, mas não são o mesmo XSD: a
 * NT SE/CGNFS-e 009 republicou o 1.01 com `finNFSe` obrigatório no infDPS,
 * `xDescServ` no fim de `cServ` e `vDedRed` trocado por `vAjusteBC`. Desde
 * 20/10/2026 a produção restrita só aceita o novo (E1235 com "expected:
 * cMotivoEmisTI, finNFSe"), enquanto a produção segue no anterior — e a NT não
 * diz quando a produção vira. Por isso a escolha é de configuração, com um
 * padrão por ambiente que reflete o que cada Sefin aplica hoje.
 *
 * O valor do caso é também o nome da pasta em `resources/xsd/`.
 */
enum Leiaute: string
{
	/** Pacote de 09/02/2026 — o que a produção aplica. */
	case V101 = '1.01';

	/** Pacote de 20/10/2026 (NT 009) — o que a produção restrita aplica. */
	case V101Nt009 = '1.01-nt009';

	/** O que vai no atributo `versao` da DPS e no sufixo dos arquivos XSD. */
	public function versaoXml(): string
	{
		return '1.01';
	}

	/** Pasta dos esquemas, relativa a `resources/xsd/`. */
	public function pastaXsd(): string
	{
		return $this->value;
	}

	/** `finNFSe` existe (e é obrigatório) no infDPS. */
	public function exigeFinNfse(): bool
	{
		return self::V101Nt009 === $this;
	}

	/**
	 * O leiaute que a Sefin de cada ambiente aplica hoje (06/11/2026). Quando a
	 * produção adotar a NT 009, troque aqui — ou, antes de atualizar a
	 * biblioteca, defina NFSE_LEIAUTE=nt009.
	 */
	public static function padraoPara(Ambiente $ambiente): self
	{
		return match ($ambiente) {
			Ambiente::Producao => self::V101,
			Ambiente::Homologacao => self::V101Nt009,
		};
	}

	/** Aceita o valor do caso ou apelidos: `1.01`/`atual`, `nt009`/`1.01-nt009`. */
	public static function de(string|self $valor): self
	{
		if ($valor instanceof self) {
			return $valor;
		}

		return match (strtolower(trim($valor))) {
			'1.01', 'atual', 'v101' => self::V101,
			'1.01-nt009', 'nt009', 'nt-009', 'v101nt009' => self::V101Nt009,
			default => throw new \InvalidArgumentException("Leiaute desconhecido: '{$valor}' (use 1.01 ou nt009)."),
		};
	}
}
