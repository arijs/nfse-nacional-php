<?php declare(strict_types=1);

namespace Arijs\NfseNacional;

/**
 * Ambientes do Sistema Nacional NFS-e.
 *
 * O valor numérico é o mesmo que vai no campo `tpAmb` da DPS (1 = Produção,
 * 2 = Homologação). A Sefin rejeita (E0006) uma DPS cujo `tpAmb` não bate com
 * o ambiente da URL para a qual ela foi enviada, por isso ambiente e URL vivem
 * juntos aqui.
 */
enum Ambiente: int
{
	case Producao = 1;
	case Homologacao = 2;

	/** API Sefin Nacional (emissão, consulta, eventos, parâmetros municipais). */
	public function urlSefin(): string
	{
		return match ($this) {
			self::Producao => 'https://sefin.nfse.gov.br/SefinNacional',
			self::Homologacao => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional',
		};
	}

	/** ADN — Ambiente de Dados Nacional (distribuição de DF-e, DANFSe). */
	public function urlAdn(): string
	{
		return match ($this) {
			self::Producao => 'https://adn.nfse.gov.br',
			self::Homologacao => 'https://adn.producaorestrita.nfse.gov.br',
		};
	}

	public function nome(): string
	{
		return match ($this) {
			self::Producao => 'producao',
			self::Homologacao => 'homologacao',
		};
	}

	/**
	 * Aceita o número do leiaute (1/2) ou os nomes usuais, em qualquer caixa:
	 * producao, prod, homologacao, homolog, producao-restrita, teste.
	 */
	public static function de(int|string|self $valor): self
	{
		if ($valor instanceof self) {
			return $valor;
		}
		$v = strtolower(trim((string) $valor));

		return match ($v) {
			'1', 'producao', 'produção', 'prod' => self::Producao,
			'2', 'homologacao', 'homologação', 'homolog', 'producao-restrita', 'producaorestrita', 'teste', 'test' => self::Homologacao,
			default => throw new \InvalidArgumentException("Ambiente desconhecido: '{$valor}' (use 1/producao ou 2/homologacao)."),
		};
	}
}
