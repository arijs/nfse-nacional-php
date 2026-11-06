<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Tests\Support;

/** infDPS mínimo e válido: prestador do Simples Nacional, tomador PJ nacional. */
final class DpsExemplo
{
	/** @return array<string,mixed> */
	public static function infDps(): array
	{
		return [
			'tpAmb' => '2',
			'dhEmi' => new \DateTimeImmutable('2026-11-06T10:15:00-03:00'),
			'verAplic' => 'teste/1.0',
			'serie' => '1',
			'nDPS' => '42',
			'dCompet' => '2026-11-06',
			'tpEmit' => '1',
			'cLocEmi' => '3550308',
			'prest' => [
				'CNPJ' => CertificadoTeste::CNPJ,
				'regTrib' => ['opSimpNac' => '3', 'regApTribSN' => '1', 'regEspTrib' => '0'],
			],
			'toma' => [
				'CNPJ' => '98765432000188',
				'xNome' => 'CLIENTE EXEMPLO LTDA',
				'end' => [
					'endNac' => ['cMun' => '3550308', 'CEP' => '01001000'],
					'xLgr' => 'Praça da Sé',
					'nro' => '1',
					'xBairro' => 'Sé',
				],
				'email' => 'financeiro@cliente.example',
			],
			'serv' => [
				'locPrest' => ['cLocPrestacao' => '3550308'],
				'cServ' => [
					'cTribNac' => '010701',
					'xDescServ' => 'Licença de uso de software (mensalidade novembro/2026)',
				],
			],
			'valores' => [
				'vServPrest' => ['vServ' => 1500.0],
				'trib' => [
					'tribMun' => ['tribISSQN' => '1', 'tpRetISSQN' => '1', 'pAliq' => 2.0],
					// ME/EPP não pode usar indTotTrib (E0712): informa o percentual aproximado do SN.
					'totTrib' => ['pTotTribSN' => 6.0],
				],
			],
		];
	}
}
