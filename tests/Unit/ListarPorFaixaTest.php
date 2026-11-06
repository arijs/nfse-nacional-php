<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Configuracao;
use Arijs\NfseNacional\Excecao\SefinExcecao;
use Arijs\NfseNacional\Nfse;
use Arijs\NfseNacional\Sefin\SefinNacional;
use Arijs\NfseNacional\Tests\Support\CertificadoTeste;
use Arijs\NfseNacional\Tests\Support\ClienteHttpFalso;
use Arijs\NfseNacional\Util\Gzip;
use PHPUnit\Framework\TestCase;

final class ListarPorFaixaTest extends TestCase
{
	private const CHAVE_1 = '35503082123456780001950000000000012026110000000017';
	private const CHAVE_3 = '35503082123456780001950000000000032026110000000019';

	public function testPulaNumerosSemNotaEBuscaXmlQuandoPedido(): void
	{
		$http = (new ClienteHttpFalso())
			->responder(200, ['chaveAcesso' => self::CHAVE_1])                                   // dps 1
			->responder(200, ['nfseXmlGZipB64' => Gzip::comprimirBase64('<NFSe>1</NFSe>')])     // nfse 1
			->responder(404, ['erros' => [['codigo' => 'E0000', 'descricao' => 'não encontrada']]]) // dps 2
			->responder(200, ['chaveAcesso' => self::CHAVE_3])                                   // dps 3
			->responder(200, ['nfseXmlGZipB64' => Gzip::comprimirBase64('<NFSe>3</NFSe>')]);    // nfse 3
		$sefin = new SefinNacional(Ambiente::Homologacao, $http);
		$vistos = [];

		$lista = $sefin->listarPorFaixa('3550308', '12345678000195', 1, 1, 3, true, static function (array $i) use (&$vistos): void {
			$vistos[] = $i['nDPS'];
		});

		self::assertSame([1, 3], array_column($lista, 'nDPS'));
		self::assertSame([self::CHAVE_1, self::CHAVE_3], array_column($lista, 'chaveAcesso'));
		self::assertSame(['<NFSe>1</NFSe>', '<NFSe>3</NFSe>'], array_column($lista, 'nfseXml'));
		// O número 2 foi consultado (e pulado por 404) com o Id certo.
		self::assertStringEndsWith('/dps/DPS355030821234567800019500001000000000000002', $http->chamadas[2]['url']);
		self::assertSame('DPS355030821234567800019500001000000000000003', $lista[1]['idDps']);
		self::assertSame([1, 3], $vistos);
		self::assertCount(5, $http->chamadas);
	}

	public function testErroQueNaoSeja404Propaga(): void
	{
		$http = (new ClienteHttpFalso())->responder(500, 'indisponível');
		$this->expectException(SefinExcecao::class);
		(new SefinNacional(Ambiente::Homologacao, $http))->listarPorFaixa('3550308', '12345678000195', 1, 1, 2);
	}

	public function testFachadaUsaOCnpjDoCertificado(): void
	{
		$http = (new ClienteHttpFalso())->responder(404, '');
		$nfse = new Nfse(new Configuracao(Ambiente::Homologacao, CertificadoTeste::certificado()), $http);

		self::assertSame([], $nfse->listarPorFaixa('3550308', 1, 7, 7));
		self::assertStringEndsWith('/dps/DPS35503082'.CertificadoTeste::CNPJ.'00001000000000000007', $http->chamadas[0]['url']);
	}

	public function testFaixaInvalida(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new SefinNacional(Ambiente::Homologacao, new ClienteHttpFalso()))->listarPorFaixa('3550308', '12345678000195', 1, 5, 4);
	}
}
